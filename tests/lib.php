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
    $backup = __DIR__ . '/../database/backups/proceedings_pre_migration_2026-05-26.db';

    if (!file_exists($backup)) {
        throw new RuntimeException(
            'Test-DB-Backup nicht gefunden: ' . $backup .
            ' — bitte sicherstellen, dass proceedings_pre_migration_2026-05-26.db vorhanden ist.'
        );
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'dgao_test_') . '.db';
    copy($backup, $tmpBase);

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
