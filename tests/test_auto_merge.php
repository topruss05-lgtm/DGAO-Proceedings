<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/db.php';
require_once __DIR__ . '/../bin/cleanup_auto_merge.php';

with_test_db(function (PDO $pdo) {
    runMigrations($pdo); // ensure v8 is applied

    $clusters = findAuthorAutoMergeClusters($pdo);

    // sanity: known clusters from the real data
    $byKey = [];
    foreach ($clusters as $c) $byKey[$c['key']] = $c;

    assert_true(isset($byKey['cpruss']),
        "cluster 'cpruss' (C. Pruss / C. Pruß / etc.) found");
    assert_true(count($byKey['cpruss']['ids']) >= 2,
        "'cpruss' cluster has 2+ ids");

    assert_true(isset($byKey['cfranke']),
        "cluster 'cfranke' (C. Franke variants) found");

    // every cluster has >1 id by definition
    foreach ($clusters as $c) {
        assert_true(count($c['ids']) > 1, "cluster {$c['key']} has 2+ ids");
        // ids are int, unique, sorted ascending makes downstream merging deterministic
        assert_equals(array_values(array_unique($c['ids'])), $c['ids'],
            "cluster {$c['key']} ids unique");
    }

    // typing: 'key' is string, 'ids' is int[]
    if (count($clusters) > 0) {
        assert_true(is_string($clusters[0]['key']), "key is string");
        assert_true(is_array($clusters[0]['ids']),  "ids is array");
        foreach ($clusters[0]['ids'] as $id) {
            assert_true(is_int($id), "id is int");
        }
    }
});
