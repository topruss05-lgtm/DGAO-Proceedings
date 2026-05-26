<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

/**
 * Finds candidate clusters for Subagent-evaluated author merges.
 *
 * Heuristic: groups remaining (post-Phase-2) authors by their normalized
 * surname alone — vorname dropped. Two authors with the same surname-norm
 * but different alias_norms (different initials, e.g., "C." vs "Ch.") land
 * in the same cluster. Real-person clusters (e.g., 19 different "M. Müller"s)
 * also land together — Subagent decides.
 *
 * Returns: array of ['nachname_norm' => string, 'ids' => int[]] entries.
 * Only clusters with >= 2 distinct ids are returned.
 * IDs are sorted ascending for deterministic processing.
 */
function findFuzzyAuthorClusters(PDO $db): array
{
    // Group by normalized surname (use the same normalization rules as
    // normalizeForAliasMatch — but applied to nachname only).
    $rows = $db->query("SELECT id, nachname FROM autoren WHERE nachname != ''")
        ->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $key = normalizeForAliasMatch((string) $r['nachname']);
        if ($key === '') continue;
        $groups[$key][] = (int) $r['id'];
    }

    $clusters = [];
    foreach ($groups as $key => $ids) {
        $ids = array_values(array_unique($ids));
        if (count($ids) < 2) continue;
        sort($ids);
        $clusters[] = ['nachname_norm' => $key, 'ids' => $ids];
    }
    return $clusters;
}

/**
 * Renders per-author detail for a cluster — name, paper count, list of
 * affiliations (canonical names from institutionen), and the 3 most-recent
 * paper titles. This is what the Subagent uses to decide identity.
 *
 * Returns: array of associative rows, one per id, with keys:
 *   id, vorname, nachname, papers, affiliations, sample_titles
 *
 * Empty strings are returned for missing data (never NULL) to make
 * Subagent prompts predictable.
 */
function renderClusterForSubagent(PDO $db, array $ids): array
{
    if (count($ids) === 0) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare("
        SELECT a.id, a.vorname, a.nachname,
               (SELECT COUNT(*) FROM paper_autoren pa WHERE pa.autor_id = a.id) AS papers,
               COALESCE(
                   (SELECT GROUP_CONCAT(i.name_de, ' | ')
                    FROM autor_institutionen ai
                    JOIN institutionen i ON i.id = ai.institut_id
                    WHERE ai.autor_id = a.id), '') AS affiliations,
               COALESCE(
                   (SELECT GROUP_CONCAT(p.titel, ' || ')
                    FROM (
                        SELECT p2.titel, p2.tagung_nummer
                        FROM paper_autoren pa2
                        JOIN papers p2 ON p2.id = pa2.paper_id
                        WHERE pa2.autor_id = a.id
                        ORDER BY p2.tagung_nummer DESC
                        LIMIT 3
                    ) p), '') AS sample_titles
        FROM autoren a
        WHERE a.id IN ($placeholders)
        ORDER BY a.id ASC
    ");
    $stmt->execute($ids);
    return array_map(function (array $r): array {
        return [
            'id'            => (int) $r['id'],
            'vorname'       => (string) ($r['vorname'] ?? ''),
            'nachname'      => (string) ($r['nachname'] ?? ''),
            'papers'        => (int) ($r['papers'] ?? 0),
            'affiliations'  => (string) ($r['affiliations'] ?? ''),
            'sample_titles' => (string) ($r['sample_titles'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
