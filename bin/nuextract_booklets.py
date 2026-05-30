#!/usr/bin/env python3
"""
NuExtract3 ueber alle DGaO-Tagungsband-Booklets.

Pro Seite jedes Booklets ein NuExtract-Call mit Single-Paper-Schema.
Output: bin/.cache/nuextract_booklets.jsonl (eine Zeile pro [booklet, page]).

Resume-faehig: ueberspringt Seiten die schon im jsonl stehen.

Run:
  bin/.venv/bin/python3 bin/nuextract_booklets.py
  bin/.venv/bin/python3 bin/nuextract_booklets.py --resume    # default
  bin/.venv/bin/python3 bin/nuextract_booklets.py --booklet DGaO_2024
"""
import argparse
import json
import re
import sys
import time
from pathlib import Path

import pypdfium2 as pdfium
import torch
from PIL import Image
from transformers import AutoModelForImageTextToText, AutoProcessor

ROOT = Path(__file__).resolve().parents[1]
BOOKLET_DIR = ROOT / "booklets"
OUT = ROOT / "bin" / ".cache" / "nuextract_booklets.jsonl"

DEVICE = "mps" if torch.backends.mps.is_available() else "cpu"

TEMPLATE = {
    "papers": [
        {
            "code": "string",
            "title": "string",
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
            "abstract": "string",
            "email": "string",
        }
    ]
}


def render_page(pdf, page_idx: int, dpi: int = 130, max_side: int = 1800):
    try:
        page = pdf[page_idx]
        bitmap = page.render(scale=dpi / 72)
        img = bitmap.to_pil().convert("RGB")
        if max(img.size) > max_side:
            ratio = max_side / max(img.size)
            new_size = (int(img.size[0] * ratio), int(img.size[1] * ratio))
            img = img.resize(new_size, Image.LANCZOS)
        return img
    except Exception as e:
        print(f"  ! render fail page {page_idx}: {e}", file=sys.stderr)
        return None


def load_done(out_path: Path) -> set:
    """Liest bestehende jsonl, returnt set of (booklet_name, page_idx)."""
    done = set()
    if not out_path.exists():
        return done
    with out_path.open() as f:
        for line in f:
            try:
                d = json.loads(line)
                done.add((d["booklet"], int(d["page"])))
            except Exception:
                continue
    return done


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--no-resume", action="store_true",
                    help="Auch schon verarbeitete Seiten neu (Default: resume).")
    ap.add_argument("--booklet", type=str, default=None,
                    help="Nur ein bestimmtes Booklet (Stem ohne .pdf), z.B. DGaO_2024")
    ap.add_argument("--max-pages", type=int, default=0,
                    help="Limit per Booklet (Tests).")
    args = ap.parse_args()

    OUT.parent.mkdir(exist_ok=True, parents=True)
    done = set() if args.no_resume else load_done(OUT)
    print(f"Resume: {len(done)} Seiten bereits verarbeitet.")

    print("Loading NuExtract3 model...", flush=True)
    t0 = time.time()
    model_path = "numind/NuExtract3"
    model = AutoModelForImageTextToText.from_pretrained(
        model_path, dtype=torch.bfloat16, device_map=DEVICE,
        trust_remote_code=True,
    ).eval()
    processor = AutoProcessor.from_pretrained(model_path, trust_remote_code=True)
    print(f"  loaded in {time.time()-t0:.1f}s on {DEVICE}", flush=True)

    booklets = sorted(BOOKLET_DIR.glob("*.pdf"))
    if args.booklet:
        booklets = [p for p in booklets if p.stem == args.booklet]
        if not booklets:
            print(f"ERROR: booklet '{args.booklet}' nicht gefunden", file=sys.stderr)
            return

    total_pages = 0
    for bp in booklets:
        try:
            pdf = pdfium.PdfDocument(str(bp))
            n = len(pdf)
            if args.max_pages:
                n = min(n, args.max_pages)
            total_pages += n
        except Exception as e:
            print(f"ERROR pdf {bp.name}: {e}", file=sys.stderr)
    print(f"Plan: {len(booklets)} Booklets, {total_pages} Seiten total.")

    template_json = json.dumps(TEMPLATE, indent=4)

    out_f = OUT.open("a")
    processed_now = 0
    skipped = 0
    for bp in booklets:
        booklet_name = bp.stem
        try:
            pdf = pdfium.PdfDocument(str(bp))
        except Exception as e:
            print(f"!! cannot open {bp.name}: {e}", file=sys.stderr)
            continue
        n = len(pdf)
        if args.max_pages:
            n = min(n, args.max_pages)
        print(f"\n=== {booklet_name} ({n} pages) ===", flush=True)

        for page_idx in range(n):
            key = (booklet_name, page_idx)
            if key in done:
                skipped += 1
                continue

            img = render_page(pdf, page_idx)
            if img is None:
                out_f.write(json.dumps({"booklet": booklet_name, "page": page_idx, "error": "render_fail"}) + "\n")
                out_f.flush()
                continue

            t1 = time.time()
            try:
                messages = [{
                    "role": "user",
                    "content": [{"type": "image", "image": img}],
                }]
                inputs = processor.apply_chat_template(
                    messages, add_generation_prompt=True,
                    tokenize=True, return_dict=True, return_tensors="pt",
                    template=template_json,
                    enable_thinking=False,
                ).to(model.device)
                with torch.inference_mode():
                    out_ids = model.generate(**inputs, max_new_tokens=1500, do_sample=False)
                generated = out_ids[:, inputs["input_ids"].shape[1]:]
                txt = processor.batch_decode(generated, skip_special_tokens=True)[0].strip()
                # NuExtract liefert pure JSON
                try:
                    result = json.loads(txt)
                except json.JSONDecodeError:
                    # Try to extract JSON substring
                    m = re.search(r"\{.*\}", txt, flags=re.DOTALL)
                    result = json.loads(m.group(0)) if m else {"raw": txt}
            except Exception as e:
                result = {"error": str(e)}

            elapsed = time.time() - t1
            rec = {
                "booklet": booklet_name,
                "page": page_idx,
                "elapsed_s": round(elapsed, 2),
                "result": result,
            }
            out_f.write(json.dumps(rec, ensure_ascii=False) + "\n")
            out_f.flush()
            processed_now += 1

            n_papers = len(result.get("papers", [])) if isinstance(result, dict) else 0
            print(f"  p{page_idx:03d}  {elapsed:5.1f}s  papers={n_papers}", flush=True)

    out_f.close()
    print(f"\nDone. processed={processed_now}, skipped(resume)={skipped}")


if __name__ == "__main__":
    main()
