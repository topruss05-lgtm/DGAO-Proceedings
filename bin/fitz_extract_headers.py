#!/usr/bin/env python3
"""
PyMuPDF Header-Extraction für DGaO-Proceedings.

Native Text-PDFs, kein ML/OCR nötig. Pro PDF (Page 0):
  - Title (groesste Font-Size)
  - Header-Zone (alles zwischen Title und 'Abstract'/'Zusammenfassung')
  - Spans mit Font-Size/Bold/Position fuer downstream Parsing

Output: bin/.cache/fitz_headers.jsonl
Performance: ~0.05-0.2s/PDF -> ~3min fuer 1478 PDFs
"""
import json
import re
import sqlite3
import sys
import time
from pathlib import Path

import fitz  # PyMuPDF

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
PDF_DIR = ROOT / "public" / "download"
OUT = ROOT / "bin" / ".cache" / "fitz_headers.jsonl"

ABSTRACT_RE = re.compile(
    r"\b(Abstract|Zusammenfassung|Summary|Kurzfassung|Einleitung|Introduction|1\.\s+(Einleitung|Introduction|Motivation))\b",
    re.IGNORECASE,
)


def extract_header(pdf_path: Path) -> dict:
    out: dict = {"title": None, "header_raw": None, "spans": [], "error": None}
    try:
        doc = fitz.open(pdf_path)
    except Exception as e:
        out["error"] = f"open: {e}"
        return out
    if doc.page_count == 0:
        doc.close()
        out["error"] = "empty"
        return out
    page = doc[0]
    try:
        data = page.get_text("dict", sort=True)
    except Exception as e:
        doc.close()
        out["error"] = f"get_text: {e}"
        return out

    spans = []
    for block in data.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                t = (span.get("text") or "").strip()
                if not t:
                    continue
                spans.append({
                    "text": t,
                    "size": round(float(span.get("size", 0)), 1),
                    "bold": bool(int(span.get("flags", 0)) & 16),  # bit 4
                    "italic": bool(int(span.get("flags", 0)) & 2),  # bit 1
                    "bbox": [round(x, 1) for x in span.get("bbox", [0, 0, 0, 0])],
                    "font": span.get("font", ""),
                })

    if not spans:
        doc.close()
        out["error"] = "no_text"
        return out

    # Title: groesste Font-Size in oberer Page-Hälfte
    page_h = page.rect.height
    upper = [s for s in spans if s["bbox"][1] < page_h * 0.45]
    if not upper:
        upper = spans
    max_size = max(s["size"] for s in upper)
    title_spans = [s for s in upper if s["size"] >= max_size - 0.3][:8]
    out["title"] = " ".join(s["text"] for s in title_spans).strip()

    # Header-Zone: alles zwischen unterer Title-Kante und 'Abstract'/oberen 50%
    title_y_end = max(s["bbox"][3] for s in title_spans) if title_spans else 0
    header_zone_spans = [
        s for s in spans
        if s["bbox"][1] > title_y_end and s["bbox"][1] < page_h * 0.55
    ]
    header_text = "\n".join(s["text"] for s in header_zone_spans)
    # Bei Abstract-Marker abschneiden
    m = ABSTRACT_RE.search(header_text)
    if m:
        header_text = header_text[:m.start()].rstrip()
    out["header_raw"] = header_text

    # Spans für downstream Parsing: nur die im Header-Zone
    out["spans"] = header_zone_spans[:50]
    doc.close()
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--ids", type=str, default="")
    args = ap.parse_args()

    OUT.parent.mkdir(parents=True, exist_ok=True)
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

    if args.ids:
        ids = [x.strip() for x in args.ids.split(",") if x.strip()]
    else:
        ids = [r[0] for r in con.execute(
            "SELECT id FROM papers WHERE hat_pdf=1 ORDER BY id").fetchall()]
    if args.limit:
        ids = ids[:args.limit]
    ids = [p for p in ids if p not in done]
    print(f"Pending: {len(ids)} Papers", file=sys.stderr)

    t0 = time.time()
    with OUT.open("a") as f:
        for i, pid in enumerate(ids, 1):
            row = con.execute(
                "SELECT tagung_nummer, pdf_dateiname FROM papers WHERE id=?",
                (pid,)).fetchone()
            if not row or not row["pdf_dateiname"]:
                f.write(json.dumps({"paper_id": pid, "error": "no pdf"}) + "\n")
                continue
            pdf_path = PDF_DIR / str(row["tagung_nummer"]) / row["pdf_dateiname"]
            if not pdf_path.exists():
                f.write(json.dumps({"paper_id": pid, "error": "missing file"}) + "\n")
                continue
            res = extract_header(pdf_path)
            res["paper_id"] = pid
            f.write(json.dumps(res, ensure_ascii=False) + "\n")
            if i % 200 == 0:
                rate = i / (time.time() - t0)
                print(f"  {i}/{len(ids)} ({rate:.1f}/s)", file=sys.stderr)
    print(f"Fertig in {(time.time()-t0):.1f}s = {(time.time()-t0)/60:.1f}min", file=sys.stderr)


if __name__ == "__main__":
    main()
