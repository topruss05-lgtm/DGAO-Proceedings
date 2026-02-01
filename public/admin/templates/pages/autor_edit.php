<?php
$autorId = $params['id'] ?? null;
$adminPageTitle = 'Autor bearbeiten';

$db = getDb();
$autor = $db->prepare('SELECT * FROM autoren WHERE id = ?');
$autor->execute([$autorId]);
$autor = $autor->fetch();

if (!$autor) {
    setFlash('danger', 'Autor nicht gefunden.');
    header('Location: /admin/autoren');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $vorname = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $affiliation = trim($_POST['affiliation'] ?? '');

    $errors = [];
    if (empty($nachname)) $errors[] = 'Nachname erforderlich.';

    // Prüfen ob Kombination bereits existiert (anderer Autor)
    if (empty($errors)) {
        $check = $db->prepare('SELECT id FROM autoren WHERE nachname = ? AND vorname = ? AND id != ?');
        $check->execute([$nachname, $vorname, $autorId]);
        if ($check->fetch()) {
            $errors[] = "Ein Autor \"{$vorname} {$nachname}\" existiert bereits. Verwende die Merge-Funktion.";
        }
    }

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->prepare('UPDATE autoren SET vorname = ?, nachname = ?, affiliation = ? WHERE id = ?')
            ->execute([$vorname, $nachname, $affiliation, $autorId]);

        setFlash('success', 'Autor aktualisiert.');
        header('Location: /admin/autoren');
        exit;
    }
}
?>

<h1 class="mb-4">Autor bearbeiten</h1>

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
                <div class="col-md-4">
                    <label for="vorname" class="form-label">Vorname / Initialen</label>
                    <input type="text" class="form-control" id="vorname" name="vorname"
                           value="<?= e($vorname ?? $autor['vorname']) ?>">
                    <div class="form-text">z.B. "A.", "K.-H.", "Hans Peter"</div>
                </div>
                <div class="col-md-4">
                    <label for="nachname" class="form-label">Nachname *</label>
                    <input type="text" class="form-control" id="nachname" name="nachname"
                           value="<?= e($nachname ?? $autor['nachname']) ?>" required>
                    <div class="form-text">z.B. "Schiebelbein", "von Bally"</div>
                </div>
                <div class="col-md-4">
                    <label for="affiliation" class="form-label">Affiliation</label>
                    <input type="text" class="form-control" id="affiliation" name="affiliation"
                           value="<?= e($affiliation ?? $autor['affiliation']) ?>">
                    <div class="form-text">z.B. "Universität Erlangen"</div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Speichern
                </button>
                <a href="/admin/autoren" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
