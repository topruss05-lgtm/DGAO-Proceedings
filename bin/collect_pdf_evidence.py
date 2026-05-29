#!/usr/bin/env python3
"""
Läuft den PDF-Extraktor über alle Papers mit PDF und sammelt Evidenz:
  - pro autor_id: alle aus Paper-PDFs extrahierten Vornamen (mit Häufigkeit + Paper-Quelle)
  - pro paper_id: Autor->Marker und Marker->Affil-Strings (für Strang B)

Output: bin/.cache/pdf_evidence.jsonl (eine Zeile pro Paper).
Idempotent: kann mit --resume fortgesetzt werden.
"""
import json
import sqlite3
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from pdf_extract import process_paper  # noqa

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
OUT = ROOT / "bin" / ".cache" / "pdf_evidence.jsonl"


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true", help="bereits verarbeitete Papers überspringen")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    OUT.parent.mkdir(exist_ok=True)
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    done = set()
    if args.resume and OUT.exists():
        with OUT.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["paper_id"])
                except Exception:
                    pass
        print(f"  resume: {len(done)} bereits erledigt", file=sys.stderr)

    ids = [r[0] for r in con.execute(
        "SELECT id FROM papers WHERE hat_pdf=1 ORDER BY id")]
    if args.limit:
        ids = ids[:args.limit]
    ids = [pid for pid in ids if pid not in done]
    print(f"  zu verarbeiten: {len(ids)}", file=sys.stderr)

    written = 0
    with OUT.open("a") as f:
        for i, pid in enumerate(ids, 1):
            try:
                res = process_paper(con, pid)
            except Exception as e:
                res = {"paper_id": pid, "errors": [f"crash: {e}"]}
            f.write(json.dumps(res, ensure_ascii=False) + "\n")
            f.flush()
            written += 1
            if i % 50 == 0:
                print(f"  ...{i}/{len(ids)}", file=sys.stderr)
    print(f"Fertig: {written} Papers verarbeitet, Output: {OUT}", file=sys.stderr)


if __name__ == "__main__":
    main()
