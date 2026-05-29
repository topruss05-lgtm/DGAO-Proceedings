#!/usr/bin/env python3
"""
Bereinigt Aliase, die fälschlich Sub-Institute als Aliase der Dachorga
behandeln.

Heuristik:
  - Alias enthält "Lehrstuhl", "Institute of", "Institut für", "Department",
    "Laboratory", "Chair of", "Group", "Faculty", "School of"
  - UND zugehörige Institution hat KEINEN dieser Begriffe im Hauptnamen
  -> falsche Zuordnung, löschen.

Plus: lange Aliase (>= 60 Zeichen) die kein direktes Substring des Hauptnamens
sind, werden konservativ entfernt — die machen falsche Substring-Matches.
"""
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"

SUB_INSTITUT_KEYS = re.compile(
    r"\b(Lehrstuhl|Institute of|Institut für|Department of|Laboratory|"
    r"Lab\.?|Chair of|Research Group|Faculty of|School of|Center for|"
    r"Centre for|Forschungsgruppe|Abteilung|Arbeitsgruppe)\b",
    re.IGNORECASE,
)


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    rows = con.execute("""
        SELECT al.id, al.institut_id, al.alias_text, i.name_de
        FROM institut_aliase al
        JOIN institutionen i ON i.id = al.institut_id
    """).fetchall()

    to_delete = []
    examples = []
    for r in rows:
        alias = r["alias_text"]
        main = r["name_de"]
        alias_has_sub = bool(SUB_INSTITUT_KEYS.search(alias))
        main_has_sub = bool(SUB_INSTITUT_KEYS.search(main))
        n_alias = norm(alias)
        n_main = norm(main)

        bad = False
        reason = ""

        # Nur klare Sub-Institut-Aliase an Nicht-Sub-Institut-Hauptname.
        # DE/EN-Translations zu loeschen ist zu riskant -> konservativ.
        if alias_has_sub and not main_has_sub:
            bad = True
            reason = "sub-key in alias only"
        # Beide haben Sub-Keys, aber ANDERE Sub-Institute (z.B. FAPS-Lehrstuhl
        # vs Optik-Institut). Token-Overlap-Schwelle 40%: weniger -> Geschwister.
        elif alias_has_sub and main_has_sub:
            ta = set(n_alias.split())
            tm = set(n_main.split())
            if ta and tm:
                # nur Content-Tokens, keine "stop" Universitaets-Wörter
                content = {"institute", "institut", "lehrstuhl", "fakultat",
                           "department", "laboratory", "chair", "fur",
                           "fuer", "for", "of", "and", "und", "die", "der", "das"}
                ta_c = ta - content
                tm_c = tm - content
                if ta_c and tm_c:
                    ov = len(ta_c & tm_c) / min(len(ta_c), len(tm_c))
                    if ov < 0.4:
                        bad = True
                        reason = f"sibling sub-instituts ({ov:.2f})"

        if bad:
            to_delete.append(r["id"])
            if len(examples) < 8:
                examples.append((main[:50], alias[:70], reason))

    print(f"Aliase total:           {len(rows)}")
    print(f"Verdächtige Aliase:     {len(to_delete)}")
    print("\nBeispiele:")
    for main, alias, reason in examples:
        print(f"  HAUPT: {main}")
        print(f"  ALIAS: {alias}")
        print(f"  GRUND: {reason}\n")

    if args.apply and to_delete:
        chunk_size = 500
        for i in range(0, len(to_delete), chunk_size):
            chunk = to_delete[i : i + chunk_size]
            placeholders = ",".join("?" * len(chunk))
            con.execute(f"DELETE FROM institut_aliase WHERE id IN ({placeholders})", chunk)
        con.commit()
        print(f"\n✓ {len(to_delete)} Aliase gelöscht")


if __name__ == "__main__":
    main()
