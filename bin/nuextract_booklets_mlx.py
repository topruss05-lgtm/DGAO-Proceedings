#!/usr/bin/env python3
"""
NuExtract3 ueber alle Booklet-Pages — MLX-Variante (Apple Silicon native).

4-6x schneller als PyTorch+MPS dank mxfp8-quantisiertem Modell.

Output: bin/.cache/nuextract_booklets.jsonl (gleiches Format wie nuextract_booklets.py).
Resume-faehig.
"""
import argparse
import json
import re
import sys
import time
from pathlib import Path

import pypdfium2 as pdfium
import fitz
from PIL import Image
from mlx_vlm import load, generate

ROOT = Path(__file__).resolve().parents[1]
BOOKLET_DIR = ROOT / "booklets"
OUT = ROOT / "bin" / ".cache" / "nuextract_booklets.jsonl"

MODEL_PATH = "numind/NuExtract3-mlx-mxfp8"

TEMPLATE = {
    "papers": [
        {
            "code": "string",
            "title": "string",
            "authors": [
                {"name": "string", "affiliation_markers": ["string"]}
            ],
            "affiliations": [
                {"marker": "string", "name": "string"}
            ],
            "abstract": "string",
            "email": "string",
        }
    ]
}

PAPER_CODE_RE = re.compile(r"(?m)^\s*([AHBCPS])\s*[\-:.\s]?\s*(\d{1,3})\b")
PAPER_CONTENT_HINT = re.compile(r"(?i)\b(abstract|affil|kontakt|institut|university|universit[ae]t|fraunhofer)\b")


def page_likely_has_paper(text: str) -> bool:
    if not text or len(text) < 100:
        return False
    if PAPER_CODE_RE.search(text):
        return True
    return len(PAPER_CONTENT_HINT.findall(text)) >= 2 and len(text) > 200


def render_page(pdf, page_idx: int, dpi: int = 110, max_side: int = 1600):
    try:
        page = pdf[page_idx]
        img = page.render(scale=dpi / 72).to_pil().convert("RGB")
        if max(img.size) > max_side:
            r = max_side / max(img.size)
            img = img.resize((int(img.size[0] * r), int(img.size[1] * r)), Image.LANCZOS)
        return img
    except Exception as e:
        print(f"  ! render fail page {page_idx}: {e}", file=sys.stderr)
        return None


def load_done(out_path: Path) -> set:
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
    ap.add_argument("--no-resume", action="store_true")
    ap.add_argument("--booklet", type=str, default=None)
    ap.add_argument("--max-pages", type=int, default=0)
    args = ap.parse_args()

    OUT.parent.mkdir(exist_ok=True, parents=True)
    done = set() if args.no_resume else load_done(OUT)
    print(f"Resume: {len(done)} Seiten bereits verarbeitet.", flush=True)

    print(f"Loading {MODEL_PATH} ...", flush=True)
    t0 = time.time()
    model, processor = load(MODEL_PATH, trust_remote_code=True)
    print(f"  loaded in {time.time()-t0:.1f}s", flush=True)

    booklets = sorted(BOOKLET_DIR.glob("*.pdf"))
    if args.booklet:
        booklets = [p for p in booklets if p.stem == args.booklet]

    total = 0
    for bp in booklets:
        try:
            pdf = pdfium.PdfDocument(str(bp))
            n = len(pdf)
            total += min(n, args.max_pages) if args.max_pages else n
        except Exception:
            pass
    print(f"Plan: {len(booklets)} Booklets, {total} Seiten.", flush=True)

    template_json = json.dumps(TEMPLATE, indent=4)

    out_f = OUT.open("a")
    processed = skipped_resume = skipped_signal = 0

    for bp in booklets:
        booklet_name = bp.stem
        try:
            pdf = pdfium.PdfDocument(str(bp))
            fdoc = fitz.open(str(bp))
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
                skipped_resume += 1
                continue

            try:
                ftext = fdoc[page_idx].get_text("text") if page_idx < len(fdoc) else ""
            except Exception:
                ftext = ""
            if not page_likely_has_paper(ftext):
                out_f.write(json.dumps({"booklet": booklet_name, "page": page_idx,
                                        "skipped": "no_paper_signal",
                                        "result": {"papers": []}}, ensure_ascii=False) + "\n")
                out_f.flush()
                skipped_signal += 1
                continue

            img = render_page(pdf, page_idx)
            if img is None:
                out_f.write(json.dumps({"booklet": booklet_name, "page": page_idx, "error": "render_fail"}) + "\n")
                out_f.flush()
                continue

            t1 = time.time()
            try:
                msgs = [{"role": "user", "content": [{"type": "image", "image": img}]}]
                prompt = processor.apply_chat_template(
                    msgs, add_generation_prompt=True, tokenize=False,
                    template=template_json, enable_thinking=False,
                )
                out = generate(model, processor, prompt, [img], max_tokens=1024, verbose=False, temp=0.0)
                txt = out if isinstance(out, str) else (out.text if hasattr(out, "text") else str(out))
                # Trailing <|im_end|>\n entfernen
                txt = txt.rstrip().rstrip("<|im_end|>").rstrip()
                try:
                    result = json.loads(txt)
                except json.JSONDecodeError:
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
            processed += 1
            n_papers = len(result.get("papers", [])) if isinstance(result, dict) else 0
            print(f"  p{page_idx:03d}  {elapsed:5.1f}s  papers={n_papers}", flush=True)

    out_f.close()
    print(f"\nDone. processed={processed}, skip_resume={skipped_resume}, skip_signal={skipped_signal}")


if __name__ == "__main__":
    main()
