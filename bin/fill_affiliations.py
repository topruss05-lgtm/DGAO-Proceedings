#!/usr/bin/env python3
"""
Strang B: paper_autor_institutionen befüllen.

Stufen (deterministisch zuerst):
  1) Single-Affil-Papers (papers.affiliationen ohne mehrere Segmente):
     alle Autoren -> die eine Affiliation, quelle='single_affil'
  2) Multi-Affil-Papers mit PDF-Evidenz und verifizierten Marker-Zuordnungen:
     Autor -> seine Marker -> entsprechende(r) PDF-Affil-String(s),
     dann match Affil-String auf institutionen via institut_aliase
     (neuer Alias wenn nicht gefunden, neuer Eintrag wenn kein Match)
     quelle='pdf'
  3) Single-Affil-Anker: Multi-Affil-Autoren in Multi-Affil-Papers,
     deren Affiliation aus eigenen Solo-Affil-Papers eindeutig ist,
     quelle='anker'
  4) Rest: nichts schreiben (kein Raten).

Audit-Trail in autor_institut_audit_v9.
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
PDF_C = ROOT / "bin" / ".cache" / "pdf_evidence.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


# Länder, die typischerweise am Ende einer Adresse stehen
COUNTRY_END = re.compile(
    r",\s*(Germany|Deutschland|Italy|Italia|France|USA|United States|United Kingdom|UK|"
    r"Switzerland|Schweiz|Austria|Österreich|Spain|España|Netherlands|Niederlande|"
    r"Belgium|Belgien|Denmark|Dänemark|Sweden|Schweden|Norway|Norwegen|Poland|Polen|"
    r"Czech Republic|Tschechien|Hungary|Ungarn|Russia|Russland|China|Japan|India|Indien|"
    r"Korea|Taiwan|Brazil|Brasilien|Canada|Mexico|Israel|Greece|Griechenland|Finland|Finnland|"
    r"Portugal|Ireland|Irland|Romania|Rumänien|Slovenia|Slowenien)\s*$",
    re.IGNORECASE,
)
# Straßen/Adress-Indikatoren (Komma-getrennt, der ganze Rest danach ist Adresse)
STREET_PAT = re.compile(
    r",\s*[\w\-]*(?:strasse|straße|str\.|street|st\.|road|rd\.|lane|avenue|ave|boulevard|"
    r"blvd|platz|square|sq\.|allee|weg|gasse|chaussee|chausee|chemin|via|piazza|"
    r"corso|viale|calle|rue|sciencepark)\b[^,]*",
    re.IGNORECASE,
)
# PLZ + Stadt am Ende: ", 07745 Jena" oder ", D-07745 Jena"
ZIP_CITY_END = re.compile(r",\s*[A-Z]?-?\d{4,6}\s+\S+(?:\s+\S+)?\s*$")
# Zip allein am Anfang: "07745 Jena, Germany" -> entfernt
ZIP_ONLY = re.compile(r"^[A-Z]?-?\d{4,6}\s+")


def clean_affil(text: str) -> str:
    """Entferne Adress-Suffixe um den Institut-Kern zu isolieren."""
    if not text:
        return ""
    t = text.strip().strip(",;/")
    # mehrfach durchlaufen, bis nichts mehr abgeschnitten wird
    for _ in range(4):
        prev = t
        t = COUNTRY_END.sub("", t).strip(",; ")
        t = STREET_PAT.sub("", t).strip(",; ")
        t = ZIP_CITY_END.sub("", t).strip(",; ")
        t = ZIP_ONLY.sub("", t).strip(",; ")
        if t == prev:
            break
    return t.strip()


def split_multi_affils(text: str) -> list[str]:
    """Multi-Affil-Roh-String mit Marker-Trennung splitten."""
    if not text:
        return []
    # Trennzeichen: ; / \n + Markerpräfix ("2 ", "3 " etc.)
    # Erst einfache Trennung
    parts = re.split(r"[;\n]+|/(?=\s*\d)|(?<=\d)\s+(?=[A-ZÄÖÜ])", text)
    out = []
    for p in parts:
        p = p.strip(" ,/;")
        if not p:
            continue
        # Marker-Präfix entfernen
        p = re.sub(r"^(?:\d{1,2}|[*†‡§¶#]+)\s+", "", p)
        if len(p) >= 5:
            out.append(p)
    return out


def is_single_affil(affiliationen: str) -> bool:
    """Ist es eindeutig single-affil (keine Marker, keine Mehrfach-Trennung)?"""
    if not affiliationen:
        return False
    t = affiliationen.strip()
    # Newline + Marker-Ziffer
    if "\n" in t and re.search(r"\n\s*[2-6]", t):
        return False
    # Semikolon/Slash + Marker-Ziffer
    if re.search(r"[;/]\s*[2-6]", t):
        return False
    # Sternchen-Marker (mehrere Stufen)
    if "*" in t and ("**" in t or t.count("*") > 1):
        return False
    # Inline-Marker: ", 2 " mit nachfolgendem Großbuchstaben (neuer Affil-Beginn)
    if re.search(r",\s*[2-6]\s+[A-ZÄÖÜ]", t):
        return False
    # Mehrere Universitäten/Institute in einem Komma-String (heuristisch)
    if t.lower().count("universität") + t.lower().count("university") + \
       t.lower().count("universita") >= 2:
        return False
    if t.lower().count("fraunhofer") >= 2:
        return False
    return True


def get_alias_lookup(con) -> dict:
    """alias_norm -> institut_id"""
    out = {}
    for r in con.execute("SELECT institut_id, alias_norm FROM institut_aliase"):
        out[r[1]] = r[0]
    # Auch institutionen.name_de direkt
    for r in con.execute("SELECT id, name_de FROM institutionen"):
        n = norm(r[1])
        if n and n not in out:
            out[n] = r[0]
    return out


def match_or_create_institut(con, affil_text: str, alias_lookup: dict,
                              dry_run: bool = False) -> int | None:
    """Affil-String -> institut_id. Erzeugt neuen Eintrag falls neu.
    Versucht zuerst: Roh-Text, dann cleaned (ohne Adress-Suffix), dann Substring."""
    if not affil_text or len(affil_text.strip()) < 5:
        return None
    raw_norm = norm(affil_text)
    cleaned = clean_affil(affil_text)
    clean_norm = norm(cleaned)

    # Priorität 1: exakter Match auf cleaned form
    if clean_norm and clean_norm in alias_lookup:
        # Auch raw_text als Alias registrieren wenn neu
        if not dry_run and raw_norm != clean_norm and raw_norm not in alias_lookup:
            iid = alias_lookup[clean_norm]
            try:
                con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (iid, affil_text.strip(), raw_norm))
                alias_lookup[raw_norm] = iid
            except sqlite3.IntegrityError:
                pass
        return alias_lookup[clean_norm]

    # Priorität 2: roh exakt
    if raw_norm in alias_lookup:
        return alias_lookup[raw_norm]

    # Priorität 3: Substring-Match — sehr konservativ.
    # Nur wenn cleaned EXAKT als alias-norm in der DB ist ODER alias-norm
    # in cleaned vorkommt UND mindestens 80% der cleaned-Tokens deckt.
    # Vermeidet "Lehrstuhl A Universität X" -> "Universität X" Mismatch.
    if clean_norm and len(clean_norm) >= 12:
        clean_tokens = set(clean_norm.split())
        best_match = None
        best_score = 0
        for k, iid in alias_lookup.items():
            if len(k) < 12:
                continue
            k_tokens = set(k.split())
            if not k_tokens:
                continue
            # alias_norm muss MAJORITY ueberschneidung mit cleaned haben
            overlap = clean_tokens & k_tokens
            min_size = min(len(clean_tokens), len(k_tokens))
            if min_size == 0:
                continue
            score = len(overlap) / min_size
            # echte Identifikation: >=85% Token-Overlap
            if score >= 0.85 and len(overlap) >= 2:
                # Wenn cleaned strikt KEIN Substring von k oder umgekehrt,
                # vermeide Sub-Institut-Mismatches.
                if k in clean_norm or clean_norm in k:
                    if score > best_score:
                        best_match, best_score = iid, score
        if best_match:
            if not dry_run and raw_norm not in alias_lookup:
                try:
                    con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                                (best_match, affil_text.strip(), raw_norm))
                    alias_lookup[raw_norm] = best_match
                except sqlite3.IntegrityError:
                    pass
            return best_match

    # Nicht gefunden — neuen Eintrag anlegen (mit clean form als Hauptname)
    if dry_run:
        return None
    main_name = cleaned if len(cleaned) >= 5 else affil_text.strip()
    cur = con.execute("INSERT INTO institutionen (name_de) VALUES (?)",
                      (main_name,))
    iid = cur.lastrowid
    if clean_norm:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, main_name, clean_norm))
            alias_lookup[clean_norm] = iid
        except sqlite3.IntegrityError:
            pass
    if raw_norm and raw_norm != clean_norm:
        try:
            con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                        (iid, affil_text.strip(), raw_norm))
            alias_lookup[raw_norm] = iid
        except sqlite3.IntegrityError:
            pass
    return iid


def load_pdf_index() -> dict:
    """paper_id -> {authors: [{autor_id, markers, verified}], affils: [{marker, text}]}"""
    out = {}
    if not PDF_C.exists():
        return out
    with PDF_C.open() as f:
        for line in f:
            try:
                d = json.loads(line)
            except Exception:
                continue
            if not d.get("readable"):
                continue
            out[d["paper_id"]] = {
                "authors": d.get("authors", []),
                "affils": d.get("affiliations", []),
            }
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--stage", choices=["single", "pdf", "anker", "unscharf", "all"], default="all")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")

    alias_lookup = get_alias_lookup(con)
    pdf_index = load_pdf_index()
    print(f"  alias_lookup: {len(alias_lookup)} Einträge")
    print(f"  pdf_index:    {len(pdf_index)} Papers")

    stats = {"stage1_single": 0, "stage2_pdf": 0, "stage3_anker": 0,
             "stage4_unscharf": 0, "instituts_neu": 0, "skipped_kein_match": 0}

    # Audit-Tabelle
    con.execute("""
        CREATE TABLE IF NOT EXISTS paper_autor_institut_audit_v9 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paper_id   TEXT, autor_id INTEGER, institut_id INTEGER,
            quelle     TEXT, applied INTEGER DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    """)

    def insert_paif(paper_id, autor_id, institut_id, quelle):
        """Insert in paper_autor_institutionen + Audit. Idempotent."""
        if not institut_id:
            return False
        con.execute("""
            INSERT OR IGNORE INTO paper_autor_institut_audit_v9
            (paper_id, autor_id, institut_id, quelle, applied)
            VALUES (?,?,?,?,?)
        """, (paper_id, autor_id, institut_id, quelle, 1 if args.apply else 0))
        if args.apply:
            con.execute("""
                INSERT OR IGNORE INTO paper_autor_institutionen
                (paper_id, autor_id, institut_id, quelle)
                VALUES (?,?,?,?)
            """, (paper_id, autor_id, institut_id, quelle))
        return True

    # ============== STUFE 1: Single-Affil-Papers ==============
    if args.stage in ("single", "all"):
        print("\n[Stufe 1] Single-Affil-Papers...")
        rows = con.execute("""
            SELECT id, affiliationen FROM papers
            WHERE affiliationen IS NOT NULL AND trim(affiliationen) != ''
        """).fetchall()
        n_inst_before = len(alias_lookup)
        for r in rows:
            if not is_single_affil(r["affiliationen"]):
                continue
            iid = match_or_create_institut(con, r["affiliationen"],
                                            alias_lookup, dry_run=not args.apply)
            if not iid:
                stats["skipped_kein_match"] += 1
                continue
            # alle Autoren des Papers
            pa = con.execute("SELECT autor_id FROM paper_autoren WHERE paper_id=?",
                              (r["id"],)).fetchall()
            for p in pa:
                if insert_paif(r["id"], p["autor_id"], iid, "single_affil"):
                    stats["stage1_single"] += 1
        stats["instituts_neu"] = len(alias_lookup) - n_inst_before

    # ============== STUFE 2: Multi-Affil-Papers mit PDF-Markern ==============
    if args.stage in ("pdf", "all"):
        print("\n[Stufe 2] Multi-Affil via PDF-Marker...")
        for paper_id, pdf in pdf_index.items():
            affils_by_marker = defaultdict(list)
            for a in pdf["affils"]:
                m = a.get("marker") or "1"  # default: erste Affil = "1"
                affils_by_marker[m].append(a["text"])
            for au in pdf["authors"]:
                if not au.get("verified"):
                    continue
                markers = au.get("markers") or []
                # wenn keine Marker UND nur eine Affil: trivial
                if not markers and len(affils_by_marker) == 1:
                    marker = list(affils_by_marker.keys())[0]
                    for t in affils_by_marker[marker]:
                        iid = match_or_create_institut(con, t, alias_lookup,
                                                        dry_run=not args.apply)
                        if iid and insert_paif(paper_id, au["autor_id"], iid, "pdf"):
                            stats["stage2_pdf"] += 1
                else:
                    # Marker -> Affils
                    for m in markers:
                        affils = affils_by_marker.get(m, [])
                        for t in affils:
                            iid = match_or_create_institut(con, t, alias_lookup,
                                                            dry_run=not args.apply)
                            if iid and insert_paif(paper_id, au["autor_id"], iid, "pdf"):
                                stats["stage2_pdf"] += 1

    # ============== STUFE 3: Single-Affil-Anker ==============
    if args.stage in ("anker", "all"):
        print("\n[Stufe 3] Single-Affil-Anker für ungelöste Multi-Affil...")
        # Pro Autor: hat er aus paper_autor_institutionen (single_affil-Quelle)
        # eindeutig EINE Institution? Wenn ja -> übertragen auf alle seine Papers
        # wo er noch KEINEN Eintrag hat.
        # Bug-Fix: direkt aus paper_autor_institutionen (nicht aus dem Audit),
        # da die Audit-Tabelle nicht zuverlässig ist beim Re-Run.
        rows = con.execute("""
            SELECT autor_id, institut_id, COUNT(DISTINCT paper_id) AS n_papers
            FROM paper_autor_institutionen WHERE quelle='single_affil'
            GROUP BY autor_id, institut_id
        """).fetchall()
        autor_to_instits = defaultdict(set)
        for r in rows:
            autor_to_instits[r["autor_id"]].add(r["institut_id"])
        unique_anker = {a: list(s)[0] for a, s in autor_to_instits.items() if len(s) == 1}
        print(f"  Eindeutige Anker: {len(unique_anker)} Autoren")
        # Finde alle (paper_id, autor_id) Verknüpfungen wo es noch keinen pai-Eintrag gibt
        missing = con.execute("""
            SELECT pa.paper_id, pa.autor_id
            FROM paper_autoren pa
            LEFT JOIN paper_autor_institutionen pai
              ON pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id
            WHERE pai.paper_id IS NULL
        """).fetchall()
        print(f"  Missing-Verknüpfungen: {len(missing)}")
        for r in missing:
            iid = unique_anker.get(r["autor_id"])
            if iid and insert_paif(r["paper_id"], r["autor_id"], iid, "anker"):
                stats["stage3_anker"] += 1

    # ============== STUFE 4: Unscharf — alle Autoren -> alle Paper-Affils ==============
    if args.stage in ("unscharf", "all"):
        print("\n[Stufe 4] Unscharf: alle Autoren ungelöster Papers -> alle Affils des Papers...")
        # Multi-Affil-Papers (oder mit Roh-Affil) ohne pai-Eintrag pro Autor
        missing = con.execute("""
            SELECT pa.paper_id, pa.autor_id, p.affiliationen
            FROM paper_autoren pa
            JOIN papers p ON p.id = pa.paper_id
            LEFT JOIN paper_autor_institutionen pai
              ON pai.paper_id = pa.paper_id AND pai.autor_id = pa.autor_id
            WHERE pai.paper_id IS NULL
              AND p.affiliationen IS NOT NULL AND trim(p.affiliationen) != ''
        """).fetchall()
        print(f"  Unscharf-Kandidaten: {len(missing)} (paper,autor)-Verknüpfungen")
        # Cache pro Paper -> Liste der gematchten institut_ids
        paper_affil_cache = {}
        for r in missing:
            pid = r["paper_id"]
            if pid not in paper_affil_cache:
                inst_ids = []
                for part in split_multi_affils(r["affiliationen"]):
                    iid = match_or_create_institut(con, part, alias_lookup,
                                                    dry_run=not args.apply)
                    if iid:
                        inst_ids.append(iid)
                paper_affil_cache[pid] = list(dict.fromkeys(inst_ids))  # dedupe, keep order
            for iid in paper_affil_cache[pid]:
                if insert_paif(pid, r["autor_id"], iid, "unscharf"):
                    stats["stage4_unscharf"] += 1

    if args.apply:
        con.commit()
    print(f"\n--- Strang B {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:24}  {v}")

    if args.apply:
        c1 = con.execute("SELECT COUNT(*) FROM paper_autor_institutionen").fetchone()[0]
        print(f"\n  paper_autor_institutionen Gesamt: {c1}")


if __name__ == "__main__":
    main()
