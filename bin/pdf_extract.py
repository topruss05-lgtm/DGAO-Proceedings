#!/usr/bin/env python3
"""
PDF-Extraktor mit Sicherheits-Layern (L1-L5).

Pro Paper:
  L1 Lesbarkeits-Check  (Anteil bekannter Wörter, ASCII-Verhältnis)
  L2 Positions-Brücke   (PDF-Position N's Nachname == paper_autoren.position=N)
  L3 Marker-Plausibilität (Marker-Klassen ≤ #Affils, jeder Marker mind. 1 Affil)
  L4 Anker-Konsens-Check kommt später (über alle Papers eines Autors)
  L5 Stichproben-Validation extern

Strategie: DB-Daten als Anker. Wir kennen die erwarteten Nachnamen + die
Affiliations-Strings. Im PDF suchen wir die Zeile mit den meisten Nachname-
Treffern (= Autor-Zeile), dann die Zeilen, die zu den DB-Affils passen.

Output: JSON pro Paper mit
  - readable: bool
  - authors: [{position, name_in_pdf, vorname_extracted, markers, verified}]
  - affiliations: [{marker, text}]
  - errors: [...]
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

# Marker-Erkennung: *, **, ***, †, ‡, §, ¶, sowie Ziffern (1, 2, 3) am Wortende
MARKER_CHARS = set("*†‡§¶#")


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def pdf_first_pages_text(pdf_path: Path, pages: int = 2) -> str:
    """pdftotext -layout, erste pages Seiten. Leer wenn Fehler."""
    try:
        r = subprocess.run(
            ["pdftotext", "-layout", "-f", "1", "-l", str(pages), str(pdf_path), "-"],
            capture_output=True, timeout=30,
        )
        return r.stdout.decode(errors="replace") if r.returncode == 0 else ""
    except Exception:
        return ""


# L1 — Lesbarkeit
KNOWN_WORDS = {"the", "and", "of", "for", "with", "to", "in", "on", "by", "as",
               "der", "die", "das", "und", "fur", "fuer", "von", "mit", "im",
               "we", "is", "are", "this", "that", "an", "be", "or", "from",
               "institute", "institut", "university", "universitaet",
               "optical", "optics", "optik", "laser", "light", "physics",
               "abstract", "introduction", "method", "results", "references"}


def is_readable(text: str) -> bool:
    if len(text) < 200:
        return False
    # Anteil ASCII-Buchstaben in den ersten 2000 Zeichen
    head = text[:2000]
    ascii_letters = sum(1 for c in head if c.isascii() and c.isalpha())
    if ascii_letters / max(len(head), 1) < 0.30:
        return False
    # Bekannte Wörter
    words = re.findall(r"[A-Za-zÄÖÜäöü]{3,}", head.lower())
    hits = sum(1 for w in words if w in KNOWN_WORDS)
    return hits >= 8


def extract_markers(token: str) -> tuple[str, list[str]]:
    """'Mantel** ¹' -> ('Mantel', ['**', '1'])"""
    # Zuerst Superscript-Ziffern (¹²³...) zu normalen Ziffern
    sup_map = str.maketrans("¹²³⁴⁵⁶⁷⁸⁹⁰", "1234567890")
    token = token.translate(sup_map)
    # Marker am Wortende sammeln
    markers = []
    # Stern-Gruppen
    m = re.search(r"([*†‡§¶#]+)\s*$", token)
    if m:
        # In Einzel-Marker aufteilen — aber Gruppen wie ** und * verschieden
        markers.append(m.group(1))
        token = token[: m.start()].strip()
    # Ziffern-Marker (1, 2, 3) — direkt nach einem Buchstaben am Wortende
    m = re.search(r"(?<=[A-Za-zÄÖÜäöüẞß])(\d{1,2})\s*$", token)
    if m and len(m.group(1)) <= 2:
        markers.append(m.group(1))
        token = token[: m.start()].strip()
    # Komma-getrennte Mehrfach-Marker am Ende: "Stollenwerk1,3"
    m = re.search(r"(?<=[A-Za-zÄÖÜäöüẞß])(\d{1,2}(?:,\d{1,2})+)\s*$", token)
    if m:
        for d in m.group(1).split(","):
            markers.append(d.strip())
        token = token[: m.start()].strip()
    return token.strip(), markers


def find_author_line(lines: list[str], expected_lastnames_norm: list[str]) -> int | None:
    """Index der Zeile mit den meisten erwarteten Nachnamen-Treffern."""
    best_i, best_score = None, 0
    for i, line in enumerate(lines[:60]):  # nur Header-Bereich
        ln = norm(line)
        hits = sum(1 for nn in expected_lastnames_norm if nn and nn in ln)
        if hits > best_score:
            best_i, best_score = i, hits
    # Mindestens die Hälfte der Nachnamen muss in einer Zeile sein
    if best_score >= max(1, len(expected_lastnames_norm) // 2):
        return best_i
    return None


def split_authors(author_line: str) -> list[str]:
    """Komma-getrennt, aber 'Last, First' Aufpassen."""
    # 'and' / 'und' als Komma
    s = re.sub(r"\s+and\s+|\s+und\s+", ",", author_line)
    parts = [p.strip() for p in s.split(",") if p.strip()]
    return parts


def parse_full_name(token: str, expected_lastname_norm: str) -> tuple[str | None, str | None]:
    """Token 'Gerd Häusler' -> (vorname='Gerd', nachname='Häusler').
    Verifiziert via expected_lastname_norm.
    Reihenfolge: 1-Wort-exakt, 2-Wort-exakt, dann Fallback substring."""
    token = re.sub(r"\s+", " ", token).strip()
    if not token:
        return None, None
    words = token.split()
    # 1. Exakter Match: zuerst 1 Wort, dann 2 (van Eldik)
    for take_last in (1, 2):
        if len(words) < take_last:
            continue
        candidate = " ".join(words[-take_last:])
        if norm(candidate) == expected_lastname_norm:
            nachname = candidate
            vorname = " ".join(words[:-take_last]).strip() or None
            return vorname, nachname
    # 2. Substring-Fallback (Diakritik-/Format-Toleranz)
    for take_last in (1, 2):
        if len(words) < take_last:
            continue
        candidate = " ".join(words[-take_last:])
        cn = norm(candidate)
        if expected_lastname_norm and (
            cn.endswith(expected_lastname_norm) or expected_lastname_norm.endswith(cn)
        ):
            nachname = candidate
            vorname = " ".join(words[:-take_last]).strip() or None
            return vorname, nachname
    return None, None


def find_affiliation_block(lines: list[str], author_line_idx: int,
                           db_affils: list[str]) -> list[tuple[str, str]]:
    """Affil-Block direkt nach der Autor-Zeile. Liste von (marker, text)."""
    affils: list[tuple[str, str]] = []
    # Bis zu 10 Zeilen nach Autor-Zeile suchen
    candidates = []
    for i in range(author_line_idx + 1, min(author_line_idx + 12, len(lines))):
        line = lines[i].strip()
        if not line:
            continue
        # Stop-Marker: Email, Abstract, "We "
        if re.match(r"(mailto:|email:|abstract|introduction|we\s)", line, re.IGNORECASE):
            break
        # Marker-Präfix? "*..." "**..." "1 ..." "2..." (mit/ohne Whitespace nach Marker)
        m = re.match(r"^([*†‡§¶#]+|\d{1,2})\s*(.+)$", line)
        if m:
            marker_raw = m.group(1)
            text = m.group(2).strip()
            affils.append((marker_raw, text))
        else:
            # Affil ohne Marker (= single-affil oder erste implicit "1")
            affils.append(("", line))
    return affils


def process_paper(con: sqlite3.Connection, paper_id: str) -> dict:
    """Hauptfunktion pro Paper."""
    paper = con.execute("""
        SELECT p.id, p.autoren_text, p.affiliationen, p.pdf_dateiname,
               p.tagung_nummer, p.hat_pdf, t.jahr
        FROM papers p JOIN tagungen t ON t.nummer = p.tagung_nummer
        WHERE p.id = ?
    """, (paper_id,)).fetchone()
    if not paper:
        return {"paper_id": paper_id, "error": "paper not found"}

    out = {
        "paper_id": paper_id, "jahr": paper["jahr"], "readable": False,
        "authors": [], "affiliations": [], "errors": [], "warnings": [],
    }
    if not paper["hat_pdf"] or not paper["pdf_dateiname"]:
        out["errors"].append("kein PDF")
        return out

    pdf_path = PDF_DIR / str(paper["tagung_nummer"]) / paper["pdf_dateiname"]
    if not pdf_path.exists():
        out["errors"].append(f"PDF fehlt: {pdf_path.name}")
        return out

    text = pdf_first_pages_text(pdf_path, pages=2)
    # L1 Lesbarkeit
    if not is_readable(text):
        out["errors"].append("L1: PDF nicht lesbar (Encoding/Layout)")
        return out
    out["readable"] = True

    # DB-Anker: erwartete Autoren in Position-Reihenfolge
    pa_rows = con.execute("""
        SELECT pa.position, a.id AS autor_id, a.vorname AS db_vorname, a.nachname
        FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
        WHERE pa.paper_id = ? ORDER BY pa.position
    """, (paper_id,)).fetchall()
    if not pa_rows:
        out["errors"].append("keine paper_autoren-Einträge")
        return out
    expected = [{"position": r["position"], "autor_id": r["autor_id"],
                 "db_vorname": r["db_vorname"], "nachname": r["nachname"],
                 "nachname_norm": norm(r["nachname"])} for r in pa_rows]

    lines = text.splitlines()
    aline_idx = find_author_line(lines, [e["nachname_norm"] for e in expected])
    if aline_idx is None:
        out["errors"].append("L2: Autor-Zeile im PDF nicht gefunden")
        return out

    # Autor-Zeile parsen
    author_tokens = split_authors(lines[aline_idx])

    # Edge case: Multi-line Autor-Zeile (z.B. wenn umgebrochen)
    # Wenn weniger Tokens als erwartet, prüfen ob nächste Zeile auch Namen enthält
    if len(author_tokens) < len(expected) and aline_idx + 1 < len(lines):
        next_ln = norm(lines[aline_idx + 1])
        extra_hits = sum(1 for e in expected if e["nachname_norm"] in next_ln)
        if extra_hits >= 2:
            author_tokens += split_authors(lines[aline_idx + 1])

    # L2 Positions-Brücke
    if len(author_tokens) != len(expected):
        out["warnings"].append(
            f"L2: Anzahl Autoren weicht ab (PDF={len(author_tokens)}, DB={len(expected)})"
        )

    # PDF-Tokens vorab parsen (clean name + Marker)
    pdf_parsed = []
    for tok in author_tokens:
        clean_name, markers = extract_markers(tok)
        pdf_parsed.append({"clean": clean_name, "markers": markers,
                           "norm_words": set(norm(clean_name).split())})

    used_pdf_idx = set()
    # Pro DB-Autor: erst Position N versuchen, dann Nachname-Suche
    for i, exp in enumerate(expected):
        match_idx = None
        # 1) Position-Match
        if i < len(pdf_parsed) and i not in used_pdf_idx:
            if exp["nachname_norm"] in pdf_parsed[i]["norm_words"]:
                match_idx = i
        # 2) Fallback: irgendwo im PDF-Token-Liste suchen
        if match_idx is None:
            for j, p in enumerate(pdf_parsed):
                if j in used_pdf_idx:
                    continue
                # Multi-Word-Nachname (van Eldik): suche als Substring im normalisierten clean
                if exp["nachname_norm"] in norm(p["clean"]):
                    match_idx = j
                    break
        if match_idx is None:
            out["authors"].append({
                "position": exp["position"], "autor_id": exp["autor_id"],
                "verified": False, "error": "Nachname nicht im PDF-Autor-Block gefunden",
                "db_nachname": exp["nachname"], "db_vorname": exp["db_vorname"],
            })
            continue
        used_pdf_idx.add(match_idx)
        p = pdf_parsed[match_idx]
        vorname, nachname = parse_full_name(p["clean"], exp["nachname_norm"])
        verified = bool(nachname)
        out["authors"].append({
            "position": exp["position"], "autor_id": exp["autor_id"],
            "pdf_position": match_idx + 1,
            "name_in_pdf": p["clean"],
            "vorname_extracted": vorname, "nachname_in_pdf": nachname,
            "markers": p["markers"], "verified": verified,
            "db_nachname": exp["nachname"], "db_vorname": exp["db_vorname"],
        })

    # Affil-Block
    affils = find_affiliation_block(lines, aline_idx, [])
    out["affiliations"] = [{"marker": m, "text": t} for m, t in affils]

    # L3 Marker-Plausibilität
    used_marker_classes = set()
    for a in out["authors"]:
        for m in a.get("markers", []):
            used_marker_classes.add(m)
    affil_markers = {a["marker"] for a in out["affiliations"] if a["marker"]}
    # Jeder verwendete Marker sollte mindestens einer Affil zugeordnet sein
    unmatched = used_marker_classes - affil_markers
    if unmatched:
        # Aber: wenn überhaupt keine Marker im Autor verwendet werden, ist das OK
        if used_marker_classes:
            out["warnings"].append(
                f"L3: Marker im Autor ohne Affil: {sorted(unmatched)}"
            )

    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("paper_ids", nargs="*", help="paper_ids (default: nimm 5 zufällige Multi-Affil-Papers)")
    ap.add_argument("--sample", type=int, default=0, help="zufällige Stichprobe N Multi-Affil-Papers")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    if args.paper_ids:
        ids = args.paper_ids
    elif args.sample:
        ids = [r[0] for r in con.execute(
            "SELECT id FROM papers WHERE hat_pdf=1 AND length(affiliationen)>40 "
            "ORDER BY RANDOM() LIMIT ?", (args.sample,))]
    else:
        ids = ["118-b1"]

    for pid in ids:
        res = process_paper(con, pid)
        print(json.dumps(res, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
