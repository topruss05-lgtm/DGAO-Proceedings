<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DB_SCHEMA_VERSION = 7;

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
        configureSqlite($pdo);
        runMigrations($pdo);
        // Read-only fence: selbst wenn ein Frontend-Pfad versehentlich
        // eine Schreib-Query absetzt, hebt SQLite hier einen Fehler.
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
        configureSqlite($pdo);
        runMigrations($pdo);
    }
    return $pdo;
}

/**
 * Setzt SQLite-Pragmas, die wir auf jeder Connection wollen.
 * - WAL: bessere Read/Write-Concurrency
 * - busy_timeout: verhindert SQLITE_BUSY bei Parallel-Zugriff
 * - synchronous=NORMAL: mit WAL sicher und deutlich schneller als FULL
 * - foreign_keys=ON: SQLite haelt FK-Checks per Connection (auch read)
 * - cache_size: 20 MB Page-Cache
 * - temp_store=MEMORY: kein Temp-File-I/O
 * - mmap_size: 128 MB Memory-Mapped-I/O
 */
function configureSqlite(PDO $db): void
{
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA cache_size = -20000');
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('PRAGMA mmap_size  = 134217728');
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

/**
 * Versionierte Migrationen via PRAGMA user_version.
 * Fast-Path: wenn user_version bereits am Target ist, kein weiterer Call.
 * Defensive Spalten-Checks halten alte DBs (user_version=0) idempotent,
 * auch wenn das schema.sql bereits die neueren Spalten enthaelt.
 */
function runMigrations(PDO $db): void
{
    $current = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($current >= DB_SCHEMA_VERSION) return;

    $autorenColumns  = $db->query('PRAGMA table_info(autoren)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $tagungenColumns = $db->query('PRAGMA table_info(tagungen)')->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array('affiliation', $autorenColumns, true)) {
        $db->exec("ALTER TABLE autoren ADD COLUMN affiliation TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('vorlage_phase_aktiv', $tagungenColumns, true)) {
        $db->exec('ALTER TABLE tagungen ADD COLUMN vorlage_phase_aktiv INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('einreichungsfrist', $tagungenColumns, true)) {
        $db->exec('ALTER TABLE tagungen ADD COLUMN einreichungsfrist TEXT');
    }

    // v4: admin_login_attempts (Brute-Force-Schutz). Drop+Recreate ist
    // sicher — die Tabelle haelt nur fluechtige Login-Counter, keine
    // dauerhaften Daten.
    $db->exec('DROP TABLE IF EXISTS admin_login_attempts');
    $db->exec('CREATE TABLE admin_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        ts INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_admin_login_attempts_ip_ts ON admin_login_attempts(ip, ts)');

    // v5: sessions-Tabelle + papers.session_id (Themen-Gruppierung aus
    // Tagungs-Booklets). Sessions sind optional pro Tagung — Frontend
    // faellt auf Code-Buchstaben-Gruppierung zurueck, wo keine Sessions
    // importiert sind.
    $hasSessions = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='sessions'")->fetchColumn();
    if (!$hasSessions) {
        $db->exec('CREATE TABLE sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tagung_nummer INTEGER NOT NULL REFERENCES tagungen(nummer),
            titel TEXT NOT NULL,
            saal TEXT,
            sortorder INTEGER NOT NULL,
            datum TEXT,
            zeit_von TEXT,
            zeit_bis TEXT
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_tagung ON sessions(tagung_nummer, sortorder)');
    }
    $papersColumns = $db->query('PRAGMA table_info(papers)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('session_id', $papersColumns, true)) {
        $db->exec('ALTER TABLE papers ADD COLUMN session_id INTEGER REFERENCES sessions(id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_papers_session ON papers(session_id)');
    }

    // v6: news-Tabelle (Hybrid auto + manual).
    $hasNews = (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='news'")->fetchColumn();
    if (!$hasNews) {
        $db->exec("CREATE TABLE news (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            source        TEXT NOT NULL CHECK (source IN ('auto','manual')),
            trigger_key   TEXT,
            tagung_nummer INTEGER REFERENCES tagungen(nummer) ON DELETE CASCADE,
            display_date  TEXT NOT NULL,
            title_de      TEXT NOT NULL,
            title_en      TEXT NOT NULL,
            body_de       TEXT NOT NULL DEFAULT '',
            body_en       TEXT NOT NULL DEFAULT '',
            link_url      TEXT,
            is_active     INTEGER NOT NULL DEFAULT 1,
            sort_weight   INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE UNIQUE INDEX idx_news_auto_unique
                   ON news(source, trigger_key, tagung_nummer)
                   WHERE source = 'auto'");
        $db->exec("CREATE INDEX idx_news_active_date ON news(is_active, display_date DESC)");
    }

    // v7: manual_override-Flag fuer auto-News. Wenn 1, laesst der
    // naechste Tagung-Save (newsUpsertAuto) Titel/Body/Link unangetastet —
    // Admin-Edits persistieren. Default 0 (Template-Hoheit wie bisher).
    $newsColumns = $db->query('PRAGMA table_info(news)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('manual_override', $newsColumns, true)) {
        $db->exec('ALTER TABLE news ADD COLUMN manual_override INTEGER NOT NULL DEFAULT 0');
    }

    $db->exec('PRAGMA user_version = ' . DB_SCHEMA_VERSION);
}

function rebuildFtsIndex(PDO $db): void
{
    $db->exec("INSERT INTO papers_fts(papers_fts) VALUES('rebuild')");
    // optimize fusioniert FTS5-Segmente, beschleunigt nachfolgende Suchen
    $db->exec("INSERT INTO papers_fts(papers_fts) VALUES('optimize')");
}
