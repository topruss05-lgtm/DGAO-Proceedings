#!/usr/bin/env python3
"""
Strang B v3: paper_autor_institutionen aus fitz_parsed.jsonl neu fuellen.

Strategie:
  - Pro Paper: structured authors (mit markers + autor_id) + affils (mit markers)
  - Pro Autor:
    * Hat Marker -> matching Affils via Marker zuweisen
    * Keine Marker + nur 1 Affil im Paper -> diese Affil
    * Keine Marker + mehrere Affils -> SKIP (unscharf, kein Raten)
  - Affil-String -> match_or_create_institut (mit verbesserter Logik)

Quelle: 'fitz_marker' (hoechste Qualitaet, schlaegt vorherige Eintraege).
Bestehende 'pdf', 'single_affil', 'anker', 'unscharf' werden behalten
WENN kein 'fitz_marker' verfuegbar ist.
"""
import json
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
IN = ROOT / "bin" / ".cache" / "fitz_parsed.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


COUNTRY_END = re.compile(
    r",\s*(Germany|Deutschland|Italy|Italia|France|USA|United States|"
    r"United Kingdom|UK|Switzerland|Schweiz|Austria|Österreich|Spain|"
    r"Netherlands|Belgium|Denmark|Sweden|Norway|Poland|Czech Republic|"
    r"Hungary|Russia|China|Japan|India|Korea|Taiwan|Brazil|Canada|Mexico|"
    r"Israel|Greece|Finland|Portugal|Ireland)\s*\.?\s*$",
    re.IGNORECASE,
)
STREET_PAT = re.compile(
    r",\s*[\w\-]*(?:strasse|straße|str\.|street|st\.|road|lane|avenue|ave|"
    r"boulevard|blvd|platz|allee|weg|gasse|via|piazza|rue|chaussee|"
    r"Albert-Einstein|Bundesallee|Heinz Nixdorf|"
    r"Hennigsdorf|Lippstadt|Shoreham|Göttingen, Germany)\b[^,]*",
    re.IGNORECASE,
)
ZIP_CITY_END = re.compile(
    r",?\s*(D-)?\d{4,6}\s+[\w\-äöüÄÖÜ]+(?:\s+\w+)?\s*$"
)


def clean_affil(text: str) -> str:
    if not text:
        return ""
    t = text.strip().strip(",;/")
    for _ in range(4):
        prev = t
        t = COUNTRY_END.sub("", t).strip(",; ")
        t = STREET_PAT.sub("", t).strip(",; ")
        t = ZIP_CITY_END.sub("", t).strip(",; ")
        if t == prev:
            break
    return t.strip()


def get_alias_lookup(con):
    lookup = {}
    for r in con.execute("SELECT institut_id, alias_norm FROM institut_aliase"):
        lookup[r[1]] = r[0]
    for r in con.execute("SELECT id, name_de FROM institutionen"):
        n = norm(r[1])
        if n and n not in lookup:
            lookup[n] = r[0]
    return lookup


def match_or_create_institut(con, text: str, lookup: dict) -> int | None:
    """Match mit Fuzzy-Fallback: exakt -> Substring (>=85% Token-Overlap)
    -> neu anlegen. Vermeidet Dup-Explosion bei DE/EN-Übersetzungen oder
    leichten Schreibvarianten."""
    if not text or len(text.strip()) < 5:
        return None
    cleaned = clean_affil(text)
    raw_norm = norm(text)
    clean_norm = norm(cleaned)
    # 1) Exakter Match
    if clean_norm and clean_norm in lookup:
        iid = lookup[clean_norm]
        if raw_norm != clean_norm and raw_norm not in lookup:
            try:
                con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (iid, text.strip(), raw_norm))
                lookup[raw_norm] = iid
            except sqlite3.IntegrityError:
                pass
        return iid
    if raw_norm in lookup:
        return lookup[raw_norm]
    # 2) Fuzzy: Substring-Match mit >= 85% Token-Overlap (sehr konservativ)
    if clean_norm and len(clean_norm) >= 12:
        clean_tokens = set(clean_norm.split())
        STOP = {"institut", "institute", "for", "of", "and", "the", "fur", "fuer", "und",
                "der", "die", "das", "in", "von", "an", "am"}
        clean_sig = clean_tokens - STOP
        best_iid, best_score = None, 0
        for k, iid in lookup.items():
            if len(k) < 12:
                continue
            k_tokens = set(k.split()) - STOP
            if not k_tokens or not clean_sig:
                continue
            overlap = clean_sig & k_tokens
            min_size = min(len(clean_sig), len(k_tokens))
            if min_size == 0:
                continue
            score = len(overlap) / min_size
            # Require strong Token-Overlap UND mind. 3 gemeinsame Content-Tokens
            # UND einer ist Substring vom anderen (verhindert Geschwister-Match)
            if score >= 0.85 and len(overlap) >= 3 and (k in clean_norm or clean_norm in k):
                if score > best_score:
                    best_iid, best_score = iid, score
        if best_iid:
            if raw_norm not in lookup:
                try:
                    con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                                (best_iid, text.strip(), raw_norm))
                    lookup[raw_norm] = best_iid
                except sqlite3.IntegrityError:
                    pass
            return best_iid
    # 3) Neu anlegen
    main_name = cleaned if len(cleaned) >= 5 else text.strip()
    cur = con.execute("INSERT INTO institutionen (name_de) VALUES (?)", (main_name,))
    iid = cur.lastrowid
    if clean_norm:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, main_name, clean_norm))
            lookup[clean_norm] = iid
        except sqlite3.IntegrityError:
            pass
    if raw_norm and raw_norm != clean_norm:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, text.strip(), raw_norm))
            lookup[raw_norm] = iid
        except sqlite3.IntegrityError:
            pass
    return iid


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--replace-existing", action="store_true",
                    help="Loesche zuerst alle quelle='unscharf'/'pdf'/'anker' pro Paper "
                         "die in fitz-Daten sind, dann neu fuellen")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    lookup = get_alias_lookup(con)

    stats = {"papers": 0, "papers_with_data": 0,
             "single_affil_assignments": 0,
             "marker_assignments": 0,
             "skipped_no_marker_multi_affil": 0,
             "skipped_unmatched_db_autor": 0,
             "skipped_no_marker_match": 0,
             "neue_institute": 0}
    inst_before = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]

    with IN.open() as f:
        for line in f:
            try:
                d = json.loads(line)
            except Exception:
                continue
            stats["papers"] += 1
            pid = d.get("paper_id")
            if not d.get("ok"):
                continue
            authors = d.get("authors", [])
            affils = d.get("affils", [])
            if not authors or not affils:
                continue
            stats["papers_with_data"] += 1

            # Replace bestehende non-pdf-Einträge für dieses Paper?
            if args.apply and args.replace_existing:
                con.execute("""
                    DELETE FROM paper_autor_institutionen
                    WHERE paper_id = ? AND quelle != 'fitz_marker'
                """, (pid,))

            # Marker-Index: marker -> [institut_ids]
            marker_to_iids: dict[str, list[int]] = {}
            unmarked_affils: list[int] = []
            for af in affils:
                iid = match_or_create_institut(con, af["text"], lookup)
                if not iid:
                    continue
                markers = af.get("markers") or []
                if markers:
                    for m in markers:
                        marker_to_iids.setdefault(m, []).append(iid)
                else:
                    unmarked_affils.append(iid)

            for au in authors:
                aid = au.get("autor_id")
                if not aid:
                    stats["skipped_unmatched_db_autor"] += 1
                    continue
                au_markers = au.get("markers") or []
                target_iids: list[int] = []
                if au_markers:
                    for m in au_markers:
                        if m in marker_to_iids:
                            target_iids.extend(marker_to_iids[m])
                    if not target_iids and len(unmarked_affils) == 1:
                        target_iids = unmarked_affils
                else:
                    # Keine Marker: nur wenn genau 1 Affil insgesamt
                    if not marker_to_iids and len(unmarked_affils) == 1:
                        target_iids = unmarked_affils
                    elif not marker_to_iids and len(unmarked_affils) > 1:
                        # Mehrere unmarkierte Affils, kein Marker -> Single-Paper-Multi-Affil
                        # (z.B. "Inst A, Inst B" ohne Stern-Trennung)
                        # Hier ist beide am gleichen Autor möglich (Affil-Gruppe)
                        # Assign ALLE als Gemeinsamkeit
                        target_iids = unmarked_affils
                    elif marker_to_iids and len(marker_to_iids) == 1 and len(unmarked_affils) == 0:
                        # Es gibt Marker-Affils aber Autor ohne Marker -> in diesem
                        # Spezialfall hat das PDF inkonsistente Marker; skip
                        stats["skipped_no_marker_match"] += 1
                        continue
                    else:
                        stats["skipped_no_marker_multi_affil"] += 1
                        continue
                target_iids = list(dict.fromkeys(target_iids))
                if target_iids and args.apply:
                    for iid in target_iids:
                        try:
                            con.execute("""
                                INSERT OR IGNORE INTO paper_autor_institutionen
                                  (paper_id, autor_id, institut_id, quelle)
                                VALUES (?, ?, ?, 'fitz_marker')
                            """, (pid, aid, iid))
                        except sqlite3.IntegrityError:
                            pass
                if au_markers:
                    stats["marker_assignments"] += len(target_iids)
                else:
                    stats["single_affil_assignments"] += len(target_iids)

    if args.apply:
        con.commit()
    inst_after = con.execute("SELECT COUNT(*) FROM institutionen").fetchone()[0]
    stats["neue_institute"] = inst_after - inst_before
    print(f"\n--- Strang B v3 {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:36}  {v}")


if __name__ == "__main__":
    main()
