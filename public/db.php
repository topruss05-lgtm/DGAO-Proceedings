<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        bootstrapDb();
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        runMigrations($pdo);
        $pdo->exec('PRAGMA query_only = ON');
    }
    return $pdo;
}

function getDbAdmin(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        bootstrapDb();
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        runMigrations($pdo);
    }
    return $pdo;
}

/**
 * Stellt sicher, dass die SQLite-DB existiert. Beim ersten Deploy auf
 * leerem Server (DB-Datei fehlt) wird das Schema aus database/schema.sql
 * gespielt. Idempotent — überspringt, wenn DB schon Tabellen hat.
 */
function bootstrapDb(): void
{
    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0775, true)) {
            throw new RuntimeException('Data-Dir kann nicht angelegt werden: ' . $dataDir);
        }
    }
    if (is_file(DB_PATH) && filesize(DB_PATH) > 0) return;

    $schemaPath = __DIR__ . '/../database/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('Schema-Datei fehlt: ' . $schemaPath);
    }
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('Schema-Datei nicht lesbar.');
    }

    $tmp = new PDO('sqlite:' . DB_PATH);
    $tmp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tmp->exec('PRAGMA journal_mode = WAL');
    $tmp->exec('PRAGMA foreign_keys = ON');
    // Schema als Block ausführen — sqlite3 .schema-Output ist mehrteilig.
    $tmp->exec($schema);
}

function runMigrations(PDO $db): void
{
    $columns = $db->query("PRAGMA table_info(autoren)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('affiliation', $columns, true)) {
        $db->exec("ALTER TABLE autoren ADD COLUMN affiliation TEXT NOT NULL DEFAULT ''");
    }

    $tagungenColumns = $db->query("PRAGMA table_info(tagungen)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('vorlage_phase_aktiv', $tagungenColumns, true)) {
        $db->exec("ALTER TABLE tagungen ADD COLUMN vorlage_phase_aktiv INTEGER NOT NULL DEFAULT 0");
    }
}

function rebuildFtsIndex(PDO $db): void
{
    $db->exec("INSERT INTO papers_fts(papers_fts) VALUES('rebuild')");
}
