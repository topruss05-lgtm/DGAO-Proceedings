<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/db.php';

with_test_db(function (PDO $pdo) {
    // 1. Sanity: backup must be at v7
    $versionBefore = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    assert_equals(7, $versionBefore, 'v8-migration: backup is at v7 before migration');

    // Insert test author with whitespace-only affiliation before migration (ghost-institution check)
    $stmtWs = $pdo->prepare('INSERT INTO autoren (vorname, nachname, affiliation) VALUES (?, ?, ?)');
    $stmtWs->execute(['X.', 'WhitespaceTest', '   ']);

    // Run migration
    runMigrations($pdo);

    // 3. user_version is now 8
    $versionAfter = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    assert_equals(8, $versionAfter, 'v8-migration: user_version === 8 after migration');

    // 4. Five new tables exist
    foreach (['autor_aliase', 'institutionen', 'institut_aliase', 'autor_institutionen', 'autor_id_redirects'] as $table) {
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
        assert_equals(1, $exists, "v8-migration: table '$table' exists");
    }

    // 5. autoren has new column orcid_id
    $autorenCols = $pdo->query('PRAGMA table_info(autoren)')->fetchAll(PDO::FETCH_COLUMN, 1);
    assert_true(in_array('orcid_id', $autorenCols, true), 'v8-migration: autoren.orcid_id column exists');

    // 6. autoren still has column affiliation
    assert_true(in_array('affiliation', $autorenCols, true), 'v8-migration: autoren.affiliation still exists');

    // 7. autor_aliase has at least as many rows as autoren
    $autorCount    = (int) $pdo->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
    $aliasCount    = (int) $pdo->query('SELECT COUNT(*) FROM autor_aliase')->fetchColumn();
    assert_true($aliasCount >= $autorCount, "v8-migration: autor_aliase rows ($aliasCount) >= autoren rows ($autorCount)");

    // 8. No alias_text contains '*'
    $starsCount = (int) $pdo->query("SELECT COUNT(*) FROM autor_aliase WHERE alias_text LIKE '%*%'")->fetchColumn();
    assert_equals(0, $starsCount, 'v8-migration: no alias_text contains *');

    // 9. No empty alias_norm
    $emptyNorm = (int) $pdo->query("SELECT COUNT(*) FROM autor_aliase WHERE alias_norm = ''")->fetchColumn();
    assert_equals(0, $emptyNorm, 'v8-migration: no empty alias_norm');

    // 10. Per-author at most 1 row with ist_aktuell=1
    $multiCurrent = (int) $pdo->query(
        'SELECT COUNT(*) FROM (SELECT autor_id, SUM(ist_aktuell) AS s FROM autor_institutionen GROUP BY autor_id HAVING s > 1)'
    )->fetchColumn();
    assert_equals(0, $multiCurrent, 'v8-migration: no author has more than 1 ist_aktuell=1 in autor_institutionen');

    // 11. Keine Ghost-Institutionen aus Whitespace-only affiliation
    $ghosts = (int) $pdo->query("SELECT COUNT(*) FROM institutionen WHERE TRIM(name_de) = ''")->fetchColumn();
    assert_equals(0, $ghosts, 'v8-migration: keine Ghost-Institutionen aus Whitespace-only affiliation');

    // 12. Idempotence: second call, version stays 8, no duplicate aliases
    $aliasCountBefore2ndCall = (int) $pdo->query('SELECT COUNT(*) FROM autor_aliase')->fetchColumn();
    runMigrations($pdo);
    $versionAfter2nd = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    assert_equals(8, $versionAfter2nd, 'v8-migration: user_version still 8 after second runMigrations call');
    $aliasCountAfter2ndCall = (int) $pdo->query('SELECT COUNT(*) FROM autor_aliase')->fetchColumn();
    assert_equals($aliasCountBefore2ndCall, $aliasCountAfter2ndCall, 'v8-migration: no duplicate aliases after second runMigrations call');
});
