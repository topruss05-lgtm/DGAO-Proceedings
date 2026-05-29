#!/usr/bin/env python3
"""
Identifiziert und löscht "Institutionen", die in Wirklichkeit Müll sind:
  - Emails (enthält "@")
  - Abstract-Fragmente (typische Satzanfänge/-fragmente)
  - Zu kurz / einzelne Wörter ohne Inst-Kontext
  - Beginnt mit Sonderzeichen oder Kleinbuchstabe (Satzfragment)
  - Enthält Referenzen wie "[1, 2]" oder "Fig. N"
  - Eindeutig Abstract-Text-Heuristiken
"""
import re
import sqlite3
import sys
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"


# Konservative Heuristik: nur klare Junk-Faelle.
ABSTRACT_HINTS = re.compile(
    r"\b(in this paper|in dieser arbeit|we present|we show|we demonstrate|"
    r"we propose|we have|with the help|results show|conclusion|introduction|"
    r"abbildung \w|figure \d|table \d|equation \d|"
    r"^- \d|"
    r"compared to|leading to|allowing for|"
    r"can be|möglich ist|woraus|wodurch|dazu genutzt|dies wurde|"
    r"in vorherigen|repositioned|reconstruct|"
    r"phase shifting|moiré|punktwolken|wellenfeld|rückpropagation)",
    re.IGNORECASE,
)

# Institut-Indikatoren — sehr breit, um false-positives zu vermeiden
INST_HINTS = re.compile(
    r"\b(institut|institute|istituto|universit|university|fakult|faculty|"
    r"hochschule|college|laboratory|labor|department|dipartiment|abteilung|"
    r"fraunhofer|max[- ]planck|helmholtz|leibniz|gmbh|inc\.?|llc|ltd|corp|"
    r"company|cnrs|cnr|center|centre|zentrum|centro|akademie|academy|"
    r"kliniken|klinik|hospital|hospitalar|ag\.?$|kg\b|e\.?\s*v\.?|"
    r"agency|bureau|ministry|ministerium|ministero|société|sciences|"
    r"national|federal|state|royal|deutsche|deutsches|"
    r"research|forschung|optics|optik|tno|enea|bessy|technologie|engineering)\b",
    re.IGNORECASE,
)


def is_junk(name: str) -> tuple[bool, str]:
    """Return (is_junk, reason). KONSERVATIV — lieber lassen als legitim verlieren."""
    if not name:
        return True, "leer"
    t = name.strip()
    if len(t) < 3:
        return True, f"zu kurz ({len(t)} chars)"
    # Email-Adressen
    if re.search(r"@\S+\.\S+", t):
        return True, "Email"
    # Beginnt mit "- N" oder Aufzaehlungspunkt
    if re.match(r"^-\s*\d", t) or re.match(r"^\s*[•◦▪]", t):
        return True, "Aufzaehlungs-Bullet"
    # Referenzen ([1, 2], Fig. X) UND kein Inst-Indikator
    if re.search(r"\[\d+[,\s\d]*\]|^Fig\.|\bFig\.\s*\d", t, re.IGNORECASE):
        if not INST_HINTS.search(t):
            return True, "Referenz/Fig"
    # Abstract-Phrasen UND kein Inst-Indikator
    if ABSTRACT_HINTS.search(t) and not INST_HINTS.search(t):
        return True, "Abstract-Phrasen"
    # Beginnt mit Kleinbuchstabe (Satzfortsetzung) UND kein Inst-Indikator
    if re.match(r"^[a-zäöü]", t) and not INST_HINTS.search(t):
        # Aber: Firmennamen wie "opsira", "asphericon", "digitX" beginnen klein
        # → zusätzlich: muss Satzpattern haben
        if re.search(r"\.\s+[a-zäöü]|\s+(can|will|wird|werden|sind|ist|wurde)\s", t):
            return True, "lowercase + Satzpattern"
        # Sonst: nicht junk
    # Endet mit Punkt + Kleinbuchstabe (Satz-Fortsetzung) UND kein Inst-Indikator
    if re.search(r"\.\s+[a-zäöü]", t) and not INST_HINTS.search(t):
        return True, "Satz-Fortsetzung ohne Inst-Indikator"
    # Sehr einzelne klein-Wörter ohne Indikator (z.B. "Aufbau")
    if " " not in t and re.match(r"^[a-zäöü]", t):
        if not INST_HINTS.search(t):
            return True, "Einzelnes Kleinbuchstaben-Wort"
    return False, ""


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")

    rows = con.execute("SELECT id, name_de FROM institutionen").fetchall()
    junk = []
    for r in rows:
        bad, reason = is_junk(r["name_de"])
        if bad:
            n_pai = con.execute(
                "SELECT COUNT(*) FROM paper_autor_institutionen WHERE institut_id=?",
                (r["id"],)).fetchone()[0]
            n_ai = con.execute(
                "SELECT COUNT(*) FROM autor_institutionen WHERE institut_id=?",
                (r["id"],)).fetchone()[0]
            junk.append({"id": r["id"], "name": r["name_de"][:80],
                         "reason": reason, "n_pai": n_pai, "n_ai": n_ai})

    print(f"Institute total:      {len(rows)}")
    print(f"Junk-Institute:       {len(junk)}")
    print(f"  davon mit pai:      {sum(1 for j in junk if j['n_pai']>0)}")
    print(f"  davon mit ai:       {sum(1 for j in junk if j['n_ai']>0)}")
    print(f"  pai-Verknüpfungen:  {sum(j['n_pai'] for j in junk)}")
    print()
    print("Beispiele:")
    for j in junk[:15]:
        print(f"  id={j['id']:5} pai={j['n_pai']:3} ai={j['n_ai']:3}  [{j['reason'][:30]}]  {j['name']}")

    if args.apply:
        ids = [j["id"] for j in junk]
        chunk = 500
        deleted_pai = 0
        deleted_ai = 0
        deleted_aliase = 0
        deleted_inst = 0
        for i in range(0, len(ids), chunk):
            batch = ids[i:i+chunk]
            ph = ",".join("?" * len(batch))
            cur = con.execute(f"DELETE FROM paper_autor_institutionen WHERE institut_id IN ({ph})", batch)
            deleted_pai += cur.rowcount
            cur = con.execute(f"DELETE FROM autor_institutionen WHERE institut_id IN ({ph})", batch)
            deleted_ai += cur.rowcount
            cur = con.execute(f"DELETE FROM institut_aliase WHERE institut_id IN ({ph})", batch)
            deleted_aliase += cur.rowcount
            con.execute(f"UPDATE institutionen SET parent_id=NULL WHERE parent_id IN ({ph})", batch)
            cur = con.execute(f"DELETE FROM institutionen WHERE id IN ({ph})", batch)
            deleted_inst += cur.rowcount
        con.commit()
        print(f"\n✓ Gelöscht: {deleted_inst} Institute, {deleted_pai} pai, {deleted_ai} ai, {deleted_aliase} Aliase")


if __name__ == "__main__":
    main()
