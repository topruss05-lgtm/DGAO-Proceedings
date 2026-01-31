<?php
$adminPageTitle = 'Autoren zusammenführen';
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $sourceId = (int)($_POST['source_id'] ?? 0);
    $targetId = (int)($_POST['target_id'] ?? 0);

    $errors = [];
    if ($sourceId === $targetId) $errors[] = 'Quell- und Ziel-Autor dürfen nicht identisch sein.';
    if ($sourceId < 1 || $targetId < 1) $errors[] = 'Beide Autoren müssen ausgewählt sein.';

    if (empty($errors)) {
        $source = $db->prepare('SELECT * FROM autoren WHERE id = ?');
        $source->execute([$sourceId]);
        $source = $source->fetch();

        $target = $db->prepare('SELECT * FROM autoren WHERE id = ?');
        $target->execute([$targetId]);
        $target = $target->fetch();

        if (!$source || !$target) {
            $errors[] = 'Einer der Autoren wurde nicht gefunden.';
        }
    }

    if (empty($errors)) {
        $dbw = getDbAdmin();
        $dbw->beginTransaction();

        try {
            // Alle paper_autoren-Einträge von source auf target umbiegen
            // Aber nur wenn target nicht bereits mit dem Paper verknüpft ist
            $papers = $dbw->prepare('SELECT paper_id, position, ist_hauptautor FROM paper_autoren WHERE autor_id = ?');
            $papers->execute([$sourceId]);

            foreach ($papers->fetchAll() as $pa) {
                // Prüfen ob target schon mit diesem Paper verknüpft ist
                $check = $dbw->prepare('SELECT COUNT(*) FROM paper_autoren WHERE paper_id = ? AND autor_id = ?');
                $check->execute([$pa['paper_id'], $targetId]);

                if ((int)$check->fetchColumn() === 0) {
                    $dbw->prepare('UPDATE paper_autoren SET autor_id = ? WHERE paper_id = ? AND autor_id = ?')
                        ->execute([$targetId, $pa['paper_id'], $sourceId]);
                } else {
                    // Duplikat: Quell-Verknüpfung löschen
                    $dbw->prepare('DELETE FROM paper_autoren WHERE paper_id = ? AND autor_id = ?')
                        ->execute([$pa['paper_id'], $sourceId]);
                }
            }

            // Quell-Autor löschen
            $dbw->prepare('DELETE FROM autoren WHERE id = ?')->execute([$sourceId]);
            $dbw->commit();

            setFlash('success', "Autor \"" . trim($source['vorname'] . ' ' . $source['nachname']) . "\" in \"" . trim($target['vorname'] . ' ' . $target['nachname']) . "\" zusammengeführt.");
            header('Location: /admin/autoren');
            exit;
        } catch (Exception $e) {
            $dbw->rollBack();
            $errors[] = 'Zusammenführen fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

// Alle Autoren für Dropdowns
$allAutoren = $db->query('
    SELECT a.id, a.vorname, a.nachname, COUNT(pa.paper_id) as paper_count
    FROM autoren a
    LEFT JOIN paper_autoren pa ON pa.autor_id = a.id
    GROUP BY a.id
    ORDER BY a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
')->fetchAll();
?>

<h1 class="mb-4">Autoren zusammenführen</h1>

<p class="text-muted">Alle Paper-Verknüpfungen des Quell-Autors werden auf den Ziel-Autor übertragen. Der Quell-Autor wird anschließend gelöscht.</p>

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
                    <label for="source_id" class="form-label">Quell-Autor (wird gelöscht)</label>
                    <select class="form-select" id="source_id" name="source_id" required>
                        <option value="">-- auswählen --</option>
                        <?php foreach ($allAutoren as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= e(trim($a['vorname'] . ' ' . $a['nachname'])) ?> (<?= $a['paper_count'] ?> Papers)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end justify-content-center pb-2">
                    <i class="bi bi-arrow-right fs-4"></i>
                </div>
                <div class="col-md-5">
                    <label for="target_id" class="form-label">Ziel-Autor (bleibt erhalten)</label>
                    <select class="form-select" id="target_id" name="target_id" required>
                        <option value="">-- auswählen --</option>
                        <?php foreach ($allAutoren as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= e(trim($a['vorname'] . ' ' . $a['nachname'])) ?> (<?= $a['paper_count'] ?> Papers)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-warning"
                        data-confirm="Quell-Autor wird unwiderruflich gelöscht. Fortfahren?">
                    <i class="bi bi-intersect"></i> Zusammenführen
                </button>
                <a href="/admin/autoren" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
