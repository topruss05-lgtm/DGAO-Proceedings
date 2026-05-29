#!/usr/bin/env python3
"""
ROR-Anreicherung der institutionen-Tabelle.

Pro Institut:
  1. Wenn ror_id bereits da: 1 API-Call -> typ, homepage_url, wikidata_id, parent
  2. Wenn ror_id fehlt: erst ROR-Suche (Name + Stadt), dann gleicher Lauf
  3. parent_id-Auflösung: gefundener Parent-Eintrag -> matche auf eigene institutionen.id
     (über ror_id oder über name_de) und setze FK.

Quelle: https://api.ror.org/v2/organizations (kein Key nötig, Polite Pool).
"""
import json
import re
import sqlite3
import sys
import time
import unicodedata
import urllib.parse
import urllib.request
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"
ROR_API = "https://api.ror.org/v2/organizations"
USER_AGENT = "DGaO-cleanup (topruss05@gmail.com)"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def http_get(url: str, params: dict | None = None, attempts: int = 3) -> dict:
    if params:
        url = url + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={
        "Accept": "application/json", "User-Agent": USER_AGENT})
    for i in range(attempts):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.loads(r.read().decode())
        except Exception as e:
            if i == attempts - 1:
                print(f"    HTTP-Fehler: {e}", file=sys.stderr)
                return {}
            time.sleep(2 ** i)
    return {}


def fetch_ror(ror_id: str) -> dict:
    rid = ror_id.split("/")[-1] if ror_id else ""
    if not rid:
        return {}
    return http_get(f"{ROR_API}/{rid}")


def search_ror(name: str, ort: str | None) -> str | None:
    """ROR-Suche -> beste ROR-ID oder None.
    KONSERVATIV: lieber kein Match als ein falscher.
    - Lange/komplexe Affils: nur das erste Komma-Segment als Suchquery
    - Stadt MUSS im Treffer auftauchen, wenn aus DGaO bekannt
    - Mind. 2 echte Token-Overlaps (außer Stadtname allein)
    """
    primary = name.split(",")[0].strip()
    q = primary
    if ort and ort.lower() not in primary.lower():
        q = f"{primary} {ort}"
    q_clean = re.sub(r"[(),\[\]\"]", " ", q).strip()
    if len(q_clean) < 4:
        return None
    data = http_get(ROR_API, {"query": q_clean})
    hits = data.get("items", [])
    if not hits:
        return None

    name_tok_full = set(norm(primary).split())
    ort_tok = set(norm(ort).split()) if ort else set()
    # Stopwords: zu allgemeine Token, die für sich allein keinen Match rechtfertigen
    STOP = {"institut", "institute", "university", "universita", "universitaet",
            "national", "applied", "angewandte", "department", "research",
            "forschung", "german", "europe", "europaeische", "international",
            "of", "for", "the", "and", "fur", "und", "von", "der", "die", "das",
            "academy", "science", "scienze", "scientific", "technical", "technische"}
    significant = name_tok_full - STOP

    best, best_score = None, 0
    for h in hits[:10]:
        if h.get("status") != "active":
            continue
        names = []
        for n in h.get("names", []):
            if n.get("value"):
                names.append(n["value"])
        if not names:
            continue
        # Stadt-Check: wenn DGaO-Stadt vorhanden, MUSS sie in den Treffer-Locations sein
        if ort_tok:
            loc_tok = set()
            for loc in h.get("locations") or []:
                gd = loc.get("geonames_details") or {}
                for v in (gd.get("name"), gd.get("country_name")):
                    if v:
                        loc_tok |= set(norm(v).split())
            if not (ort_tok & loc_tok):
                continue
        # Token-Score: signifikante Überlappung (ohne Stopwords)
        h_tok = set()
        for n in names:
            h_tok |= set(norm(n).split())
        sig_ov = len(significant & h_tok)
        if sig_ov > best_score:
            best, best_score = h.get("id"), sig_ov
    # mind. 2 signifikante Token UND best_score > 0 (kein Zufall durch Stadtname allein)
    return best if best_score >= 2 else None


def extract_fields(ror_data: dict) -> dict:
    """ROR-Daten -> unsere Spalten."""
    out = {}
    types = ror_data.get("types") or []
    if types:
        out["typ"] = types[0]
    for link in ror_data.get("links", []):
        if link.get("type") == "website" and link.get("value"):
            out["homepage_url"] = link["value"]
            break
    for ext in ror_data.get("external_ids") or []:
        if ext.get("type") == "wikidata":
            preferred = ext.get("preferred")
            allvals = ext.get("all") or []
            out["wikidata_id"] = preferred or (allvals[0] if allvals else None)
            break
    # Parent ROR-ID extrahieren (nicht ID-Auflösung -- die kommt später)
    parents = [r.get("id") for r in (ror_data.get("relationships") or [])
               if r.get("type") == "parent"]
    if parents:
        out["_parent_ror"] = parents[0]
    # Aliase / Akronyme
    aliases = []
    acronyms = []
    for n in ror_data.get("names") or []:
        v = n.get("value")
        if not v:
            continue
        ntypes = n.get("types") or []
        if "acronym" in ntypes:
            acronyms.append(v)
        elif "alias" in ntypes or "label" in ntypes:
            aliases.append(v)
    if aliases:
        out["_aliases"] = aliases
    if acronyms:
        out["_acronyms"] = acronyms
    return out


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="nur N Institute (0=alle)")
    ap.add_argument("--only-with-ror", action="store_true",
                    help="nur die mit vorhandener ror_id (kein Search)")
    ap.add_argument("--ids", type=str, default="", help="kommagetrennte IDs")
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")

    q = """
        SELECT id, name_de, name_en, kuerzel, ort, land, ror_id,
               parent_id, typ, homepage_url, wikidata_id
        FROM institutionen
    """
    where = []
    if args.only_with_ror:
        where.append("ror_id IS NOT NULL AND ror_id != ''")
    if args.ids:
        ids = [int(x) for x in args.ids.split(",") if x.strip()]
        where.append(f"id IN ({','.join('?'*len(ids))})")
        q_params = ids
    else:
        q_params = []
    if where:
        q += " WHERE " + " AND ".join(where)
    q += " ORDER BY id"
    if args.limit:
        q += f" LIMIT {args.limit}"
    rows = con.execute(q, q_params).fetchall()
    print(f"Verarbeite {len(rows)} Institute...")

    stats = {"hatte_ror": 0, "gefunden": 0, "nicht_gefunden": 0,
             "ror_to_fields": 0, "aliases_neu": 0, "acronyms_neu": 0,
             "parent_set": 0}

    # Map ROR-ID -> institutionen.id für spätere Parent-Auflösung — alle Institute
    # (auch außerhalb der gefilterten rows, damit Parents korrekt aufgelöst werden)
    ror_to_iid = {}
    for ar in con.execute("SELECT id, ror_id FROM institutionen WHERE ror_id IS NOT NULL AND ror_id != ''").fetchall():
        ror_to_iid[ar["ror_id"].rstrip("/").lower()] = ar["id"]

    # Erster Pass: Felder + Aliase + Aufzeichnung parent-ROR-IDs
    parent_ror_map = {}  # institutionen.id -> parent_ror_id

    for r in rows:
        iid = r["id"]
        ror_id = r["ror_id"]
        if not ror_id:
            ror_id = search_ror(r["name_de"], r["ort"])
            if not ror_id:
                stats["nicht_gefunden"] += 1
                continue
            stats["gefunden"] += 1
            con.execute("UPDATE institutionen SET ror_id=? WHERE id=?", (ror_id, iid))
            ror_to_iid[ror_id.rstrip("/").lower()] = iid
        else:
            stats["hatte_ror"] += 1

        data = fetch_ror(ror_id)
        if not data:
            continue
        fields = extract_fields(data)
        stats["ror_to_fields"] += 1

        sets, params = [], []
        if "typ" in fields and not r["typ"]:
            sets.append("typ=?"); params.append(fields["typ"])
        if "homepage_url" in fields and not r["homepage_url"]:
            sets.append("homepage_url=?"); params.append(fields["homepage_url"])
        if "wikidata_id" in fields and not r["wikidata_id"]:
            sets.append("wikidata_id=?"); params.append(fields["wikidata_id"])
        if sets:
            params.append(iid)
            con.execute(f"UPDATE institutionen SET {', '.join(sets)} WHERE id=?", params)

        # Aliase + Akronyme einfügen (UNIQUE constraint auf alias_norm verhindert Duplikate)
        for alias in fields.get("_aliases", []):
            an = norm(alias)
            if an:
                try:
                    con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                                (iid, alias, an))
                    stats["aliases_neu"] += 1
                except sqlite3.IntegrityError:
                    pass
        for acr in fields.get("_acronyms", []):
            an = norm(acr)
            if an:
                try:
                    con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                                (iid, acr, an))
                    stats["acronyms_neu"] += 1
                except sqlite3.IntegrityError:
                    pass
            # kuerzel setzen, falls leer
            if not r["kuerzel"]:
                con.execute("UPDATE institutionen SET kuerzel=? WHERE id=?", (acr, iid))

        if fields.get("_parent_ror"):
            parent_ror_map[iid] = fields["_parent_ror"]

        if stats["ror_to_fields"] % 50 == 0:
            con.commit()
            print(f"  ...{stats['ror_to_fields']} verarbeitet", file=sys.stderr)

    con.commit()

    # Zweiter Pass: parent_id auflösen (parent-ROR -> institutionen.id, FK setzen)
    # Sub-Pass A: parents, deren ROR wir schon in unserer DB haben
    # Sub-Pass B: parents, die noch nicht da sind -> als neuer institutionen-Eintrag anlegen
    for iid, parent_ror in parent_ror_map.items():
        key = parent_ror.rstrip("/").lower()
        if key in ror_to_iid:
            con.execute("UPDATE institutionen SET parent_id=? WHERE id=?",
                        (ror_to_iid[key], iid))
            stats["parent_set"] += 1
            continue
        # Parent fehlt noch -> ROR holen und anlegen
        pdata = fetch_ror(parent_ror)
        if not pdata:
            continue
        pname = ""
        pname_en = ""
        for n in pdata.get("names") or []:
            ntypes = n.get("types") or []
            if "ror_display" in ntypes and not pname:
                pname = n.get("value") or ""
            if "label" in ntypes and (n.get("lang") == "en") and not pname_en:
                pname_en = n.get("value") or ""
        if not pname:
            continue
        # Stadt/Land aus locations
        port, pland = None, None
        for loc in pdata.get("locations") or []:
            gd = loc.get("geonames_details") or {}
            port = port or gd.get("name")
            pland = pland or gd.get("country_code")
        pfields = extract_fields(pdata)
        cur = con.execute("""
            INSERT INTO institutionen (name_de, name_en, ort, land, ror_id, typ, homepage_url, wikidata_id)
            VALUES (?,?,?,?,?,?,?,?)
        """, (pname, pname_en or "", port, pland or "DE", parent_ror,
              pfields.get("typ"), pfields.get("homepage_url"), pfields.get("wikidata_id")))
        new_pid = cur.lastrowid
        ror_to_iid[key] = new_pid
        # Sein eigener Alias
        an = norm(pname)
        if an:
            try:
                con.execute("INSERT INTO institut_aliase (institut_id, alias_text, alias_norm) VALUES (?,?,?)",
                            (new_pid, pname, an))
            except sqlite3.IntegrityError:
                pass
        con.execute("UPDATE institutionen SET parent_id=? WHERE id=?", (new_pid, iid))
        stats["parent_set"] += 1

    con.commit()

    print("\n--- ROR-Anreicherung fertig ---")
    for k, v in stats.items():
        print(f"  {k:18}  {v}")


if __name__ == "__main__":
    main()
