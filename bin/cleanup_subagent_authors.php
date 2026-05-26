<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    require_once __DIR__ . '/../public/config.php';
    require_once __DIR__ . '/../public/db.php';

    $cmd    = $argv[1] ?? 'help';
    $dbPath = $argv[2] ?? __DIR__ . '/../public/data/proceedings.db';
    $tmpDir = __DIR__ . '/../tmp/subagent';

    if (!in_array($cmd, ['export', 'process'], true)) {
        echo "Usage:\n";
        echo "  php bin/cleanup_subagent_authors.php export  [DB-Pfad]\n";
        echo "  php bin/cleanup_subagent_authors.php process [DB-Pfad] [--threshold=0.9]\n";
        exit($cmd === 'help' ? 0 : 1);
    }

    if (!is_file($dbPath)) {
        fwrite(STDERR, "DB nicht gefunden: $dbPath\n");
        exit(1);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    runMigrations($pdo);

    if ($cmd === 'export') {
        $n = exportClustersForSubagents($pdo, $tmpDir);
        printf("%d Cluster nach %s/ exportiert\n", $n, $tmpDir);
    } else {
        $threshold = 0.9;
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--threshold=([0-9.]+)$/', $arg, $m)) {
                $threshold = (float) $m[1];
            }
        }
        $stats = processSubagentVerdicts($pdo, $tmpDir, $threshold);
        printf("Auto-gemerged: %d, in Queue: %d, Fehler: %d\n",
            $stats['auto_merged'], $stats['queued'], $stats['errors']);
    }
    exit(0);
}

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

/**
 * Exports all fuzzy author cluster candidates as JSON files for Subagent
 * evaluation. One file per cluster: cluster_NNNN.json, zero-padded to 4
 * digits for sorted listing.
 *
 * Wipes any existing cluster_*.json or verdict_*.json files in $outDir
 * first (the directory is treated as ephemeral scratch space).
 *
 * Returns the number of cluster files written.
 *
 * Schema of each cluster file:
 *   {
 *     "cluster_id": <int>,
 *     "nachname_norm": <string>,
 *     "candidates": [
 *       {"id":<int>, "name":<string>, "papers":<int>,
 *        "affiliations":<string>, "sample_titles":<string>},
 *       ...
 *     ]
 *   }
 */
function exportClustersForSubagents(PDO $db, string $outDir): int
{
    if (!is_dir($outDir)) {
        if (!mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Konnte Verzeichnis nicht anlegen: $outDir");
        }
    }
    // Clean slate — remove old cluster/verdict files
    foreach (glob($outDir . '/cluster_*.json') ?: [] as $f) @unlink($f);
    foreach (glob($outDir . '/verdict_*.json') ?: [] as $f) @unlink($f);

    $candidates = findFuzzyAuthorClusters($db);
    $i = 0;
    foreach ($candidates as $c) {
        $i++;
        $detail = renderClusterForSubagent($db, $c['ids']);
        $payload = [
            'cluster_id'    => $i,
            'nachname_norm' => $c['nachname_norm'],
            'candidates'    => array_map(fn(array $r): array => [
                'id'            => $r['id'],
                'name'          => trim(($r['vorname'] !== '' ? $r['vorname'] . ' ' : '') . $r['nachname']),
                'papers'        => $r['papers'],
                'affiliations'  => $r['affiliations'],
                'sample_titles' => $r['sample_titles'],
            ], $detail),
        ];
        $path = sprintf('%s/cluster_%04d.json', $outDir, $i);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Konnte Datei nicht schreiben: $path");
        }
    }
    return $i;
}

/**
 * Processes Subagent verdicts: for each verdict_NNNN.json file present,
 * decides whether to auto-merge (high confidence) or queue for manual review.
 *
 * Auto-merge fires when verdict.verdict === 'merge' AND verdict.confidence >= $threshold.
 * For each group in verdict.groups (each is an array of autor_ids), calls
 * mergeAuthorCluster() to atomically merge.
 *
 * Lower-confidence verdicts ('keep_separate', 'unsure', or 'merge' below
 * threshold) land in the merge_review_queue table for admin disposition.
 *
 * Creates merge_review_queue table on first run if missing.
 *
 * Returns ['auto_merged' => int, 'queued' => int, 'errors' => int].
 *
 * Verdict file schema (Subagent must write):
 *   {
 *     "verdict": "merge" | "keep_separate" | "unsure",
 *     "confidence": <float 0..1>,
 *     "groups": [[id, id, ...], ...],   // ignored unless verdict === 'merge'
 *     "reason": <string>
 *   }
 */
function processSubagentVerdicts(PDO $db, string $verdictDir, float $threshold = 0.9): array
{
    require_once __DIR__ . '/cleanup_auto_merge.php';

    $stats = ['auto_merged' => 0, 'queued' => 0, 'errors' => 0];

    // Review-Queue-Tabelle bei Bedarf anlegen (idempotent)
    $db->exec("CREATE TABLE IF NOT EXISTS merge_review_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL DEFAULT 'author' CHECK (kind IN ('author','institution')),
        cluster_json TEXT NOT NULL,
        verdict_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    foreach (glob($verdictDir . '/verdict_*.json') ?: [] as $vf) {
        try {
            $verdict = json_decode((string) file_get_contents($vf), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "Ungueltiges JSON in $vf: " . $e->getMessage() . "\n");
            continue;
        }

        $cf = str_replace('verdict_', 'cluster_', $vf);
        if (!is_file($cf)) {
            $stats['errors']++;
            fwrite(STDERR, "Kein Cluster-File zu $vf (erwartet: $cf)\n");
            continue;
        }
        $cluster = json_decode((string) file_get_contents($cf), true, 32);

        $isMerge   = ($verdict['verdict'] ?? '') === 'merge';
        $isAutoOk  = $isMerge && (float) ($verdict['confidence'] ?? 0) >= $threshold;

        if ($isAutoOk) {
            // Each group in verdict.groups is merged independently
            foreach ($verdict['groups'] ?? [] as $group) {
                $ids = array_values(array_unique(array_map('intval', (array) $group)));
                if (count($ids) < 2) continue;
                try {
                    mergeAuthorCluster($db, $ids);
                    $stats['auto_merged']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    fwrite(STDERR, "Merge-Fehler in cluster {$cluster['cluster_id']} (ids " . implode(',', $ids) . "): " . $e->getMessage() . "\n");
                }
            }
        } else {
            try {
                $db->prepare(
                    "INSERT INTO merge_review_queue (kind, cluster_json, verdict_json) VALUES ('author', ?, ?)"
                )->execute([
                    json_encode($cluster, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $stats['queued']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                fwrite(STDERR, "Queue-Fehler in cluster {$cluster['cluster_id']}: " . $e->getMessage() . "\n");
            }
        }
    }
    return $stats;
}
