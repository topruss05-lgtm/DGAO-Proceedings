#!/usr/bin/env python3
"""
Sammelt für gegebene Autoren die vollständige OpenAlex-Evidenz (deterministisch),
die ein LLM-Richter (Opus/Sonnet) für das finale Urteil braucht.

Pro Autor: alle Nachname-Kandidaten mit Vorname-Varianten, allen Affiliations (mit Jahren),
ORCID, works_count, raw_affiliation_strings des Top-Kandidaten.
Ausgabe als JSON — kein eigenes Urteil, nur Evidenz.
"""
import json
import sqlite3
import sys
import unicodedata
import re
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from openalex_test import api_get, norm, raw_affiliation_strings  # noqa: E402

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"


def evidence_for(init: str, nn: str, db_aff: str, papers_titles: list) -> dict:
    data = api_get("authors", {"filter": f"display_name.search:{nn}", "per-page": 25})
    nn_norm = norm(nn)
    init_letter = norm(init.replace(".", "").split()[0])[:1] if init else ""
    cands = []
    for c in data.get("results", []):
        if nn_norm not in norm(c.get("display_name", "") + " " +
                               " ".join(c.get("display_name_alternatives", []))):
            continue
        # Nur Initial-konsistente Kandidaten (Vorname-Variante beginnt mit Initiale)
        if init_letter:
            allnames = norm(c.get("display_name", "") + " " +
                            " ".join(c.get("display_name_alternatives", [])))
            firsts = {w[:1] for w in allnames.split() if w and w != nn_norm}
            if init_letter not in firsts:
                continue
        affs = []
        for aff in c.get("affiliations", []):
            inst = aff.get("institution", {})
            yrs = aff.get("years", [])
            affs.append({"name": inst.get("display_name"),
                         "country": inst.get("country_code"),
                         "years": f"{min(yrs)}-{max(yrs)}" if yrs else None})
        cands.append({
            "display_name": c.get("display_name"),
            "name_variants": [v for v in c.get("display_name_alternatives", [])
                              if any(ch.isalpha() for ch in v)][:8],
            "works_count": c.get("works_count"),
            "orcid": c.get("orcid"),
            "affiliations": affs,
            "id": c.get("id"),
        })
    # raw_affiliation_strings für Initial-konsistente Top-3 (nach works)
    cands.sort(key=lambda x: x["works_count"] or 0, reverse=True)
    for c in cands[:3]:
        c["raw_affiliation_strings"] = raw_affiliation_strings(c["id"], nn)[:6]
    return {
        "dgao": {"initial": init, "nachname": nn, "db_affiliation": db_aff,
                 "paper_titles": papers_titles[:5]},
        "openalex_candidates": cands[:8],
    }


def main():
    ids = [int(x) for x in sys.argv[1:]] if len(sys.argv) > 1 else []
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    placeholders = ",".join("?" * len(ids)) if ids else None
    q = """
        SELECT a.id, a.vorname AS init, a.nachname,
          (SELECT i.name_de FROM autor_institutionen ai JOIN institutionen i ON i.id=ai.institut_id
           WHERE ai.autor_id=a.id LIMIT 1) AS aff
        FROM autoren a
    """
    if ids:
        q += f" WHERE a.id IN ({placeholders})"
    rows = con.execute(q, ids).fetchall()
    out = []
    for r in rows:
        titles = [t["titel"] for t in con.execute(
            "SELECT p.titel FROM papers p JOIN paper_autoren pa ON pa.paper_id=p.id "
            "WHERE pa.autor_id=? LIMIT 5", (r["id"],)).fetchall()]
        ev = evidence_for(r["init"], r["nachname"], r["aff"], titles)
        ev["autor_id"] = r["id"]
        out.append(ev)
    print(json.dumps(out, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
