<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/test_*.php') ?: [];

foreach ($files as $file) {
    echo '→ ' . basename($file) . "\n";
    try {
        require $file;
    } catch (Throwable $e) {
        $GLOBALS['__tests']['fail']++;
        $GLOBALS['__tests']['failures'][] = 'FAIL [' . basename($file) . ']: ' . $e->getMessage();
    }
}

$pass     = $GLOBALS['__tests']['pass'];
$fail     = $GLOBALS['__tests']['fail'];
$failures = $GLOBALS['__tests']['failures'];

echo str_repeat('-', 40) . "\n";
echo "Pass: {$pass}, Fail: {$fail}\n";

if ($fail > 0) {
    foreach ($failures as $msg) {
        echo '  FAIL: ' . $msg . "\n";
    }
    exit(1);
}

exit(0);
