#!/usr/bin/env python3
"""
Strang B v4: paper_autor_institutionen aus NuExtract3-Daten neu fuellen.

Quelle: 'nuextract' (hoechste Qualitaet, schlaegt alle anderen).
Pro Paper:
  - Lese nuextract_evidence.jsonl
  - Pro Autor: matche auf paper_autoren.autor_id via Nachname (Positions-Bruecke)
  - Pro Affil: matche/erstelle institut_id via verbesserte Logik
  - Schreibe (paper_id, autor_id, institut_id) mit quelle='nuextract'

Konservativ: nur sichere Matches, keine Fuzzy-Falsch-Matches.
Replace existing 'fitz_marker','unscharf','pdf' Eintraege.
"""
import json
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
IN = ROOT / "bin" / ".cache" / "nuextract_evidence.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


COUNTRY_END = re.compile(
    r",\s*(Germany|Deutschland|Italy|Italia|France|USA|United States|"
    r"United Kingdom|UK|Switzerland|Schweiz|Austria|Spain|Netherlands|"
    r"Belgium|Denmark|Sweden|Poland|Czech Republic|China|Japan|Korea)\s*\.?\s*$",
    re.IGNORECASE,
)


def clean_affil(text: str) -> str:
    t = (text or "").strip().strip(",;/")
    for _ in range(3):
        prev = t
        t = COUNTRY_END.sub("", t).strip(",; ")
        if t == prev:
            break
    return t


def get_alias_lookup(con):
    lookup = {}
    for r in con.execute("SELECT institut_id, alias_norm FROM institut_aliase"):
        lookup[r[1]] = r[0]
    for r in con.execute("SELECT id, name_de FROM institutionen"):
        n = norm(r[1])
        if n and n not in lookup:
            lookup[n] = r[0]
    return lookup


def get_inst_name(con, iid):
    r = con.execute("SELECT name_de FROM institutionen WHERE id=?", (iid,)).fetchone()
    return (r[0] if r else "") or ""


def best_inst_match(con, text: str, lookup: dict) -> int | None:
    """Bei mehrdeutigem Alias-Match: nimm Kandidat mit höchstem Hauptnamen-Overlap."""
    if not text or len(text) < 5:
        return None
    cleaned = clean_affil(text)
    raw_norm = norm(text)
    clean_norm = norm(cleaned)
    candidates = []
    if clean_norm:
        for r in con.execute("SELECT DISTINCT institut_id FROM institut_aliase WHERE alias_norm=?",
                              (clean_norm,)):
            candidates.append(r[0])
        for r in con.execute("SELECT id FROM institutionen WHERE LOWER(REPLACE(name_de,'ß','ss'))=?",
                              (cleaned.lower().replace("ß", "ss"),)):
            if r[0] not in candidates:
                candidates.append(r[0])
    if not candidates and raw_norm:
        for r in con.execute("SELECT DISTINCT institut_id FROM institut_aliase WHERE alias_norm=?",
                              (raw_norm,)):
            candidates.append(r[0])
    if not candidates:
        return None
    if len(candidates) == 1:
        return candidates[0]
    # Best-Match via Hauptname-Overlap
    STOP = {"institut", "institute", "of", "for", "and", "the", "fur", "fuer",
            "und", "der", "die", "das", "in", "an", "am"}
    text_tokens = set(norm(text).split()) - STOP
    best, best_score = candidates[0], -1.0
    for iid in candidates:
        main = get_inst_name(con, iid)
        main_tokens = set(norm(main).split()) - STOP
        if not main_tokens:
            continue
        ov = len(text_tokens & main_tokens) / max(len(text_tokens), 1)
        if ov > best_score:
            best, best_score = iid, ov
    return best


def match_or_create(con, text: str, lookup: dict) -> int | None:
    """Match oder neuanlegen. Verbesserter Substring-Match."""
    if not text or len(text.strip()) < 5:
        return None
    iid = best_inst_match(con, text, lookup)
    if iid:
        # raw als Alias eintragen
        rn = norm(text)
        if rn and rn not in lookup:
            try:
                con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (iid, text.strip(), rn))
                lookup[rn] = iid
            except sqlite3.IntegrityError:
                pass
        return iid
    # Substring-Fuzzy: text als Substring eines bestehenden Hauptnamens?
    cn = norm(clean_affil(text))
    if cn and len(cn) >= 12:
        for r in con.execute("SELECT id, name_de FROM institutionen"):
            mn = norm(r[1] or "")
            if not mn or len(mn) < 12:
                continue
            if cn in mn or mn in cn:
                STOP = {"institut", "institute", "of", "for", "and", "the", "fur",
                        "fuer", "und", "der", "die", "das"}
                ct = set(cn.split()) - STOP
                mt = set(mn.split()) - STOP
                if ct and mt and len(ct & mt) / min(len(ct), len(mt)) >= 0.7:
                    try:
                        con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                                    (r[0], text.strip(), norm(text)))
                        lookup[norm(text)] = r[0]
                    except sqlite3.IntegrityError:
                        pass
                    return r[0]
    # Neu anlegen
    main_name = clean_affil(text) if len(clean_affil(text)) >= 5 else text.strip()
    cur = con.execute("INSERT INTO institutionen (name_de) VALUES (?)", (main_name,))
    iid = cur.lastrowid
    if cn:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, main_name, cn))
            lookup[cn] = iid
        except sqlite3.IntegrityError:
            pass
    rn = norm(text)
    if rn != cn:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, text.strip(), rn))
            lookup[rn] = iid
        except sqlite3.IntegrityError:
            pass
    return iid


def match_author(parsed_name: str, db_authors: list, used: set) -> int | None:
    """Match parsed_name (z.B. 'Gerd Häusler') zu DB-autor_id via Nachname."""
    parsed_name = re.sub(r"\*+|\d+(?:,\d+)*", "", parsed_name).strip()
    words = parsed_name.split()
    if not words:
        return None
    # Multi-word Nachnamen (van Eldik, Di Sarcina)
    for take in (1, 2):
        if len(words) < take:
            continue
        cn = norm(" ".join(words[-take:]))
        for r in db_authors:
            if r["autor_id"] in used:
                continue
            db_nn = norm(r["nachname"])
            if cn == db_nn or cn.endswith(db_nn) or db_nn.endswith(cn):
                used.add(r["autor_id"])
                return r["autor_id"]
    return None


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--replace-existing", action="store_true")
    ap.add_argument("--min-papers", type=int, default=0)
    args = ap.parse_args()

    if not IN.exists():
        print(f"ERROR: {IN} fehlt", file=sys.stderr)
        return

    con = sqlite3.connect(str(DB))
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    lookup = get_alias_lookup(con)

    stats = {"papers": 0, "with_data": 0, "assignments": 0,
             "no_match_autor": 0, "no_match_affil": 0,
             "inst_neu": 0}
    inst_before = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]

    with IN.open() as f:
        for line in f:
            try:
                d = json.loads(line)
            except Exception:
                continue
            stats["papers"] += 1
            pid = d.get("paper_id")
            res = d.get("result")
            if not res or not isinstance(res, dict):
                continue
            authors = res.get("authors") or []
            affils = res.get("affiliations") or []
            if not authors or not affils:
                continue
            stats["with_data"] += 1

            # DB-Autoren laden
            db_aut = con.execute("""
                SELECT pa.position, a.id AS autor_id, a.nachname
                FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
                WHERE pa.paper_id = ? ORDER BY pa.position
            """, (pid,)).fetchall()
            if not db_aut:
                continue

            # Replace bestehende non-nuextract Einträge
            if args.apply and args.replace_existing:
                con.execute("""
                    DELETE FROM paper_autor_institutionen
                    WHERE paper_id = ? AND quelle != 'nuextract'
                """, (pid,))

            # Marker -> institut_ids
            marker_to_iids: dict[str, list[int]] = {}
            all_iids: list[int] = []
            for af in affils:
                aff_text = (af.get("name") or "").strip()
                if not aff_text or len(aff_text) < 5:
                    continue
                iid = match_or_create(con, aff_text, lookup)
                if not iid:
                    stats["no_match_affil"] += 1
                    continue
                marker = (af.get("marker") or "").strip()
                marker_to_iids.setdefault(marker, []).append(iid)
                all_iids.append(iid)

            # Pro Autor: Marker-Lookup oder Default
            used_db = set()
            for au in authors:
                name = (au.get("name") or "").strip()
                if not name:
                    continue
                aid = match_author(name, db_aut, used_db)
                if not aid:
                    stats["no_match_autor"] += 1
                    continue
                au_markers = au.get("affiliation_markers") or []
                target_iids: list[int] = []
                if au_markers:
                    for m in au_markers:
                        m = (m or "").strip()
                        if m in marker_to_iids:
                            target_iids.extend(marker_to_iids[m])
                        elif m:
                            # Marker ist evtl. Affil-Name selbst (NuExtract macht das gelegentlich)
                            iid = match_or_create(con, m, lookup)
                            if iid:
                                target_iids.append(iid)
                else:
                    # Keine Marker → alle Affils (single-affil-Fall, NuExtract erkennt das)
                    target_iids = all_iids[:]
                target_iids = list(dict.fromkeys(target_iids))
                if target_iids and args.apply:
                    for iid in target_iids:
                        try:
                            con.execute("""
                                INSERT OR IGNORE INTO paper_autor_institutionen
                                  (paper_id, autor_id, institut_id, quelle)
                                VALUES (?,?,?,'nuextract')
                            """, (pid, aid, iid))
                        except sqlite3.IntegrityError:
                            pass
                stats["assignments"] += len(target_iids)

    if args.apply:
        con.commit()
    inst_after = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]
    stats["inst_neu"] = inst_after - inst_before
    print(f"\n--- Strang B v4 {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:24}  {v}")


if __name__ == "__main__":
    main()
