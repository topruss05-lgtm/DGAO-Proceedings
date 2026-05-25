<?php
require_once __DIR__ . '/../../pdf_parser.php';
require_once __DIR__ . '/../../news_events.php';

const BOOKLET_DIR = __DIR__ . '/../../../../booklets';

$nummer = $params['nummer'] ?? null;
$isNew = $nummer === null;
$adminPageTitle = $isNew ? 'Neue Tagung anlegen' : "Tagung {$nummer} bearbeiten";

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

$errors = [];
$importStats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $pdfFile = $_FILES['pdf_file'] ?? null;
    $hasPdf  = $pdfFile && ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $reimport = isset($_POST['reimport']);

    // ---------- NEU: PDF Pflicht; alles Übrige aus dem PDF ziehen ----------
    if ($isNew) {
        if (!$hasPdf) {
            $errors[] = 'Bitte das Tagungsband-PDF hochladen — alle weiteren Daten werden daraus extrahiert.';
        } elseif ($pdfFile['size'] > 30 * 1024 * 1024) {
            $errors[] = 'PDF zu groß (max. 30 MB).';
        } else {
            $fh = fopen($pdfFile['tmp_name'], 'rb');
            $magic = $fh ? fread($fh, 4) : '';
            if ($fh) fclose($fh);
            if ($magic !== '%PDF') $errors[] = 'Datei ist keine gültige PDF.';
        }

        $data = ['nummer'=>0,'jahr'=>0,'ort'=>'','datum_von'=>'','datum_bis'=>'',
                 'einreichungsfrist'=>'', 'vorlage_phase_aktiv'=>0];
        $parsedRows = null;

        if (empty($errors)) {
            $extracted = extractPdfText($pdfFile['tmp_name']);
            if ($extracted['error'] !== null) {
                $errors[] = 'PDF-Extraktion fehlgeschlagen: ' . $extracted['error'];
            } else {
                $meta = extractTagungMetadata($extracted['text']);
                if (!empty($meta['errors'])) {
                    $errors = array_merge($errors, $meta['errors']);
                }
                $data['nummer']    = $meta['nummer'];
                $data['jahr']      = $meta['jahr'];
                $data['ort']       = $meta['ort'];
                $data['datum_von'] = $meta['datum_von'];
                $data['datum_bis'] = $meta['datum_bis'];
                $data['einreichungsfrist'] = $meta['einreichungsfrist'] ?? '';

                // Konflikt: Tagung bereits vorhanden?
                if (empty($errors)) {
                    $exists = getDb()->prepare('SELECT 1 FROM tagungen WHERE nummer = ?');
                    $exists->execute([$data['nummer']]);
                    if ($exists->fetchColumn()) {
                        $errors[] = sprintf(
                            'Tagung %d existiert bereits. Zum Re-Import bitte über „Bearbeiten" gehen und „Beiträge ersetzen" anhaken.',
                            $data['nummer']
                        );
                    }
                }

                if (empty($errors)) {
                    $parsedFull = parsePdfText(
                        $extracted['text'], $data['jahr'], $data['datum_von'], $data['datum_bis']
                    );
                    $parsedRows = $parsedFull['rows'] ?? [];
                    if (empty($parsedRows)) {
                        $errors[] = 'Keine Beiträge im PDF erkannt — ist das das offizielle Tagungsband-Booklet?';
                    }
                }
            }
        }
    }

    // ---------- EDIT: nur Metadaten + optional Re-Import ----------
    if (!$isNew) {
        $data = [
            'nummer'    => (int)($_POST['nummer'] ?? $tagung['nummer']),
            'jahr'      => (int)($_POST['jahr'] ?? 0),
            'ort'       => trim($_POST['ort'] ?? ''),
            'datum_von' => trim($_POST['datum_von'] ?? ''),
            'datum_bis' => trim($_POST['datum_bis'] ?? ''),
            'einreichungsfrist' => trim($_POST['einreichungsfrist'] ?? ''),
            'vorlage_phase_aktiv' => isset($_POST['vorlage_phase_aktiv']) ? 1 : 0,
        ];
        if ($data['jahr'] < 1900 || $data['jahr'] > 2100) $errors[] = 'Jahr ungültig.';

        $parsedRows = null;
        if ($hasPdf && empty($errors)) {
            if ($pdfFile['size'] > 30 * 1024 * 1024) $errors[] = 'PDF zu groß (max. 30 MB).';
            $extracted = extractPdfText($pdfFile['tmp_name']);
            if ($extracted['error'] !== null) {
                $errors[] = 'PDF-Extraktion fehlgeschlagen: ' . $extracted['error'];
            } elseif ($reimport) {
                $parsedFull = parsePdfText(
                    $extracted['text'], $data['jahr'], $data['datum_von'], $data['datum_bis']
                );
                $parsedRows = $parsedFull['rows'] ?? [];
            }
        }
    }

    // ---------- DB-Persistenz ----------
    if (empty($errors)) {
        $db = getDbAdmin();
        $db->beginTransaction();
        try {
            if ($data['vorlage_phase_aktiv'] === 1) {
                $db->prepare('UPDATE tagungen SET vorlage_phase_aktiv = 0 WHERE nummer != ?')
                   ->execute([$data['nummer']]);
            }
            $stmt = $db->prepare(
                'INSERT INTO tagungen (nummer, jahr, ort, datum_von, datum_bis, einreichungsfrist, vorlage_phase_aktiv)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(nummer) DO UPDATE SET
                    jahr = excluded.jahr,
                    ort = excluded.ort,
                    datum_von = excluded.datum_von,
                    datum_bis = excluded.datum_bis,
                    einreichungsfrist = excluded.einreichungsfrist,
                    vorlage_phase_aktiv = excluded.vorlage_phase_aktiv'
            );
            $stmt->execute([
                $data['nummer'], $data['jahr'], $data['ort'],
                $data['datum_von'], $data['datum_bis'],
                $data['einreichungsfrist'] !== '' ? $data['einreichungsfrist'] : null,
                $data['vorlage_phase_aktiv'],
            ]);
            $db->commit();

            // News-Auto-Trigger: erkennt Status-Wechsel
            // (vorlage_phase_aktiv 0↔1, einreichungsfrist set/changed).
            // $tagung ist der pre-save-State (null bei isNew).
            newsOnTagungSaved($tagung ?: null, $data);
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('tagung_edit save error: ' . $e);
            $errors[] = 'Speichern fehlgeschlagen — Details im Server-Log.';
        }
    }

    if (empty($errors) && !empty($parsedRows)) {
        $overwrite = $isNew || $reimport;
        $importResult = executeImport($data['nummer'], $parsedRows, $overwrite);
        if (!$importResult['success']) {
            $errors[] = $importResult['message'];
        } else {
            $importStats = $importResult['stats'];
            if (!is_dir(BOOKLET_DIR)) {
                if (!@mkdir(BOOKLET_DIR, 0755, true) && !is_dir(BOOKLET_DIR)) {
                    error_log('tagung_edit: Booklet-Dir konnte nicht angelegt werden: ' . BOOKLET_DIR);
                }
            }
            $bookletDest = BOOKLET_DIR . '/DGaO_' . $data['jahr'] . '.pdf';
            if (!@copy($pdfFile['tmp_name'], $bookletDest)) {
                error_log('tagung_edit: Booklet-Kopie fehlgeschlagen: ' . $bookletDest);
            }
            // Proceedings-Online-News, wenn Papers importiert wurden.
            $paperCount = (int)($importStats['papers'] ?? 0);
            if ($paperCount > 0) {
                newsOnTagungProceedingsOnline($data, $paperCount);
            }
        }
    }

    if (empty($errors)) {
        if ($isNew) {
            $msg = sprintf(
                'Tagung %d (%s, %s bis %s) angelegt — %d Beiträge importiert (%d Autoren-Zuordnungen).',
                $data['nummer'], $data['ort'], $data['datum_von'], $data['datum_bis'],
                $importStats['papers'], $importStats['authors']
            );
        } else {
            $msg = $importStats
                ? "Tagung aktualisiert — {$importStats['papers']} Beiträge importiert."
                : 'Tagung aktualisiert.';
        }
        setFlash('success', $msg);
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

<?php if ($isNew): ?>
<!-- ============================================================
     NEU: Komplett aus dem PDF — kein Tippen, nur Upload.
     ============================================================ -->
<div class="card">
    <div class="card-body">
        <p class="text-muted mb-4">
            Lade das offizielle DGaO-Tagungsband-Booklet hoch. Tagungsnummer, Jahr, Ort, Datum,
            sowie alle Beiträge, Autor:innen, Affiliations und Abstracts werden automatisch
            extrahiert. Du musst nichts manuell eintragen.
        </p>

        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <label for="pdf_file" class="form-label fw-semibold">
                <i class="bi bi-file-earmark-pdf"></i> Tagungsband (PDF) *
            </label>
            <input type="file" class="form-control" id="pdf_file" name="pdf_file"
                   accept=".pdf,application/pdf" required>
            <div class="form-text mb-4">
                Max. 30 MB. Wird unter <code>booklets/DGaO_{Jahr}.pdf</code> archiviert.
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-magic"></i> PDF einlesen &amp; Tagung anlegen
            </button>
            <a href="/admin/tagungen" class="btn btn-outline-secondary btn-lg">Abbrechen</a>
        </form>
    </div>
</div>

<details class="mt-3">
    <summary class="text-muted small">Was wird extrahiert?</summary>
    <ul class="small text-muted mt-2">
        <li>Tagungsnummer aus „zur N. Jahrestagung der DGaO"</li>
        <li>Datum aus „vom DD. Monat bis DD. Monat YYYY"</li>
        <li>Ort aus „in &lt;Stadt&gt;" nach der Mitgliederversammlung</li>
        <li>Beiträge: Code, Typ, Titel, Autor:innen, Affiliations, Email, Abstract,
            Termin und Saal</li>
        <li>Cross-Validation gegen die Programmübersicht (S. 4-5)</li>
        <li>Zurückgezogene Beiträge („- ZURÜCKGEZOGEN -") werden übersprungen</li>
    </ul>
</details>

<?php else: ?>
<!-- ============================================================
     EDIT: Metadaten korrigieren + optionaler Re-Import.
     ============================================================ -->
<div class="card">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>

            <h2 class="h6 text-muted text-uppercase mb-3" style="letter-spacing:.08em;">Tagungsdaten</h2>
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="nummer" class="form-label">Tagungsnummer</label>
                    <input type="number" class="form-control" id="nummer" name="nummer"
                           value="<?= e((string)($data['nummer'] ?? $tagung['nummer'])) ?>"
                           readonly>
                </div>
                <div class="col-md-3">
                    <label for="jahr" class="form-label">Jahr *</label>
                    <input type="number" class="form-control" id="jahr" name="jahr"
                           value="<?= e((string)($data['jahr'] ?? $tagung['jahr'])) ?>"
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
                <div class="col-md-6">
                    <label for="einreichungsfrist" class="form-label">
                        Einreichungsfrist Manuskript
                    </label>
                    <input type="date" class="form-control" id="einreichungsfrist" name="einreichungsfrist"
                           value="<?= e($data['einreichungsfrist'] ?? $tagung['einreichungsfrist'] ?? '') ?>">
                    <div class="form-text">
                        Wird auf <code>/einreichen</code> öffentlich angezeigt.
                        Beim PDF-Import aus dem Tagungsband wird die Frist
                        („31.07.YYYY") falls erkennbar automatisch ausgefüllt.
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <h2 class="h6 text-muted text-uppercase mb-2" style="letter-spacing:.08em;">
                Tagungsband neu einlesen (optional)
            </h2>
            <p class="form-text mt-0 mb-3">
                Beiträge erneut aus einem aktualisierten Tagungsband extrahieren.
                Zum Aktivieren bitte zusätzlich „Beiträge ersetzen" anhaken — sonst wird die PDF ignoriert.
            </p>
            <input type="file" class="form-control mb-2" id="pdf_file" name="pdf_file"
                   accept=".pdf,application/pdf">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="reimport" name="reimport" value="1">
                <label class="form-check-label" for="reimport">
                    <strong>Beiträge ersetzen</strong> &mdash; bestehende Papers/Autoren-Zuordnungen werden gelöscht.
                </label>
            </div>

            <hr class="my-4">

            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="vorlage_phase_aktiv" name="vorlage_phase_aktiv" value="1"
                       <?= ($data['vorlage_phase_aktiv'] ?? (int)($tagung['vorlage_phase_aktiv'] ?? 0)) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="vorlage_phase_aktiv">
                    <strong>Manuskript-Vorlagen-Phase aktiv</strong>
                </label>
                <div class="form-text">
                    Wenn aktiv: Autor:innen können Manuskript-Vorlagen öffentlich herunterladen.
                    Nur eine Tagung gleichzeitig — Aktivieren deaktiviert andere automatisch.
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
<?php endif; ?>
