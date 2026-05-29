#!/usr/bin/env python3
"""
Wendet Sonnet-Subagent-Ergebnisse auf die DB an.

Liest alle bin/.cache/borderline_batch_*_result.json und schreibt:
  - autoren.vorname  (wenn confidence >= 0.85 und kein existierender voller Vorname)
  - autoren.orcid_id (wenn ORCID gefunden und family-name matched)
  - autor_aliase     (alle bekannten Varianten)
  - autor_vorname_audit_v9 (Audit-Trail mit Quelle 'sonnet_web')

Sicherheits-Checks:
  - Familyname in ORCID muss zum DB-Nachnamen passen (falls ORCID gesetzt)
  - Vorname-Initial muss konsistent sein
  - Stichprobe-Output für manuelle Review
"""
import glob
import json
import re
import sqlite3
import sys
import unicodedata
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def verify_orcid_family(orcid_url: str, expected_nachname: str) -> bool:
    """ORCID-Family-Name muss zum DB-Nachnamen passen."""
    if not orcid_url:
        return True
    oid = orcid_url.split("/")[-1]
    try:
        req = urllib.request.Request(
            f"https://pub.orcid.org/v3.0/{oid}/personal-details",
            headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=10) as r:
            d = json.loads(r.read().decode())
        fn = ((d.get("name") or {}).get("family-name") or {}).get("value", "")
        return norm(fn) == norm(expected_nachname)
    except Exception:
        return False  # ORCID nicht verifizierbar -> nicht setzen


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--min-conf", type=float, default=0.85)
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    # Sicherstellen dass autor_vorname_audit_v9 existiert
    con.execute("""
        CREATE TABLE IF NOT EXISTS autor_vorname_audit_v9 (
            autor_id   INTEGER PRIMARY KEY,
            old_vorname TEXT, new_vorname TEXT,
            anzeige_name TEXT, orcid_id   TEXT,
            confidence REAL, quelle TEXT, reason TEXT, applied INTEGER DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    """)

    files = sorted(set(
        glob.glob(str(ROOT / "bin/.cache/borderline_batch_*_result.json")) +
        glob.glob(str(ROOT / "bin/.cache/borderline_wave*_batch_*_result.json"))
    ))
    print(f"Sonnet-Result-Dateien: {len(files)}")

    all_results = []
    for fp in files:
        with open(fp) as f:
            data = json.load(f)
        if not isinstance(data, list):
            print(f"  ⚠ {fp}: kein Array")
            continue
        all_results.extend(data)
    print(f"  Total Einträge: {len(all_results)}")

    stats = {"vorname_set": 0, "vorname_skip_lowconf": 0,
             "vorname_skip_existing": 0,
             "orcid_set": 0, "orcid_rejected_family_mismatch": 0,
             "aliase_neu": 0}

    for r in all_results:
        aid = r.get("id")
        if not aid:
            continue
        row = con.execute(
            "SELECT id, vorname, nachname, orcid_id FROM autoren WHERE id=?",
            (aid,)).fetchone()
        if not row:
            continue

        decision_vn = r.get("vorname")
        decision_orcid = r.get("orcid")
        conf = float(r.get("confidence") or 0)
        reason = (r.get("reason") or "")[:500]

        # Audit
        con.execute("""
            INSERT OR REPLACE INTO autor_vorname_audit_v9
              (autor_id, old_vorname, new_vorname, orcid_id, confidence, quelle, reason)
            VALUES (?,?,?,?,?,'sonnet_web', ?)
        """, (aid, row["vorname"], decision_vn, decision_orcid, conf, reason))

        # Vorname schreiben
        if decision_vn and conf >= args.min_conf and not (
            row["vorname"] and "." not in row["vorname"] and len(row["vorname"]) > 3
        ):
            # Initial-Konsistenz
            init = norm(row["vorname"].replace(".", ""))[:1]
            if not init or norm(decision_vn)[:1] == init:
                if args.apply:
                    con.execute("UPDATE autoren SET vorname=? WHERE id=?",
                                (decision_vn, aid))
                stats["vorname_set"] += 1
            else:
                stats["vorname_skip_lowconf"] += 1
        elif decision_vn and conf < args.min_conf:
            stats["vorname_skip_lowconf"] += 1
        elif decision_vn:
            stats["vorname_skip_existing"] += 1

        # ORCID setzen NUR nach Family-Name-Verifikation
        if decision_orcid and not row["orcid_id"]:
            # URL-Prefix normalisieren
            if not decision_orcid.startswith("http"):
                decision_orcid = "https://orcid.org/" + decision_orcid.strip()
            if verify_orcid_family(decision_orcid, row["nachname"]):
                if args.apply:
                    con.execute("UPDATE autoren SET orcid_id=? WHERE id=?",
                                (decision_orcid, aid))
                stats["orcid_set"] += 1
            else:
                stats["orcid_rejected_family_mismatch"] += 1

        # Aliase
        if decision_vn:
            for variant in [
                f"{decision_vn} {row['nachname']}",
                f"{row['nachname']}, {decision_vn}",
            ]:
                an = norm(variant)
                if an:
                    try:
                        if args.apply:
                            con.execute("""
                                INSERT INTO autor_aliase (autor_id, alias_text, alias_norm)
                                VALUES (?,?,?)
                            """, (aid, variant, an))
                        stats["aliase_neu"] += 1
                    except sqlite3.IntegrityError:
                        pass

    if args.apply:
        con.commit()

    print(f"\n--- Apply {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:36}  {v}")


if __name__ == "__main__":
    main()
