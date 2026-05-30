#!/usr/bin/env python3
"""
NuExtract3 Pilot — Author/Affiliation-Extraktion aus PDF-Header-Bildern.

Pro PDF:
  1. PDF Seite 0 → PNG (via pypdfium2)
  2. NuExtract3 mit Schema-Template → JSON {authors, affiliations, author_affiliations}
  3. Speichern als bin/.cache/nuextract_evidence.jsonl
"""
import io
import json
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

DEVICE = "mps" if torch.backends.mps.is_available() else "cpu"
print(f"Device: {DEVICE}", file=sys.stderr)

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


def pdf_page0_image(pdf_path: Path, dpi: int = 130, max_side: int = 1800) -> Image.Image | None:
    try:
        pdf = pdfium.PdfDocument(str(pdf_path))
        if len(pdf) == 0:
            return None
        page = pdf[0]
        bitmap = page.render(scale=dpi / 72)
        img = bitmap.to_pil().convert("RGB")
        pdf.close()
        # Resize wenn zu groß (vermeidet MPS-Buffer-Limit)
        w, h = img.size
        if max(w, h) > max_side:
            scale = max_side / max(w, h)
            img = img.resize((int(w * scale), int(h * scale)), Image.LANCZOS)
        return img
    except Exception as e:
        print(f"  pdfium-Fehler {pdf_path.name}: {e}", file=sys.stderr)
        return None


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("paper_ids", nargs="*",
                    help="paper_ids für Pilot, oder leer für Default-Sample")
    args = ap.parse_args()

    OUT.parent.mkdir(parents=True, exist_ok=True)
    if not args.paper_ids:
        args.paper_ids = ["118-b1", "113-p31", "109-a19", "124-p12", "110-b25"]
    print(f"Lade NuExtract3 ({DEVICE})…", file=sys.stderr)
    t_load = time.time()
    model_path = "numind/NuExtract3"
    model = AutoModelForImageTextToText.from_pretrained(
        model_path,
        dtype=torch.bfloat16,
        device_map=DEVICE,
        trust_remote_code=True,
    ).eval()
    processor = AutoProcessor.from_pretrained(model_path, trust_remote_code=True)
    print(f"Modell geladen in {time.time() - t_load:.1f}s", file=sys.stderr)

    import sqlite3
    con = sqlite3.connect(str(ROOT / "public" / "data" / "proceedings.db"))
    con.row_factory = sqlite3.Row

    with OUT.open("a") as f:
        for pid in args.paper_ids:
            row = con.execute(
                "SELECT tagung_nummer, pdf_dateiname FROM papers WHERE id=?",
                (pid,)).fetchone()
            if not row:
                continue
            pdf_path = PDF_DIR / str(row["tagung_nummer"]) / row["pdf_dateiname"]
            if not pdf_path.exists():
                continue
            img = pdf_page0_image(pdf_path)
            if img is None:
                continue
            t0 = time.time()
            messages = [{
                "role": "user",
                "content": [
                    {"type": "image", "image": img},
                ],
            }]
            try:
                inputs = processor.apply_chat_template(
                    messages, add_generation_prompt=True,
                    tokenize=True, return_dict=True, return_tensors="pt",
                    template=json.dumps(TEMPLATE, indent=4),
                    enable_thinking=False,
                ).to(model.device)
                with torch.inference_mode():
                    outputs = model.generate(
                        **inputs, max_new_tokens=1024,
                        do_sample=False,
                    )
                generated = outputs[:, inputs["input_ids"].shape[1]:]
                result_txt = processor.batch_decode(
                    generated, skip_special_tokens=True,
                )[0].strip()
                dt = time.time() - t0
                print(f"  {pid}: {dt:.1f}s", file=sys.stderr)
                print(f"    {result_txt[:200]}", file=sys.stderr)
                try:
                    parsed = json.loads(result_txt)
                except json.JSONDecodeError:
                    parsed = {"raw": result_txt}
                f.write(json.dumps({"paper_id": pid, "elapsed_s": round(dt, 2),
                                     "result": parsed}, ensure_ascii=False) + "\n")
                f.flush()
            except Exception as e:
                print(f"  {pid}: ERROR {e}", file=sys.stderr)
                f.write(json.dumps({"paper_id": pid, "error": str(e)}) + "\n")


if __name__ == "__main__":
    main()
