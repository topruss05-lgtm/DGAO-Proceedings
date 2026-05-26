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

// ── mergeAuthorCluster tests ──────────────────────────────────────────────────

with_test_db(function (PDO $pdo) {
    runMigrations($pdo);

    // --- Happy-path: merge a real cluster from the live data ---
    $clusters = findAuthorAutoMergeClusters($pdo);
    assert_true(count($clusters) >= 1, "at least one cluster exists for merge test");

    $first      = $clusters[0];
    $idsBefore  = $first['ids'];

    // Count all paper_autoren rows for the whole cluster before merge
    $placeholders = implode(',', array_fill(0, count($idsBefore), '?'));
    $stmtBefore   = $pdo->prepare(
        "SELECT COUNT(*) FROM paper_autoren WHERE autor_id IN ($placeholders)"
    );
    $stmtBefore->execute($idsBefore);
    $paperCountBefore = (int) $stmtBefore->fetchColumn();

    $anchor = mergeAuthorCluster($pdo, $idsBefore);

    // anchor must come from the original cluster
    assert_true(in_array($anchor, $idsBefore, true),
        "anchor is one of the cluster ids");

    // duplicates deleted from autoren
    $duplicates = array_values(array_filter($idsBefore, fn($id) => $id !== $anchor));
    foreach ($duplicates as $d) {
        $gone = (int) $pdo->query("SELECT COUNT(*) FROM autoren WHERE id = $d")->fetchColumn();
        assert_equals(0, $gone, "duplicate $d deleted from autoren");
    }

    // redirect entries exist for every duplicate
    foreach ($duplicates as $d) {
        $redir = (int) $pdo->query(
            "SELECT COUNT(*) FROM autor_id_redirects WHERE alte_id = $d AND neue_id = $anchor"
        )->fetchColumn();
        assert_equals(1, $redir, "redirect entry for duplicate $d to anchor $anchor");
    }

    // all paper_autoren transferred to anchor
    $cntAfter = (int) $pdo->query(
        "SELECT COUNT(*) FROM paper_autoren WHERE autor_id = $anchor"
    )->fetchColumn();
    assert_equals($paperCountBefore, $cntAfter,
        "all paper_autoren transferred to anchor (got $cntAfter, expected $paperCountBefore)");

    // ist_aktuell: at most 1 row per anchor
    $aktCnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM autor_institutionen WHERE autor_id = $anchor AND ist_aktuell = 1"
    )->fetchColumn();
    assert_true($aktCnt <= 1,
        "anchor has at most 1 ist_aktuell row (got $aktCnt)");

    // no duplicate (paper_id, autor_id) pairs in the whole table
    $dups = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT paper_id, autor_id FROM paper_autoren
            GROUP BY paper_id, autor_id HAVING COUNT(*) > 1
        )"
    )->fetchColumn();
    assert_equals(0, $dups, "no duplicate (paper_id, autor_id) pairs after merge");

    // --- Edge-case: two authors on the same paper (PK dedup path) ---
    $pdo->exec("INSERT INTO autoren (vorname, nachname) VALUES ('T.', 'TestPersonA')");
    $id1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO autoren (vorname, nachname) VALUES ('T.', 'TestPersonA')");
    $id2 = (int) $pdo->lastInsertId();

    // identical alias_norm so they form a cluster
    $pdo->exec("INSERT INTO autor_aliase (autor_id, alias_text, alias_norm)
                VALUES ($id1, 'T. TestPersonA', 'ttestpersona')");
    $pdo->exec("INSERT INTO autor_aliase (autor_id, alias_text, alias_norm)
                VALUES ($id2, 'T. TestPersonA', 'ttestpersona')");

    // link both to the same paper (pick a paper neither is already on)
    $pid = $pdo->query(
        "SELECT id FROM papers
         WHERE id NOT IN (SELECT paper_id FROM paper_autoren WHERE autor_id IN ($id1, $id2))
         LIMIT 1"
    )->fetchColumn();
    $pdo->exec("INSERT INTO paper_autoren (paper_id, autor_id, position) VALUES ('$pid', $id1, 9901)");
    $pdo->exec("INSERT INTO paper_autoren (paper_id, autor_id, position) VALUES ('$pid', $id2, 9902)");

    $anchor2 = mergeAuthorCluster($pdo, [$id1, $id2]);

    // exactly one paper_autoren row for that paper with the anchor
    $cnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM paper_autoren WHERE paper_id = '$pid' AND autor_id = $anchor2"
    )->fetchColumn();
    assert_equals(1, $cnt, "exactly one paper_autoren row after dedup-merge (same paper)");

    // the non-anchor id is gone from autoren
    $other = ($anchor2 === $id1) ? $id2 : $id1;
    $gone  = (int) $pdo->query("SELECT COUNT(*) FROM autoren WHERE id = $other")->fetchColumn();
    assert_equals(0, $gone, "non-anchor test author deleted from autoren");
});
