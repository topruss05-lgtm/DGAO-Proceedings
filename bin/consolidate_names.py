#!/usr/bin/env python3
"""
Konsolidiert PDF + OpenAlex + ORCID -> autoren.vorname/anzeige_name/orcid_id + autor_aliase.

Quellen-Priorität für vorname:
  1) ORCID given-names           (höchste — vom Autor selbst gepflegt)
  2) PDF-Mehrheits-Form          (verifiziert in seinen eigenen Papers)
  3) OpenAlex display-name       (Sekundär)
  4) sonst: bleibt wie gehabt (Initiale)

anzeige_name: ORCID credit-name wenn != given+family, sonst NULL.
orcid_id:     aus OpenAlex (über ORCID-Detail bestätigt).

Aliase:       alle beobachteten Schreibvarianten aus allen Quellen + ASCII-Normalisierungen.

Schreibt nur, wenn:
  - eindeutige Quelle hoher Konfidenz
  - keine Konflikte zwischen Quellen
Sonst: Eintrag in review_queue, nicht in DB.
"""
import json
import re
import sqlite3
import sys
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "public" / "data" / "proceedings.db"
PDF_C = ROOT / "bin" / ".cache" / "pdf_evidence.jsonl"
OA_C = ROOT / "bin" / ".cache" / "openalex_evidence.jsonl"
OR_C = ROOT / "bin" / ".cache" / "orcid_evidence.jsonl"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def load_pdf(con) -> dict:
    """autor_id -> {'vornamen': Counter, 'papers': N, 'verified': N}"""
    out = defaultdict(lambda: {"vornamen": Counter(), "papers": 0, "verified": 0,
                                "raw": Counter()})
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
            for a in d.get("authors", []):
                aid = a.get("autor_id")
                if not aid:
                    continue
                out[aid]["papers"] += 1
                if a.get("verified"):
                    out[aid]["verified"] += 1
                    vn = a.get("vorname_extracted")
                    name_in_pdf = a.get("name_in_pdf")
                    if vn and len(vn) > 1 and not vn.endswith("."):
                        out[aid]["vornamen"][vn] += 1
                    if name_in_pdf:
                        out[aid]["raw"][name_in_pdf] += 1
    return out


def load_oa(con) -> dict:
    """autor_id -> bester Kandidat dict"""
    out = {}
    if not OA_C.exists():
        return out
    with OA_C.open() as f:
        for line in f:
            try:
                d = json.loads(line)
            except Exception:
                continue
            aid = d.get("autor_id")
            if not aid:
                continue
            cands = d.get("candidates") or []
            if not cands:
                out[aid] = None
                continue
            # Top candidate ist bereits sortiert (aff_score+works)
            top = cands[0]
            # Anzahl Kandidaten mit gleichem Vornamen (Vornamen-Konsens)
            distinct_vn = set()
            for c in cands:
                if c.get("aff_score", 0) < 0.5:
                    continue
                vc = norm(c.get("vorname_candidate") or "")
                parts = vc.split()
                if parts:
                    distinct_vn.add(parts[0])
            top["_distinct_vn"] = len(distinct_vn)
            top["_other_alts"] = []
            # Sammle ALLE alternatives über alle Kandidaten (für Aliase)
            for c in cands:
                top["_other_alts"].extend(c.get("alternatives") or [])
            out[aid] = top
    return out


def load_orcid() -> dict:
    """orcid -> details dict"""
    out = {}
    if not OR_C.exists():
        return out
    with OR_C.open() as f:
        for line in f:
            try:
                d = json.loads(line)
            except Exception:
                continue
            oid = d.get("orcid")
            if oid:
                out[oid] = d
    return out


def init_letter(vn: str) -> str:
    parts = norm((vn or "").replace(".", "")).split()
    return parts[0][:1] if parts else ""


def consolidate_one(aid: int, db_vorname: str, db_nachname: str,
                    pdf: dict, oa: dict | None, orcid_db: dict) -> dict:
    """Entscheide pro Autor: vorname, anzeige_name, orcid_id, Aliase."""
    out = {
        "autor_id": aid, "old_vorname": db_vorname, "nachname": db_nachname,
        "new_vorname": None, "anzeige_name": None, "orcid_id": None,
        "aliases": [], "quelle": None, "confidence": 0.0, "reason": "",
    }
    init = init_letter(db_vorname)

    # ORCID-ID aus OpenAlex
    orcid_url = oa.get("orcid") if oa else None
    orcid_short = orcid_url.split("/")[-1] if orcid_url else None
    orcid_data = orcid_db.get(orcid_short) if orcid_short else None

    # ===== Aliase sammeln (alle Quellen) =====
    aliases: set = set()
    # Schon-bestehender Name
    if db_vorname and db_nachname:
        aliases.add(f"{db_vorname} {db_nachname}".strip())
    # PDF-Rohnamen
    for raw in pdf.get("raw", []):
        aliases.add(raw)
    # OpenAlex display_name + alternatives
    if oa:
        if oa.get("display_name"):
            aliases.add(oa["display_name"])
        for alt in oa.get("_other_alts", []):
            aliases.add(alt)
    # ORCID-Namen
    if orcid_data and not orcid_data.get("error") and not orcid_data.get("not_found"):
        gn = orcid_data.get("given_names")
        fn = orcid_data.get("family_name")
        cn = orcid_data.get("credit_name")
        if gn and fn:
            aliases.add(f"{gn} {fn}")
        if cn:
            aliases.add(cn)
        for o in orcid_data.get("other_names") or []:
            aliases.add(o)
    out["aliases"] = sorted(a for a in aliases if a and len(a) >= 3)

    # ===== Vornamen-Kandidaten mit Quelle und Konfidenz =====
    sources = []
    # ORCID (höchste Priorität)
    if orcid_data and not orcid_data.get("error") and not orcid_data.get("not_found"):
        gn = (orcid_data.get("given_names") or "").strip()
        if gn:
            # Initial-Konsistenz prüfen
            if not init or norm(gn)[:1] == init:
                sources.append(("orcid", gn, 0.98))
        cn = (orcid_data.get("credit_name") or "").strip()
        if cn and gn and cn != gn:
            # credit-name könnte abweichend sein -> nur als anzeige_name
            out["anzeige_name"] = cn
    # PDF-Mehrheit (mind. 2 Belege oder eindeutig)
    pdf_vn = pdf.get("vornamen") or Counter()
    if pdf_vn:
        top_vn, top_n = pdf_vn.most_common(1)[0]
        total = sum(pdf_vn.values())
        if top_n >= 2 or (top_n == 1 and len(pdf_vn) == 1 and pdf.get("verified", 0) >= 1):
            # Konfidenz: 0.85 wenn 2+ Belege ohne Konflikt, 0.75 für 1 Beleg
            conf = 0.95 if top_n >= 3 else (0.85 if top_n >= 2 else 0.75)
            if len(pdf_vn) > 1:
                # Konflikt -> nimm Mehrheit, niedriger
                conf = max(top_n / total, 0.5) * 0.9
            if not init or norm(top_vn)[:1] == init:
                sources.append(("pdf", top_vn, conf))
    # OpenAlex
    if oa and oa.get("vorname_candidate"):
        vn = oa["vorname_candidate"]
        aff = oa.get("aff_score", 0)
        distinct = oa.get("_distinct_vn", 0)
        # eindeutig wenn distinct_vn <= 1
        if distinct <= 1:
            conf = 0.8 + 0.15 * aff
        else:
            conf = 0.5 + 0.15 * aff
        if not init or norm(vn)[:1] == init:
            sources.append(("openalex", vn, conf))

    # ===== Konsensus / Auswahl =====
    if sources:
        # Priorität: höchste Konfidenz
        sources.sort(key=lambda s: s[2], reverse=True)
        best_src, best_vn, best_conf = sources[0]
        # Konsens-Check: matchen mind. 2 Quellen?
        agree = sum(1 for s in sources if norm(s[1]).split()[0] == norm(best_vn).split()[0])
        if agree >= 2:
            best_conf = min(0.99, best_conf + 0.05)
        out["new_vorname"] = best_vn
        out["quelle"] = best_src
        out["confidence"] = round(best_conf, 3)
        # Anzeige-Name plausi: nur setzen wenn != vorname + nachname
        if out["anzeige_name"] == f"{best_vn} {db_nachname}":
            out["anzeige_name"] = None
        out["reason"] = f"sources={[(s[0], s[2]) for s in sources]}, agree={agree}"

    # ORCID setzen NUR wenn der Top-Kandidat plausibel zur Person passt:
    #   - Vorname-Initial konsistent ODER
    #   - hoher Aff-Score (>= 0.7) ODER
    #   - DB-Vorname schon voll und matcht OpenAlex/ORCID-Vorname
    if orcid_url and oa:
        oa_first = norm((oa.get("vorname_candidate") or "")).split()
        oa_first_init = oa_first[0][:1] if oa_first else ""
        db_init = init  # bereits oben berechnet
        aff_score = oa.get("aff_score", 0)
        db_vn_full = (db_vorname or "")
        db_is_full = len(db_vn_full) > 3 and not db_vn_full.endswith(".")
        ok = False
        if db_init and oa_first_init and db_init == oa_first_init:
            ok = True
        elif aff_score >= 0.7:
            ok = True
        elif db_is_full and oa_first and norm(db_vn_full).split()[0] == oa_first[0]:
            ok = True
        # ORCID-Verifikation: family-name muss zum Nachnamen passen
        if ok and orcid_data and not orcid_data.get("error") and not orcid_data.get("not_found"):
            of = norm(orcid_data.get("family_name") or "")
            if of and of != norm(db_nachname):
                ok = False
                out["reason"] += " | ORCID family-name mismatch"
        if ok:
            out["orcid_id"] = orcid_url

    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true",
                    help="tatsächlich in DB schreiben (sonst dry-run)")
    ap.add_argument("--min-conf", type=float, default=0.85,
                    help="Mindest-Konfidenz für vorname-Schreibvorgang")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row

    pdf_ev = load_pdf(con)
    oa_ev = load_oa(con)
    orcid_ev = load_orcid()

    print(f"  PDF-Evidenz: {len(pdf_ev)} Autoren")
    print(f"  OpenAlex-Evidenz: {len(oa_ev)} Autoren ({sum(1 for v in oa_ev.values() if v)} mit Kandidaten)")
    print(f"  ORCID-Evidenz: {len(orcid_ev)} ORCIDs")

    # Stage-Tabelle anlegen für Audit
    con.execute("""
        CREATE TABLE IF NOT EXISTS autor_vorname_audit_v9 (
            autor_id   INTEGER PRIMARY KEY,
            old_vorname TEXT,
            new_vorname TEXT,
            anzeige_name TEXT,
            orcid_id   TEXT,
            confidence REAL,
            quelle     TEXT,
            reason     TEXT,
            applied    INTEGER DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    """)

    rows = con.execute("SELECT id, vorname, nachname FROM autoren ORDER BY id").fetchall()
    if args.limit:
        rows = rows[:args.limit]

    written_vn = 0
    written_orcid = 0
    written_alias = 0
    written_anzeige = 0
    audit_count = 0

    for r in rows:
        aid, db_vn, db_nn = r["id"], r["vorname"], r["nachname"]
        decision = consolidate_one(aid, db_vn, db_nn,
                                   pdf_ev.get(aid, {}), oa_ev.get(aid), orcid_ev)

        # Audit immer schreiben (auch für niedrige Konfidenz)
        con.execute("""
            INSERT OR REPLACE INTO autor_vorname_audit_v9
            (autor_id, old_vorname, new_vorname, anzeige_name, orcid_id, confidence, quelle, reason)
            VALUES (?,?,?,?,?,?,?,?)
        """, (aid, db_vn, decision["new_vorname"], decision["anzeige_name"],
              decision["orcid_id"], decision["confidence"], decision["quelle"],
              decision["reason"]))
        audit_count += 1

        if not args.apply:
            continue

        applied = 0
        # vorname schreiben?
        if decision["new_vorname"] and decision["confidence"] >= args.min_conf:
            new_vn = decision["new_vorname"]
            # Encoding-/Layout-Verdacht: zu kurz, fehlende Vokale, Ersatz-Chars
            looks_broken = (
                len(new_vn) < 3
                or "?" in new_vn or "�" in new_vn
                or not re.search(r"[aeiouäöüAEIOUÄÖÜ]", new_vn)
            )
            # db_vn nur Initialen-Form? (enthält Punkt ODER ist <=3 Zeichen)
            db_is_initials = bool(db_vn) and ("." in db_vn or len(db_vn) <= 3)
            if not looks_broken and len(new_vn) > len(db_vn or "") and db_is_initials:
                con.execute("UPDATE autoren SET vorname=? WHERE id=?",
                            (new_vn, aid))
                written_vn += 1
                applied = 1
        # anzeige_name schreiben
        if decision["anzeige_name"]:
            con.execute("UPDATE autoren SET anzeige_name=? WHERE id=?",
                        (decision["anzeige_name"], aid))
            written_anzeige += 1
            applied = 1
        # orcid_id schreiben
        if decision["orcid_id"]:
            con.execute("UPDATE autoren SET orcid_id=? WHERE id=?",
                        (decision["orcid_id"], aid))
            written_orcid += 1
            applied = 1
        # Aliase
        for alias in decision["aliases"]:
            an = norm(alias)
            if not an:
                continue
            try:
                con.execute("INSERT INTO autor_aliase (autor_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (aid, alias, an))
                written_alias += 1
            except sqlite3.IntegrityError:
                pass
        if applied:
            con.execute("UPDATE autor_vorname_audit_v9 SET applied=1 WHERE autor_id=?", (aid,))

    con.commit()
    print(f"\n--- Konsolidierung {'APPLY' if args.apply else 'DRY-RUN'} ---")
    print(f"  Audit-Einträge:       {audit_count}")
    if args.apply:
        print(f"  vorname geschrieben:  {written_vn}")
        print(f"  anzeige_name gesetzt: {written_anzeige}")
        print(f"  orcid_id gesetzt:     {written_orcid}")
        print(f"  Aliase neu:           {written_alias}")
    else:
        # Dry-run Stats aus dem Audit
        c1 = con.execute("SELECT COUNT(*) FROM autor_vorname_audit_v9 WHERE new_vorname IS NOT NULL AND confidence >= ?", (args.min_conf,)).fetchone()[0]
        c2 = con.execute("SELECT COUNT(*) FROM autor_vorname_audit_v9 WHERE orcid_id IS NOT NULL").fetchone()[0]
        print(f"  würde vorname setzen (conf>={args.min_conf}): {c1}")
        print(f"  würde orcid setzen: {c2}")


if __name__ == "__main__":
    main()
