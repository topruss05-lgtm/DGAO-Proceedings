<?php
declare(strict_types=1);

// Nimmt die merge_review_queue durch und führt Sub-Merges aus, die innerhalb
// von keep_separate-Verdicts vom Subagent identifiziert wurden. Diese wurden
// vom ursprünglichen Process-Schritt ignoriert, weil das Top-Level-Verdict
// keep_separate war.

require_once __DIR__ . '/../public/helpers.php';
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/../public/db.php';
require_once __DIR__ . '/cleanup_auto_merge.php';

$dbPath  = $argv[1] ?? __DIR__ . '/../public/data/proceedings.db';
$authConf = (float) ($argv[2] ?? 0.90);
$instConf = (float) ($argv[3] ?? 0.85);

if (!is_file($dbPath)) { fwrite(STDERR, "DB nicht gefunden: $dbPath\n"); exit(1); }

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');

$stats = ['auth_merged' => 0, 'inst_merged' => 0, 'skipped_low_conf' => 0, 'errors' => 0];

$rows = $pdo->query("
    SELECT id, kind, cluster_json, verdict_json
    FROM merge_review_queue
    WHERE status = 'pending'
")->fetchAll();

printf("Bearbeite %d Queue-Einträge...\n", count($rows));

foreach ($rows as $row) {
    $verdict = json_decode($row['verdict_json'], true);
    $confidence = (float) ($verdict['confidence'] ?? 0);
    $groups = $verdict['groups'] ?? [];

    // Sub-Groups mit ≥2 IDs extrahieren
    $subMerges = array_values(array_filter(
        array_map(fn($g) => array_values(array_unique(array_map('intval', (array)$g))), $groups),
        fn($g) => count($g) >= 2
    ));
    if (count($subMerges) === 0) continue;

    $threshold = $row['kind'] === 'author' ? $authConf : $instConf;
    if ($confidence < $threshold) { $stats['skipped_low_conf']++; continue; }

    if ($row['kind'] === 'author') {
        foreach ($subMerges as $ids) {
            try {
                mergeAuthorCluster($pdo, $ids);
                $stats['auth_merged']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                fwrite(STDERR, "Autor-Merge-Fehler (ids " . implode(',', $ids) . "): " . $e->getMessage() . "\n");
            }
        }
    } else {
        // Institution merge: Anker = niedrigste ID, Aliase + autor_institutionen umhängen
        // (gleiche Logik wie processInstitutionVerdicts, aber ohne canonical-Update)
        foreach ($subMerges as $gi => $ids) {
            sort($ids);
            $anchor = $ids[0];
            $duplicates = array_slice($ids, 1);
            $dupPh = implode(',', array_fill(0, count($duplicates), '?'));

            $pdo->beginTransaction();
            try {
                // Falls eine canonical-Information für diese Sub-Gruppe vorliegt: anwenden
                $canonicals = $verdict['canonical'] ?? [];
                $can = $canonicals[$gi] ?? null;
                if (is_array($can) && !empty($can['name_de'])) {
                    $pdo->prepare("
                        UPDATE institutionen
                        SET name_de = COALESCE(NULLIF(:nd,''), name_de),
                            name_en = COALESCE(NULLIF(:ne,''), name_en),
                            kuerzel = COALESCE(NULLIF(:k,''), kuerzel),
                            universitaet = COALESCE(NULLIF(:u,''), universitaet),
                            ort = COALESCE(NULLIF(:o,''), ort),
                            land = COALESCE(NULLIF(:l,''), land),
                            ror_id = COALESCE(NULLIF(:r,''), ror_id)
                        WHERE id = :id
                    ")->execute([
                        ':nd' => $can['name_de'] ?? '',
                        ':ne' => $can['name_en'] ?? '',
                        ':k'  => $can['kuerzel'] ?? '',
                        ':u'  => $can['universitaet'] ?? '',
                        ':o'  => $can['ort'] ?? '',
                        ':l'  => $can['land'] ?? '',
                        ':r'  => $can['ror_id'] ?? '',
                        ':id' => $anchor,
                    ]);
                }

                $pdo->prepare("UPDATE OR IGNORE institut_aliase SET institut_id = ? WHERE institut_id IN ($dupPh)")
                    ->execute([$anchor, ...$duplicates]);
                $pdo->prepare("DELETE FROM institut_aliase WHERE institut_id IN ($dupPh)")
                    ->execute($duplicates);

                // autor_institutionen: INSERT OR IGNORE + DELETE Pattern (siehe Fix Phase 4)
                $pdo->prepare("
                    INSERT OR IGNORE INTO autor_institutionen (autor_id, institut_id, ist_aktuell)
                    SELECT DISTINCT autor_id, ?, MAX(ist_aktuell)
                    FROM autor_institutionen
                    WHERE institut_id IN ($dupPh)
                    GROUP BY autor_id
                ")->execute([$anchor, ...$duplicates]);
                $pdo->prepare("DELETE FROM autor_institutionen WHERE institut_id IN ($dupPh)")
                    ->execute($duplicates);

                $pdo->prepare("DELETE FROM institutionen WHERE id IN ($dupPh)")->execute($duplicates);
                $pdo->commit();
                $stats['inst_merged']++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $stats['errors']++;
                fwrite(STDERR, "Inst-Merge-Fehler (ids " . implode(',', $ids) . "): " . $e->getMessage() . "\n");
            }
        }
    }

    // Queue-Eintrag als approved markieren — die Sub-Merges sind angewendet
    $pdo->prepare("UPDATE merge_review_queue SET status = 'approved' WHERE id = ?")
        ->execute([$row['id']]);
}

printf("Fertig: Autoren-Sub-Merges %d, Institut-Sub-Merges %d, übersprungen (low conf) %d, Fehler %d\n",
    $stats['auth_merged'], $stats['inst_merged'], $stats['skipped_low_conf'], $stats['errors']);
