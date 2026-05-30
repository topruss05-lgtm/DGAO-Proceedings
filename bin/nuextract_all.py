#!/usr/bin/env python3
"""
NuExtract3 Vollauflauf über alle 1478 PDFs.
Output: bin/.cache/nuextract_evidence.jsonl (eine Zeile pro Paper).
Resume-fähig.
"""
import json
import sqlite3
import sys
import time
from pathlib import Path

import pypdfium2 as pdfium
import torch
from PIL import Image
from transformers import AutoModelForImageTextToText, AutoProcessor

ROOT = Path(__file__).resolve().parents[1]
PDF_DIR = ROOT / "public" / "download"
OUT = ROOT / "bin" / ".cache" / "nuextract_evidence.jsonl"
DB = ROOT / "public" / "data" / "proceedings.db"

DEVICE = "mps" if torch.backends.mps.is_available() else "cpu"

TEMPLATE = {
    "authors": [
        {
            "name": "string",
            "affiliation_markers": ["string"],
        }
    ],
    "affiliations": [
        {
            "marker": "string",
            "name": "string",
        }
    ],
    "title": "string",
    "email": "string",
}


def pdf_page0_image(pdf_path: Path, dpi: int = 130, max_side: int = 1800):
    try:
        pdf = pdfium.PdfDocument(str(pdf_path))
        if len(pdf) == 0:
            return None
        page = pdf[0]
        bitmap = page.render(scale=dpi / 72)
        img = bitmap.to_pil().convert("RGB")
        pdf.close()
        w, h = img.size
        if max(w, h) > max_side:
            scale = max_side / max(w, h)
            img = img.resize((int(w * scale), int(h * scale)), Image.LANCZOS)
        return img
    except Exception:
        return None


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--ids", type=str, default="")
    args = ap.parse_args()

    OUT.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(str(DB))
    con.row_factory = sqlite3.Row

    done = set()
    if args.resume and OUT.exists():
        with OUT.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["paper_id"])
                except Exception:
                    pass
        print(f"  resume: {len(done)} bereits", file=sys.stderr)

    if args.ids:
        ids = [x.strip() for x in args.ids.split(",") if x.strip()]
    else:
        ids = [r[0] for r in con.execute(
            "SELECT id FROM papers WHERE hat_pdf=1 ORDER BY id").fetchall()]
    if args.limit:
        ids = ids[:args.limit]
    ids = [p for p in ids if p not in done]
    print(f"Pending: {len(ids)} Papers ({DEVICE})", file=sys.stderr)

    print(f"Lade NuExtract3...", file=sys.stderr)
    t_load = time.time()
    model_path = "numind/NuExtract3"
    model = AutoModelForImageTextToText.from_pretrained(
        model_path, dtype=torch.bfloat16, device_map=DEVICE,
        trust_remote_code=True,
    ).eval()
    processor = AutoProcessor.from_pretrained(model_path, trust_remote_code=True)
    print(f"Modell geladen in {time.time()-t_load:.1f}s", file=sys.stderr)

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
                f.write(json.dumps({"paper_id": pid, "error": "missing"}) + "\n")
                continue
            img = pdf_page0_image(pdf_path)
            if img is None:
                f.write(json.dumps({"paper_id": pid, "error": "pdfium fail"}) + "\n")
                continue
            t_p = time.time()
            try:
                messages = [{
                    "role": "user",
                    "content": [{"type": "image", "image": img}],
                }]
                inputs = processor.apply_chat_template(
                    messages, add_generation_prompt=True,
                    tokenize=True, return_dict=True, return_tensors="pt",
                    template=json.dumps(TEMPLATE, indent=4),
                    enable_thinking=False,
                ).to(model.device)
                with torch.inference_mode():
                    outputs = model.generate(
                        **inputs, max_new_tokens=1024, do_sample=False,
                    )
                generated = outputs[:, inputs["input_ids"].shape[1]:]
                txt = processor.batch_decode(
                    generated, skip_special_tokens=True,
                )[0].strip()
                try:
                    parsed = json.loads(txt)
                except json.JSONDecodeError:
                    parsed = {"raw": txt}
                f.write(json.dumps({
                    "paper_id": pid,
                    "elapsed_s": round(time.time() - t_p, 1),
                    "result": parsed,
                }, ensure_ascii=False) + "\n")
            except Exception as e:
                f.write(json.dumps({"paper_id": pid, "error": str(e)[:200]}) + "\n")
            f.flush()
            if i % 10 == 0:
                el = time.time() - t0
                rate = i / el
                eta = (len(ids) - i) / rate / 60
                print(f"  {i}/{len(ids)} ({rate:.2f}/s, ETA {eta:.0f}min)", file=sys.stderr)
    print(f"Fertig in {(time.time()-t0)/60:.1f}min", file=sys.stderr)


if __name__ == "__main__":
    main()
