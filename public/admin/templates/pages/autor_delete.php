<?php
$autorId = $params['id'] ?? null;
$adminPageTitle = 'Autor löschen';

$db = getDb();
$autor = $db->prepare('SELECT * FROM autoren WHERE id = ?');
$autor->execute([$autorId]);
$autor = $autor->fetch();

if (!$autor) {
    setFlash('danger', 'Autor nicht gefunden.');
    header('Location: /admin/autoren');
    exit;
}

$paperCount = $db->prepare('SELECT COUNT(*) FROM paper_autoren WHERE autor_id = ?');
$paperCount->execute([$autorId]);
$paperCount = (int)$paperCount->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $dbw = getDbAdmin();
    $dbw->beginTransaction();

    try {
        $dbw->prepare('DELETE FROM paper_autoren WHERE autor_id = ?')->execute([$autorId]);
        $dbw->prepare('DELETE FROM autoren WHERE id = ?')->execute([$autorId]);
        $dbw->commit();

        setFlash('success', "Autor \"" . trim($autor['vorname'] . ' ' . $autor['nachname']) . "\" gelöscht.");
    } catch (Throwable $e) {
        $dbw->rollBack();
        error_log('autor_delete error: ' . $e);
        setFlash('danger', 'Löschen fehlgeschlagen — Details im Server-Log.');
    }

    header('Location: /admin/autoren');
    exit;
}
?>

<h1 class="mb-4">Autor löschen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> Autor wirklich löschen?
        </h5>
        <p><strong><?= e(trim($autor['vorname'] . ' ' . $autor['nachname'])) ?></strong></p>
        <?php if ($paperCount > 0): ?>
        <p class="text-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Dieser Autor ist mit <strong><?= $paperCount ?> Papers</strong> verknüpft.
            Die Verknüpfungen werden ebenfalls gelöscht.
        </p>
        <?php endif; ?>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endgültig löschen
            </button>
            <a href="/admin/autoren" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>
