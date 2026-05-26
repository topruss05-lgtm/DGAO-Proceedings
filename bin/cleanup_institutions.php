<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require_once __DIR__ . '/../public/config.php';
    require_once __DIR__ . '/../public/db.php';

    $cmd    = $argv[1] ?? 'help';
    $dbPath = $argv[2] ?? __DIR__ . '/../public/data/proceedings.db';
    $tmpDir = __DIR__ . '/../tmp/institutions';

    if (!in_array($cmd, ['export', 'process'], true)) {
        echo "Usage:\n  php bin/cleanup_institutions.php export  [DB-Pfad]\n  php bin/cleanup_institutions.php process [DB-Pfad] [--threshold=0.85]\n";
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
        $n = exportInstitutionClusters($pdo, $tmpDir);
        printf("%d Institut-Cluster nach %s/ exportiert\n", $n, $tmpDir);
    } else {
        $threshold = 0.85;
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--threshold=([0-9.]+)$/', $arg, $m)) $threshold = (float)$m[1];
        }
        $stats = processInstitutionVerdicts($pdo, $tmpDir, $threshold);
        printf("Gemerged: %d, Queue: %d, Fehler: %d\n", $stats['merged'], $stats['queued'], $stats['errors']);
    }
    exit(0);
}

/**
 * Token-basiertes Clustering: Institutionen, deren normalisierter name_de
 * mindestens 3 gemeinsame signifikante Tokens (>4 Zeichen, ohne Stopwoerter)
 * teilen, werden zu einem Cluster zusammengefasst.
 *
 * Greedy Single-Pass: jede Institution wird dem ersten passenden Cluster
 * zugeordnet. Gibt nur Cluster mit >= 2 Mitgliedern zurueck.
 *
 * Returns: array<int[]> -- jedes Element ist ein aufsteigend sortiertes
 * Array von institutionen.id-Werten.
 */
function findInstitutionClusters(PDO $db): array
{
    // Deutsche + englische haeufige Stopwoerter sowie gattungsbildende Begriffe,
    // die fast jede Institution enthaelt und daher keine echte Gemeinsamkeit
    // signalisieren (z.B. 'institut', 'university').
    // Stopwoerter in normalisierter Form (nach mb_strtolower + ß->ss + Umlaut->ASCII).
    // 'institut'/'university' etc. werden gefiltert, damit nicht jedes Institut
    // jedes andere matcht.
    $stopwords = ['der','die','das','und','fur','fuer','von','zu','am','im','aus','mit','bei',
                  'nach','beim','uber','ueber',
                  'institut','instit','university','universitat','universitaet',
                  'fachbereich','department','fakultat','fakultaet',
                  'school','college','centre','center',
                  'de','of','for','the','at','in'];

    $rows = $db->query("SELECT id, name_de FROM institutionen ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    // Tokenisierung: Normalisierung analog zu normalizeForAliasMatch
    // (ß->ss, Umlaute->ae/oe/ue, lowercase, dann splitten)
    $tokens = [];
    foreach ($rows as $r) {
        $name = mb_strtolower((string)$r['name_de']);
        // ß -> ss
        $name = str_replace("\xc3\x9f", 'ss', $name);
        // Umlaute: ä->ae, ö->oe, ü->ue (Multibyte-Bytesequenzen)
        $name = str_replace(["\xc3\xa4","\xc3\xb6","\xc3\xbc"], ['ae','oe','ue'], $name);
        $words = preg_split('/[\s,\-\.\(\)\/\:;]+/u', $name) ?: [];
        $words = array_filter($words, fn($w) => mb_strlen($w) > 4 && !in_array($w, $stopwords, true));
        $tokens[(int)$r['id']] = array_values(array_unique($words));
    }

    // Greedy Single-Pass Clustering
    $assigned = [];
    $clusters = [];
    foreach ($tokens as $i => $itoks) {
        if (isset($assigned[$i]) || count($itoks) < 3) continue;
        $cluster = [$i];
        $assigned[$i] = true;
        foreach ($tokens as $j => $jtoks) {
            if ($i === $j || isset($assigned[$j])) continue;
            $shared = array_intersect($itoks, $jtoks);
            if (count($shared) >= 3) {
                $cluster[] = $j;
                $assigned[$j] = true;
            }
        }
        if (count($cluster) >= 2) {
            sort($cluster);
            $clusters[] = $cluster;
        }
    }
    return $clusters;
}

/**
 * Rendert die Details eines Clusters fuer die Subagent-Evaluation.
 * Gibt pro Institution id, den rohen PDF-String (name_de) und die Anzahl
 * verknuepfter Autoren zurueck -- die Groesse ist ein wichtiges Signal.
 *
 * Returns: array<array{id:int, name_de:string, author_count:int}>
 */
function renderInstitutionClusterForSubagent(PDO $db, array $ids): array
{
    if (count($ids) === 0) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT i.id, i.name_de,
               (SELECT COUNT(*) FROM autor_institutionen ai WHERE ai.institut_id = i.id) AS author_count
        FROM institutionen i
        WHERE i.id IN ($placeholders)
        ORDER BY i.id ASC
    ");
    $stmt->execute($ids);
    return array_map(fn(array $r): array => [
        'id'           => (int) $r['id'],
        'name_de'      => (string) $r['name_de'],
        'author_count' => (int) ($r['author_count'] ?? 0),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Exportiert alle Institutions-Cluster als inst_NNNN.json (4-stellig
 * nullgepadded) in $outDir. Loescht vorher alle alten inst_*.json und
 * verdict_inst_*.json im Verzeichnis (ephemerer Scratch-Bereich).
 *
 * Returns: Anzahl geschriebener Cluster-Dateien.
 */
function exportInstitutionClusters(PDO $db, string $outDir): int
{
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        throw new RuntimeException("Konnte Verzeichnis nicht anlegen: $outDir");
    }
    foreach (glob($outDir . '/inst_*.json') ?: [] as $f) @unlink($f);
    foreach (glob($outDir . '/verdict_inst_*.json') ?: [] as $f) @unlink($f);

    $clusters = findInstitutionClusters($db);
    $i = 0;
    foreach ($clusters as $ids) {
        $i++;
        $variants = renderInstitutionClusterForSubagent($db, $ids);
        $payload = [
            'cluster_id' => $i,
            'variants'   => $variants,
        ];
        $path = sprintf('%s/inst_%04d.json', $outDir, $i);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    return $i;
}

/**
 * Verarbeitet Subagent-Verdicts (verdict_inst_NNNN.json).
 *
 * Auto-Merge: verdict === 'merge' UND confidence >= $threshold.
 * Niedrige Konfidenz / keep_separate / unsure -> merge_review_queue.
 *
 * Verdict-Schema:
 *   {
 *     "verdict": "merge" | "keep_separate" | "unsure",
 *     "confidence": 0.0-1.0,
 *     "groups": [[id, id, ...], ...],
 *     "canonical": [
 *       {"name_de":"...", "name_en":"...", "kuerzel":"...",
 *        "universitaet":"...", "ort":"...", "land":"DE", "ror_id":null}
 *     ],
 *     "reason": "..."
 *   }
 *
 * Returns: ['merged' => int, 'queued' => int, 'errors' => int]
 */
function processInstitutionVerdicts(PDO $db, string $verdictDir, float $threshold = 0.85): array
{
    $stats = ['merged' => 0, 'queued' => 0, 'errors' => 0];

    // Queue-Tabelle existiert bereits (von author-pipeline), idempotentes CREATE
    $db->exec("CREATE TABLE IF NOT EXISTS merge_review_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL DEFAULT 'author' CHECK (kind IN ('author','institution')),
        cluster_json TEXT NOT NULL,
        verdict_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    foreach (glob($verdictDir . '/verdict_inst_*.json') ?: [] as $vf) {
        try {
            $verdict = json_decode((string)file_get_contents($vf), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "Ungueltiges JSON in $vf: " . $e->getMessage() . "\n");
            continue;
        }

        // Zugehoerige Cluster-Datei: verdict_inst_NNNN.json -> inst_NNNN.json
        $cf = str_replace('verdict_inst_', 'inst_', $vf);
        $cluster = is_file($cf) ? json_decode((string)file_get_contents($cf), true, 32) : null;
        if ($cluster === null) {
            $stats['errors']++;
            fwrite(STDERR, "Kein Cluster-File zu $vf\n");
            continue;
        }

        $isAuto = ($verdict['verdict'] ?? '') === 'merge'
               && (float)($verdict['confidence'] ?? 0) >= $threshold;

        if (!$isAuto) {
            $db->prepare(
                "INSERT INTO merge_review_queue (kind, cluster_json, verdict_json) VALUES ('institution', ?, ?)"
            )->execute([
                json_encode($cluster, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($verdict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $stats['queued']++;
            continue;
        }

        // Auto-Merge: atomare Transaktion pro Cluster
        $db->beginTransaction();
        try {
            foreach (($verdict['groups'] ?? []) as $gi => $group) {
                $ids = array_values(array_unique(array_map('intval', (array)$group)));
                if (count($ids) < 2) continue;
                sort($ids);
                $anchor = $ids[0];
                $duplicates = array_slice($ids, 1);
                $dupPh = implode(',', array_fill(0, count($duplicates), '?'));

                // Kanonische Daten auf den Anker schreiben (COALESCE: nur NULL-Felder
                // werden gesetzt, vorhandene Werte bleiben erhalten)
                $can = $verdict['canonical'][$gi] ?? null;
                if (is_array($can)) {
                    $db->prepare("
                        UPDATE institutionen
                        SET name_de      = COALESCE(:nd, name_de),
                            name_en      = COALESCE(:ne, name_en),
                            kuerzel      = COALESCE(:k,  kuerzel),
                            universitaet = COALESCE(:u,  universitaet),
                            ort          = COALESCE(:o,  ort),
                            land         = COALESCE(:l,  land),
                            ror_id       = COALESCE(:r,  ror_id)
                        WHERE id = :id
                    ")->execute([
                        ':nd' => ($can['name_de']      ?? null) ?: null,
                        ':ne' => ($can['name_en']      ?? null) ?: null,
                        ':k'  => ($can['kuerzel']      ?? null) ?: null,
                        ':u'  => ($can['universitaet'] ?? null) ?: null,
                        ':o'  => ($can['ort']          ?? null) ?: null,
                        ':l'  => ($can['land']         ?? null) ?: null,
                        ':r'  => ($can['ror_id']       ?? null) ?: null,
                        ':id' => $anchor,
                    ]);
                }

                // institut_aliase umhaengen (UPDATE OR IGNORE verhindert Duplikat-
                // Konflikte, ueberschuessige Eintraege werden danach geloescht)
                $db->prepare("UPDATE OR IGNORE institut_aliase SET institut_id = ? WHERE institut_id IN ($dupPh)")
                   ->execute([$anchor, ...$duplicates]);
                $db->prepare("DELETE FROM institut_aliase WHERE institut_id IN ($dupPh)")
                   ->execute($duplicates);

                // autor_institutionen umhaengen: zuerst Konflikte beseitigen,
                // dann restliche Verknuepfungen auf Anker verschieben
                $db->prepare("
                    DELETE FROM autor_institutionen
                    WHERE institut_id IN ($dupPh)
                      AND autor_id IN (SELECT autor_id FROM autor_institutionen WHERE institut_id = ?)
                ")->execute([...$duplicates, $anchor]);
                $db->prepare("UPDATE autor_institutionen SET institut_id = ? WHERE institut_id IN ($dupPh)")
                   ->execute([$anchor, ...$duplicates]);

                // Duplikate aus institutionen entfernen
                $db->prepare("DELETE FROM institutionen WHERE id IN ($dupPh)")->execute($duplicates);

                $stats['merged']++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $stats['errors']++;
            fwrite(STDERR, "Merge-Fehler in cluster {$cluster['cluster_id']}: " . $e->getMessage() . "\n");
        }
    }
    return $stats;
}
