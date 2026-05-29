#!/usr/bin/env python3
"""
Parst fitz_headers.jsonl -> strukturierte Author/Affil-Zuordnung.

Pro Paper:
  - Author-Tokens (mit Markern * / ** / 1, 2)
  - Affil-Blöcke (mit Marker-Prefix)
  - Marker-basierte Zuordnung Autor -> Affil
  - Match per Nachname zu paper_autoren

Output: bin/.cache/fitz_parsed.jsonl
"""
import json
import re
import sqlite3
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
IN = ROOT / "bin" / ".cache" / "fitz_headers.jsonl"
OUT = ROOT / "bin" / ".cache" / "fitz_parsed.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


# Tokens am Wortende: * ** *** †‡§¶# oder 1, 2, 3 (Ziffer direkt nach Buchstabe)
MARKER_END_RE = re.compile(
    r"(\*{1,4}|†+|‡+|§+|¶+|#+|\d{1,2}(?:,\d{1,2})*)$"
)
MARKER_START_RE = re.compile(
    r"^\s*(\*{1,4}|†+|‡+|§+|¶+|#+|\d{1,2}|\(\d+\))\s*"
)

EMAIL_RE = re.compile(r"\S+@\S+\.\S+")
MAILTO_LINE_RE = re.compile(r"^\s*(mailto:|email:|e-mail:|kontakt:)", re.IGNORECASE)


def reconstruct_lines_from_spans(spans: list, page_width: float = 600) -> list[str]:
    """Aus spans (mit bbox) Zeilen rekonstruieren (gleiche y-Position = gleiche Zeile)."""
    if not spans:
        return []
    # Sort by y, then x
    spans = sorted(spans, key=lambda s: (round(s["bbox"][1], 0), s["bbox"][0]))
    lines = []
    current_y = None
    current = []
    for s in spans:
        y = round(s["bbox"][1], 0)
        if current_y is None or abs(y - current_y) <= 2:
            current.append(s["text"])
            current_y = y if current_y is None else current_y
        else:
            lines.append(" ".join(current))
            current = [s["text"]]
            current_y = y
    if current:
        lines.append(" ".join(current))
    # Trim whitespace, drop empty
    return [re.sub(r"\s+", " ", ln).strip() for ln in lines if ln.strip()]


def parse_marker(tok: str) -> tuple[str, list[str]]:
    """'Häusler*' -> ('Häusler', ['*']). Ziffer-Marker auch."""
    tok = tok.strip().rstrip(",").strip()
    markers = []
    m = MARKER_END_RE.search(tok)
    if m:
        raw = m.group(1)
        if "," in raw:
            markers = [x.strip() for x in raw.split(",")]
        else:
            markers = [raw]
        tok = tok[: m.start()].rstrip(",").strip()
    return tok, markers


def split_author_line(line: str) -> list[tuple[str, list[str]]]:
    """'A. Meyer*, B. Schmidt** ***, C. Klee*' -> [(name, markers), ...]"""
    line = re.sub(r"\bDr\.\s*|^\s*Prof\.\s*|^\s*PD\s+", "", line)
    parts = [p.strip() for p in re.split(r",\s*(?=[A-ZÄÖÜ])|;\s*", line) if p.strip()]
    out = []
    for p in parts:
        if EMAIL_RE.search(p):
            continue
        name, markers = parse_marker(p)
        # zusätzliche Marker mit Space getrennt: "Stollenwerk* ***"
        name_parts = name.split()
        extra_markers = []
        while name_parts and re.fullmatch(r"\*{1,4}|\d{1,2}|†|‡|§|¶|#", name_parts[-1]):
            extra_markers.insert(0, name_parts.pop())
        name = " ".join(name_parts)
        markers = extra_markers + markers
        if name:
            out.append((name, markers))
    return out


def parse_affil_line(line: str) -> tuple[list[str], str]:
    """'*Institute of Optics, ...' -> (markers, text)."""
    text = line.strip()
    m = MARKER_START_RE.match(text)
    markers = []
    if m:
        raw = m.group(1).strip()
        # Ziffer-Marker
        if raw.isdigit():
            markers = [raw]
        elif raw.startswith("(") and raw.endswith(")"):
            markers = [raw[1:-1]]
        else:
            markers = [raw]
        text = text[m.end():].strip()
    return markers, text


def parse_header(header_raw: str, spans: list = None) -> dict:
    """Header-Block -> {authors:[(name,[markers])], affils:[(markers,text)], email}"""
    out = {"authors": [], "affils": [], "email": None, "raw_first": "", "method": "raw"}
    if not header_raw and spans:
        lines = reconstruct_lines_from_spans(spans)
        out["method"] = "spans"
    else:
        lines = [ln.strip() for ln in (header_raw or "").split("\n") if ln.strip()]
        # Wenn fitz die Zeilen extrem fragmentiert hat (Ein-Wort-pro-Zeile), rekonstruiere via spans
        if spans and lines and sum(1 for ln in lines if len(ln) <= 3) >= len(lines) / 2:
            lines = reconstruct_lines_from_spans(spans)
            out["method"] = "spans-fallback"
    if not lines:
        return out
    # Email
    for ln in lines:
        m = EMAIL_RE.search(ln)
        if m:
            out["email"] = m.group(0)
            break
    # Autoren-Block: erste 1-3 Zeilen die keine Inst-Stichwörter haben und Komma + Großbuchstabe
    author_lines = []
    affil_start_idx = 0
    INST_RE = re.compile(
        r"\b(Institut|Institute|University|Universit|Fakult|Faculty|Hochschule|"
        r"Lehrstuhl|Department|Laboratory|Fraunhofer|Max[- ]Planck|Helmholtz|"
        r"Leibniz|GmbH|CNRS|CNR|National|Center|Centre|Zentrum)\b", re.IGNORECASE
    )
    for i, ln in enumerate(lines):
        if MAILTO_LINE_RE.match(ln) or EMAIL_RE.search(ln):
            affil_start_idx = i
            break
        # Affil-Zeile: beginnt mit Marker oder enthält Inst-Indikator
        m_aff = MARKER_START_RE.match(ln)
        if m_aff and INST_RE.search(ln):
            affil_start_idx = i
            break
        if INST_RE.search(ln) and not re.search(r",\s*[A-ZÄÖÜ]\.", ln):
            # Wenn die Zeile Inst-Wörter hat ohne Komma+Großinitial → Affil
            affil_start_idx = i
            break
        author_lines.append(ln)
        # Heuristik: max 2 Autoren-Zeilen
        if i >= 1:
            affil_start_idx = i + 1
    out["raw_first"] = " ".join(author_lines) if author_lines else ""
    # Autoren splitten
    if author_lines:
        full = " ".join(author_lines)
        out["authors"] = [(n, m) for n, m in split_author_line(full)]
    # Affils
    if affil_start_idx > 0 and affil_start_idx < len(lines):
        for ln in lines[affil_start_idx:]:
            if MAILTO_LINE_RE.match(ln) or EMAIL_RE.search(ln):
                break
            markers, text = parse_affil_line(ln)
            if text and len(text) > 5:
                out["affils"].append({"markers": markers, "text": text})
    return out


def match_to_db_authors(parsed_authors: list, db_authors: list) -> list[dict]:
    """Pro parsed-Author den passenden DB-autor via Nachname matchen."""
    used = set()
    out = []
    for name, markers in parsed_authors:
        # letztes Wort als Nachname-Indikator
        words = [w for w in re.split(r"\s+", name) if w and not re.match(r"^[A-ZÄÖÜ]\.", w)]
        if not words:
            words = name.split()
        # 2-Word Nachname (van Eldik, Di Sarcina)?
        candidate_nachnames = []
        if len(words) >= 2:
            candidate_nachnames.append(" ".join(words[-2:]))
        if words:
            candidate_nachnames.append(words[-1])
        matched = None
        for cn in candidate_nachnames:
            cn_norm = norm(cn)
            if not cn_norm:
                continue
            for r in db_authors:
                if r["autor_id"] in used:
                    continue
                if norm(r["nachname"]) == cn_norm or cn_norm.endswith(norm(r["nachname"])):
                    matched = r["autor_id"]
                    used.add(matched)
                    break
            if matched:
                break
        out.append({
            "name_pdf": name, "markers": markers,
            "autor_id": matched,
        })
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--ids", type=str, default="")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    ids_filter = set(args.ids.split(",")) if args.ids else None

    if not IN.exists():
        print(f"ERROR: {IN} nicht gefunden — erst bin/fitz_extract_headers.py laufen lassen",
              file=sys.stderr)
        return

    stats = {"total": 0, "ok": 0, "no_header": 0, "no_authors": 0, "matched_all": 0}
    with IN.open() as fin, OUT.open("w") as fout:
        for line in fin:
            try:
                d = json.loads(line)
            except Exception:
                continue
            pid = d.get("paper_id")
            if ids_filter and pid not in ids_filter:
                continue
            stats["total"] += 1
            if d.get("error") or not d.get("header_raw"):
                if not d.get("spans"):
                    stats["no_header"] += 1
                    fout.write(json.dumps({"paper_id": pid, "ok": False,
                                            "error": d.get("error", "no header")},
                                          ensure_ascii=False) + "\n")
                    continue
            parsed = parse_header(d.get("header_raw") or "", d.get("spans") or [])
            if not parsed["authors"]:
                stats["no_authors"] += 1
                fout.write(json.dumps({"paper_id": pid, "ok": False,
                                        "error": "no authors parsed",
                                        "parsed": parsed}, ensure_ascii=False) + "\n")
                continue
            stats["ok"] += 1
            # Match zu paper_autoren
            db_aut = con.execute("""
                SELECT pa.position, a.id AS autor_id, a.nachname
                FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
                WHERE pa.paper_id = ? ORDER BY pa.position
            """, (pid,)).fetchall()
            matched = match_to_db_authors(parsed["authors"],
                                          [{"autor_id": r["autor_id"], "nachname": r["nachname"]}
                                           for r in db_aut])
            n_matched = sum(1 for m in matched if m["autor_id"])
            if n_matched == len(db_aut) and n_matched > 0:
                stats["matched_all"] += 1
            out = {
                "paper_id": pid, "ok": True,
                "method": parsed["method"],
                "title": d.get("title"),
                "email": parsed["email"],
                "authors": matched,
                "affils": parsed["affils"],
                "db_aut_count": len(db_aut),
                "n_matched": n_matched,
            }
            fout.write(json.dumps(out, ensure_ascii=False) + "\n")

    print(f"\n--- Parse Stats ---")
    for k, v in stats.items():
        print(f"  {k:20} {v}")


if __name__ == "__main__":
    main()
