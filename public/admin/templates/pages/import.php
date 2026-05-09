<?php
require_once __DIR__ . '/../../pdf_parser.php';

$adminPageTitle = 'Daten-Import';

$step   = $_POST['step'] ?? 'upload';
$source = $_POST['source'] ?? 'pdf';

// --- Step 3: Confirm ---
if ($step === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

// --- Step 2: Preview ---
if ($step === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $tagungNummer = (int)($_POST['tagung_nummer'] ?? 0);
    $tagungJahr   = (int)($_POST['tagung_jahr'] ?? 0);
    $tagungOrt    = trim($_POST['tagung_ort'] ?? '');
    $tagungVon    = trim($_POST['tagung_datum_von'] ?? '');
    $tagungBis    = trim($_POST['tagung_datum_bis'] ?? '');
    $overwrite    = isset($_POST['overwrite']);

    $errors = [];
    if ($tagungNummer < 1) $errors[] = 'Tagungsnummer ungültig.';
    if ($tagungJahr < 1900) $errors[] = 'Jahr ungültig.';

    $parsed = null;
    $crossValidation = null;

    if ($source === 'pdf') {
        // PDF-Branch: Tagungsband-Booklet
        $maxSize = 30 * 1024 * 1024;
        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'PDF-Datei fehlt oder Upload fehlgeschlagen.';
        } elseif ($_FILES['pdf_file']['size'] > $maxSize) {
            $errors[] = 'Datei zu groß (max. 30 MB).';
        } else {
            // Magic-Bytes-Check
            $fh = fopen($_FILES['pdf_file']['tmp_name'], 'rb');
            $magic = fread($fh, 4);
            fclose($fh);
            if ($magic !== '%PDF') {
                $errors[] = 'Datei ist keine gültige PDF (Magic-Bytes-Check fehlgeschlagen).';
            }
        }

        if (empty($errors)) {
            $extracted = extractPdfText($_FILES['pdf_file']['tmp_name']);
            if ($extracted['error'] !== null) {
                $errors[] = 'PDF-Extraktion fehlgeschlagen: ' . $extracted['error'];
            } else {
                $parsedFull = parsePdfText($extracted['text'], $tagungJahr, $tagungVon, $tagungBis);
                $parsed = ['error' => null, 'rows' => $parsedFull['rows']];

                // Cross-Validation gegen Programmübersicht
                $expected = extractExpectedCodes($extracted['text']);
                $foundCodes = array_map(fn($r) => $r['code'], $parsedFull['rows']);
                $crossValidation = [
                    'expected_count' => count($expected),
                    'found_count'    => count($foundCodes),
                    'missing'        => array_values(array_diff($expected, $foundCodes)),
                    'extra'          => array_values(array_diff($foundCodes, $expected)),
                ];
            }
        }
    } else {
        // CSV-Branch (legacy)
        $delimiter = $_POST['delimiter'] ?? ';';
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
        if (empty($errors)) {
            $parsed = parseCsvFile($_FILES['csv_file']['tmp_name'], $delimiter);
            if ($parsed['error'] !== null) {
                $errors[] = $parsed['error'];
            }
        }
    }

    if (!empty($errors)) {
        $uploadErrors = $errors;
        $step = 'upload';
    } else {
        // Validierung pro Zeile
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
            if (!empty($validation['errors'])) $totalErrors++;
        }

        $_SESSION['import_data'] = [
            'tagung' => [
                'nummer'    => $tagungNummer,
                'jahr'      => $tagungJahr,
                'ort'       => $tagungOrt,
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
            'source'      => $source,
            'crossValidation' => $crossValidation,
        ];
    }
}
?>

<h1 class="mb-4"><i class="bi bi-upload"></i> Daten-Import</h1>

<?php if ($step === 'upload'): ?>

<?php if (!empty($uploadErrors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($uploadErrors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item">
        <button class="nav-link <?= $source === 'pdf' ? 'active' : '' ?>" data-bs-toggle="tab"
                data-bs-target="#tab-pdf" type="button" role="tab">
            <i class="bi bi-file-earmark-pdf"></i> Tagungsband-PDF
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $source === 'csv' ? 'active' : '' ?>" data-bs-toggle="tab"
                data-bs-target="#tab-csv" type="button" role="tab">
            <i class="bi bi-filetype-csv"></i> CSV-Import
        </button>
    </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom p-4 bg-white">

    <!-- Tab 1: PDF -->
    <div class="tab-pane fade <?= $source === 'pdf' ? 'show active' : '' ?>" id="tab-pdf" role="tabpanel">
        <p class="text-muted mb-3">
            Lade den offiziellen DGaO-Tagungsband (PDF-Booklet) hoch. Beiträge, Autoren,
            Affiliations, Abstracts und Metadaten werden automatisch extrahiert. Die
            Programmübersicht (S. 4-5) wird zur Cross-Validation genutzt — fehlende oder
            zusätzliche Codes werden im nächsten Schritt deutlich angezeigt.
        </p>

        <form method="post" action="/admin/import" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="preview">
            <input type="hidden" name="source" value="pdf">

            <h6 class="mb-3">Tagungsdaten</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label for="pdf_tagung_nummer" class="form-label">Tagungsnummer *</label>
                    <input type="number" class="form-control" id="pdf_tagung_nummer" name="tagung_nummer"
                           value="<?= e($_POST['tagung_nummer'] ?? '') ?>" required min="1">
                </div>
                <div class="col-md-3">
                    <label for="pdf_tagung_jahr" class="form-label">Jahr *</label>
                    <input type="number" class="form-control" id="pdf_tagung_jahr" name="tagung_jahr"
                           value="<?= e($_POST['tagung_jahr'] ?? date('Y')) ?>" required min="1900" max="2100">
                </div>
                <div class="col-md-6">
                    <label for="pdf_tagung_ort" class="form-label">Ort</label>
                    <input type="text" class="form-control" id="pdf_tagung_ort" name="tagung_ort"
                           value="<?= e($_POST['tagung_ort'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="pdf_tagung_datum_von" class="form-label">Datum von *</label>
                    <input type="date" class="form-control" id="pdf_tagung_datum_von" name="tagung_datum_von"
                           value="<?= e($_POST['tagung_datum_von'] ?? '') ?>" required>
                    <div class="form-text">Wird für Wochentag→Datum-Mapping benötigt.</div>
                </div>
                <div class="col-md-3">
                    <label for="pdf_tagung_datum_bis" class="form-label">Datum bis *</label>
                    <input type="date" class="form-control" id="pdf_tagung_datum_bis" name="tagung_datum_bis"
                           value="<?= e($_POST['tagung_datum_bis'] ?? '') ?>" required>
                </div>
            </div>

            <h6 class="mb-3">Tagungsband (PDF)</h6>
            <div class="mb-4">
                <input type="file" class="form-control" id="pdf_file" name="pdf_file" accept=".pdf,application/pdf" required>
                <div class="form-text">Max. 30 MB. Das offizielle Tagungsband-Booklet (das mit Cover, Programmübersicht und allen Abstracts).</div>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="pdf_overwrite" name="overwrite" checked>
                    <label class="form-check-label" for="pdf_overwrite">
                        Bestehende Daten dieser Tagung überschreiben
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-eye"></i> Vorschau anzeigen
            </button>
        </form>
    </div>

    <!-- Tab 2: CSV -->
    <div class="tab-pane fade <?= $source === 'csv' ? 'show active' : '' ?>" id="tab-csv" role="tabpanel">
        <p class="text-muted mb-3">
            Falls die Daten bereits als CSV vorliegen — z.B. aus einem Migrations-Export.
        </p>

        <form method="post" action="/admin/import" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="preview">
            <input type="hidden" name="source" value="csv">

            <h6 class="mb-3">Tagungsdaten</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label for="csv_tagung_nummer" class="form-label">Tagungsnummer *</label>
                    <input type="number" class="form-control" id="csv_tagung_nummer" name="tagung_nummer"
                           value="<?= e($_POST['tagung_nummer'] ?? '') ?>" required min="1">
                </div>
                <div class="col-md-3">
                    <label for="csv_tagung_jahr" class="form-label">Jahr *</label>
                    <input type="number" class="form-control" id="csv_tagung_jahr" name="tagung_jahr"
                           value="<?= e($_POST['tagung_jahr'] ?? date('Y')) ?>" required min="1900" max="2100">
                </div>
                <div class="col-md-6">
                    <label for="csv_tagung_ort" class="form-label">Ort</label>
                    <input type="text" class="form-control" id="csv_tagung_ort" name="tagung_ort"
                           value="<?= e($_POST['tagung_ort'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="csv_tagung_datum_von" class="form-label">Datum von</label>
                    <input type="date" class="form-control" id="csv_tagung_datum_von" name="tagung_datum_von"
                           value="<?= e($_POST['tagung_datum_von'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="csv_tagung_datum_bis" class="form-label">Datum bis</label>
                    <input type="date" class="form-control" id="csv_tagung_datum_bis" name="tagung_datum_bis"
                           value="<?= e($_POST['tagung_datum_bis'] ?? '') ?>">
                </div>
            </div>

            <h6 class="mb-3">CSV-Datei</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt" required>
                    <div class="form-text">Max. 5 MB. Spalten: Code, Typ, Titel, Autoren, Abstract, Keywords, …</div>
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
                    <input class="form-check-input" type="checkbox" id="csv_overwrite" name="overwrite" checked>
                    <label class="form-check-label" for="csv_overwrite">
                        Bestehende Daten dieser Tagung überschreiben
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-eye"></i> Vorschau anzeigen
            </button>
        </form>
    </div>
</div>

<?php elseif ($step === 'preview' && isset($previewData)): ?>

<div class="alert alert-info">
    <strong>Tagung <?= $previewData['tagung']['nummer'] ?></strong>
    (<?= e($previewData['tagung']['jahr']) ?><?= $previewData['tagung']['ort'] ? ', ' . e($previewData['tagung']['ort']) : '' ?>)
    &mdash; <?= count($previewData['rows']) ?> Beiträge erkannt
    <?php if ($previewData['totalErrors'] > 0): ?>
        <span class="text-danger">, <?= $previewData['totalErrors'] ?> Validierungsfehler</span>
    <?php endif; ?>
    <?php if ($previewData['overwrite']): ?>
        <span class="text-warning"> &mdash; Bestehende Daten werden überschrieben</span>
    <?php endif; ?>
</div>

<?php if (!empty($previewData['crossValidation'])): $cv = $previewData['crossValidation']; ?>
<div class="alert alert-<?= empty($cv['missing']) ? 'success' : 'warning' ?>">
    <strong>Cross-Validation gegen Programmübersicht:</strong>
    <?= $cv['expected_count'] ?> Vortrags-Codes in der Übersicht erwartet, <?= $cv['found_count'] ?> Beiträge geparst.
    <?php if (!empty($cv['missing'])): ?>
        <br><span class="text-danger"><strong>Fehlend (in Übersicht, nicht geparst):</strong> <?= e(implode(', ', $cv['missing'])) ?></span>
    <?php endif; ?>
    <?php if (!empty($cv['extra'])): ?>
        <?php
            // Poster sind erwartungsgemäß "extra" weil Programmübersicht sie nicht einzeln listet.
            $nonPosterExtras = array_filter($cv['extra'], fn($c) => $c[0] !== 'P');
        ?>
        <?php if (!empty($nonPosterExtras)): ?>
            <br><span class="text-warning"><strong>Zusätzlich gefunden (nicht in Übersicht):</strong> <?= e(implode(', ', $nonPosterExtras)) ?></span>
        <?php endif; ?>
        <?php $posterCount = count($cv['extra']) - count($nonPosterExtras); if ($posterCount > 0): ?>
            <br><small class="text-muted">(+ <?= $posterCount ?> Poster — werden in der Programmübersicht nicht einzeln gelistet)</small>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

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
                    <th>Affiliation</th>
                    <th>Email</th>
                    <th>Datum</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewData['rows'] as $i => $vr): ?>
                <tr class="<?= !empty($vr['errors']) ? 'table-danger' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= e($vr['data']['code'] ?? '') ?></strong></td>
                    <td><span class="badge bg-secondary"><?= e($vr['typ'] ?? '') ?></span></td>
                    <td title="<?= e($vr['data']['titel'] ?? '') ?>"><?= e(mb_strimwidth($vr['data']['titel'] ?? '', 0, 50, '…')) ?></td>
                    <td title="<?= e($vr['data']['autoren'] ?? '') ?>"><?= e(mb_strimwidth($vr['data']['autoren'] ?? '', 0, 30, '…')) ?></td>
                    <td title="<?= e($vr['data']['affiliationen'] ?? '') ?>"><?= e(mb_strimwidth(str_replace("\n", ' / ', $vr['data']['affiliationen'] ?? ''), 0, 25, '…')) ?></td>
                    <td><?= e(mb_strimwidth($vr['data']['kontakt_email'] ?? '', 0, 25, '…')) ?></td>
                    <td><?= e($vr['data']['datum'] ?? '') ?></td>
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
