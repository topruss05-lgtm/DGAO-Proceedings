<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

// ---------------------------------------------------------------------------
// CLI entry point — only runs when invoked directly, never when require'd
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    require_once __DIR__ . '/../public/config.php';
    require_once __DIR__ . '/../public/db.php';

    $dryRun = false;
    $dbPath = null;

    $args = array_slice($argv, 1);
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } else {
            $dbPath = $arg;
        }
    }

    if ($dbPath === null) {
        $dbPath = __DIR__ . '/../public/data/proceedings.db';
    }

    if (!is_file($dbPath)) {
        fwrite(STDERR, "FEHLER: Datenbank nicht gefunden: $dbPath\n");
        exit(1);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    runMigrations($pdo);

    $clusters = findAuthorAutoMergeClusters($pdo);

    $totalDups = 0;
    foreach ($clusters as $c) {
        $totalDups += count($c['ids']) - 1;
    }

    printf("Gefunden: %d Cluster, %d Duplikate insgesamt\n", count($clusters), $totalDups);

    if ($dryRun) {
        echo "Top 10 Cluster:\n";
        foreach (array_slice($clusters, 0, 10) as $c) {
            printf("  %-30s  \xe2\x86\x92  %d IDs: %s\n", $c['key'], count($c['ids']), implode(', ', $c['ids']));
        }
        echo "\n(dry-run, nichts gemerged)\n";
        exit(0);
    }

    // Real run
    $merged = 0;
    $errors = 0;
    foreach ($clusters as $c) {
        try {
            mergeAuthorCluster($pdo, $c['ids']);
            $merged += count($c['ids']) - 1;
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, "FEHLER bei {$c['key']}: {$e->getMessage()}\n");
        }
    }

    printf("Fertig: %d Records gemerged, %d Fehler\n", $merged, $errors);
    exit($errors > 0 ? 1 : 0);
}

/**
 * Identifies author clusters eligible for rule-based auto-merge.
 *
 * Criterion: identical alias_norm — same normalized string after star-strip,
 * lowercase, ß→ss, diacritics→ASCII, punctuation/whitespace removed. When
 * two autoren records share an alias_norm, they're almost certainly the
 * same person with different surface forms in different PDFs.
 *
 * Returns an array of clusters. Each cluster:
 *   ['key' => string, 'ids' => int[]]
 *
 * Only clusters with 2+ ids are returned (singletons aren't merge candidates).
 * Ids are unique and sorted ascending for deterministic downstream merging.
 */
function findAuthorAutoMergeClusters(PDO $db): array
{
    $sql = "
        SELECT alias_norm AS key, GROUP_CONCAT(autor_id, ',') AS ids
        FROM autor_aliase
        GROUP BY alias_norm
        HAVING COUNT(DISTINCT autor_id) > 1
        ORDER BY alias_norm
    ";
    $clusters = [];
    foreach ($db->query($sql) as $row) {
        $ids = array_values(array_unique(array_map('intval', explode(',', $row['ids']))));
        sort($ids);
        $clusters[] = ['key' => (string) $row['key'], 'ids' => $ids];
    }
    return $clusters;
}

/**
 * Merges a cluster of duplicate author IDs into a single anchor record.
 *
 * Anchor selection: the ID whose paper-authorships span the latest tagung
 * (highest MAX(papers.tagung_nummer)). Tie-break by lowest ID for determinism.
 *
 * Operations (atomic transaction):
 *   1. Move paper_autoren links to anchor (dedup PK conflicts first)
 *   2. Move autor_institutionen links to anchor (dedup PK conflicts first)
 *   3. Recompute ist_aktuell for anchor (one row per author at most)
 *   4. Move autor_aliase to anchor (UNIQUE swallows dups via OR IGNORE)
 *   5. Write autor_id_redirects entries (alte_id -> neue_id = anchor)
 *   6. DELETE duplicate rows from autoren
 *
 * @param int[] $ids Cluster ids, must be >= 2 entries.
 * @return int The chosen anchor id.
 * @throws InvalidArgumentException If fewer than 2 ids given.
 */
function mergeAuthorCluster(PDO $db, array $ids): int
{
    if (count($ids) < 2) {
        throw new InvalidArgumentException('mergeAuthorCluster needs >= 2 ids');
    }
    sort($ids);

    $db->beginTransaction();
    try {
        // -- 1. Anchor selection: highest max tagung_nummer, then lowest id
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT a.id,
                   COALESCE((SELECT MAX(p.tagung_nummer)
                             FROM paper_autoren pa
                             JOIN papers p ON p.id = pa.paper_id
                             WHERE pa.autor_id = a.id), 0) AS max_tg
            FROM autoren a
            WHERE a.id IN ($placeholders)
            ORDER BY max_tg DESC, a.id ASC
        ");
        $stmt->execute($ids);
        $anchor = (int) $stmt->fetch(PDO::FETCH_ASSOC)['id'];

        $duplicates      = array_values(array_filter($ids, fn($id) => $id !== $anchor));
        $dupPlaceholders = implode(',', array_fill(0, count($duplicates), '?'));

        // -- 2. paper_autoren: delete conflicts, then update rest
        $db->prepare("
            DELETE FROM paper_autoren
            WHERE autor_id IN ($dupPlaceholders)
              AND paper_id IN (SELECT paper_id FROM paper_autoren WHERE autor_id = ?)
        ")->execute([...$duplicates, $anchor]);

        $db->prepare("
            UPDATE paper_autoren SET autor_id = ?
            WHERE autor_id IN ($dupPlaceholders)
        ")->execute([$anchor, ...$duplicates]);

        // -- 3. autor_institutionen: same dedup pattern
        $db->prepare("
            DELETE FROM autor_institutionen
            WHERE autor_id IN ($dupPlaceholders)
              AND institut_id IN (SELECT institut_id FROM autor_institutionen WHERE autor_id = ?)
        ")->execute([...$duplicates, $anchor]);

        $db->prepare("
            UPDATE autor_institutionen SET autor_id = ?
            WHERE autor_id IN ($dupPlaceholders)
        ")->execute([$anchor, ...$duplicates]);

        // -- 4. ist_aktuell recompute on the anchor
        $db->prepare("UPDATE autor_institutionen SET ist_aktuell = 0 WHERE autor_id = ?")->execute([$anchor]);
        $best = $db->prepare("
            SELECT ai.institut_id, MAX(p.tagung_nummer) AS max_tg
            FROM autor_institutionen ai
            JOIN paper_autoren pa ON pa.autor_id = ai.autor_id
            JOIN papers p ON p.id = pa.paper_id
            WHERE ai.autor_id = ?
            GROUP BY ai.institut_id
            ORDER BY max_tg DESC, ai.institut_id ASC
            LIMIT 1
        ");
        $best->execute([$anchor]);
        $row = $best->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare("
                UPDATE autor_institutionen SET ist_aktuell = 1
                WHERE autor_id = ? AND institut_id = ?
            ")->execute([$anchor, (int) $row['institut_id']]);
        } else {
            // Anchor has no paper authorships — fall back to lowest institut_id (deterministic).
            $fallback = $db->prepare("
                SELECT institut_id FROM autor_institutionen
                WHERE autor_id = ? ORDER BY institut_id ASC LIMIT 1
            ");
            $fallback->execute([$anchor]);
            $fid = $fallback->fetchColumn();
            if ($fid !== false) {
                $db->prepare("
                    UPDATE autor_institutionen SET ist_aktuell = 1
                    WHERE autor_id = ? AND institut_id = ?
                ")->execute([$anchor, (int) $fid]);
            }
        }

        // -- 5. Aliase: UPDATE OR IGNORE handles UNIQUE conflicts; then DELETE leftovers
        $db->prepare("
            UPDATE OR IGNORE autor_aliase SET autor_id = ?
            WHERE autor_id IN ($dupPlaceholders)
        ")->execute([$anchor, ...$duplicates]);

        $db->prepare("
            DELETE FROM autor_aliase WHERE autor_id IN ($dupPlaceholders)
        ")->execute($duplicates);

        // -- 6. Redirect map
        $insRedir = $db->prepare(
            "INSERT OR REPLACE INTO autor_id_redirects (alte_id, neue_id) VALUES (?, ?)"
        );
        foreach ($duplicates as $d) {
            $insRedir->execute([$d, $anchor]);
        }

        // -- 7. Drop duplicate autoren rows (must be last: FK constraint)
        $db->prepare("DELETE FROM autoren WHERE id IN ($dupPlaceholders)")->execute($duplicates);

        $db->commit();
        return $anchor;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
