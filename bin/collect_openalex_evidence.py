#!/usr/bin/env python3
"""
OpenAlex-Evidenz pro Autor sammeln:
  - Vorname-Kandidat (display_name + display_name_alternatives)
  - ORCID
  - alle Namens-Varianten (Aliase)
  - Affiliations-Liste mit Jahren

Strategie:
  1) Affil-gefiltert: /authors?filter=display_name.search:<NN>,affiliations.institution.id:<id>
     (über resolve_institution_ids aus openalex_test.py)
  2) Fallback: /authors?filter=display_name.search:<NN>  -> Token-Match-Score

Output: bin/.cache/openalex_evidence.jsonl
"""
import json
import sqlite3
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from openalex_test import (api_get, norm, resolve_institution_ids,
                           all_oa_institutions, score_against_strings,
                           extract_full_first_name)  # noqa

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
OUT = ROOT / "bin" / ".cache" / "openalex_evidence.jsonl"


def resolve_author(con, autor_id: int) -> dict:
    row = con.execute("""
        SELECT a.id, a.vorname AS init, a.nachname, a.orcid_id,
               (SELECT i.name_de FROM autor_institutionen ai JOIN institutionen i ON i.id=ai.institut_id
                WHERE ai.autor_id=a.id LIMIT 1) AS aff,
               (SELECT COUNT(*) FROM paper_autoren pa WHERE pa.autor_id=a.id) AS n_papers
        FROM autoren a WHERE a.id = ?
    """, (autor_id,)).fetchone()
    if not row:
        return {"autor_id": autor_id, "error": "not found"}

    init = row["init"]
    nn = row["nachname"]
    aff = row["aff"]
    init_letter = norm((init or "").replace(".", "").split()[0])[:1] if init else ""
    nn_norm = norm(nn)

    out = {
        "autor_id": autor_id, "db_vorname": init, "db_nachname": nn,
        "db_aff": aff, "n_papers": row["n_papers"],
        "candidates": [],
    }

    # Stufe 1: Affil-gefiltert
    iid_list = resolve_institution_ids(aff) if aff else []
    cand_by_id = {}
    for iid in iid_list:
        data = api_get("authors", {
            "filter": f"display_name.search:{nn},affiliations.institution.id:{iid}",
            "per-page": 5,
        })
        for c in data.get("results", []):
            if nn_norm not in norm(c.get("display_name", "")):
                continue
            cand_by_id[c["id"]] = (c, 1.0, "affil-filter")

    # Stufe 2: Fallback Nachname-Suche
    data = api_get("authors", {"filter": f"display_name.search:{nn}", "per-page": 12})
    for c in data.get("results", []):
        if c["id"] in cand_by_id:
            continue
        dn_all = norm(c.get("display_name", "") + " " +
                      " ".join(c.get("display_name_alternatives") or []))
        if nn_norm not in dn_all:
            continue
        # Initial-Konsistenz wenn db_vorname nur Initiale
        fname = extract_full_first_name(c, init or "")
        if init_letter and fname and norm(fname)[0] != init_letter:
            continue
        s = score_against_strings(aff, all_oa_institutions(c)) if aff else 0.0
        cand_by_id[c["id"]] = (c, s, "nachname-token")

    # Top-N (max 4) sortiert nach Score+works
    cands = list(cand_by_id.values())
    cands.sort(key=lambda x: (x[1], x[0].get("works_count", 0)), reverse=True)
    for c, score, src in cands[:4]:
        fname = extract_full_first_name(c, init or "")
        affs = []
        for a in c.get("affiliations") or []:
            inst = a.get("institution") or {}
            yrs = a.get("years") or []
            affs.append({
                "name": inst.get("display_name"),
                "ror": inst.get("ror"),
                "country": inst.get("country_code"),
                "years": [min(yrs), max(yrs)] if yrs else None,
            })
        out["candidates"].append({
            "openalex_id": c["id"],
            "display_name": c.get("display_name"),
            "alternatives": [v for v in (c.get("display_name_alternatives") or [])
                             if any(x.isalpha() for x in v)][:10],
            "vorname_candidate": fname,
            "orcid": c.get("orcid"),
            "works_count": c.get("works_count"),
            "aff_score": round(score, 3),
            "affiliations": affs,
            "source": src,
        })
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--ids", type=str, default="")
    args = ap.parse_args()

    OUT.parent.mkdir(exist_ok=True)
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    done = set()
    if args.resume and OUT.exists():
        with OUT.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["autor_id"])
                except Exception:
                    pass
        print(f"  resume: {len(done)} bereits erledigt", file=sys.stderr)

    if args.ids:
        ids = [int(x) for x in args.ids.split(",") if x.strip()]
    else:
        ids = [r[0] for r in con.execute(
            "SELECT id FROM autoren ORDER BY id"
        )]
    if args.limit:
        ids = ids[:args.limit]
    ids = [i for i in ids if i not in done]
    print(f"  zu verarbeiten: {len(ids)}", file=sys.stderr)

    written = 0
    t0 = time.time()
    with OUT.open("a") as f:
        for i, aid in enumerate(ids, 1):
            try:
                res = resolve_author(con, aid)
            except Exception as e:
                res = {"autor_id": aid, "error": f"crash: {e}"}
            f.write(json.dumps(res, ensure_ascii=False) + "\n")
            f.flush()
            written += 1
            if i % 50 == 0:
                el = time.time() - t0
                rate = i / el
                eta = (len(ids) - i) / rate
                print(f"  ...{i}/{len(ids)} ({rate:.1f}/s, ETA {eta/60:.0f}min)", file=sys.stderr)
    print(f"Fertig: {written} Autoren, Output: {OUT}", file=sys.stderr)


if __name__ == "__main__":
    main()
