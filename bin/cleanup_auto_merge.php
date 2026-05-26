<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

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
