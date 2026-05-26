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

// Aktuelle Affiliation aus autor_institutionen (read-only Anzeige).
// Bearbeitung der Institute erfolgt jetzt über das Cleanup-Admin-Tool.
$affStmt = $db->prepare('
    SELECT i.id, i.name_de
    FROM autor_institutionen ai
    JOIN institutionen i ON i.id = ai.institut_id
    WHERE ai.autor_id = ? AND ai.ist_aktuell = 1
    LIMIT 1
');
$affStmt->execute([$autorId]);
$currentAff = $affStmt->fetch();
$autor['affiliation_display'] = $currentAff ? (string)$currentAff['name_de'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $vorname  = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');

    $errors = [];
    if (empty($nachname)) $errors[] = 'Nachname erforderlich.';

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->prepare('UPDATE autoren SET vorname = ?, nachname = ? WHERE id = ?')
            ->execute([$vorname, $nachname, $autorId]);

        setFlash('success', 'Autor aktualisiert. Institut-Zuordnungen über das Cleanup-Tool bearbeiten.');
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
                    <label class="form-label">Aktuelle Affiliation</label>
                    <input type="text" class="form-control" readonly
                           value="<?= e($autor['affiliation_display']) ?>">
                    <div class="form-text">Read-only — Institut-Zuordnungen via <a href="/admin/cleanup">Cleanup-Tool</a> bearbeiten.</div>
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
