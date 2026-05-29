#!/usr/bin/env python3
"""
Marker als Library (nicht Subprocess) — Modelle einmal laden, dann alle PDFs.
Page 0 reicht für Header.
"""
import json
import re
import sqlite3
import sys
import time
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
PDF_DIR = ROOT / "public" / "download"
OUT_JSONL = ROOT / "bin" / ".cache" / "marker_evidence.jsonl"
MD_DIR = ROOT / "bin" / ".cache" / "marker_md"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--ids", type=str, default="")
    args = ap.parse_args()

    OUT_JSONL.parent.mkdir(parents=True, exist_ok=True)
    MD_DIR.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    # Marker einmal laden
    print("Lade Marker-Modelle...", file=sys.stderr)
    from marker.converters.pdf import PdfConverter
    from marker.models import create_model_dict
    from marker.output import text_from_rendered
    from marker.config.parser import ConfigParser
    config = ConfigParser({"page_range": "0", "disable_image_extraction": True,
                           "output_format": "markdown"})
    converter = PdfConverter(
        config=config.generate_config_dict(),
        artifact_dict=create_model_dict(),
        processor_list=config.get_processors(),
        renderer=config.get_renderer(),
    )
    print("Modelle geladen", file=sys.stderr)

    done = set()
    if args.resume and OUT_JSONL.exists():
        with OUT_JSONL.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["paper_id"])
                except Exception:
                    pass
        print(f"  resume: {len(done)} bereits erledigt", file=sys.stderr)

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
    with OUT_JSONL.open("a") as f:
        for i, pid in enumerate(ids, 1):
            row = con.execute(
                "SELECT tagung_nummer, pdf_dateiname FROM papers WHERE id=?",
                (pid,)).fetchone()
            if not row or not row["pdf_dateiname"]:
                f.write(json.dumps({"paper_id": pid, "ok": False,
                                     "error": "no pdf"}) + "\n")
                continue
            pdf_path = PDF_DIR / str(row["tagung_nummer"]) / row["pdf_dateiname"]
            if not pdf_path.exists():
                f.write(json.dumps({"paper_id": pid, "ok": False,
                                     "error": f"missing {pdf_path.name}"}) + "\n")
                continue
            try:
                rendered = converter(str(pdf_path))
                md, _, _ = text_from_rendered(rendered)
                # Speichere MD für späteren Parse + Debug
                (MD_DIR / f"{pid}.md").write_text(md, encoding="utf-8")
                f.write(json.dumps({"paper_id": pid, "ok": True,
                                     "md_len": len(md)}, ensure_ascii=False) + "\n")
            except Exception as e:
                f.write(json.dumps({"paper_id": pid, "ok": False,
                                     "error": str(e)[:200]}) + "\n")
            f.flush()
            if i % 20 == 0:
                el = time.time() - t0
                rate = i / el
                eta = (len(ids) - i) / rate / 60
                print(f"  {i}/{len(ids)} ({rate:.2f}/s, ETA {eta:.1f}min)", file=sys.stderr)
    print(f"Fertig in {(time.time()-t0)/60:.1f}min", file=sys.stderr)


if __name__ == "__main__":
    main()
