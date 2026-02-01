<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA query_only = ON');
    }
    return $pdo;
}

function getDbAdmin(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
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

function runMigrations(PDO $db): void
{
    $columns = $db->query("PRAGMA table_info(autoren)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('affiliation', $columns, true)) {
        $db->exec("ALTER TABLE autoren ADD COLUMN affiliation TEXT NOT NULL DEFAULT ''");
    }
}

function rebuildFtsIndex(PDO $db): void
{
    $db->exec("INSERT INTO papers_fts(papers_fts) VALUES('rebuild')");
}
