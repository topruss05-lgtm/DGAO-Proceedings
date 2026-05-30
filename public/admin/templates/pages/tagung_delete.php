<?php
$nummer = $params['nummer'] ?? null;
$adminPageTitle = "Tagung {$nummer} löschen";

$db = getDb();
$tagung = $db->prepare('SELECT * FROM tagungen WHERE nummer = ?');
$tagung->execute([$nummer]);
$tagung = $tagung->fetch();

if (!$tagung) {
    setFlash('danger', 'Tagung nicht gefunden.');
    header('Location: /admin/tagungen');
    exit;
}

$paperCount = $db->prepare('SELECT COUNT(*) FROM papers WHERE tagung_nummer = ?');
$paperCount->execute([$nummer]);
$paperCount = (int)$paperCount->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $dbw = getDbAdmin();
    $dbw->beginTransaction();

    try {
        // Verknüpfungen löschen
        $paperIds = $dbw->prepare('SELECT id FROM papers WHERE tagung_nummer = ?');
        $paperIds->execute([$nummer]);
        $ids = $paperIds->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $dbw->prepare("DELETE FROM submissions WHERE paper_id IN ($ph)")->execute($ids);
            $dbw->prepare("DELETE FROM paper_autoren WHERE paper_id IN ($ph)")->execute($ids);
        }

        $dbw->prepare('DELETE FROM papers WHERE tagung_nummer = ?')->execute([$nummer]);
        $dbw->prepare('DELETE FROM tagungen WHERE nummer = ?')->execute([$nummer]);

        rebuildFtsIndex($dbw);
        $dbw->commit();

        setFlash('success', "Tagung {$nummer} mit {$paperCount} Papers gelöscht.");
    } catch (Throwable $e) {
        $dbw->rollBack();
        error_log('tagung_delete error: ' . $e);
        setFlash('danger', 'Löschen fehlgeschlagen — Details im Server-Log.');
    }

    header('Location: /admin/tagungen');
    exit;
}
?>

<h1 class="mb-4">Tagung löschen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> Tagung <?= $tagung['nummer'] ?> wirklich löschen?
        </h5>
        <p>
            <strong><?= $tagung['nummer'] ?>. DGaO-Tagung</strong> (<?= e($tagung['jahr']) ?>, <?= e($tagung['ort'] ?? 'Ort unbekannt') ?>)
        </p>
        <p>
            Damit werden auch <strong><?= $paperCount ?> Papers</strong> und alle zugehörigen Autoren-Verknüpfungen gelöscht.
        </p>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endgültig löschen
            </button>
            <a href="/admin/tagungen" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>
