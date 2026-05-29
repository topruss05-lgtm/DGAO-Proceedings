#!/usr/bin/env python3
"""
Vergleicht autor_vorname_audit (Qwen-Local) mit autor_vorname_groundtruth (Sonnet).
Quality-Report für die 20 Stichproben-Autoren.
"""

import sqlite3
from pathlib import Path

DB = Path(__file__).resolve().parents[2] / "public" / "data" / "proceedings.db"

con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row

rows = con.execute("""
    SELECT g.autor_id, g.alter_vorname,
           g.neuer_vorname AS sonnet_vn, g.confidence AS sonnet_conf,
           q.neuer_vorname AS qwen_vn,   q.confidence AS qwen_conf,
           a.nachname,
           (SELECT i.name_de FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff
    FROM autor_vorname_groundtruth g
    LEFT JOIN autor_vorname_audit q
           ON q.autor_id = g.autor_id AND q.source = 'qwen_local'
    JOIN autoren a ON a.id = g.autor_id
    ORDER BY g.autor_id
""").fetchall()

if not rows:
    print("Keine Ground-Truth-Daten gefunden. Sonnet-Stichprobe-Run abwarten?")
    raise SystemExit(0)

print(f"\n{'='*100}\nVergleich Qwen-Local vs. Sonnet (Ground Truth)\n{'='*100}\n")

match = mismatch = qwen_missing = both_null = sonnet_only = 0
mismatches = []

for r in rows:
    sn = (r["sonnet_vn"] or "").strip().lower() or None
    qn = (r["qwen_vn"] or "").strip().lower() or None

    if qn is None and r["qwen_conf"] is None:
        qwen_missing += 1
        status = "✗ Qwen fehlt"
    elif sn is None and qn is None:
        both_null += 1
        status = "= both NULL"
    elif sn and qn and sn == qn:
        match += 1
        status = "✓ MATCH"
    elif sn and not qn:
        sonnet_only += 1
        status = "△ Qwen unsicher (Sonnet hat Antwort)"
    elif sn and qn and sn != qn:
        mismatch += 1
        status = "✗ MISMATCH"
        mismatches.append(r)
    else:
        status = "?"

    print(f"id={r['autor_id']:6} {r['alter_vorname']:4} {r['nachname']:20}  "
          f"S: {(r['sonnet_vn'] or '—'):15} ({r['sonnet_conf'] or 0:.2f})  "
          f"Q: {(r['qwen_vn'] or '—'):15} ({r['qwen_conf'] or 0:.2f})  {status}")

total = len(rows)
print(f"\n{'='*100}")
print(f"Total: {total} Stichproben-Autoren")
print(f"  ✓ Match            : {match}")
print(f"  = both NULL        : {both_null}")
print(f"  △ Qwen konservativer (Sonnet hat Antwort): {sonnet_only}")
print(f"  ✗ Mismatch         : {mismatch}")
print(f"  ✗ Qwen fehlt       : {qwen_missing}")
agreement = (match + both_null) / total * 100
print(f"\nÜbereinstimmung (Match + beide NULL): {agreement:.0f}%")

if mismatches:
    print(f"\n{'─'*100}\nMismatches im Detail:\n")
    for r in mismatches:
        print(f"  id={r['autor_id']}  {r['alter_vorname']} {r['nachname']} @ {r['aff'] or '?'}")
        print(f"    Sonnet:  {r['sonnet_vn']} (conf {r['sonnet_conf']:.2f})")
        print(f"    Qwen:    {r['qwen_vn']} (conf {r['qwen_conf']:.2f})")
        print()
