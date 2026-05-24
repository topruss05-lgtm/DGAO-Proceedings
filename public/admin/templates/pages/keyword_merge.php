<?php
$adminPageTitle = 'Keywords zusammenführen';
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $sourceId = (int)($_POST['source_id'] ?? 0);
    $targetId = (int)($_POST['target_id'] ?? 0);

    $errors = [];
    if ($sourceId === $targetId) $errors[] = 'Quell- und Ziel-Keyword dürfen nicht identisch sein.';
    if ($sourceId < 1 || $targetId < 1) $errors[] = 'Beide Keywords müssen ausgewählt sein.';

    if (empty($errors)) {
        $source = $db->prepare('SELECT * FROM keywords WHERE id = ?');
        $source->execute([$sourceId]);
        $source = $source->fetch();

        $target = $db->prepare('SELECT * FROM keywords WHERE id = ?');
        $target->execute([$targetId]);
        $target = $target->fetch();

        if (!$source || !$target) {
            $errors[] = 'Eines der Keywords wurde nicht gefunden.';
        }
    }

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->beginTransaction();

        try {
            $papers = $dbw->prepare('SELECT paper_id FROM paper_keywords WHERE keyword_id = ?');
            $papers->execute([$sourceId]);

            foreach ($papers->fetchAll() as $pk) {
                $check = $dbw->prepare('SELECT COUNT(*) FROM paper_keywords WHERE paper_id = ? AND keyword_id = ?');
                $check->execute([$pk['paper_id'], $targetId]);

                if ((int)$check->fetchColumn() === 0) {
                    $dbw->prepare('UPDATE paper_keywords SET keyword_id = ? WHERE paper_id = ? AND keyword_id = ?')
                        ->execute([$targetId, $pk['paper_id'], $sourceId]);
                } else {
                    $dbw->prepare('DELETE FROM paper_keywords WHERE paper_id = ? AND keyword_id = ?')
                        ->execute([$pk['paper_id'], $sourceId]);
                }
            }

            $dbw->prepare('DELETE FROM keywords WHERE id = ?')->execute([$sourceId]);
            $dbw->commit();

            setFlash('success', "Keyword \"{$source['keyword']}\" in \"{$target['keyword']}\" zusammengeführt.");
            header('Location: /admin/keywords');
            exit;
        } catch (Throwable $e) {
            $dbw->rollBack();
            error_log('keyword_merge error: ' . $e);
            $errors[] = 'Zusammenführen fehlgeschlagen — Details im Server-Log.';
        }
    }
}

$allKeywords = $db->query('
    SELECT k.id, k.keyword, COUNT(pk.paper_id) as paper_count
    FROM keywords k
    LEFT JOIN paper_keywords pk ON pk.keyword_id = k.id
    GROUP BY k.id
    ORDER BY k.keyword
')->fetchAll();
?>

<h1 class="mb-4">Keywords zusammenführen</h1>

<p class="text-muted">Alle Paper-Verknüpfungen des Quell-Keywords werden auf das Ziel-Keyword übertragen. Das Quell-Keyword wird anschließend gelöscht.</p>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>

            <div class="row g-3">
                <div class="col-md-5">
                    <label for="source_id" class="form-label">Quell-Keyword (wird gelöscht)</label>
                    <select class="form-select" id="source_id" name="source_id" required>
                        <option value="">-- auswählen --</option>
                        <?php foreach ($allKeywords as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= e($k['keyword']) ?> (<?= $k['paper_count'] ?> Papers)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end justify-content-center pb-2">
                    <i class="bi bi-arrow-right fs-4"></i>
                </div>
                <div class="col-md-5">
                    <label for="target_id" class="form-label">Ziel-Keyword (bleibt erhalten)</label>
                    <select class="form-select" id="target_id" name="target_id" required>
                        <option value="">-- auswählen --</option>
                        <?php foreach ($allKeywords as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= e($k['keyword']) ?> (<?= $k['paper_count'] ?> Papers)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-warning"
                        data-confirm="Quell-Keyword wird unwiderruflich gelöscht. Fortfahren?">
                    <i class="bi bi-intersect"></i> Zusammenführen
                </button>
                <a href="/admin/keywords" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
