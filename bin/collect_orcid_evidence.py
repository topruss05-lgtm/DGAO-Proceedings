#!/usr/bin/env python3
"""
ORCID-personal-details für alle ORCIDs aus dem OpenAlex-Cache.

Pro ORCID: given-names, family-name, credit-name, other-names, biography.
Output: bin/.cache/orcid_evidence.jsonl
"""
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OA_CACHE = ROOT / "bin" / ".cache" / "openalex_evidence.jsonl"
OUT = ROOT / "bin" / ".cache" / "orcid_evidence.jsonl"


def fetch(oid: str) -> dict:
    url = f"https://pub.orcid.org/v3.0/{oid}/personal-details"
    req = urllib.request.Request(url, headers={"Accept": "application/json",
                                               "User-Agent": "DGaO-cleanup (topruss05@gmail.com)"})
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return {"_not_found": True}
            if attempt == 2:
                return {"_error": str(e)}
            time.sleep(2 ** attempt)
        except Exception as e:
            if attempt == 2:
                return {"_error": str(e)}
            time.sleep(2 ** attempt)
    return {}


def extract(d: dict) -> dict:
    n = d.get("name") or {}
    given = ((n.get("given-names") or {}) or {}).get("value")
    family = ((n.get("family-name") or {}) or {}).get("value")
    credit = ((n.get("credit-name") or {}) or {}).get("value")
    others = []
    on = d.get("other-names") or {}
    for o in on.get("other-name") or []:
        c = o.get("content")
        if c:
            others.append(c)
    bio = ((d.get("biography") or {}) or {}).get("content")
    return {
        "given_names": given, "family_name": family, "credit_name": credit,
        "other_names": others, "biography": bio[:300] if bio else None,
    }


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--resume", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    OUT.parent.mkdir(exist_ok=True)

    # ORCIDs aus OpenAlex-Cache sammeln (kann live wachsen)
    orcids: set = set()
    if OA_CACHE.exists():
        with OA_CACHE.open() as f:
            for line in f:
                try:
                    d = json.loads(line)
                except Exception:
                    continue
                for c in d.get("candidates") or []:
                    o = c.get("orcid")
                    if o:
                        orcids.add(o.split("/")[-1])

    done = set()
    if args.resume and OUT.exists():
        with OUT.open() as f:
            for line in f:
                try:
                    done.add(json.loads(line)["orcid"])
                except Exception:
                    pass
        print(f"  resume: {len(done)} bereits", file=sys.stderr)

    todo = [o for o in orcids if o not in done]
    if args.limit:
        todo = todo[:args.limit]
    print(f"  ORCIDs gesamt: {len(orcids)}, zu fetchen: {len(todo)}", file=sys.stderr)

    t0 = time.time()
    with OUT.open("a") as f:
        for i, oid in enumerate(todo, 1):
            data = fetch(oid)
            out = {"orcid": oid}
            if "_error" in data:
                out["error"] = data["_error"]
            elif "_not_found" in data:
                out["not_found"] = True
            else:
                out.update(extract(data))
            f.write(json.dumps(out, ensure_ascii=False) + "\n")
            f.flush()
            if i % 100 == 0:
                el = time.time() - t0
                print(f"  ...{i}/{len(todo)}  ({i/el:.1f}/s)", file=sys.stderr)
    print(f"Fertig: {len(todo)}, Output: {OUT}", file=sys.stderr)


if __name__ == "__main__":
    main()
