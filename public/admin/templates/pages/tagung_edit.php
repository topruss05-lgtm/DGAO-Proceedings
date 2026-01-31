<?php
$nummer = $params['nummer'] ?? null;
$isNew = $nummer === null;
$adminPageTitle = $isNew ? 'Neue Tagung' : "Tagung {$nummer} bearbeiten";

$tagung = null;
if (!$isNew) {
    $tagung = getDb()->prepare('SELECT * FROM tagungen WHERE nummer = ?');
    $tagung->execute([$nummer]);
    $tagung = $tagung->fetch();
    if (!$tagung) {
        setFlash('danger', 'Tagung nicht gefunden.');
        header('Location: /admin/tagungen');
        exit;
    }
}

// POST verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'nummer'   => (int)($_POST['nummer'] ?? 0),
        'jahr'     => (int)($_POST['jahr'] ?? 0),
        'ort'      => trim($_POST['ort'] ?? ''),
        'datum_von' => trim($_POST['datum_von'] ?? ''),
        'datum_bis' => trim($_POST['datum_bis'] ?? ''),
    ];

    $errors = [];
    if ($data['nummer'] < 1) $errors[] = 'Tagungsnummer ungültig.';
    if ($data['jahr'] < 1900 || $data['jahr'] > 2100) $errors[] = 'Jahr ungültig.';

    if (empty($errors)) {
        $db = getDbAdmin();
        $stmt = $db->prepare('INSERT OR REPLACE INTO tagungen (nummer, jahr, ort, datum_von, datum_bis) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$data['nummer'], $data['jahr'], $data['ort'], $data['datum_von'], $data['datum_bis']]);

        setFlash('success', $isNew ? 'Tagung angelegt.' : 'Tagung aktualisiert.');
        header('Location: /admin/tagungen');
        exit;
    }
}
?>

<h1 class="mb-4"><?= e($adminPageTitle) ?></h1>

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
                <div class="col-md-3">
                    <label for="nummer" class="form-label">Tagungsnummer *</label>
                    <input type="number" class="form-control" id="nummer" name="nummer"
                           value="<?= e((string)($data['nummer'] ?? $tagung['nummer'] ?? '')) ?>"
                           <?= !$isNew ? 'readonly' : '' ?> required min="1">
                </div>
                <div class="col-md-3">
                    <label for="jahr" class="form-label">Jahr *</label>
                    <input type="number" class="form-control" id="jahr" name="jahr"
                           value="<?= e((string)($data['jahr'] ?? $tagung['jahr'] ?? date('Y'))) ?>"
                           required min="1900" max="2100">
                </div>
                <div class="col-md-6">
                    <label for="ort" class="form-label">Ort</label>
                    <input type="text" class="form-control" id="ort" name="ort"
                           value="<?= e($data['ort'] ?? $tagung['ort'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="datum_von" class="form-label">Datum von</label>
                    <input type="date" class="form-control" id="datum_von" name="datum_von"
                           value="<?= e($data['datum_von'] ?? $tagung['datum_von'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="datum_bis" class="form-label">Datum bis</label>
                    <input type="date" class="form-control" id="datum_bis" name="datum_bis"
                           value="<?= e($data['datum_bis'] ?? $tagung['datum_bis'] ?? '') ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Speichern
                </button>
                <a href="/admin/tagungen" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
