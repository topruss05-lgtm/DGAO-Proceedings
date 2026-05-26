
## 2026-05-26: Phase 2 Auto-Merge auf lokaler DB

- Schema v7 → v8 (autor_aliase, institutionen, institut_aliase, autor_institutionen, autor_id_redirects)
- 549 Records regelbasiert gemerged (5184 → 4635 Autoren)
- 549 Redirect-Einträge angelegt
- Pruß-Cluster konsolidiert: 5 IDs → 1 Anker (C. Pruß, 49 Papers); Ch. Pruß (2 Papers) noch separat — Subagent-Job in Phase 3
- Backup vor Migration: database/backups/proceedings_pre_phase2_2026-05-26_1717.db

## 2026-05-26: Phase 3 Subagent-Bewertung (Autoren-Cluster)

- 472 Cluster-Kandidaten von Sonnet-Subagents bewertet (10 Batch-Orchestrators parallel)
- Verdicts: 91 merge, 370 keep_separate, 11 unsure
- Auto-merged (Konfidenz ≥ 0.9): 87 zusätzliche Records
- Live-DB: 4635 → 4546 Autoren (-89), Gesamt seit Phase 2: 5184 → 4546 (-638, ~12%)
- Redirects: 549 → 633
- 386 Vorschläge in merge_review_queue für Admin-Review (keep_separate-Verdicts + niedrig-Konfidenz-merges + unsure)
- Pruss-Cluster konsolidiert: 5184/5 IDs → 1 ID (1466 C. Pruß, 51 Papers)
- Backup vor Phase 3: database/backups/proceedings_pre_phase3_2026-05-26_1747.db
- Beispiele für erfolgreiche Subagent-Merges: Christian Koos / C. Koos (KIT), Meint Smit / M.K. Smit / M. Smit (TU Eindhoven), Andrea Toulouse / A. Toulouse, Dr. E. Willenborg / E. Willenborg (Fraunhofer ILT), Hans Peter Herzig / H.-P. Herzig (EPFL), Franco Zappa / F. Zappa (PoliMi)
