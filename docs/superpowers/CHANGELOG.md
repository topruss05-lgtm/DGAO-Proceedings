
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

## 2026-05-26: Manuelle Unmerges nach Borderline-Review

Review der 19 Phase-3-Subagent-Merges mit Konfidenz 0.90-0.92 (Opus 4.7):
- 17 von 19 eindeutig korrekt
- **2 unmerged** und als separate Personen wiederhergestellt:
  - **Matusevich** (cluster_0264): V. Matusevich (id 294, Volodymyr) ≠ A. Matusevich (id 897, Alexander), beide FSU Jena Angewandte Optik. Subagent hatte sich in seinem eigenen `reason` widersprochen, der Output war inkonsistent. 13 Papers (5 nur-A + 8 mit beiden als Co-Autoren) auf id 897 zurückübertragen.
  - **Beckmann** (cluster_0017): C. Beckmann (id 8324, Laser-Laboratorium Göttingen e.V.) ≠ C. M. Beckmann (id 9046, Institut für Nanophotonik Göttingen e.V.) — zwei verschiedene Forschungseinrichtungen in derselben Stadt. 1 Paper auf id 8324 zurück.
- Live-DB nach Unmerge: 4548 Autoren (4546 + 2), 631 Redirects (633 - 2)
- Backup: database/backups/proceedings_pre_unmerge_*.db
