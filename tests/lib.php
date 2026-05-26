<?php
declare(strict_types=1);

$GLOBALS['__tests'] = [
    'pass'     => 0,
    'fail'     => 0,
    'failures' => [],
];

function assert_equals(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected === $actual) {
        $GLOBALS['__tests']['pass']++;
    } else {
        $GLOBALS['__tests']['fail']++;
        $label = $msg !== '' ? $msg : 'assert_equals';
        $GLOBALS['__tests']['failures'][] = sprintf(
            '%s — expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true),
        );
    }
}

function assert_true(bool $cond, string $msg = ''): void
{
    if ($cond) {
        $GLOBALS['__tests']['pass']++;
    } else {
        $GLOBALS['__tests']['fail']++;
        $label = $msg !== '' ? $msg : 'assert_true';
        $GLOBALS['__tests']['failures'][] = sprintf('%s — expected true, got false', $label);
    }
}

function assert_count(int $expected, array $arr, string $msg = ''): void
{
    $actual = count($arr);
    if ($expected === $actual) {
        $GLOBALS['__tests']['pass']++;
    } else {
        $GLOBALS['__tests']['fail']++;
        $label = $msg !== '' ? $msg : 'assert_count';
        $GLOBALS['__tests']['failures'][] = sprintf(
            '%s — expected count %d, got %d',
            $label,
            $expected,
            $actual,
        );
    }
}

function with_test_db(callable $fn): void
{
    $backups = glob(__DIR__ . '/../database/backups/proceedings_pre_migration_*.db') ?: [];
    if (empty($backups)) {
        throw new RuntimeException(
            'Kein Pre-Migration-Backup gefunden in database/backups/proceedings_pre_migration_*.db'
        );
    }
    sort($backups);
    $backup = end($backups);

    $tmpBase = sys_get_temp_dir() . '/dgao_test_' . bin2hex(random_bytes(8)) . '.db';
    if (!copy($backup, $tmpBase)) {
        throw new RuntimeException("Backup-Kopie fehlgeschlagen: $backup → $tmpBase");
    }

    $pdo = new PDO('sqlite:' . $tmpBase, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    try {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $fn($pdo);
    } finally {
        unset($pdo);
        foreach ([$tmpBase, $tmpBase . '-wal', $tmpBase . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }
}
