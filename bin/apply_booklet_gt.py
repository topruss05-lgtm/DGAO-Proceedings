#!/usr/bin/env python3
"""
Apply Booklet-GT (bin/.cache/gt/DGaO_YYYY.json) auf DB als hoechste Quelle.

Schreibt in paper_autor_institutionen mit quelle='booklet_nuextract'.
Diese Quelle hat absolute Prioritaet vor allen anderen — sie ueberschreibt
nuextract/manuell/anker/single_affil/orcid/openalex/legacy_ai usw.

Workflow:
  1. Pro GT-Paper: matche auf existing paper via code + tagung_nummer
  2. Wenn Paper existiert: match GT-Autoren auf paper_autoren via Nachname
     - matched: setze ist_hauptautor falls position==1 (Booklet-Reihenfolge)
     - GT-Affiliations: schreibe pai mit quelle='booklet_nuextract'
  3. Wenn Paper fehlt: report (separate Liste)
  4. Wenn Autor fehlt: optional auto-add (autoren-Tabelle)

Run:
  bin/.venv/bin/python3 bin/apply_booklet_gt.py            # dry-run
  bin/.venv/bin/python3 bin/apply_booklet_gt.py --apply    # write to DB
"""
import argparse
import json
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GT_DIR = ROOT / "bin" / ".cache" / "gt"
DB = ROOT / "public" / "data" / "proceedings.db"


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


def best_inst_match(con, text: str, lookup: dict) -> int | None:
    if not text or len(text) < 5:
        return None
    cleaned = clean_affil(text)
    raw_norm = norm(text)
    clean_norm = norm(cleaned)
    candidates = []
    if clean_norm:
        for r in con.execute("SELECT DISTINCT institut_id FROM institut_aliase WHERE alias_norm=?", (clean_norm,)):
            candidates.append(r[0])
    if not candidates and raw_norm:
        for r in con.execute("SELECT DISTINCT institut_id FROM institut_aliase WHERE alias_norm=?", (raw_norm,)):
            candidates.append(r[0])
    if not candidates:
        return None
    if len(candidates) == 1:
        return candidates[0]
    STOP = {"institut", "institute", "of", "for", "and", "the", "fur", "fuer", "und", "der", "die", "das"}
    text_tokens = set(norm(text).split()) - STOP
    best, best_score = candidates[0], -1.0
    for iid in candidates:
        r = con.execute("SELECT name_de FROM institutionen WHERE id=?", (iid,)).fetchone()
        main = (r[0] if r else "") or ""
        main_tokens = set(norm(main).split()) - STOP
        if not main_tokens:
            continue
        ov = len(text_tokens & main_tokens) / max(len(text_tokens), 1)
        if ov > best_score:
            best, best_score = iid, ov
    return best


def match_or_create_institut(con, text: str, lookup: dict) -> int | None:
    if not text or len(text.strip()) < 5:
        return None
    iid = best_inst_match(con, text, lookup)
    if iid:
        rn = norm(text)
        if rn and rn not in lookup:
            try:
                con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (iid, text.strip(), rn))
                lookup[rn] = iid
            except sqlite3.IntegrityError:
                pass
        return iid
    # Neu anlegen
    main_name = clean_affil(text) if len(clean_affil(text)) >= 5 else text.strip()
    cur = con.execute("INSERT INTO institutionen (name_de) VALUES (?)", (main_name,))
    iid = cur.lastrowid
    cn = norm(clean_affil(text))
    if cn:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, main_name, cn))
            lookup[cn] = iid
        except sqlite3.IntegrityError:
            pass
    return iid


def match_autor_in_paper(con, gt_name: str, paper_id: str) -> int | None:
    """Match GT-Autor (z.B. 'Gerd Häusler') auf existierenden paper_autoren-Eintrag via Nachname."""
    name = re.sub(r"\*+|\d+(?:,\d+)*|\(.*?\)", "", gt_name or "").strip()
    words = name.split()
    if not words:
        return None
    db_autoren = con.execute("""
        SELECT a.id, a.nachname FROM autoren a
        JOIN paper_autoren pa ON pa.autor_id = a.id
        WHERE pa.paper_id = ?
    """, (paper_id,)).fetchall()
    for take in (1, 2):
        if len(words) < take:
            continue
        cn = norm(" ".join(words[-take:]))
        for r in db_autoren:
            db_nn = norm(r[1])
            if cn == db_nn or cn.endswith(db_nn) or db_nn.endswith(cn):
                return r[0]
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--gt-dir", default=str(GT_DIR))
    args = ap.parse_args()

    gt_dir = Path(args.gt_dir)
    if not gt_dir.exists():
        print(f"ERROR: GT-Dir nicht da: {gt_dir}", file=sys.stderr)
        return

    con = sqlite3.connect(str(DB))
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    lookup = get_alias_lookup(con)

    stats = {
        "gt_papers": 0,
        "gt_with_code": 0,
        "matched_db_paper": 0,
        "missing_db_paper": 0,
        "matched_autor": 0,
        "missing_autor": 0,
        "affil_assignments": 0,
        "inst_neu": 0,
    }
    inst_before = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]
    missing_papers = []

    # Range-Code expandieren: "A19-A24" -> ["A19","A20","A21","A22","A23","A24"]
    range_re = re.compile(r"^([A-Z])(\d+)\s*[-–]\s*([A-Z])?(\d+)$")

    def expand_codes(raw: str) -> list[str]:
        raw = (raw or "").strip().upper()
        if not raw:
            return []
        m = range_re.match(raw)
        if m:
            l1, n1, l2, n2 = m.group(1), int(m.group(2)), m.group(3) or m.group(1), int(m.group(4))
            if l1 == l2 and n1 <= n2 and n2 - n1 < 30:
                return [f"{l1}{i}" for i in range(n1, n2 + 1)]
        return [raw]

    for gt_file in sorted(gt_dir.glob("*.json")):
        gt = json.loads(gt_file.read_text(encoding="utf-8"))
        tagung_nummer = gt.get("tagung_nummer_match")
        if not tagung_nummer:
            print(f"  skip {gt_file.name}: keine tagung_nummer_match", file=sys.stderr)
            continue
        print(f"=== {gt_file.name} (tagung {tagung_nummer}) ===")

        for gt_paper in gt.get("papers", []):
            stats["gt_papers"] += 1
            raw_code = (gt_paper.get("code") or "").strip().upper()
            if not raw_code:
                continue
            stats["gt_with_code"] += 1

            # Range-Codes wie "A19-A24" expandieren. Bei >1 Code = Session-Übersicht
            # ohne sinnvolle Affil-Daten → nur match-count, kein Apply.
            codes = expand_codes(raw_code)
            if len(codes) > 1:
                for code in codes:
                    paper_id = f"{tagung_nummer}-{code.lower()}"
                    row = con.execute("SELECT id FROM papers WHERE id=?", (paper_id,)).fetchone()
                    if row:
                        stats["matched_db_paper"] += 1
                    else:
                        stats["missing_db_paper"] += 1
                        missing_papers.append(paper_id)
                continue

            # Single-Code: normaler Apply-Flow
            code = codes[0]
            paper_id = f"{tagung_nummer}-{code.lower()}"
            row = con.execute("SELECT id FROM papers WHERE id=?", (paper_id,)).fetchone()
            if not row:
                stats["missing_db_paper"] += 1
                missing_papers.append(paper_id)
                continue
            stats["matched_db_paper"] += 1

            # Affiliations: marker -> institut_id
            marker_to_iid = {}
            for af in gt_paper.get("affiliations") or []:
                text = (af.get("name") or "").strip()
                marker = (af.get("marker") or "").strip()
                if not text or len(text) < 5:
                    continue
                iid = match_or_create_institut(con, text, lookup) if args.apply else best_inst_match(con, text, lookup)
                if not iid:
                    continue
                if marker:
                    marker_to_iid[marker] = iid

            # Pro Autor: matche + zuordnen
            for au in gt_paper.get("authors") or []:
                gt_name = (au.get("name") or "").strip()
                if not gt_name:
                    continue
                aid = match_autor_in_paper(con, gt_name, paper_id)
                if not aid:
                    stats["missing_autor"] += 1
                    continue
                stats["matched_autor"] += 1

                au_markers = au.get("affiliation_markers") or []
                target_iids = []
                if au_markers:
                    for m in au_markers:
                        m = (m or "").strip()
                        if m in marker_to_iid:
                            target_iids.append(marker_to_iid[m])
                elif marker_to_iid:
                    # Keine Marker beim Autor → alle Booklet-Affils
                    target_iids = list(marker_to_iid.values())

                target_iids = list(dict.fromkeys(target_iids))
                if target_iids and args.apply:
                    # Delete existing assignments for (paper, autor), insert booklet_nuextract als hoechste Quelle
                    con.execute("""
                        DELETE FROM paper_autor_institutionen
                        WHERE paper_id=? AND autor_id=?
                    """, (paper_id, aid))
                    for iid in target_iids:
                        try:
                            con.execute("""
                                INSERT OR IGNORE INTO paper_autor_institutionen
                                  (paper_id, autor_id, institut_id, quelle)
                                VALUES (?, ?, ?, 'booklet_nuextract')
                            """, (paper_id, aid, iid))
                        except sqlite3.IntegrityError:
                            pass
                stats["affil_assignments"] += len(target_iids)

    if args.apply:
        con.commit()
    inst_after = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]
    stats["inst_neu"] = inst_after - inst_before

    print(f"\n--- {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:25}  {v}")

    if missing_papers:
        print(f"\nFehlende Paper-IDs in DB ({len(missing_papers)}):")
        for p in missing_papers[:20]:
            print(f"  {p}")
        if len(missing_papers) > 20:
            print(f"  ... +{len(missing_papers) - 20} weitere")


if __name__ == "__main__":
    main()
