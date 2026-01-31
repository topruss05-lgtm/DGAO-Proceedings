<?php
$kwId = $params['id'] ?? null;
$adminPageTitle = 'Keyword löschen';

$db = getDb();
$kw = $db->prepare('SELECT * FROM keywords WHERE id = ?');
$kw->execute([$kwId]);
$kw = $kw->fetch();

if (!$kw) {
    setFlash('danger', 'Keyword nicht gefunden.');
    header('Location: /admin/keywords');
    exit;
}

$paperCount = $db->prepare('SELECT COUNT(*) FROM paper_keywords WHERE keyword_id = ?');
$paperCount->execute([$kwId]);
$paperCount = (int)$paperCount->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $dbw = getDbAdmin();
    $dbw->beginTransaction();

    try {
        $dbw->prepare('DELETE FROM paper_keywords WHERE keyword_id = ?')->execute([$kwId]);
        $dbw->prepare('DELETE FROM keywords WHERE id = ?')->execute([$kwId]);
        $dbw->commit();

        setFlash('success', "Keyword \"{$kw['keyword']}\" gelöscht.");
    } catch (Exception $e) {
        $dbw->rollBack();
        setFlash('danger', 'Löschen fehlgeschlagen: ' . $e->getMessage());
    }

    header('Location: /admin/keywords');
    exit;
}
?>

<h1 class="mb-4">Keyword löschen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> Keyword wirklich löschen?
        </h5>
        <p><strong><?= e($kw['keyword']) ?></strong></p>
        <?php if ($paperCount > 0): ?>
        <p class="text-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Dieses Keyword ist mit <strong><?= $paperCount ?> Papers</strong> verknüpft.
            Die Verknüpfungen werden ebenfalls gelöscht.
        </p>
        <?php endif; ?>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endgültig löschen
            </button>
            <a href="/admin/keywords" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>
