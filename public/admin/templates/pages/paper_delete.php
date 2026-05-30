<?php
$paperId = $params['id'] ?? null;
$adminPageTitle = 'Paper löschen';

$db = getDb();
$paper = $db->prepare('SELECT * FROM papers WHERE id = ?');
$paper->execute([$paperId]);
$paper = $paper->fetch();

if (!$paper) {
    setFlash('danger', 'Paper nicht gefunden.');
    header('Location: /admin/papers');
    exit;
}
$paper['autoren_text'] = buildPaperAutorenString((string)$paper['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $dbw = getDbAdmin();
    $dbw->beginTransaction();

    try {
        $dbw->prepare('DELETE FROM submissions WHERE paper_id = ?')->execute([$paperId]);
        $dbw->prepare('DELETE FROM paper_keywords WHERE paper_id = ?')->execute([$paperId]);
        $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ?')->execute([$paperId]);
        $dbw->prepare('DELETE FROM papers WHERE id = ?')->execute([$paperId]);

        rebuildFtsIndex($dbw);
        $dbw->commit();

        setFlash('success', "Paper \"{$paper['code']}\" gelöscht.");
    } catch (Throwable $e) {
        $dbw->rollBack();
        error_log('paper_delete error: ' . $e);
        setFlash('danger', 'Löschen fehlgeschlagen — Details im Server-Log.');
    }

    header('Location: /admin/papers?tagung=' . $paper['tagung_nummer']);
    exit;
}
?>

<h1 class="mb-4">Paper löschen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> Paper wirklich löschen?
        </h5>
        <p><strong><?= e($paper['code']) ?>:</strong> <?= e($paper['titel']) ?></p>
        <p class="text-muted"><?= e($paper['autoren_text']) ?></p>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endgültig löschen
            </button>
            <a href="/admin/papers?tagung=<?= $paper['tagung_nummer'] ?>" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>
