<?php
$adminPageTitle = 'CSV-Import';

// Schritt bestimmen
$step = $_POST['step'] ?? 'upload';

if ($step === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Schritt 3: Import ausführen
    verifyCsrf();

    $importData = $_SESSION['import_data'] ?? null;
    if (!$importData) {
        setFlash('danger', 'Import-Daten abgelaufen. Bitte erneut hochladen.');
        header('Location: /admin/import');
        exit;
    }

    $result = executeImport($importData['tagung'], $importData['rows'], $importData['overwrite']);
    unset($_SESSION['import_data']);

    setFlash($result['success'] ? 'success' : 'danger', $result['message']);
    header('Location: /admin/import');
    exit;
}

if ($step === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Schritt 2: CSV parsen und Vorschau
    verifyCsrf();

    $tagungNummer = (int)($_POST['tagung_nummer'] ?? 0);
    $tagungJahr = (int)($_POST['tagung_jahr'] ?? 0);
    $tagungOrt = trim($_POST['tagung_ort'] ?? '');
    $tagungVon = trim($_POST['tagung_datum_von'] ?? '');
    $tagungBis = trim($_POST['tagung_datum_bis'] ?? '');
    $delimiter = $_POST['delimiter'] ?? ';';
    $overwrite = isset($_POST['overwrite']);

    $errors = [];
    if ($tagungNummer < 1) $errors[] = 'Tagungsnummer ungültig.';
    if ($tagungJahr < 1900) $errors[] = 'Jahr ungültig.';

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'CSV-Datei fehlt oder Upload fehlgeschlagen.';
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Datei zu groß (max. 5 MB).';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $errors[] = 'Nur CSV-Dateien erlaubt.';
        }
    }

    if (!empty($errors)) {
        $uploadErrors = $errors;
        $step = 'upload';
    } else {
        $parsed = parseCsvFile($_FILES['csv_file']['tmp_name'], $delimiter);

        if ($parsed['error']) {
            $uploadErrors = [$parsed['error']];
            $step = 'upload';
        } else {
            // Zeilen validieren
            $validatedRows = [];
            $totalErrors = 0;

            foreach ($parsed['rows'] as $row) {
                $validation = validateImportRow($row);
                $validatedRows[] = [
                    'data'    => $row,
                    'errors'  => $validation['errors'],
                    'typ'     => $validation['typ_norm'],
                    'datum'   => $validation['datum'],
                ];
                if (!empty($validation['errors'])) {
                    $totalErrors++;
                }
            }

            // Daten in Session speichern
            $_SESSION['import_data'] = [
                'tagung' => [
                    'nummer'   => $tagungNummer,
                    'jahr'     => $tagungJahr,
                    'ort'      => $tagungOrt,
                    'datum_von' => $tagungVon,
                    'datum_bis' => $tagungBis,
                ],
                'rows'      => $parsed['rows'],
                'overwrite' => $overwrite,
            ];

            $previewData = [
                'rows'        => $validatedRows,
                'totalErrors' => $totalErrors,
                'tagung'      => $_SESSION['import_data']['tagung'],
                'overwrite'   => $overwrite,
            ];
        }
    }
}
?>

<h1 class="mb-4"><i class="bi bi-upload"></i> CSV-Import</h1>

<?php if ($step === 'upload'): ?>
<!-- Schritt 1: Upload-Formular -->

<?php if (!empty($uploadErrors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($uploadErrors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><strong>Neue Konferenzdaten importieren</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/import" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="preview">

            <h6 class="mb-3">Tagungsdaten</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label for="tagung_nummer" class="form-label">Tagungsnummer *</label>
                    <input type="number" class="form-control" id="tagung_nummer" name="tagung_nummer"
                           value="<?= e($_POST['tagung_nummer'] ?? '') ?>" required min="1">
                </div>
                <div class="col-md-3">
                    <label for="tagung_jahr" class="form-label">Jahr *</label>
                    <input type="number" class="form-control" id="tagung_jahr" name="tagung_jahr"
                           value="<?= e($_POST['tagung_jahr'] ?? date('Y')) ?>" required min="1900" max="2100">
                </div>
                <div class="col-md-6">
                    <label for="tagung_ort" class="form-label">Ort</label>
                    <input type="text" class="form-control" id="tagung_ort" name="tagung_ort"
                           value="<?= e($_POST['tagung_ort'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="tagung_datum_von" class="form-label">Datum von</label>
                    <input type="date" class="form-control" id="tagung_datum_von" name="tagung_datum_von"
                           value="<?= e($_POST['tagung_datum_von'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="tagung_datum_bis" class="form-label">Datum bis</label>
                    <input type="date" class="form-control" id="tagung_datum_bis" name="tagung_datum_bis"
                           value="<?= e($_POST['tagung_datum_bis'] ?? '') ?>">
                </div>
            </div>

            <h6 class="mb-3">CSV-Datei</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <label for="csv_file" class="form-label">CSV-Datei auswählen *</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                    <div class="form-text">Max. 5 MB. Erwartete Spalten: Code, Typ, Titel, Autoren, Abstract, Keywords, ...</div>
                </div>
                <div class="col-md-4">
                    <label for="delimiter" class="form-label">Trennzeichen</label>
                    <select class="form-select" id="delimiter" name="delimiter">
                        <option value=";" selected>Semikolon (;)</option>
                        <option value=",">Komma (,)</option>
                        <option value="&#9;">Tab</option>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" checked>
                    <label class="form-check-label" for="overwrite">
                        Bestehende Daten dieser Tagung überschreiben
                    </label>
                    <div class="form-text">Vorhandene Papers, Autoren-Verknüpfungen und Keywords-Verknüpfungen dieser Tagung werden vor dem Import gelöscht.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-eye"></i> Vorschau anzeigen
            </button>
        </form>
    </div>
</div>

<?php elseif ($step === 'preview' && isset($previewData)): ?>
<!-- Schritt 2: Vorschau -->

<div class="alert alert-info">
    <strong>Tagung <?= $previewData['tagung']['nummer'] ?></strong> (<?= e($previewData['tagung']['jahr']) ?>, <?= e($previewData['tagung']['ort']) ?>)
    &mdash; <?= count($previewData['rows']) ?> Beiträge erkannt
    <?php if ($previewData['totalErrors'] > 0): ?>
        <span class="text-danger">, <?= $previewData['totalErrors'] ?> Fehler</span>
    <?php endif; ?>
    <?php if ($previewData['overwrite']): ?>
        <span class="text-warning"> &mdash; Bestehende Daten werden überschrieben</span>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-hover table-sm import-preview-table mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Typ</th>
                    <th>Titel</th>
                    <th>Autoren</th>
                    <th>Keywords</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewData['rows'] as $i => $vr): ?>
                <tr class="<?= !empty($vr['errors']) ? 'table-danger' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($vr['data']['code'] ?? '') ?></strong></td>
                    <td><span class="badge bg-secondary"><?= e($vr['typ'] ?? '') ?></span></td>
                    <td title="<?= e($vr['data']['titel'] ?? '') ?>"><?= e(mb_strimwidth($vr['data']['titel'] ?? '', 0, 60, '...')) ?></td>
                    <td title="<?= e($vr['data']['autoren'] ?? '') ?>"><?= e(mb_strimwidth($vr['data']['autoren'] ?? '', 0, 40, '...')) ?></td>
                    <td><?= e(mb_strimwidth($vr['data']['keywords'] ?? '', 0, 30, '...')) ?></td>
                    <td>
                        <?php if (empty($vr['errors'])): ?>
                            <span class="import-status-ok"><i class="bi bi-check-circle-fill"></i></span>
                        <?php else: ?>
                            <span class="import-status-error" title="<?= e(implode('; ', $vr['errors'])) ?>">
                                <i class="bi bi-x-circle-fill"></i> <?= e(implode('; ', $vr['errors'])) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2">
    <form method="post" action="/admin/import">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="confirm">
        <button type="submit" class="btn btn-primary"
                <?= $previewData['totalErrors'] === count($previewData['rows']) ? 'disabled' : '' ?>>
            <i class="bi bi-check-lg"></i> <?= count($previewData['rows']) - $previewData['totalErrors'] ?> Beiträge importieren
        </button>
    </form>
    <a href="/admin/import" class="btn btn-outline-secondary">Abbrechen</a>
</div>

<?php endif; ?>
