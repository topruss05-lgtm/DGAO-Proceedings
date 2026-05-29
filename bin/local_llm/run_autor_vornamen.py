#!/usr/bin/env python3
"""
Lokale Pipeline für Autoren-Vornamen-Vervollständigung mit Qwen3.5-27B-Claude-4.6-Opus-Distilled.

Multi-Step-Loop pro Autor:
  1. Web-Search (DuckDuckGo via ddgs)
  2. LLM entscheidet welche URLs lesenswert
  3. Fetch + zusammenfassen
  4. LLM macht finale Entscheidung
  5. Self-critique pass
  6. JSON-Output → DB

Verwendung:
  python run_autor_vornamen.py --limit 5 --dry-run         # Sanity-Check
  python run_autor_vornamen.py --groundtruth-only          # Stichprobe (20 Autoren)
  python run_autor_vornamen.py --resume                    # Volllauf, fortsetzbar
"""

from __future__ import annotations

import argparse
import json
import re
import sqlite3
import sys
import time
from pathlib import Path
from typing import Optional

import requests
from ddgs import DDGS
from mlx_lm import load, generate
from mlx_lm.sample_utils import make_sampler

# --- Config ---
DB_PATH = Path(__file__).resolve().parents[2] / "public" / "data" / "proceedings.db"
MODEL_ID = "mlx-community/Qwen3.5-27B-Claude-4.6-Opus-Distilled-MLX-4bit"
GROUNDTRUTH_AUTOR_IDS = [
    9661, 5261, 261, 3461, 4261, 7861, 1461, 2461, 3261, 3861,
    7261, 11461, 61, 3061, 4661, 5861, 6461, 9061, 9261, 10261,
]

SYSTEM_PROMPT = """Du bist ein konservativer Recherche-Assistent. Deine Aufgabe: aus dem
Vornamens-Initial eines Wissenschaftlers den vollständigen Vornamen ableiten, basierend auf
Web-Suche-Snippets.

REGELN (zwingend einhalten):
1. Nutze NUR Informationen aus den gegebenen Snippets. Keine Annahmen aus Trainingsdaten.
2. Der Vorname muss EINDEUTIG aus mind. 2 unabhängigen Snippets belegt sein, sonst gibst du
   confidence < 0.7 und vorname = null zurück.
3. Bei zwei plausiblen Vornamen → confidence < 0.5, vorname = null.
4. Bei nicht-deutschen Autoren: behalte ggf. die nicht-deutsche Schreibweise (z.B. "François"
   nicht "Franz").
5. Initialen + Bindestriche (H.-P.) → vollständiger Doppelname (Hans-Peter) NUR mit Beleg.
6. Antworte AUSSCHLIESSLICH mit dem geforderten JSON. Kein Text davor/danach.
"""

# --- DB helpers ---

def open_db(path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.execute("PRAGMA foreign_keys = ON")
    con.row_factory = sqlite3.Row
    return con

def init_schema(con: sqlite3.Connection) -> None:
    con.execute("""
        CREATE TABLE IF NOT EXISTS autor_vorname_audit (
            autor_id INTEGER PRIMARY KEY,
            alter_vorname TEXT,
            neuer_vorname TEXT,
            source TEXT,
            confidence REAL,
            reason TEXT,
            processed_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    """)
    con.commit()

def fetch_pending(con: sqlite3.Connection, ids: Optional[list[int]], limit: Optional[int]) -> list[sqlite3.Row]:
    base = """
        SELECT a.id, a.vorname, a.nachname,
               (SELECT i.name_de FROM autor_institutionen ai
                JOIN institutionen i ON i.id = ai.institut_id
                WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff,
               COUNT(pa.paper_id) AS papers
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        WHERE (a.vorname LIKE '%.%' OR LENGTH(a.vorname) <= 3)
          AND a.id NOT IN (SELECT autor_id FROM autor_vorname_audit WHERE source = 'qwen_local')
    """
    params: list = []
    if ids:
        placeholders = ",".join("?" * len(ids))
        base += f" AND a.id IN ({placeholders})"
        params.extend(ids)
    base += " GROUP BY a.id ORDER BY papers DESC"
    if limit:
        base += " LIMIT ?"
        params.append(limit)
    return con.execute(base, params).fetchall()

# --- Web-Search ---

def web_search(query: str, max_results: int = 5) -> list[dict]:
    """DuckDuckGo via ddgs — kein API-Key, robust."""
    try:
        with DDGS(timeout=15) as ddg:
            results = list(ddg.text(query, max_results=max_results, region="wt-wt"))
        return [{"title": r.get("title", ""), "url": r.get("href", ""), "body": r.get("body", "")}
                for r in results]
    except Exception as e:
        print(f"  [web_search ERROR] {e}", file=sys.stderr)
        return []

def fetch_url_summary(url: str, max_chars: int = 2000) -> str:
    """Fetcht eine URL, gibt Text-Snippet (entstripped HTML) zurück."""
    try:
        r = requests.get(url, timeout=10, headers={"User-Agent": "Mozilla/5.0 dgao-bot/1.0"})
        if r.status_code != 200:
            return ""
        text = re.sub(r"<script.*?</script>", "", r.text, flags=re.DOTALL | re.IGNORECASE)
        text = re.sub(r"<style.*?</style>", "", text, flags=re.DOTALL | re.IGNORECASE)
        text = re.sub(r"<[^>]+>", " ", text)
        text = re.sub(r"\s+", " ", text).strip()
        return text[:max_chars]
    except Exception:
        return ""

# --- LLM call ---

class LLM:
    def __init__(self, model_id: str = MODEL_ID):
        print(f"Loading model {model_id} (this can take a minute)...")
        self.model, self.tokenizer = load(model_id)
        self.sampler = make_sampler(temp=0.0, top_p=1.0)
        print("  Model loaded.")

    def chat(self, system: str, user: str, max_tokens: int = 1024) -> str:
        messages = [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ]
        prompt = self.tokenizer.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
        return generate(
            self.model, self.tokenizer, prompt=prompt,
            max_tokens=max_tokens, sampler=self.sampler, verbose=False,
        )

# --- Per-author pipeline ---

def extract_json(text: str) -> Optional[dict]:
    """Holt das erste JSON-Object aus einem LLM-Output."""
    text = text.strip()
    # Strip markdown fences
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"\s*```$", "", text)
    # Find first { ... } block
    m = re.search(r"\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}", text, re.DOTALL)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

def process_author(llm: LLM, row: sqlite3.Row, dry_run: bool = False) -> dict:
    initial = row["vorname"]
    nachname = row["nachname"]
    aff = row["aff"] or ""

    # Step 1: Web search
    query = f"\"{initial} {nachname}\" {aff}".strip()
    if not aff:
        query = f"\"{initial} {nachname}\""
    results = web_search(query, max_results=5)

    # Step 2: LLM evaluates snippets — entscheidet ob mehr Recherche nötig
    snippets_text = "\n".join(
        f"[{i}] {r['title']}\n    URL: {r['url']}\n    {r['body'][:300]}"
        for i, r in enumerate(results, 1)
    )

    # Step 3: Direct decision call (für strukturierten Output)
    decision_prompt = f"""Du sollst den vollständigen Vornamen ableiten.

AUTOR:
- Initial: {initial}
- Nachname: {nachname}
- Affiliation: {aff or "(unbekannt)"}

WEB-SUCHE für '{query}':
{snippets_text or "(keine Treffer)"}

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt nach diesem Schema:
{{
  "reasoning": "<2-4 Sätze: Welche Snippets stützen welchen Vornamen? Mehrdeutigkeit?>",
  "neuer_vorname": "<voller Vorname mit Belegen aus den Snippets, oder null>",
  "confidence": <float 0.0-1.0>,
  "evidence": ["<URL oder Snippet-Nummer #1>", "<#2>"]
}}

Regeln nochmal:
- Mind. 2 unabhängige Snippets müssen den gleichen Vornamen belegen
- Bei Unsicherheit: neuer_vorname=null, confidence<0.7
- Achte auf passende Affiliation (Universitäts-Match)
"""

    raw = llm.chat(SYSTEM_PROMPT, decision_prompt, max_tokens=600)
    parsed = extract_json(raw)
    if not parsed:
        return {
            "autor_id": row["id"],
            "alter_vorname": initial,
            "neuer_vorname": None,
            "confidence": 0.0,
            "reason": f"LLM-Output-Parse-Fehler. Raw: {raw[:200]}",
            "raw_llm_output": raw,
        }

    nv = parsed.get("neuer_vorname")
    if isinstance(nv, str):
        nv = nv.strip() or None

    return {
        "autor_id": row["id"],
        "alter_vorname": initial,
        "neuer_vorname": nv,
        "confidence": float(parsed.get("confidence", 0.0)),
        "reason": (parsed.get("reasoning", "") + " EVIDENCE: " +
                   ", ".join(parsed.get("evidence", [])))[:500],
        "raw_llm_output": raw,
    }

# --- Main ---

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=None, help="Anzahl Autoren begrenzen")
    ap.add_argument("--dry-run", action="store_true", help="Keine DB-Updates")
    ap.add_argument("--groundtruth-only", action="store_true", help="Nur die 20 Ground-Truth-Autoren")
    ap.add_argument("--resume", action="store_true", help="Skipt bereits prozessierte")
    args = ap.parse_args()

    con = open_db(DB_PATH)
    init_schema(con)

    ids = GROUNDTRUTH_AUTOR_IDS if args.groundtruth_only else None
    rows = fetch_pending(con, ids, args.limit)
    print(f"Pending: {len(rows)} Autoren")

    if not rows:
        print("Nichts zu tun.")
        return

    llm = LLM()
    t_start = time.time()

    for i, row in enumerate(rows, 1):
        t0 = time.time()
        try:
            result = process_author(llm, row, dry_run=args.dry_run)
        except Exception as e:
            print(f"  [{i}/{len(rows)}] id={row['id']} ERROR: {e}", file=sys.stderr)
            continue

        elapsed = time.time() - t0
        nv = result["neuer_vorname"] or "—"
        conf = result["confidence"]
        marker = "✓" if conf >= 0.9 else "?"
        print(f"  [{i}/{len(rows)}] {marker} id={row['id']} {row['vorname']} {row['nachname']:25} → {nv:20} (conf={conf:.2f}, {elapsed:.1f}s)")

        if args.dry_run:
            continue

        con.execute("""
            INSERT OR REPLACE INTO autor_vorname_audit
              (autor_id, alter_vorname, neuer_vorname, source, confidence, reason)
            VALUES (?, ?, ?, 'qwen_local', ?, ?)
        """, (result["autor_id"], result["alter_vorname"], result["neuer_vorname"],
              result["confidence"], result["reason"]))

        # UPDATE autoren bei hoher Konfidenz
        if result["neuer_vorname"] and conf >= 0.9:
            con.execute("UPDATE autoren SET vorname = ? WHERE id = ?",
                        (result["neuer_vorname"], result["autor_id"]))
        con.commit()

    total = time.time() - t_start
    print(f"\nFertig: {len(rows)} Autoren in {total:.0f}s ({total/len(rows):.1f}s/Autor)")

if __name__ == "__main__":
    main()
