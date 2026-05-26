<?php
declare(strict_types=1);

/**
 * Phase 5 — Drop legacy autoren.affiliation column.
 *
 * Usage:  php bin/cleanup_drop_affiliation.php [<db-path>]
 * Default db-path: public/data/proceedings.db (relative to project root)
 *
 * Pre-check: aborts if any author with a non-empty affiliation has no row
 * in autor_institutionen (data would be silently lost).
 *
 * Table-rebuild recipe (safe SQLite column-drop):
 *   1. PRAGMA foreign_keys = OFF  (outside transaction)
 *   2. BEGIN TRANSACTION
 *   3. CREATE TABLE autoren_new without affiliation column
 *   4. INSERT ... SELECT (copy all other data)
 *   5. DROP TABLE autoren
 *   6. ALTER TABLE autoren_new RENAME TO autoren
 *   7. Re-create index
 *   8. COMMIT
 *   9. PRAGMA foreign_keys = ON
 */

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
    // Only run as direct CLI invocation.
    return;
}

// ── Path resolution ──────────────────────────────────────────────────────────
$scriptDir  = dirname(__DIR__);  // project root (bin/ is one level below root)
$defaultDb  = $scriptDir . '/public/data/proceedings.db';
$dbPath     = isset($argv[1]) ? $argv[1] : $defaultDb;

if (!file_exists($dbPath)) {
    fwrite(STDERR, "ERROR: Datenbank nicht gefunden: $dbPath\n");
    exit(1);
}

echo "Datenbank: $dbPath\n";

// ── Open PDO ─────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $dbPath, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: Datenbankverbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Pre-Check: orphaned affiliations ─────────────────────────────────────────
$orphanCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM autoren
     WHERE TRIM(affiliation) != ''
       AND id NOT IN (SELECT autor_id FROM autor_institutionen)"
)->fetchColumn();

if ($orphanCount > 0) {
    fwrite(STDERR, "ABBRUCH: $orphanCount Autoren haben eine nicht-leere affiliation, aber keinen Eintrag in autor_institutionen.\n");
    fwrite(STDERR, "Diese Daten wuerden beim Droppen der Spalte verloren gehen.\n");
    fwrite(STDERR, "Bitte erst Phase 3/4 vollstaendig durchfuehren.\n\n");
    fwrite(STDERR, "Erste 3 Beispiele:\n");
    $examples = $pdo->query(
        "SELECT id, vorname, nachname, affiliation FROM autoren
         WHERE TRIM(affiliation) != ''
           AND id NOT IN (SELECT autor_id FROM autor_institutionen)
         LIMIT 3"
    )->fetchAll();
    foreach ($examples as $row) {
        fwrite(STDERR, sprintf("  ID %d: %s %s -- \"%s\"\n",
            $row['id'], $row['vorname'], $row['nachname'], $row['affiliation']));
    }
    exit(1);
}

echo "Pre-Check OK: keine verwaisten Affiliations gefunden.\n";

// ── Check that affiliation column actually exists ─────────────────────────────
$cols = $pdo->query('PRAGMA table_info(autoren)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('affiliation', $cols, true)) {
    echo "INFO: Spalte 'affiliation' existiert bereits nicht mehr. Nichts zu tun.\n";
    exit(0);
}

// ── Table-Rebuild ─────────────────────────────────────────────────────────────
echo "Starte Table-Rebuild fuer autoren (ohne affiliation-Spalte)...\n";

// FK must be OFF outside the transaction for the rebuild trick to work safely
$pdo->exec('PRAGMA foreign_keys = OFF');

try {
    $pdo->beginTransaction();

    $pdo->exec(
        "CREATE TABLE autoren_new (
            id       INTEGER PRIMARY KEY,
            vorname  TEXT NOT NULL DEFAULT '',
            nachname TEXT NOT NULL,
            orcid_id TEXT
        )"
    );

    $pdo->exec(
        'INSERT INTO autoren_new (id, vorname, nachname, orcid_id)
         SELECT id, vorname, nachname, orcid_id FROM autoren'
    );

    $pdo->exec('DROP TABLE autoren');
    $pdo->exec('ALTER TABLE autoren_new RENAME TO autoren');
    $pdo->exec('CREATE INDEX idx_autoren_nachname ON autoren(nachname)');

    $pdo->commit();
    echo "Transaktion committed.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    fwrite(STDERR, "FEHLER waehrend Table-Rebuild: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec('PRAGMA foreign_keys = ON');

// ── Verify ────────────────────────────────────────────────────────────────────
$finalCols    = $pdo->query('PRAGMA table_info(autoren)')->fetchAll(PDO::FETCH_COLUMN, 1);
$autorCount   = (int) $pdo->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
$orphanCheck  = (int) $pdo->query(
    'SELECT COUNT(*) FROM paper_autoren pa
     LEFT JOIN autoren a ON a.id = pa.autor_id
     WHERE a.id IS NULL'
)->fetchColumn();

echo "\n=== Ergebnis ===\n";
echo "Spalten in autoren: " . implode(', ', $finalCols) . "\n";
echo "Anzahl Autoren: $autorCount\n";
echo "FK-Waisen in paper_autoren: $orphanCheck\n";

if (in_array('affiliation', $finalCols, true)) {
    fwrite(STDERR, "WARNUNG: Spalte 'affiliation' ist immer noch vorhanden -- Rebuild fehlgeschlagen?\n");
    exit(1);
}

if ($orphanCheck !== 0) {
    fwrite(STDERR, "WARNUNG: $orphanCheck FK-Waisen in paper_autoren -- FK-Integritaet verletzt!\n");
    exit(1);
}

echo "\nERFOLG: autoren.affiliation wurde entfernt.\n";
echo "Zur Verifikation:\n";
echo "  sqlite3 \"$dbPath\" \"PRAGMA table_info(autoren)\"\n";
echo "  sqlite3 \"$dbPath\" \"SELECT COUNT(*) FROM autoren\"\n";
