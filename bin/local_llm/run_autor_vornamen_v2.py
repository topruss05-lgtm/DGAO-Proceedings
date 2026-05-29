#!/usr/bin/env python3
"""
Pipeline v2 für Autoren-Vornamen mit Multi-Step Tool-Use.

Verbesserungen gegenüber v1:
1. Mehrere Such-Queries pro Autor (Initial+Nachname, "Dr. Initial Nachname", Nachname+Institut)
2. Längere Snippets (800 chars statt 300, 10 Treffer statt 5)
3. Tool-Use-Loop: bei conf<0.7 fetcht das LLM 1-2 Top-URLs und macht zweiten Entscheidungs-Call
4. Hint-Liste: gängige Vornamen passend zur Initiale werden im Prompt erwähnt (verbessert Erkennung)
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

DB_PATH = Path(__file__).resolve().parents[2] / "public" / "data" / "proceedings.db"
MODEL_ID = "mlx-community/Qwen3.5-27B-Claude-4.6-Opus-Distilled-MLX-4bit"
GROUNDTRUTH_AUTOR_IDS = [
    9661, 5261, 261, 3461, 4261, 7861, 1461, 2461, 3261, 3861,
    7261, 11461, 61, 3061, 4661, 5861, 6461, 9061, 9261, 10261,
]

SYSTEM_PROMPT = """Du bist ein konservativer Recherche-Assistent. Aus Web-Suche-Snippets sollst du den
vollständigen Vornamen eines Wissenschaftlers ableiten.

REGELN:
1. Antworte mit reinem JSON nach dem geforderten Schema (kein Markdown, kein Text drumherum).
2. Verwende NUR Informationen aus den gegebenen Snippets/URLs.
3. Bei Unsicherheit confidence < 0.7 und vorname = null — niemals raten.
4. Achte auf Affiliation-Match: der gefundene Vorname muss zu einer Person mit passender Institution gehören.
5. Initiale "Ch." kann sowohl "Christian" als auch "Christoph", "Christof" oder "Christine" sein — wenn das Snippet nicht eindeutig ist: confidence < 0.5.
6. Für nicht-deutsche Autoren: nicht-deutsche Schreibweise behalten (François, Sébastien, ...).
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
    # ALTER für Migration falls Tabelle alt ist
    cols = {r[1] for r in con.execute("PRAGMA table_info(autor_vorname_audit)")}
    if "reason" not in cols:
        con.execute("ALTER TABLE autor_vorname_audit ADD COLUMN reason TEXT")
    con.commit()

def fetch_pending(con, ids, limit, source_tag):
    base = f"""
        SELECT a.id, a.vorname, a.nachname,
               (SELECT i.name_de FROM autor_institutionen ai
                JOIN institutionen i ON i.id = ai.institut_id
                WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS aff,
               COUNT(pa.paper_id) AS papers
        FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        WHERE (a.vorname LIKE '%.%' OR LENGTH(a.vorname) <= 3)
          AND a.id NOT IN (SELECT autor_id FROM autor_vorname_audit WHERE source = ?)
    """
    params: list = [source_tag]
    if ids:
        placeholders = ",".join("?" * len(ids))
        base += f" AND a.id IN ({placeholders})"
        params.extend(ids)
    base += " GROUP BY a.id ORDER BY papers DESC"
    if limit:
        base += " LIMIT ?"
        params.append(limit)
    return con.execute(base, params).fetchall()

# --- Web ---

def web_search(query, max_results=10):
    try:
        with DDGS(timeout=15) as ddg:
            return [{"title": r.get("title",""), "url": r.get("href",""), "body": r.get("body","")}
                    for r in ddg.text(query, max_results=max_results, region="wt-wt")]
    except Exception as e:
        print(f"  [web_search ERROR] {e}", file=sys.stderr)
        return []

def fetch_url_text(url, max_chars=4000):
    try:
        r = requests.get(url, timeout=12, headers={"User-Agent":"Mozilla/5.0 dgao-bot/1.0"})
        if r.status_code != 200:
            return ""
        text = re.sub(r"<script.*?</script>", "", r.text, flags=re.DOTALL|re.IGNORECASE)
        text = re.sub(r"<style.*?</style>", "", text, flags=re.DOTALL|re.IGNORECASE)
        text = re.sub(r"<[^>]+>", " ", text)
        text = re.sub(r"\s+", " ", text).strip()
        return text[:max_chars]
    except Exception:
        return ""

# --- LLM ---

class LLM:
    def __init__(self, model_id=MODEL_ID):
        print(f"Loading {model_id}...")
        self.model, self.tokenizer = load(model_id)
        self.sampler = make_sampler(temp=0.0, top_p=1.0)
        print("  Model loaded.")

    def chat(self, system, user, max_tokens=700):
        msgs = [{"role":"system","content":system},{"role":"user","content":user}]
        prompt = self.tokenizer.apply_chat_template(msgs, tokenize=False, add_generation_prompt=True)
        return generate(self.model, self.tokenizer, prompt=prompt,
                        max_tokens=max_tokens, sampler=self.sampler, verbose=False)

def extract_json(text):
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"\s*```$", "", text)
    m = re.search(r"\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}", text, re.DOTALL)
    if not m: return None
    try:
        return json.loads(m.group(0))
    except json.JSONDecodeError:
        return None

# --- Vorname-Hints (für Initial → Kandidaten) ---

VORNAMEN_HINTS = {
    "A.": ["Andreas", "Alexander", "Andrea", "Anna", "André", "Anke", "Albert", "Achim", "Arnd", "Aron", "Axel"],
    "B.": ["Bernd", "Bernhard", "Bettina", "Burkhard", "Boris", "Bruno", "Bastian"],
    "C.": ["Christian", "Christoph", "Christof", "Cornelia", "Carsten", "Claudia", "Claus", "Carolin", "Christopher"],
    "Ch.": ["Christian", "Christoph", "Christof", "Christine", "Christa", "Christopher"],
    "Chr.": ["Christian", "Christoph", "Christof", "Christine"],
    "D.": ["Daniel", "Dirk", "Detlef", "Doris", "David", "Dietmar", "Dorothee"],
    "E.": ["Eric", "Erik", "Ernst", "Eva", "Erika", "Egbert", "Eberhard"],
    "F.": ["Frank", "Friedrich", "Florian", "Franziska", "Felix", "Franz"],
    "G.": ["Günter", "Gerd", "Gerhard", "Georg", "Gisela", "Guido"],
    "H.": ["Hans", "Hartmut", "Helmut", "Heinrich", "Heinz", "Herbert", "Horst", "Holger", "Heiner"],
    "H.-P.": ["Hans-Peter"],
    "I.": ["Ingo", "Ina", "Ilse", "Iris", "Ilaria"],
    "J.": ["Jürgen", "Jens", "Joachim", "Johannes", "Jan", "Julia", "Julian"],
    "K.": ["Klaus", "Karl", "Karsten", "Kerstin", "Katja", "Kai", "Kathrin", "Kathy"],
    "K.-H.": ["Karl-Heinz"],
    "L.": ["Lars", "Lutz", "Lukas", "Leonhard"],
    "M.": ["Martin", "Michael", "Michaela", "Manfred", "Markus", "Marco", "Matthias", "Marcus"],
    "N.": ["Norbert", "Nicole", "Nils", "Nina"],
    "O.": ["Otto", "Olaf", "Oliver"],
    "P.": ["Peter", "Paul", "Petra", "Philipp", "Patrick"],
    "Ph.": ["Philipp"],
    "R.": ["Rainer", "Roland", "Rolf", "Rudolf", "Ralph", "Ralf", "Robert", "Richard"],
    "S.": ["Stefan", "Stephan", "Susanne", "Sabine", "Stefanie", "Steffen", "Sebastian", "Sven", "Simon"],
    "St.": ["Stefan", "Stephan", "Steffen"],
    "T.": ["Thomas", "Tobias", "Thorsten", "Torsten", "Tilo", "Tim"],
    "Th.": ["Thomas", "Thorsten"],
    "U.": ["Ulrich", "Ulrike", "Ute", "Udo", "Uwe", "Ursula"],
    "V.": ["Volker", "Volkmar", "Vera"],
    "W.": ["Wolfgang", "Werner", "Wilhelm", "Walter"],
}

def hints_for(initial: str) -> list[str]:
    return VORNAMEN_HINTS.get(initial, [])

# --- Per-author pipeline ---

def process_author(llm, row, verbose=True):
    initial = row["vorname"]
    nachname = row["nachname"]
    aff = row["aff"] or ""

    # --- Step 1: Multiple search queries ---
    queries = [
        f'"{initial} {nachname}" {aff}'.strip(),
        f'"{nachname}" {aff} Vorname OR "Dr." OR "Prof."',
    ]
    if aff:
        queries.append(f'"{nachname}" "{aff[:40]}"')

    all_results = []
    seen_urls = set()
    for q in queries:
        for r in web_search(q, max_results=8):
            if r["url"] in seen_urls: continue
            seen_urls.add(r["url"])
            all_results.append(r)
        if len(all_results) >= 12:
            break
    all_results = all_results[:12]

    snippets = "\n".join(
        f"[{i}] {r['title']}\n    URL: {r['url']}\n    {r['body'][:600]}"
        for i, r in enumerate(all_results, 1)
    )

    # --- Step 2: Initial decision ---
    candidates = hints_for(initial)
    hints_str = ", ".join(candidates) if candidates else "(keine gängigen Hints für diese Initiale)"

    decision_prompt = f"""AUTOR: Initial="{initial}", Nachname="{nachname}", Affiliation="{aff or '?'}"

GÄNGIGE VORNAMEN für diese Initiale: {hints_str}
(Du kannst andere Vornamen wählen — nur Anhaltspunkt.)

WEB-SUCHE ({len(all_results)} Treffer):
{snippets or "(keine Treffer)"}

Liefere folgendes JSON:
{{
  "reasoning": "<2-3 Sätze: welche Snippets stützen welchen Namen? Welche Affiliations-Indizien?>",
  "neuer_vorname": "<Voller Vorname mit Beleg in Snippets, oder null>",
  "confidence": <float 0.0-1.0>,
  "evidence_snippet_nrs": [<int>, ...],
  "needs_url_fetch": <true wenn Snippets nicht reichen aber eine spezifische URL helfen würde, sonst false>,
  "url_to_fetch": "<URL falls needs_url_fetch=true>"
}}

WICHTIG:
- Wenn 2+ Snippets denselben Vornamen + passende Affiliation belegen → confidence ≥ 0.9
- Wenn ein Snippet allein → confidence 0.7-0.85
- Wenn unklar → confidence < 0.7 und vorname=null
- Wenn passende Profil-/Team-/Pressemitteilungs-Snippets vorhanden aber Name nur als "X. Nachname" → setze needs_url_fetch=true und URL davon
"""

    raw1 = llm.chat(SYSTEM_PROMPT, decision_prompt, max_tokens=700)
    parsed1 = extract_json(raw1)

    if not parsed1:
        return {"autor_id": row["id"], "alter_vorname": initial, "neuer_vorname": None,
                "confidence": 0.0, "reason": f"Parse-Fehler: {raw1[:150]}"}

    # --- Step 3: Optional URL-Fetch wenn LLM unsicher und URL angefordert ---
    nv = parsed1.get("neuer_vorname")
    conf = float(parsed1.get("confidence", 0.0))
    needs = bool(parsed1.get("needs_url_fetch", False))
    url = parsed1.get("url_to_fetch", "")

    if (not nv or conf < 0.85) and needs and url and url.startswith("http"):
        if verbose:
            print(f"    [fetch] {url[:80]}")
        page_text = fetch_url_text(url, max_chars=4000)
        if page_text:
            fetch_prompt = f"""AUTOR: Initial="{initial}", Nachname="{nachname}", Affiliation="{aff or '?'}"

GÄNGIGE VORNAMEN: {hints_str}

URL ({url}):
{page_text}

Bisher hattest du: vorname="{nv}" conf={conf:.2f}.

Liefere finales JSON:
{{
  "reasoning": "<was steht auf der Seite, was bestätigt/widerlegt es?>",
  "neuer_vorname": "<Voller Vorname oder null>",
  "confidence": <float>,
  "evidence": ["<Belegstelle 1>", "<Belegstelle 2>"]
}}

Regeln gleich: bei Unsicherheit null und conf<0.7.
"""
            raw2 = llm.chat(SYSTEM_PROMPT, fetch_prompt, max_tokens=600)
            parsed2 = extract_json(raw2)
            if parsed2:
                nv = parsed2.get("neuer_vorname") or nv
                conf = float(parsed2.get("confidence", conf))
                parsed1["reasoning"] = "(after URL fetch) " + parsed2.get("reasoning", "")
                parsed1["evidence_snippet_nrs"] = parsed2.get("evidence", [])

    nv = nv.strip() if isinstance(nv, str) and nv.strip() else None
    return {
        "autor_id": row["id"],
        "alter_vorname": initial,
        "neuer_vorname": nv,
        "confidence": conf,
        "reason": (str(parsed1.get("reasoning", "")) +
                   " EVIDENCE: " + str(parsed1.get("evidence_snippet_nrs", "")))[:500],
    }

# --- Main ---

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--groundtruth-only", action="store_true")
    ap.add_argument("--source-tag", default="qwen_local_v2")
    ap.add_argument("--reset-groundtruth", action="store_true",
                    help="löscht autor_vorname_audit-Einträge der 20 GT-Autoren für source-tag (re-run möglich)")
    args = ap.parse_args()

    con = open_db(DB_PATH)
    init_schema(con)

    if args.reset_groundtruth:
        placeholders = ",".join("?"*len(GROUNDTRUTH_AUTOR_IDS))
        con.execute(f"DELETE FROM autor_vorname_audit WHERE source=? AND autor_id IN ({placeholders})",
                    [args.source_tag, *GROUNDTRUTH_AUTOR_IDS])
        con.commit()
        print(f"  Reset {args.source_tag} für 20 GT-Autoren")

    ids = GROUNDTRUTH_AUTOR_IDS if args.groundtruth_only else None
    rows = fetch_pending(con, ids, args.limit, args.source_tag)
    print(f"Pending: {len(rows)} Autoren  (source-tag: {args.source_tag})")
    if not rows: return

    llm = LLM()
    t_start = time.time()

    for i, row in enumerate(rows, 1):
        t0 = time.time()
        try:
            r = process_author(llm, row)
        except Exception as e:
            print(f"  [{i}/{len(rows)}] id={row['id']} ERROR: {e}", file=sys.stderr)
            continue

        elapsed = time.time() - t0
        nv = r["neuer_vorname"] or "—"
        conf = r["confidence"]
        marker = "✓" if conf >= 0.9 else ("?" if conf < 0.5 else "~")
        print(f"  [{i}/{len(rows)}] {marker} id={row['id']:6} {row['vorname']:5} {row['nachname']:25} → {nv:20} (conf={conf:.2f}, {elapsed:.0f}s)")

        if args.dry_run: continue
        con.execute("""
            INSERT OR REPLACE INTO autor_vorname_audit
              (autor_id, alter_vorname, neuer_vorname, source, confidence, reason)
            VALUES (?, ?, ?, ?, ?, ?)
        """, (r["autor_id"], r["alter_vorname"], r["neuer_vorname"], args.source_tag,
              r["confidence"], r["reason"]))
        if r["neuer_vorname"] and conf >= 0.9:
            con.execute("UPDATE autoren SET vorname = ? WHERE id = ?",
                        (r["neuer_vorname"], r["autor_id"]))
        con.commit()

    total = time.time() - t_start
    print(f"\nFertig: {len(rows)} Autoren in {total:.0f}s ({total/max(1,len(rows)):.1f}s/Autor)")

if __name__ == "__main__":
    main()
