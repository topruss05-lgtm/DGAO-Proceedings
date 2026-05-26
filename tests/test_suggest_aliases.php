<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/helpers.php';

// We don't go through HTTP here — we replicate the suggest.php author query
// directly against a test DB to verify the JOIN + normalization works.
// This catches regressions in the refactored SQL.

with_test_db(function (PDO $pdo) {
    require_once __DIR__ . '/../public/db.php';
    runMigrations($pdo);

    // Apply phase-2 auto-merge so the test DB matches production state
    require_once __DIR__ . '/../bin/cleanup_auto_merge.php';
    foreach (findAuthorAutoMergeClusters($pdo) as $c) {
        mergeAuthorCluster($pdo, $c['ids']);
    }

    // Helper: run the suggest-author query for a given input
    $run = function (string $q) use ($pdo): array {
        $qNorm = normalizeForAliasMatch($q);
        $sql = "
            SELECT a.id, a.vorname, a.nachname, COUNT(DISTINCT pa.paper_id) AS papers
            FROM autoren a
            JOIN paper_autoren pa ON pa.autor_id = a.id
            WHERE EXISTS (
                SELECT 1 FROM autor_aliase al
                WHERE al.autor_id = a.id AND al.alias_norm LIKE :qnorm
            )
            GROUP BY a.id
            HAVING papers > 0
            ORDER BY papers DESC, a.nachname COLLATE NOCASE
            LIMIT 5
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':qnorm' => '%' . $qNorm . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // Test 1: "Pruss" finds C. Pruß (which had alias "C. Pruss" before merge)
    $hits = $run('Pruss');
    assert_true(count($hits) >= 1, "Suggest 'Pruss' liefert mind. 1 Treffer");
    assert_true(stripos($hits[0]['nachname'], 'Pru') !== false,
        "Top-Treffer ist ein Pruß, war: " . $hits[0]['nachname']);

    // Test 2: "Pruß" (with ß) finds the same person — alias_norm normalizes both
    $hits2 = $run('Pruß');
    assert_true(count($hits2) >= 1, "Suggest 'Pruß' liefert mind. 1 Treffer");
    assert_equals((int) $hits[0]['id'], (int) $hits2[0]['id'],
        "Pruss und Pruß finden denselben Top-Treffer");

    // Test 3: "Ch. Pruss" (different initials + Pruss) also finds Pruß
    //   — but only if the merge logic + Ch.-prefix matching collapses to chpruss.
    //     "Ch. Pruss" → norm "chpruss" → matches the Ch. Pruß author (id 7524 in live DB).
    //     This is Phase 3's job to merge with C. Pruß. For now, we expect a hit
    //     but it might be a different ID than for "Pruss".
    $hits3 = $run('Ch. Pruss');
    assert_true(count($hits3) >= 1, "Suggest 'Ch. Pruss' liefert mind. 1 Treffer");

    // Test 4: short empty query shouldn't crash (but our early-exit handles it
    //   at the suggest.php level — here we just verify the query itself doesn't break)
    $hits4 = $run('xxxxxxxxxxxxx_nonexistent');
    assert_equals(0, count($hits4), "Nonexistent query liefert 0 Treffer");
});
