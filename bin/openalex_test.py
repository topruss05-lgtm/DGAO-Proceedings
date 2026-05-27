#!/usr/bin/env python3
"""
OpenAlex-Test gegen die 20 Ground-Truth-Autoren.

Misst die DETERMINISTISCHE Hit-Rate (0 Fehler Pflicht) für Vorname-Vervollständigung
per OpenAlex Author-Search + mehrstufigem Affiliation-Match.

Pipeline pro Autor:
  1. /authors?filter=display_name.search:<Nachname>  (bis 25 Kandidaten)
  2. Kandidaten filtern: Nachname-Match (normalisiert) + voller Vorname passend zur Initiale
  3. aff_score je Kandidat gegen ALLE affiliations[] (historisch + parallel) + Stadt + DE/EN-Präfix
  4. Falls Top-Kandidat aff_score < RAW_THRESHOLD: raw_affiliation_strings aus Works nachladen
  5. Akzeptieren nur bei eindeutigem Vornamen-Konsens unter Kandidaten mit Score >= ACCEPT.
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
MAILTO = "topruss05@gmail.com"
BASE = "https://api.openalex.org"

ACCEPT = 0.50          # Mindest-Aff-Score für Akzeptanz
RAW_THRESHOLD = 0.50   # Unter diesem Score: raw_affiliation_strings nachladen

STOP = {"der", "die", "das", "den", "und", "fur", "fuer", "of", "the", "for", "and",
        "institut", "institute", "istituto", "university", "universitat", "universitaet",
        "universita", "universite", "fakultat", "lehrstuhl", "professur", "gmbh", "mbh",
        "ag", "ev", "co", "kg", "department", "dept", "abteilung", "center", "centre",
        "zentrum", "research", "forschung", "angewandte", "applied", "fachhochschule",
        "technische", "technical", "hochschule", "fraunhofer", "max", "planck", "national",
        "nazionale", "national", "science", "sciences", "scienze", "di", "der", "des"}


def norm(s: str) -> str:
    if not s:
        return ""
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = s.lower()
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def tokens(s: str, keep_stop=False) -> set:
    out = set()
    for t in norm(s).split():
        if len(t) > 2 and (keep_stop or t not in STOP):
            out.add(t)
    return out


ACRONYM_HINTS = {
    "ito": ["stuttgart"],
    "ipq": ["karlsruhe"],
    "iof": ["fraunhofer", "jena", "angewandte optik"],
    "enea": ["new technologies", "energy", "sustainable"],
    "bessy": ["helmholtz", "berlin", "elektronenspeicherring"],
    "ptb": ["physikalisch", "bundesanstalt", "braunschweig"],
}


def acronyms(s: str) -> set:
    out = set()
    for m in re.findall(r"\(([^)]+)\)", s):
        if 2 <= len(m) <= 8 and m.isupper():
            out.add(m.lower())
    for m in re.findall(r"\b([A-Z]{2,6})\b", s):
        out.add(m.lower())
    return out


def prefix_match(a: str, b: str, n=6) -> bool:
    """DE/EN-Heuristik: photonik~photonics teilen langen Präfix."""
    return len(a) >= n and len(b) >= n and a[:n] == b[:n]


def api_get(path: str, params: dict) -> dict:
    params = {**params, "mailto": MAILTO}
    url = f"{BASE}/{path}?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={"User-Agent": f"DGaO-cleanup ({MAILTO})"})
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.loads(r.read().decode())
        except Exception as e:
            if attempt == 2:
                print(f"    API-Fehler: {e}", file=sys.stderr)
                return {}
            time.sleep(2 ** attempt)
    return {}


def extract_full_first_name(author: dict, initial: str) -> str | None:
    init_letter = norm(initial.replace(".", "").split()[0])[:1] if initial else ""
    candidates = [author.get("display_name", "")]
    candidates += author.get("display_name_alternatives", [])
    found = {}
    for c in candidates:
        part = c.split(",", 1)[1].strip() if "," in c else c
        for w in part.replace(".", ". ").split():
            ww = w.strip(".,")
            wn = norm(ww)
            if len(wn) <= 1:
                continue
            # all-caps Kürzel (FT, JR) ausschließen
            if ww.isupper() and len(ww) <= 4:
                continue
            if init_letter and wn[0] != init_letter:
                continue
            # korrekt-kapitalisiert bevorzugen (Wolfgang, nicht WOLFGANG)
            if ww[0].isupper() and not ww.isupper():
                found[ww] = found.get(ww, 0) + 1
    if not found:
        return None
    best = sorted(found.items(), key=lambda kv: (kv[1], len(kv[0])), reverse=True)
    return best[0][0]


def score_against_strings(db_aff: str, inst_names: list) -> float:
    """0..1 Match-Score zwischen DB-Affil und einer Liste Institutions-/Roh-Strings."""
    if not db_aff:
        return 0.0
    db_tok = tokens(db_aff)
    db_city = tokens(db_aff, keep_stop=True)
    db_acr = acronyms(db_aff)
    best = 0.0
    for name in inst_names:
        if not name:
            continue
        oa_tok = tokens(name)
        oa_city = tokens(name, keep_stop=True)
        oa_norm = norm(name)
        # 1) inhaltlicher Token-Overlap
        if oa_tok and db_tok:
            ov = db_tok & oa_tok
            # DE/EN-Präfix dazuzählen
            pref = sum(1 for x in db_tok for y in oa_tok if prefix_match(x, y))
            eff = len(ov) + 0.5 * pref
            if eff:
                best = max(best, min(1.0, eff / min(len(db_tok), len(oa_tok))))
        # 2) Akronym-Hints
        for acr in db_acr:
            for frag in ACRONYM_HINTS.get(acr, []):
                if frag in oa_norm:
                    best = max(best, 1.0)
        # 3) gemeinsamer Eigenname/Stadt (z.B. Karlsruhe, Bremen) als Boost
        shared_city = (db_city & oa_city) - STOP
        if shared_city and best < 0.5:
            best = max(best, 0.5)
    return best


def raw_affiliation_strings(author_id: str, nachname: str) -> list:
    aid = author_id.split("/")[-1]
    data = api_get("works", {"filter": f"author.id:{aid}", "per-page": 25,
                             "select": "authorships"})
    nn = norm(nachname)
    out = set()
    for w in data.get("results", []):
        for au in w.get("authorships", []):
            if nn in norm(au.get("author", {}).get("display_name", "")):
                for raw in au.get("raw_affiliation_strings", []):
                    out.add(raw)
    return list(out)


def all_oa_institutions(author: dict) -> list:
    seen = {}
    for aff in author.get("affiliations", []):
        inst = aff.get("institution", {})
        if inst.get("display_name"):
            seen[inst["display_name"]] = True
    for inst in (author.get("last_known_institutions") or []):
        if inst.get("display_name"):
            seen[inst["display_name"]] = True
    return list(seen.keys())


_INST_CACHE: dict = {}


def resolve_institution_ids(db_aff: str) -> list:
    """DGaO-Affil -> OpenAlex Institution-IDs (beste Treffer). Cached."""
    if not db_aff:
        return []
    if db_aff in _INST_CACHE:
        return _INST_CACHE[db_aff]
    ids = []
    # Kuratierte Akronym-Hints zuerst (ITO->Stuttgart, IPQ->Karlsruhe) — am spezifischsten
    hint_queries = []
    for acr in acronyms(db_aff):
        for frag in ACRONYM_HINTS.get(acr, []):
            hint_queries.append(frag)
    # dann volle Affil + Uni-/Haupt-Teil (Komma-Segmente)
    parts = [p.strip() for p in db_aff.split(",")]
    queries = hint_queries + [db_aff] + [p for p in parts if len(p) > 6]
    seen_q = set()
    for q in queries:
        # Klammern/Sonderzeichen raus — sprengen sonst die Such-Syntax
        q_clean = re.sub(r"[(),\[\]]", " ", q)
        q_clean = re.sub(r"\s+", " ", q_clean).strip()
        qn = norm(q_clean)
        if not qn or qn in seen_q:
            continue
        seen_q.add(qn)
        # Top-Level search= ist robuster als filter=display_name.search:
        data = api_get("institutions", {"search": q_clean, "per-page": 3})
        for inst in data.get("results", []):
            iid = inst.get("id", "").split("/")[-1]
            # Plausibilität: Token-Overlap zwischen Query und gefundenem Namen
            if iid and (tokens(q) & tokens(inst.get("display_name", ""))
                        or acronyms(db_aff)):
                ids.append(iid)
        if ids:
            break
    ids = list(dict.fromkeys(ids))[:3]
    _INST_CACHE[db_aff] = ids
    return ids


def fetch_orcid_first_name(orcid_url: str, initial: str) -> str | None:
    """ORCID Public API: vollständigen Vornamen holen (für identifizierte Person ohne Vorname in OA)."""
    if not orcid_url:
        return None
    oid = orcid_url.split("/")[-1]
    try:
        url = f"https://pub.orcid.org/v3.0/{oid}/personal-details"
        req = urllib.request.Request(url, headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=20) as r:
            d = json.loads(r.read().decode())
        name = (d.get("name") or {})
        given = ((name.get("given-names") or {}).get("value") or "").strip()
        if given:
            first = given.split()[0]
            init_letter = norm(initial.replace(".", "").split()[0])[:1] if initial else ""
            if not init_letter or norm(first)[:1] == init_letter:
                return first
    except Exception:
        return None
    return None


def resolve(init: str, nn: str, aff: str) -> dict:
    nn_norm = norm(nn)
    init_letter = norm(init.replace(".", "").split()[0])[:1] if init else ""

    # ---- STUFE 1: Affiliation-gefilterte Suche (präzise) ----
    inst_ids = resolve_institution_ids(aff)
    for iid in inst_ids:
        data = api_get("authors", {
            "filter": f"display_name.search:{nn},affiliations.institution.id:{iid}",
            "per-page": 10})
        cands = [c for c in data.get("results", [])
                 if nn_norm in norm(c.get("display_name", ""))]
        # Initial-konsistente Kandidaten
        ic = []
        for c in cands:
            fname = extract_full_first_name(c, init)
            dn_first = norm(c.get("display_name", "").split()[0]) if c.get("display_name") else ""
            # passt Initiale? (über fname ODER display_name-Initiale)
            ok = (fname and (not init_letter or norm(fname)[0] == init_letter)) or \
                 (not fname and init_letter and dn_first[:1] == init_letter)
            if ok:
                ic.append((c, fname))
        if len(ic) == 1:
            c, fname = ic[0]
            if not fname and c.get("orcid"):
                fname = fetch_orcid_first_name(c["orcid"], init)
            if fname:
                return {"fname": fname, "aff": 1.0, "orcid": c.get("orcid"),
                        "reason": f"affil-filter eindeutig (works={c.get('works_count')})"}

    # ---- STUFE 2: Nachname-Suche + Token-/Raw-Affil-Match (Fallback) ----
    data = api_get("authors", {"filter": f"display_name.search:{nn}", "per-page": 25})
    cands = data.get("results", [])

    scored = []
    for c in cands:
        dn_all = norm(c.get("display_name", "") + " " + " ".join(c.get("display_name_alternatives", [])))
        if nn_norm not in dn_all:
            continue
        fname = extract_full_first_name(c, init)
        if not fname:                       # ohne vollen Vornamen für uns wertlos
            continue
        if init_letter and norm(fname)[0] != init_letter:
            continue
        s = score_against_strings(aff, all_oa_institutions(c))
        scored.append({"name": c.get("display_name"), "fname": fname,
                       "works": c.get("works_count", 0), "aff": s,
                       "orcid": c.get("orcid"), "id": c.get("id")})

    if not scored:
        return {"fname": None, "aff": 0.0, "reason": "kein Kandidat mit Vorname"}

    scored.sort(key=lambda x: (x["aff"], x["works"]), reverse=True)
    top = scored[0]

    # raw_affiliation_strings nachladen, wenn unsicher
    if top["aff"] < RAW_THRESHOLD and top["id"]:
        raws = raw_affiliation_strings(top["id"], nn)
        if raws:
            top["aff"] = max(top["aff"], score_against_strings(aff, raws))
        scored.sort(key=lambda x: (x["aff"], x["works"]), reverse=True)
        top = scored[0]

    # Eindeutigkeit: alle Kandidaten mit aff>=ACCEPT müssen denselben Vornamen tragen
    strong = [s for s in scored if s["aff"] >= ACCEPT]
    distinct = {norm(s["fname"]).split()[0] for s in strong}
    if top["aff"] >= ACCEPT and len(distinct) == 1:
        return {"fname": top["fname"], "aff": top["aff"], "orcid": top["orcid"],
                "reason": f"eindeutig ({len(strong)} Kand., works={top['works']})"}
    if top["aff"] >= ACCEPT and len(distinct) > 1:
        return {"fname": None, "aff": top["aff"],
                "reason": f"mehrdeutig: {distinct}"}
    return {"fname": None, "aff": top["aff"], "reason": "Aff-Score zu niedrig"}


def main():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    rows = con.execute("""
        SELECT a.id, a.vorname AS init, a.nachname, g.neuer_vorname AS sonnet_gt,
          (SELECT i.name_de FROM autor_institutionen ai JOIN institutionen i ON i.id=ai.institut_id
           WHERE ai.autor_id=a.id LIMIT 1) AS aff
        FROM autoren a JOIN autor_vorname_groundtruth g ON g.autor_id=a.id
        ORDER BY a.nachname
    """).fetchall()

    stats = {"correct": 0, "wrong": 0, "no_match": 0, "gt_null": 0}
    print(f"{'Nachname':16} {'Init':6} {'Sonnet-GT':18} {'OpenAlex':16} {'Aff':5} Verdict")
    print("-" * 100)

    for r in rows:
        init, nn, gt, aff = r["init"], r["nachname"], r["sonnet_gt"], r["aff"]
        res = resolve(init, nn, aff)
        oa = res["fname"]
        ad = f"{res['aff']:.2f}"

        if oa:
            if gt is None:
                v = f"⚠ GT-NULL, OA={oa} ({res['reason']})"
            elif norm(oa) == norm(gt) or norm(gt).startswith(norm(oa) + " ") or norm(oa).startswith(norm(gt)):
                v = f"✓ KORREKT  ({res['reason']})"; stats["correct"] += 1
            else:
                v = f"✗ FALSCH war {gt}  ({res['reason']})"; stats["wrong"] += 1
        else:
            if gt is None:
                v = f"○ kein Match (GT NULL ok)"; stats["gt_null"] += 1
            else:
                v = f"○ kein Match ({res['reason']})"; stats["no_match"] += 1

        print(f"{nn:16} {init:6} {str(gt):18} {str(oa):16} {ad:5} {v}")

    print("-" * 100)
    solvable = stats["correct"] + stats["wrong"] + stats["no_match"]
    print(f"KORREKT: {stats['correct']}  FALSCH: {stats['wrong']}  "
          f"verpasst(GT≠NULL): {stats['no_match']}  GT-NULL-ok: {stats['gt_null']}")
    if solvable:
        print(f"Hit-Rate auf lösbaren: {stats['correct']}/{solvable} = "
              f"{100*stats['correct']/solvable:.0f}%   |  FEHLER: {stats['wrong']}")


if __name__ == "__main__":
    main()
