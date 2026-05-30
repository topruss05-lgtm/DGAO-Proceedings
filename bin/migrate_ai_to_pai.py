#!/usr/bin/env python3
"""
Migration: autor_institutionen (ai) -> paper_autor_institutionen (pai).

Ziel: ai-Tabelle obsolet machen. pai ist neue Source of Truth.

Phasen:
  1. Verwaiste Autoren (ohne paper_autoren-Eintrag) loeschen
  2. Fehlende pai-Eintraege aus ai befuellen (quelle='legacy_ai')
  3. Stats ausgeben (paper_autoren ohne Affil als Review-Queue)

Dry-Run per default. --apply zum Schreiben.
"""
import argparse
import sqlite3
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"


def audit(con):
    rows = {
        "autoren_total": con.execute("SELECT COUNT(*) FROM autoren").fetchone()[0],
        "autoren_ohne_paper": con.execute("""
            SELECT COUNT(*) FROM autoren a
            WHERE NOT EXISTS (SELECT 1 FROM paper_autoren pa WHERE pa.autor_id = a.id)
        """).fetchone()[0],
        "paper_autoren_total": con.execute("SELECT COUNT(*) FROM paper_autoren").fetchone()[0],
        "paper_autoren_ohne_pai": con.execute("""
            SELECT COUNT(*) FROM paper_autoren pa
            WHERE NOT EXISTS (SELECT 1 FROM paper_autor_institutionen pai
                              WHERE pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id)
        """).fetchone()[0],
        "rettbar_via_ai": con.execute("""
            SELECT COUNT(*) FROM paper_autoren pa
            WHERE NOT EXISTS (SELECT 1 FROM paper_autor_institutionen pai
                              WHERE pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id)
              AND EXISTS (SELECT 1 FROM autor_institutionen ai WHERE ai.autor_id = pa.autor_id)
        """).fetchone()[0],
        "review_queue": con.execute("""
            SELECT COUNT(*) FROM paper_autoren pa
            WHERE NOT EXISTS (SELECT 1 FROM paper_autor_institutionen pai
                              WHERE pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id)
              AND NOT EXISTS (SELECT 1 FROM autor_institutionen ai WHERE ai.autor_id = pa.autor_id)
        """).fetchone()[0],
        "ai_rows": con.execute("SELECT COUNT(*) FROM autor_institutionen").fetchone()[0],
    }
    return rows


def phase1_delete_orphan_autoren(con, apply: bool) -> int:
    """Loesche Autoren ohne paper_autoren-Eintrag."""
    rows = con.execute("""
        SELECT id, vorname, nachname FROM autoren a
        WHERE NOT EXISTS (SELECT 1 FROM paper_autoren pa WHERE pa.autor_id = a.id)
    """).fetchall()
    for r in rows:
        print(f"  orphan autor: #{r[0]:5} {r[1]} {r[2]}")
    if apply and rows:
        ids = [r[0] for r in rows]
        # Hängende ai-/Alias-/Redirect-Refs via ON DELETE CASCADE
        con.executemany("DELETE FROM autoren WHERE id = ?", [(i,) for i in ids])
    return len(rows)


def phase2_migrate_ai_to_pai(con, apply: bool) -> int:
    """Pro (paper, autor) ohne pai aber mit ai: alle ai-Eintraege als pai eintragen."""
    rows = con.execute("""
        SELECT pa.paper_id, pa.autor_id, ai.institut_id
        FROM paper_autoren pa
        JOIN autor_institutionen ai ON ai.autor_id = pa.autor_id
        WHERE NOT EXISTS (SELECT 1 FROM paper_autor_institutionen pai
                          WHERE pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id)
    """).fetchall()
    if apply and rows:
        con.executemany("""
            INSERT OR IGNORE INTO paper_autor_institutionen
              (paper_id, autor_id, institut_id, quelle)
            VALUES (?, ?, ?, 'legacy_ai')
        """, rows)
    return len(rows)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    args = ap.parse_args()

    con = sqlite3.connect(str(DB))
    con.execute("PRAGMA foreign_keys = ON")

    print("=== AUDIT vorher ===")
    pre = audit(con)
    for k, v in pre.items():
        print(f"  {k:30}  {v}")

    print(f"\n=== Phase 1: orphan Autoren {'APPLY' if args.apply else 'DRY'} ===")
    n1 = phase1_delete_orphan_autoren(con, args.apply)
    print(f"  -> {n1} Autoren betroffen")

    print(f"\n=== Phase 2: ai -> pai-Migration {'APPLY' if args.apply else 'DRY'} ===")
    n2 = phase2_migrate_ai_to_pai(con, args.apply)
    print(f"  -> {n2} (paper, autor, institut)-Tripel als 'legacy_ai' eingefuegt")

    if args.apply:
        con.commit()

    print("\n=== AUDIT nachher ===")
    post = audit(con)
    for k, v in post.items():
        delta = v - pre[k]
        sign = '+' if delta >= 0 else ''
        print(f"  {k:30}  {v:6}   ({sign}{delta})")


if __name__ == "__main__":
    main()
