#!/usr/bin/env python3
"""
Listet alle Borderline-Fälle aus dem Qwen-Local-Lauf für Opus-4.7-Tiefenrecherche.

Kriterien für "Borderline":
  - confidence < 0.9 (Qwen unsicher)
  - oder neuer_vorname IS NULL aber Qwen hat es versucht
  - oder Stichproben-Mismatch mit Sonnet

Output: JSON nach borderline_review_queue.json (für Opus zu bearbeiten).
"""

import json
import sqlite3
from pathlib import Path

DB = Path(__file__).resolve().parents[2] / "public" / "data" / "proceedings.db"
OUT = Path(__file__).resolve().parent / "borderline_review_queue.json"

con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row

# Borderline aus Qwen-Lokal
qwen_borderline = con.execute("""
    SELECT a.id, a.vorname AS alter_vorname, a.nachname,
           q.neuer_vorname AS qwen_vorschlag,
           q.confidence AS qwen_conf,
           q.reason AS qwen_reason,
           (SELECT i.name_de FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff,
           COUNT(pa.paper_id) AS papers
    FROM autor_vorname_audit q
    JOIN autoren a ON a.id = q.autor_id
    LEFT JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE q.source = 'qwen_local'
      AND q.confidence < 0.9
    GROUP BY a.id
    ORDER BY papers DESC, a.nachname
""").fetchall()

# Stichproben-Mismatches (falls vorhanden)
stichprobe_mismatches = con.execute("""
    SELECT g.autor_id, a.vorname AS alter_vorname, a.nachname,
           g.neuer_vorname AS sonnet_vn, g.confidence AS sonnet_conf,
           q.neuer_vorname AS qwen_vn, q.confidence AS qwen_conf,
           q.reason AS qwen_reason,
           (SELECT i.name_de FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff
    FROM autor_vorname_groundtruth g
    JOIN autoren a ON a.id = g.autor_id
    LEFT JOIN autor_vorname_audit q
           ON q.autor_id = g.autor_id AND q.source = 'qwen_local'
    WHERE (g.neuer_vorname IS NULL) != (q.neuer_vorname IS NULL)
       OR LOWER(g.neuer_vorname) != LOWER(q.neuer_vorname)
""").fetchall()

queue = {
    "qwen_low_confidence": [dict(r) for r in qwen_borderline],
    "stichprobe_mismatches": [dict(r) for r in stichprobe_mismatches],
}

OUT.write_text(json.dumps(queue, indent=2, ensure_ascii=False))

print(f"Qwen-Low-Confidence:     {len(qwen_borderline)} Autoren")
print(f"Stichproben-Mismatches:  {len(stichprobe_mismatches)} Autoren")
print(f"\nQueue: {OUT}")
print(f"Total für Opus-Review:   {len(qwen_borderline) + len(stichprobe_mismatches)}")
