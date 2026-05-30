#!/usr/bin/env python3
"""
Post-Process: nuextract_booklets.jsonl -> Ground-Truth JSON pro Tagung.

Input:  bin/.cache/nuextract_booklets.jsonl
Output: bin/.cache/gt/DGaO_YYYY.json   (eine pro Booklet)
        Schema:
          {
            "booklet": "DGaO_2024",
            "jahr": 2024,
            "tagung_nummer_match": 125,
            "papers": [
              {"page": 30, "code": "...", "title": "...",
               "authors": [{"name":"...","affiliation_markers":[...]}],
               "affiliations": [{"marker":"...","name":"..."}],
               "abstract": "...", "email": "..."}
            ]
          }

Konsolidierung:
  - Mehrfache Pages mit gleichem code → mergen (abstract concat, authors dedup)
  - Pages ohne papers oder ohne meaningful content (title leer + authors leer) -> weg
"""
import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IN = ROOT / "bin" / ".cache" / "nuextract_booklets.jsonl"
OUT_DIR = ROOT / "bin" / ".cache" / "gt"

# Booklet-Jahr -> Tagung-Nummer (105=2004 ... 127=2026)
JAHR_TO_TAGUNG = {2004 + i: 105 + i + 1 for i in range(23)}  # 2004->106, 2005->107,... — aber DB hat 105=2004!
# Korrekte Mapping aus DB
JAHR_TO_TAGUNG = {
    2004: 105, 2005: 106, 2006: 107, 2007: 108, 2008: 109,
    2009: 110, 2010: 111, 2011: 112, 2012: 113, 2013: 114,
    2014: 115, 2015: 116, 2016: 117, 2017: 118, 2018: 119,
    2019: 120, 2020: 121, 2021: 122, 2022: 123, 2023: 124,
    2024: 125, 2025: 126, 2026: 127,
}


def is_meaningful(paper: dict) -> bool:
    """Filter: paper hat Substanz?"""
    title = (paper.get("title") or "").strip()
    authors = paper.get("authors") or []
    abstract = (paper.get("abstract") or "").strip()
    code = (paper.get("code") or "").strip()
    if not title and not authors:
        return False
    return True


def merge_paper(into: dict, more: dict) -> dict:
    """Merge zwei Paper-Records (z.B. wenn ueber 2 Pages verteilt)."""
    if more.get("title") and len(more["title"]) > len(into.get("title") or ""):
        into["title"] = more["title"]
    for k in ("code", "email"):
        if not into.get(k) and more.get(k):
            into[k] = more[k]
    if more.get("abstract"):
        if into.get("abstract") and more["abstract"] not in into["abstract"]:
            into["abstract"] = into["abstract"].rstrip() + " " + more["abstract"]
        else:
            into.setdefault("abstract", more["abstract"])
    # Authors: dedup by name
    seen_names = {(a.get("name") or "").strip().lower() for a in (into.get("authors") or [])}
    for a in more.get("authors") or []:
        nm = (a.get("name") or "").strip().lower()
        if nm and nm not in seen_names:
            into.setdefault("authors", []).append(a)
            seen_names.add(nm)
    # Affiliations: dedup by marker+name
    seen_aff = {((a.get("marker") or "").strip(), (a.get("name") or "").strip().lower()) for a in (into.get("affiliations") or [])}
    for a in more.get("affiliations") or []:
        key = ((a.get("marker") or "").strip(), (a.get("name") or "").strip().lower())
        if key not in seen_aff and (a.get("name") or "").strip():
            into.setdefault("affiliations", []).append(a)
            seen_aff.add(key)
    return into


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", default=str(IN))
    ap.add_argument("--outdir", default=str(OUT_DIR))
    args = ap.parse_args()

    in_path = Path(args.input)
    out_dir = Path(args.outdir)
    out_dir.mkdir(parents=True, exist_ok=True)

    if not in_path.exists():
        print(f"ERROR: {in_path} nicht da", file=sys.stderr)
        return

    # Sammle pro Booklet
    by_booklet: dict[str, list[dict]] = {}
    n_total_lines = 0
    n_pages_with_papers = 0
    with in_path.open() as f:
        for line in f:
            n_total_lines += 1
            try:
                d = json.loads(line)
            except Exception:
                continue
            res = d.get("result") or {}
            if not isinstance(res, dict):
                continue
            papers = res.get("papers") or []
            if not papers:
                continue
            n_pages_with_papers += 1
            booklet = d.get("booklet", "unknown")
            page = int(d.get("page", -1))
            for p in papers:
                if not is_meaningful(p):
                    continue
                p["_page"] = page
                by_booklet.setdefault(booklet, []).append(p)

    print(f"jsonl: {n_total_lines} lines, {n_pages_with_papers} pages with papers")

    # Pro Booklet: konsolidieren via code
    for booklet, papers in sorted(by_booklet.items()):
        jahr = None
        m = re.search(r"DGaO_(\d{4})", booklet)
        if m:
            jahr = int(m.group(1))
        tagung_nummer = JAHR_TO_TAGUNG.get(jahr) if jahr else None

        # Konsolidieren: Papers mit gleichem (nicht-leerem) code mergen
        merged_by_code: dict[str, dict] = {}
        no_code: list[dict] = []
        for p in papers:
            code = (p.get("code") or "").strip().upper()
            if not code:
                no_code.append(p)
                continue
            if code in merged_by_code:
                merge_paper(merged_by_code[code], p)
            else:
                merged_by_code[code] = p

        final_papers = sorted(merged_by_code.values(), key=lambda x: (x.get("_page", 0)))
        # Plus no_code-Papers anhängen (typisch: Keynote/Plenarvorträge ohne Code)
        final_papers.extend(sorted(no_code, key=lambda x: x.get("_page", 0)))

        out = {
            "booklet": booklet,
            "jahr": jahr,
            "tagung_nummer_match": tagung_nummer,
            "n_papers": len(final_papers),
            "papers": final_papers,
        }
        out_path = out_dir / f"{booklet}.json"
        out_path.write_text(json.dumps(out, indent=2, ensure_ascii=False), encoding="utf-8")
        print(f"  {booklet} -> {out_path.name} ({len(final_papers)} papers, jahr={jahr}, tagung={tagung_nummer})")


if __name__ == "__main__":
    main()
