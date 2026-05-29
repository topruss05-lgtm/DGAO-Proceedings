#!/usr/bin/env python3
"""
Cross-Cluster-Institut-Dedup. Konservativ.

Strategie:
  1. ROR-Cluster bilden: alle Institute mit gleicher ror_id
  2. Pro Cluster: Master = derjenige mit MEISTEN paper_autor_institutionen
                  ODER der ROR-1:1-Treffer aus Phase 1 (älteste id)
  3. Andere Cluster-Mitglieder: nur mergen bei Token-Overlap >= 70%
     mit dem Master. Sonst ror_id=NULL setzen (falsches ROR-Mapping).
  4. Beim Merge:
     - alle paper_autor_institutionen.institut_id auf master
     - alle autor_institutionen.institut_id auf master (mit INSERT OR IGNORE)
     - alle institut_aliase.institut_id auf master (mit INSERT OR IGNORE)
     - alten Eintrag DELETE
"""
import re
import sqlite3
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path

DB = Path(__file__).resolve().parents[1] / "public" / "data" / "proceedings.db"


def norm(s: str) -> str:
    if not s:
        return ""
    s = s.replace("ß", "ss").replace("ẞ", "SS")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]+", " ", s.lower()).strip()


def token_overlap(a: str, b: str) -> float:
    """Symmetrisches Jaccard auf Content-Tokens."""
    STOP = {"institut", "institute", "university", "universitat", "of", "for",
            "and", "fur", "fuer", "und", "der", "die", "das", "the", "of"}
    ta = {t for t in norm(a).split() if len(t) > 2 and t not in STOP}
    tb = {t for t in norm(b).split() if len(t) > 2 and t not in STOP}
    if not ta or not tb:
        return 0.0
    return len(ta & tb) / min(len(ta), len(tb))


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--min-overlap", type=float, default=0.70)
    args = ap.parse_args()

    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")

    rows = con.execute("""
        SELECT id, name_de, ror_id,
               (SELECT COUNT(*) FROM paper_autor_institutionen WHERE institut_id=institutionen.id) AS n_pai,
               (SELECT COUNT(*) FROM autor_institutionen      WHERE institut_id=institutionen.id) AS n_ai
        FROM institutionen
        WHERE ror_id IS NOT NULL
    """).fetchall()

    by_ror = defaultdict(list)
    for r in rows:
        by_ror[r["ror_id"].rstrip("/").lower()].append(dict(r))

    stats = {"merged": 0, "ror_cleared": 0, "clusters_processed": 0,
             "deleted_orphan": 0}
    log = []
    for ror, group in by_ror.items():
        if len(group) < 2:
            continue
        stats["clusters_processed"] += 1
        # Master: der mit meisten paper_autor_inst (bei Gleichstand: kleinste id)
        group.sort(key=lambda x: (-x["n_pai"], x["id"]))
        master = group[0]
        for other in group[1:]:
            ov = token_overlap(master["name_de"], other["name_de"])
            if ov >= args.min_overlap:
                # MERGE
                log.append({
                    "ror": ror, "master_id": master["id"],
                    "from_id": other["id"], "overlap": round(ov, 2),
                    "master_name": master["name_de"][:60],
                    "from_name": other["name_de"][:60],
                })
                if args.apply:
                    # paper_autor_institutionen: INSERT OR IGNORE (PK Konflikt vermeiden) + DELETE
                    con.execute("""
                        INSERT OR IGNORE INTO paper_autor_institutionen
                          (paper_id, autor_id, institut_id, quelle)
                        SELECT paper_id, autor_id, ?, quelle
                        FROM paper_autor_institutionen WHERE institut_id=?
                    """, (master["id"], other["id"]))
                    con.execute("DELETE FROM paper_autor_institutionen WHERE institut_id=?",
                                (other["id"],))
                    # autor_institutionen
                    con.execute("""
                        INSERT OR IGNORE INTO autor_institutionen (autor_id, institut_id, ist_aktuell)
                        SELECT autor_id, ?, MAX(ist_aktuell)
                        FROM autor_institutionen WHERE institut_id=?
                        GROUP BY autor_id
                    """, (master["id"], other["id"]))
                    con.execute("DELETE FROM autor_institutionen WHERE institut_id=?",
                                (other["id"],))
                    # institut_aliase
                    con.execute("""
                        INSERT OR IGNORE INTO institut_aliase
                          (institut_id, alias_text, alias_norm)
                        SELECT ?, alias_text, alias_norm
                        FROM institut_aliase WHERE institut_id=?
                    """, (master["id"], other["id"]))
                    con.execute("DELETE FROM institut_aliase WHERE institut_id=?",
                                (other["id"],))
                    # Ergänze den alten Namen als Alias des Masters
                    try:
                        con.execute("""
                            INSERT OR IGNORE INTO institut_aliase
                              (institut_id, alias_text, alias_norm)
                            VALUES (?, ?, ?)
                        """, (master["id"], other["name_de"], norm(other["name_de"])))
                    except sqlite3.IntegrityError:
                        pass
                    # Children (parent_id) auf master umhängen
                    con.execute("UPDATE institutionen SET parent_id=? WHERE parent_id=?",
                                (master["id"], other["id"]))
                    # Audit + DELETE (audit_log: institut_id PK -> INSERT OR REPLACE)
                    con.execute("""
                        INSERT OR REPLACE INTO institut_audit_log
                          (institut_id, action, notes, source, duplicate_of)
                        VALUES (?, 'merge_into', ?, 'ror_cluster', ?)
                    """, (other["id"], f"ror={ror} overlap={ov:.2f}", master["id"]))
                    con.execute("DELETE FROM institutionen WHERE id=?", (other["id"],))
                stats["merged"] += 1
            else:
                # Kein Merge: ror_id auf NULL setzen (falsches ROR-Mapping)
                log.append({
                    "ror": ror, "master_id": master["id"],
                    "from_id": other["id"], "overlap": round(ov, 2),
                    "master_name": master["name_de"][:60],
                    "from_name": other["name_de"][:60],
                    "action": "ror_cleared",
                })
                if args.apply:
                    con.execute("UPDATE institutionen SET ror_id=NULL WHERE id=?",
                                (other["id"],))
                stats["ror_cleared"] += 1

    if args.apply:
        # Orphan-Cleanup: institutionen ohne paper_autor_inst, ohne autor_inst,
        # ohne FK-Referenzen
        cur = con.execute("""
            DELETE FROM institutionen
            WHERE id NOT IN (SELECT institut_id FROM paper_autor_institutionen)
              AND id NOT IN (SELECT institut_id FROM autor_institutionen)
              AND id NOT IN (SELECT parent_id FROM institutionen WHERE parent_id IS NOT NULL)
        """)
        stats["deleted_orphan"] = cur.rowcount
        con.commit()

    print(f"\n--- Institut-Dedup {'APPLY' if args.apply else 'DRY-RUN'} ---")
    for k, v in stats.items():
        print(f"  {k:24}  {v}")
    print(f"\nBeispiele (erste 8):")
    for l in log[:8]:
        action = l.get("action", "merge" if l["overlap"] >= args.min_overlap else "ror_cleared")
        print(f"  [{action}] ov={l['overlap']:.2f}  master={l['master_id']:5} '{l['master_name'][:40]}'")
        print(f"                       from={l['from_id']:5} '{l['from_name'][:40]}'")


if __name__ == "__main__":
    main()
