<?php

declare(strict_types=1);

/**
 * Importiert die Themen-Sessions aus den DGaO-Tagungsband-PDFs in /booklets/
 * in die SQLite-DB (Tabelle `sessions`) und verlinkt die zugehoerigen Papers
 * ueber papers.session_id.
 *
 * Idempotent: bei mehrfacher Ausfuehrung werden bestehende Sessions je Tagung
 * zuerst geloescht und papers.session_id auf NULL zurueckgesetzt.
 *
 * Usage (CLI):
 *   php database/import_sessions.php           # alle Booklets
 *   php database/import_sessions.php 2026 2025 # nur bestimmte Jahre
 *
 * Usage (programmatisch, z.B. Admin-Endpoint):
 *   require_once 'database/import_sessions.php';
 *   $report = runSessionImport($pdo, $bookletsDir, [2026]);
 */

require_once __DIR__ . '/../public/admin/session_parser.php';

function listBooklets(string $dir, array $filterYears = []): array
{
    $files = [];
    $iter = glob($dir . '/DGaO_*.pdf') ?: [];
    foreach ($iter as $path) {
        $name = basename($path);
        if (!preg_match('/^DGaO_(\d{4})\.pdf$/i', $name, $m)) continue;
        $year = (int)$m[1];
        if ($filterYears !== [] && !in_array($year, $filterYears, true)) continue;
        $files[$year] = $path;
    }
    krsort($files);
    return $files;
}

function loadYearToTagungMap(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT nummer, jahr FROM tagungen');
    $map = [];
    foreach ($stmt as $row) {
        $map[(int)$row['jahr']] = (int)$row['nummer'];
    }
    return $map;
}

function dbConnect(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function resetTagungSessions(PDO $pdo, int $tagungNummer): void
{
    $pdo->prepare('UPDATE papers SET session_id = NULL WHERE tagung_nummer = ?')
        ->execute([$tagungNummer]);
    $pdo->prepare('DELETE FROM sessions WHERE tagung_nummer = ?')
        ->execute([$tagungNummer]);
}

function loadPaperCodes(PDO $pdo, int $tagungNummer): array
{
    $stmt = $pdo->prepare('SELECT code FROM papers WHERE tagung_nummer = ?');
    $stmt->execute([$tagungNummer]);
    $out = [];
    foreach ($stmt as $row) {
        $code = (string)$row['code'];
        $out[strtoupper($code)] = $code;
    }
    return $out;
}

function importTagung(PDO $pdo, int $tagungNummer, array $sessions): array
{
    $warnings = [];
    $paperCodes = loadPaperCodes($pdo, $tagungNummer);

    $insertSession = $pdo->prepare(
        'INSERT INTO sessions (tagung_nummer, titel, saal, sortorder, datum, zeit_von, zeit_bis)
         VALUES (:tn, :titel, :saal, :sortorder, :datum, :zv, :zb)'
    );
    $updatePaper = $pdo->prepare(
        'UPDATE papers SET session_id = :sid WHERE tagung_nummer = :tn AND UPPER(code) = :code'
    );

    $papersLinked = 0;
    $sessionsInserted = 0;

    $pdo->beginTransaction();
    try {
        foreach ($sessions as $sess) {
            $codes = $sess['codes'] ?? [];
            $foundCodes = [];
            $missing = [];
            foreach ($codes as $c) {
                $uc = strtoupper((string)$c);
                if (isset($paperCodes[$uc])) {
                    $foundCodes[] = $uc;
                } else {
                    $missing[] = $c;
                }
            }

            $rawRange = (string)($sess['code_range_raw'] ?? '');
            $expected = count($codes);
            $found    = count($foundCodes);

            if ($expected === 0) {
                $warnings[] = sprintf(
                    'Session "%s" hat keine Codes - uebersprungen.',
                    (string)$sess['titel']
                );
                continue;
            }

            if ($found === 0) {
                $warnings[] = sprintf(
                    'Session "%s" [%s]: kein einziger Code in DB gefunden - uebersprungen.',
                    (string)$sess['titel'],
                    $rawRange
                );
                continue;
            }

            if ($missing !== []) {
                $warnings[] = sprintf(
                    'Session "%s" [%s]: erwartet %d, gefunden %d (fehlend: %s)',
                    (string)$sess['titel'],
                    $rawRange,
                    $expected,
                    $found,
                    implode(',', $missing)
                );
            }

            $insertSession->execute([
                ':tn'        => $tagungNummer,
                ':titel'     => (string)$sess['titel'],
                ':saal'      => $sess['saal'] ?? null,
                ':sortorder' => (int)($sess['sortorder'] ?? 0),
                ':datum'     => $sess['datum'] ?? null,
                ':zv'        => $sess['zeit_von'] ?? null,
                ':zb'        => $sess['zeit_bis'] ?? null,
            ]);
            $sessionId = (int)$pdo->lastInsertId();
            $sessionsInserted++;

            foreach ($foundCodes as $uc) {
                $updatePaper->execute([
                    ':sid'  => $sessionId,
                    ':tn'   => $tagungNummer,
                    ':code' => $uc,
                ]);
                $papersLinked += $updatePaper->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'sessions_inserted' => $sessionsInserted,
        'papers_linked'     => $papersLinked,
        'warnings'          => $warnings,
    ];
}

function countUnlinkedPapers(PDO $pdo, int $tagungNummer): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM papers WHERE tagung_nummer = ? AND session_id IS NULL'
    );
    $stmt->execute([$tagungNummer]);
    $row = $stmt->fetch();
    return (int)($row['c'] ?? 0);
}

function unlinkedCodesSummary(PDO $pdo, int $tagungNummer): string
{
    $stmt = $pdo->prepare(
        'SELECT code FROM papers WHERE tagung_nummer = ? AND session_id IS NULL ORDER BY code'
    );
    $stmt->execute([$tagungNummer]);
    $codes = [];
    foreach ($stmt as $row) {
        $codes[] = (string)$row['code'];
    }
    if ($codes === []) return '';
    $byPrefix = [];
    foreach ($codes as $c) {
        $p = preg_replace('/\d+$/', '', $c) ?? $c;
        $byPrefix[$p] = ($byPrefix[$p] ?? 0) + 1;
    }
    $parts = [];
    foreach ($byPrefix as $p => $n) {
        $parts[] = "{$p}x{$n}";
    }
    return implode(', ', $parts);
}

// --- CLI-Hauptablauf ---
$ROOT_DIR     = __DIR__ . '/..';
$BOOKLETS_DIR = $ROOT_DIR . '/booklets';
$DB_PATH      = $ROOT_DIR . '/public/data/proceedings.db';

$filterYears = [];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (preg_match('/^\d{4}$/', $arg)) {
        $filterYears[] = (int)$arg;
    }
}

if (!is_file($DB_PATH)) {
    fwrite(STDERR, "DB nicht gefunden: " . $DB_PATH . "\n");
    exit(1);
}

$pdo = dbConnect($DB_PATH);
$yearToTagung = loadYearToTagungMap($pdo);
$booklets = listBooklets($BOOKLETS_DIR, $filterYears);

if ($booklets === []) {
    echo "Keine passenden Booklets gefunden.\n";
    exit(0);
}

echo str_repeat('=', 78) . "\n";
echo "DGaO Session Import\n";
echo "DB: " . $DB_PATH . "\n";
echo "Booklets: " . count($booklets) . " (" . implode(', ', array_keys($booklets)) . ")\n";
echo str_repeat('=', 78) . "\n\n";

$totalsByYear = [];
foreach ($booklets as $year => $path) {
    if (!isset($yearToTagung[$year])) {
        echo "[$year] SKIP: kein Tagungs-Record fuer jahr=$year in DB.\n\n";
        continue;
    }
    $tagungNummer = $yearToTagung[$year];

    echo "[$year] Tagung $tagungNummer - parse " . basename($path) . " ...\n";
    $parsed = parseSessionsFromPdf($path);
    if ($parsed['error'] !== null) {
        echo "  PARSE-ERROR: {$parsed['error']}\n";
        echo "  -> SKIP (keine Sessions importiert).\n\n";
        continue;
    }
    $sessions = $parsed['sessions'];
    if ($sessions === []) {
        echo "  Keine Sessions im PDF erkannt.\n";
        echo "  -> SKIP.\n\n";
        continue;
    }

    resetTagungSessions($pdo, $tagungNummer);
    $result = importTagung($pdo, $tagungNummer, $sessions);

    $unlinked = countUnlinkedPapers($pdo, $tagungNummer);
    $unlinkedSummary = unlinkedCodesSummary($pdo, $tagungNummer);

    printf(
        "  OK: %d Sessions importiert, %d Papers verlinkt, %d Papers ohne Session (%s)\n",
        $result['sessions_inserted'],
        $result['papers_linked'],
        $unlinked,
        $unlinkedSummary
    );
    foreach ($result['warnings'] as $w) {
        echo "    WARN: $w\n";
    }
    echo "\n";

    $totalsByYear[$year] = [
        'tagung'     => $tagungNummer,
        'sessions'   => $result['sessions_inserted'],
        'linked'     => $result['papers_linked'],
        'unlinked'   => $unlinked,
        'warnings'   => count($result['warnings']),
    ];
}

echo str_repeat('=', 78) . "\n";
echo "Zusammenfassung\n";
echo str_repeat('-', 78) . "\n";
printf("%-6s %-7s %10s %10s %10s %10s\n", 'Jahr', 'Tagung', 'Sessions', 'Verlinkt', 'Ohne', 'Warnungen');
foreach ($totalsByYear as $year => $row) {
    printf(
        "%-6d %-7d %10d %10d %10d %10d\n",
        $year, $row['tagung'], $row['sessions'], $row['linked'], $row['unlinked'], $row['warnings']
    );
}
echo str_repeat('=', 78) . "\n";
