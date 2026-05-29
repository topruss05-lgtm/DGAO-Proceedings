#!/usr/bin/env python3
"""
Marker-OCR-Pipeline für DGaO-Papers.

Pro Paper:
  1. PDF -> Marker -> Markdown (Page 0 reicht für Header)
  2. Markdown parsen: Autoren-Zeile + Affil-Zeile(n) extrahieren
  3. Per Positions-Brücke zu paper_autoren matchen
  4. Output: bin/.cache/marker_evidence.jsonl
     (parallel zu pdf_evidence.jsonl, später konsolidiert)
"""
import json
import re
import sqlite3
import subprocess
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
PDF_DIR = ROOT / "public" / "download"
OUT_DIR = ROOT / "bin" / ".cache" / "marker_out"
OUT_JSONL = ROOT / "bin" / ".cache" / "marker_evidence.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def run_marker(pdf_path: Path) -> str | None:
    """Marker auf Page 0 -> Markdown-String."""
    pdf_stem = pdf_path.stem
    out_md = OUT_DIR / pdf_stem / f"{pdf_stem}.md"
    if out_md.exists() and out_md.stat().st_size > 100:
        return out_md.read_text(errors="replace")
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    try:
        r = subprocess.run(
            ["marker_single", str(pdf_path),
             "--output_dir", str(OUT_DIR), "--page_range", "0",
             "--disable_image_extraction"],
            capture_output=True, timeout=180,
        )
        if r.returncode != 0:
            return None
        if out_md.exists():
            return out_md.read_text(errors="replace")
    except Exception as e:
        print(f"  marker-Fehler {pdf_path.name}: {e}", file=sys.stderr)
    return None


# Marker baut Author+Affil typischerweise als:
#   <Title-Heading>
#   <empty>
#   <Autoren-Tokens>* <Affil-Block in italic *...* >
# Beispiel:
#   ## Speckle Reduction I: ...
#
#   Gerd Häusler*, Florian Dötzer*, Klaus Mantel** *Inst. of Optics, ... *
#
# Autoren erkennen wir durch Position direkt nach erstem H1/H2.
TITLE_RE = re.compile(r"^#{1,2}\s+(.+?)$", re.MULTILINE)


AFFIL_KEYWORDS = (
    r"Institute|Institut|Istituto|Universit|University|Fakult|Faculty|Hochschule|"
    r"College|Laboratory|Labor|Department|Dipartiment|Abteilung|Fraunhofer|"
    r"Max[- ]?Planck|Helmholtz|Leibniz|GmbH|Lehrstuhl|Chair|Centre|Center|Zentrum|"
    r"Research|Forschung|National|Federal|Royal|Deutsche|Académie|Academy|École|"
    r"Ecole|Service|Kliniken|Klinik|Hospital|CNRS|CNR\b|INRIA|NASA|ESA|TNO|FBH|IPHT|"
    r"PTB\b|BESSY|DLR\b|Fak\.|Inst\."
)
AFFIL_START_RE = re.compile(r"(\*+)\s*(" + AFFIL_KEYWORDS + r")", re.IGNORECASE)
AFFIL_SPLIT_RE = re.compile(r"\s+(?=\*+(?:" + AFFIL_KEYWORDS + r"))", re.IGNORECASE)


def split_authors_affils(line: str) -> tuple[list[str], list[tuple[str, str]]]:
    """Erste Marker-Zeile -> (author_tokens, affils [(marker, text)])"""
    # Backslash-Escapes von Marker entfernen (\* -> *)
    line = line.replace("\\*", "*").replace("\\\\", "")
    # Auch Italic-Trenner mit Markdown
    line = re.sub(r"<mailto:[^>]+>", "", line).strip()
    m = AFFIL_START_RE.search(line)
    if not m:
        return [], []
    # WICHTIG: KEIN .rstrip("*") -- die letzten Autor-Marker (** etc) bleiben drin
    autor_part = line[: m.start()].rstrip().rstrip(",").rstrip()
    affil_part = line[m.start():].strip().strip("*").strip()
    # Autoren splitten am Komma vor Großbuchstabe
    authors = [a.strip() for a in re.split(r",\s*(?=[A-ZÄÖÜ])", autor_part) if a.strip()]
    # Affil-Block in Stücke splitten (an *Affil-Stichwort oder **Affil-Stichwort)
    aff_chunks = AFFIL_SPLIT_RE.split(affil_part)
    affs: list[tuple[str, str]] = []
    for chunk in aff_chunks:
        chunk = chunk.strip().strip(",;").strip()
        if not chunk:
            continue
        m2 = re.match(r"^(\*+)\s*(.+)$", chunk)
        if m2:
            affs.append((m2.group(1), m2.group(2).strip().rstrip("*").strip()))
        else:
            affs.append(("*", chunk.rstrip("*").strip()))
    if not affs and affil_part:
        affs.append(("*", affil_part.rstrip("*").strip()))
    return authors, affs


def extract_from_md(md_text: str) -> dict:
    """Markdown -> {authors:[...], affils:[(marker,text)]}"""
    # Suche erste Heading-Zeile, dann erste Inhalts-Zeile danach mit Author-Pattern
    lines = md_text.splitlines()
    title_idx = None
    for i, ln in enumerate(lines[:20]):
        if re.match(r"^#{1,2}\s+\S", ln):
            title_idx = i
            break
    if title_idx is None:
        return {"authors": [], "affils": []}
    # Erste nicht-leere Zeile nach Title
    for j in range(title_idx + 1, min(len(lines), title_idx + 15)):
        ln = lines[j].strip()
        if not ln:
            continue
        # Erkennt typischer Autor-Affil-Block: enthält Komma + (Marker oder Inst-Stichwort)
        if "," in ln or "*" in ln:
            authors, affs = split_authors_affils(ln)
            if authors:
                return {"authors": authors, "affils": [{"marker": m, "text": t} for m, t in affs]}
    return {"authors": [], "affils": []}


def process_paper(con, paper_id: str) -> dict:
    row = con.execute("""
        SELECT p.id, p.tagung_nummer, p.pdf_dateiname, p.hat_pdf
        FROM papers p WHERE p.id = ?
    """, (paper_id,)).fetchone()
    out = {"paper_id": paper_id, "ok": False, "authors": [], "affils": [], "error": None}
    if not row or not row["hat_pdf"] or not row["pdf_dateiname"]:
        out["error"] = "kein PDF"
        return out
    pdf_path = PDF_DIR / str(row["tagung_nummer"]) / row["pdf_dateiname"]
    if not pdf_path.exists():
        out["error"] = f"PDF fehlt {pdf_path.name}"
        return out
    md = run_marker(pdf_path)
    if not md:
        out["error"] = "marker failed"
        return out
    parsed = extract_from_md(md)
    out.update(parsed)
    out["ok"] = bool(parsed["authors"])
    # PDF-Autoren mit DB-paper_autoren matchen via Nachname
    db_aut = con.execute("""
        SELECT pa.position, a.id AS autor_id, a.nachname
        FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
        WHERE pa.paper_id = ? ORDER BY pa.position
    """, (paper_id,)).fetchall()
    # Pro PDF-Autor: passenden DB-autor finden
    pdf_aut_enriched = []
    used = set()
    for tok in parsed["authors"]:
        clean = re.sub(r"\*+|\d+(?:,\d+)*\s*$", "", tok).strip(" ,")
        words = clean.split()
        if not words:
            continue
        # nimm letztes Wort als Nachname-Indikator
        nn_norm = norm(words[-1])
        matched = None
        for r in db_aut:
            if r["autor_id"] in used:
                continue
            if norm(r["nachname"]) == nn_norm or nn_norm.endswith(norm(r["nachname"])):
                matched = r["autor_id"]
                used.add(matched)
                break
        # Marker extrahieren
        marker_match = re.search(r"(\*+|\d+(?:,\d+)*)\s*$", tok)
        markers = [marker_match.group(1)] if marker_match else []
        pdf_aut_enriched.append({
            "name_pdf": clean,
            "autor_id": matched,
            "markers": markers,
        })
    out["authors"] = pdf_aut_enriched
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("paper_ids", nargs="*")
    ap.add_argument("--all", action="store_true")
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    OUT_JSONL.parent.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    done = set()
    if args.resume and OUT_JSONL.exists():
        with OUT_JSONL.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["paper_id"])
                except Exception:
                    pass

    if args.paper_ids:
        ids = args.paper_ids
    else:
        rows = con.execute(
            "SELECT id FROM papers WHERE hat_pdf=1 ORDER BY id").fetchall()
        ids = [r[0] for r in rows]
    if args.limit:
        ids = ids[:args.limit]
    ids = [p for p in ids if p not in done]
    print(f"Pending: {len(ids)} Papers", file=sys.stderr)

    import time
    t0 = time.time()
    with OUT_JSONL.open("a") as f:
        for i, pid in enumerate(ids, 1):
            try:
                res = process_paper(con, pid)
            except Exception as e:
                res = {"paper_id": pid, "ok": False, "error": str(e)}
            f.write(json.dumps(res, ensure_ascii=False) + "\n")
            f.flush()
            if i % 25 == 0:
                el = time.time() - t0
                rate = i / el
                eta = (len(ids) - i) / rate / 60
                print(f"  {i}/{len(ids)} ({rate:.2f}/s, ETA {eta:.0f}min)", file=sys.stderr)
    print(f"Fertig: {len(ids)} Papers in {(time.time()-t0)/60:.1f}min", file=sys.stderr)


if __name__ == "__main__":
    main()
