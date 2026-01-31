<?php
$kwId = $params['id'] ?? null;
$adminPageTitle = 'Keyword bearbeiten';

$db = getDb();
$kw = $db->prepare('SELECT * FROM keywords WHERE id = ?');
$kw->execute([$kwId]);
$kw = $kw->fetch();

if (!$kw) {
    setFlash('danger', 'Keyword nicht gefunden.');
    header('Location: /admin/keywords');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $keyword = trim($_POST['keyword'] ?? '');

    $errors = [];
    if (empty($keyword)) $errors[] = 'Keyword erforderlich.';

    if (empty($errors) && $keyword !== $kw['keyword']) {
        $check = $db->prepare('SELECT id FROM keywords WHERE keyword = ? AND id != ?');
        $check->execute([$keyword, $kwId]);
        if ($check->fetch()) {
            $errors[] = "Keyword \"{$keyword}\" existiert bereits. Verwende die Merge-Funktion.";
        }
    }

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->prepare('UPDATE keywords SET keyword = ? WHERE id = ?')
            ->execute([$keyword, $kwId]);

        setFlash('success', 'Keyword aktualisiert.');
        header('Location: /admin/keywords');
        exit;
    }
}
?>

<h1 class="mb-4">Keyword bearbeiten</h1>

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
                <div class="col-md-6">
                    <label for="keyword" class="form-label">Keyword *</label>
                    <input type="text" class="form-control" id="keyword" name="keyword"
                           value="<?= e($keyword ?? $kw['keyword']) ?>" required>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Speichern
                </button>
                <a href="/admin/keywords" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
