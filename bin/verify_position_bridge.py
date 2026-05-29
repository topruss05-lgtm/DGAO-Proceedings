#!/usr/bin/env python3
"""
Verifiziert die Positions-Brücke: stimmt die Reihenfolge in papers.autoren_text
mit paper_autoren.position überein? Das ist die fundamentale Annahme für das
PDF-Parsing (PDF-Position N → autoren_text Position N → paper_autoren autor_id).

Vorgehen pro Paper:
  - autoren_text in Tokens splitten (Komma, Semikolon)
  - paper_autoren in Position-Reihenfolge laden
  - prüfen: Nachname-Match an jeder Position (normalisiert)
  - Mismatches kategorisieren
"""
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def split_authors(text: str) -> list:
    # Trennzeichen: Komma, Semikolon, " and ", " und "
    text = re.sub(r"\s+and\s+|\s+und\s+", ",", text)
    parts = re.split(r"[,;]", text)
    return [p.strip() for p in parts if p.strip()]


def last_word(token: str) -> str:
    """letzter Bestandteil (sehr grob; behandelt 'van Eldik', 'Di Sarcina')."""
    # Marker (*) und Whitespace säubern
    t = re.sub(r"[*\d†‡§¶]+", " ", token).strip()
    t = re.sub(r"\s+", " ", t)
    words = t.split()
    if not words:
        return ""
    # mehrwortige Nachnamen: nimm letzte 2 wenn vorletztes klein (van, de, di, von)
    if len(words) >= 2 and words[-2].lower() in {"van", "von", "de", "di", "del", "della", "den", "der", "le", "la", "el", "al"}:
        return f"{words[-2]} {words[-1]}"
    return words[-1]


def main():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    rows = con.execute("""
        SELECT p.id, p.autoren_text,
               (SELECT COUNT(*) FROM paper_autoren pa WHERE pa.paper_id=p.id) AS n_pa
        FROM papers p
        WHERE p.autoren_text IS NOT NULL AND trim(p.autoren_text) != ''
    """).fetchall()

    stats = {"perfekt": 0, "anzahl_weicht_ab": 0, "reihenfolge_falsch": 0,
             "teilweise_falsch": 0}
    beispiele_mismatch = []
    beispiele_count = []
    pa_cache = {}

    for r in rows:
        tokens = split_authors(r["autoren_text"])
        pa = con.execute("""
            SELECT pa.position, a.nachname
            FROM paper_autoren pa JOIN autoren a ON a.id=pa.autor_id
            WHERE pa.paper_id=? ORDER BY pa.position
        """, (r["id"],)).fetchall()
        if len(tokens) != len(pa):
            stats["anzahl_weicht_ab"] += 1
            if len(beispiele_count) < 5:
                beispiele_count.append({
                    "paper": r["id"], "txt": r["autoren_text"][:80],
                    "n_text": len(tokens), "n_pa": len(pa),
                })
            continue
        # Reihenfolge prüfen
        mismatches = []
        for i, (tok, dbrow) in enumerate(zip(tokens, pa)):
            tok_last = norm(last_word(tok))
            db_last = norm(dbrow["nachname"])
            if not tok_last or not db_last:
                continue
            # ist der DB-Nachname als Suffix im Token enthalten?
            if not (tok_last.endswith(db_last) or db_last.endswith(tok_last)):
                mismatches.append((i + 1, tok, dbrow["nachname"]))
        if not mismatches:
            stats["perfekt"] += 1
        elif len(mismatches) == len(pa):
            stats["reihenfolge_falsch"] += 1
        else:
            stats["teilweise_falsch"] += 1
            if len(beispiele_mismatch) < 5:
                beispiele_mismatch.append({
                    "paper": r["id"], "txt": r["autoren_text"][:80],
                    "mismatches": mismatches,
                })

    total = sum(stats.values())
    print(f"Geprüft: {total} Papers mit autoren_text")
    for k, v in stats.items():
        print(f"  {k:24} {v:5}  ({100*v/total:5.1f}%)")
    print()
    print("--- Beispiele Anzahl-Mismatch ---")
    for b in beispiele_count:
        print(f"  {b['paper']:10}  txt={b['n_text']} pa={b['n_pa']}  | {b['txt']}")
    print("--- Beispiele Teil-Mismatch (Reihenfolge) ---")
    for b in beispiele_mismatch:
        print(f"  {b['paper']:10}  | {b['txt']}")
        for pos, tok, db in b["mismatches"]:
            print(f"      pos {pos}: '{tok}' vs DB-Nachname '{db}'")


if __name__ == "__main__":
    main()
