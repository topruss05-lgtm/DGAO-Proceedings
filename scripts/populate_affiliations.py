#!/usr/bin/env python3
"""
Einmaliges Script: Liest vortraege.xlsx und befüllt autoren.affiliation
in der SQLite-Datenbank anhand der Sterne-Zuordnung.

Ausführung:
    python3 scripts/populate_affiliations.py

Sterne-Format im Excel:
    autor:         "Th. Kinder*, G. Sparrer**, K.-D. Salewski***"
    organisation:  "* TEM Messtechnik GmbH; ** PTB Braunschweig; *** Uni Greifswald"

    Ohne Sterne: alle Autoren teilen dieselbe Organisation.
"""

import os
import re
import sqlite3
import sys

import openpyxl

# --- Paths ---
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)
EXCEL_PATH = os.path.join(PROJECT_DIR, "vortraege.xlsx")
DB_PATH = os.path.join(PROJECT_DIR, "public", "data", "proceedings.db")


def fix_encoding(s: str) -> str:
    """Fix double-encoded UTF-8 (UTF-8 bytes misinterpreted as CP1252, twice)."""
    if not s:
        return s
    try:
        fixed = s.encode("cp1252").decode("utf-8")
        fixed = fixed.encode("cp1252").decode("utf-8")
        return fixed
    except (UnicodeEncodeError, UnicodeDecodeError):
        try:
            return s.encode("cp1252").decode("utf-8")
        except (UnicodeEncodeError, UnicodeDecodeError):
            return s


def parse_author_stars(autoren_text: str) -> list[dict]:
    """
    Parse author names and their star suffixes.
    Returns list of {'name': str, 'stars': str}.
    """
    # Normalize newlines in author list, then split on commas
    normalized = re.sub(r"\s*\n\s*", " ", autoren_text)
    authors = [a.strip() for a in normalized.split(",")]
    result = []
    for author in authors:
        if not author:
            continue
        m = re.match(r"^(.+?)(\*+)\s*$", author)
        if m:
            result.append({"name": m.group(1).strip(), "stars": m.group(2)})
        else:
            result.append({"name": author.strip(), "stars": ""})
    return result


def parse_org_stars(org_text: str) -> dict:
    """
    Parse organisation field into star-mapped affiliations.
    Returns dict: star_string -> affiliation.
    '' key = base affiliation (no stars).

    Handles both ';' and ',' as separators before star markers.
    """
    org_text = org_text.strip()
    if not org_text:
        return {}

    # Split on semicolons, commas, or newlines that precede a star marker
    parts = re.split(r"\s*[;,\n]\s*(?=\*)", org_text)

    star_map = {}
    for part in parts:
        part = part.strip()
        if not part:
            continue
        m = re.match(r"^(\*+)\s*(.+)$", part, re.DOTALL)
        if m:
            star_map[m.group(1)] = m.group(2).strip()
        else:
            # Base affiliation (no stars)
            star_map[""] = part
    return star_map


def resolve_affiliations(autoren_text: str, org_text: str) -> list[dict]:
    """
    Resolve each author to their affiliation using star mapping.
    Returns list of {'name': str, 'affiliation': str}.
    """
    authors = parse_author_stars(autoren_text)
    star_map = parse_org_stars(org_text)

    has_stars = any(a["stars"] for a in authors)

    result = []
    for author in authors:
        if has_stars:
            affiliation = star_map.get(author["stars"], star_map.get("", ""))
        else:
            # No stars in authors: use parsed base affiliation
            if "" in star_map:
                affiliation = star_map[""]
            elif star_map:
                # Only star-prefixed orgs exist, use the first one
                affiliation = list(star_map.values())[0]
            else:
                affiliation = org_text.strip()
        result.append({"name": author["name"], "affiliation": affiliation})
    return result


INITIAL_PATTERN = re.compile(
    r"^[A-ZÄÖÜ][a-zäöü]{0,2}\.(-[A-ZÄÖÜ][a-zäöü]{0,2}\.)?$"
)
PARTICLES = {
    "von", "van", "de", "del", "della", "dalla", "di", "du",
    "le", "la", "den", "der", "ten", "ter", "zu",
}


def parse_display_name(display_name: str) -> tuple[str, str]:
    """
    Parse author display name into (vorname, nachname).
    Mirrors the PHP parseAuthorDisplayName() function.
    """
    name = display_name.strip()
    if not name:
        return ("", "")

    # Normalize: "A.Schiebelbein" -> "A. Schiebelbein"
    name = re.sub(r"\.([A-ZÄÖÜ])", r". \1", name)

    parts = name.split()
    if len(parts) == 1:
        return ("", parts[0])

    # Collect leading initials
    initials = []
    rest = list(parts)
    while rest and INITIAL_PATTERN.match(rest[0]):
        initials.append(rest.pop(0))

    if initials and rest:
        return (" ".join(initials), " ".join(rest))

    # Full name heuristic: look for nobiliary particle
    for i in range(1, len(parts)):
        if parts[i].lower() in PARTICLES:
            return (" ".join(parts[:i]), " ".join(parts[i:]))

    # Fallback: last word = nachname
    nachname = parts[-1]
    vorname = " ".join(parts[:-1])
    return (vorname, nachname)


def main():
    if not os.path.exists(EXCEL_PATH):
        print(f"Error: Excel file not found: {EXCEL_PATH}")
        sys.exit(1)
    if not os.path.exists(DB_PATH):
        print(f"Error: Database not found: {DB_PATH}")
        sys.exit(1)

    print(f"Reading {EXCEL_PATH}...")
    wb = openpyxl.load_workbook(EXCEL_PATH, read_only=True)
    ws = wb["vortraege"]

    # Column indices (0-based): autor=6, organisation=7, tagungsnummer=1
    # Build author -> affiliation mapping, sorted by tagungsnummer (latest wins)
    rows_data = []
    for row in ws.iter_rows(min_row=2, values_only=True):
        tagung_nr = int(row[1]) if row[1] else 0
        autor_raw = str(row[6]) if row[6] else ""
        org_raw = str(row[7]) if row[7] else ""
        if not autor_raw:
            continue
        rows_data.append((tagung_nr, autor_raw, org_raw))
    wb.close()

    # Sort by tagungsnummer ascending so latest tagung overwrites earlier ones
    rows_data.sort(key=lambda x: x[0])

    print(f"Processing {len(rows_data)} rows...")

    # Build mapping: (vorname, nachname) -> affiliation
    author_affiliation = {}
    for tagung_nr, autor_raw, org_raw in rows_data:
        autor_fixed = fix_encoding(autor_raw)
        org_fixed = fix_encoding(org_raw)

        resolved = resolve_affiliations(autor_fixed, org_fixed)
        for entry in resolved:
            if not entry["affiliation"]:
                continue
            vorname, nachname = parse_display_name(entry["name"])
            if not nachname:
                continue
            author_affiliation[(vorname, nachname)] = entry["affiliation"]

    print(f"Resolved {len(author_affiliation)} unique author-affiliation pairs.")

    # Update database
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA journal_mode = WAL")

    # Ensure affiliation column exists
    cols = [row[1] for row in conn.execute("PRAGMA table_info(autoren)").fetchall()]
    if "affiliation" not in cols:
        conn.execute("ALTER TABLE autoren ADD COLUMN affiliation TEXT NOT NULL DEFAULT ''")

    # Load all authors from DB
    db_authors = conn.execute("SELECT id, vorname, nachname FROM autoren").fetchall()
    print(f"Database has {len(db_authors)} authors.")

    updated = 0
    not_found = 0
    skipped = 0

    for (vorname, nachname), affiliation in author_affiliation.items():
        # Find matching author in DB
        matches = [
            a for a in db_authors
            if a[1] == vorname and a[2] == nachname
        ]
        if matches:
            conn.execute(
                "UPDATE autoren SET affiliation = ? WHERE id = ?",
                (affiliation, matches[0][0]),
            )
            updated += 1
        else:
            not_found += 1

    conn.commit()
    conn.close()

    print(f"\nDone!")
    print(f"  Updated:   {updated}")
    print(f"  Not found: {not_found}")
    print(f"  Total in mapping: {len(author_affiliation)}")


if __name__ == "__main__":
    main()
