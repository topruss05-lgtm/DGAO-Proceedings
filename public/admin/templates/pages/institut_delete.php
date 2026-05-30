<?php
$institutId = (int)($params['id'] ?? 0);
$adminPageTitle = 'Institution löschen';

$db = getDb();
$inst = $db->prepare('SELECT * FROM institutionen WHERE id = ?');
$inst->execute([$institutId]);
$inst = $inst->fetch();

if (!$inst) {
    setFlash('danger', 'Institution nicht gefunden.');
    header('Location: /admin/institute');
    exit;
}

$nAutoren = (int)$db->query("SELECT COUNT(*) FROM autor_institutionen WHERE institut_id = $institutId")->fetchColumn();
$nPapers  = (int)$db->query("SELECT COUNT(DISTINCT paper_id) FROM paper_autor_institutionen WHERE institut_id = $institutId")->fetchColumn();
$nSubs    = (int)$db->query("SELECT COUNT(*) FROM institutionen WHERE parent_id = $institutId")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $dbw = getDbAdmin();
    $dbw->beginTransaction();
    try {
        // Sub-Institute: parent_id leeren
        $dbw->prepare('UPDATE institutionen SET parent_id = NULL WHERE parent_id = ?')->execute([$institutId]);
        // Verknuepfungen (CASCADE-Klausel im Schema, aber explizit fuer Klarheit)
        $dbw->prepare('DELETE FROM paper_autor_institutionen WHERE institut_id = ?')->execute([$institutId]);
        $dbw->prepare('DELETE FROM autor_institutionen WHERE institut_id = ?')->execute([$institutId]);
        $dbw->prepare('DELETE FROM institut_aliase WHERE institut_id = ?')->execute([$institutId]);
        $dbw->prepare('DELETE FROM institutionen WHERE id = ?')->execute([$institutId]);
        $dbw->commit();
        setFlash('success', "Institution \"" . $inst['name_de'] . "\" gelöscht.");
    } catch (Throwable $e) {
        $dbw->rollBack();
        error_log('institut_delete: ' . $e);
        setFlash('danger', 'Löschen fehlgeschlagen — Details im Server-Log.');
    }
    header('Location: /admin/institute');
    exit;
}
?>

<h1 class="mb-4">Institution löschen</h1>

<div class="card border-danger">
    <div class="card-body">
        <h5 class="card-title text-danger">
            <i class="bi bi-exclamation-triangle"></i> Institution wirklich löschen?
        </h5>
        <p><strong><?= e($inst['name_de']) ?></strong><?php if ($inst['kuerzel']): ?> <span class="text-muted">(<?= e($inst['kuerzel']) ?>)</span><?php endif; ?></p>

        <?php if ($nAutoren > 0 || $nPapers > 0): ?>
        <div class="alert alert-warning small mb-3">
            <strong>Verknüpfungen werden mitgelöscht:</strong>
            <?= $nAutoren ?> Autor-Verknüpfung(en), <?= $nPapers ?> Paper-Zuordnung(en).
            <?php if ($nSubs > 0): ?><br><?= $nSubs ?> Sub-Institut(e) werden auf <code>parent_id = NULL</code> gesetzt.<?php endif; ?>
            <br>Falls dieses Institut tatsächlich mit einem anderen identisch ist, nutze stattdessen
            <a href="/admin/institute/<?= $institutId ?>/edit">die Merge-Funktion</a>.
        </div>
        <?php endif; ?>

        <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash"></i> Endgültig löschen
            </button>
            <a href="/admin/institute" class="btn btn-outline-secondary">Abbrechen</a>
        </form>
    </div>
</div>
