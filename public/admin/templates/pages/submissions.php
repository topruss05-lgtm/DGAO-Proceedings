<?php
require_once __DIR__ . '/../../../submissions.php';

$adminPageTitle = 'Manuskript-Eingang';

$db = getDb();

// ---------- POST: PDF von Mail-Eingang zuordnen ----------
$uploadResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $paperId = trim($_POST['paper_id'] ?? '');
    $email   = trim($_POST['uploader_email'] ?? '');
    $file    = $_FILES['pdf_file'] ?? null;

    $errors = [];
    if ($paperId === '') $errors[] = 'Beitrag nicht ausgewählt.';
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Keine PDF-Datei hochgeladen.';
    }

    if (empty($errors)) {
        $res = adminCreateSubmissionFromMail($paperId, $email, $file);
        if ($res['ok']) {
            setFlash('success', 'Manuskript eingespielt — bereit zur Freigabe.');
            header('Location: /admin/submissions/' . $res['token']);
            exit;
        }
        $errors[] = $res['error'];
    }
    $uploadResult = ['errors' => $errors];
}

// ---------- Filter + Liste ----------
$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'expired', 'all'], true)) {
    $filter = 'pending';
}

$sql = 'SELECT s.*, p.code, p.titel, p.tagung_nummer, p.hauptautor
        FROM submissions s
        JOIN papers p ON p.id = s.paper_id';
$sqlParams = [];
if ($filter !== 'all') {
    $sql .= ' WHERE s.status = ?';
    $sqlParams[] = $filter;
}
$sql .= ' ORDER BY s.uploaded_at DESC NULLS LAST, s.requested_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($sqlParams);
$rows = $stmt->fetchAll();

$counts = $db->query(
    "SELECT status, COUNT(*) as n FROM submissions GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = array_merge(['pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0], $counts);

// Für das Dropdown: Beiträge der aktiven Vorlagen-Tagung anzeigen
$activeTagung = getCurrentVorlagenTagung();
$activeCode = null;
$papersForUpload = [];
if ($activeTagung) {
    $st = $db->prepare(
        'SELECT id, code, titel, hauptautor, hat_pdf
         FROM papers WHERE tagung_nummer = ?
         ORDER BY
            CASE typ
                WHEN \'hauptvortrag\'  THEN 1
                WHEN \'sondervortrag\' THEN 2
                WHEN \'vortrag\'       THEN 3
                WHEN \'poster\'        THEN 4
                ELSE 9
            END,
            substr(code,1,1), CAST(substr(code,2) AS INTEGER)'
    );
    $st->execute([$activeTagung['nummer']]);
    $papersForUpload = $st->fetchAll();
}
?>

<h1 class="mb-4"><i class="bi bi-envelope-paper"></i> Manuskript-Eingang</h1>

<p class="text-muted small mb-4">
    Autor:innen senden ihre fertigen Manuskripte per E-Mail an
    <code>sekretariat@dgao.de</code>. Hier können Sie die eingegangenen PDFs
    einem Beitrag zuordnen und für die Veröffentlichung freigeben.
</p>

<!-- ============================================================
     Upload-Formular: PDF eingegangen → einsortieren
     ============================================================ -->
<div class="card mb-4" style="border-left: 3px solid var(--bs-primary);">
    <div class="card-header bg-light">
        <strong><i class="bi bi-cloud-upload"></i> Eingegangenes Manuskript einsortieren</strong>
    </div>
    <div class="card-body">
        <?php if (!empty($uploadResult['errors'])): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($uploadResult['errors'] as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if (!$activeTagung): ?>
            <div class="alert alert-warning mb-0">
                Aktuell ist keine Tagung als <em>Vorlagen-Phase aktiv</em> markiert.
                Setzen Sie unter <a href="/admin/tagungen">Tagungen</a> den Schalter,
                damit hier die zugehörigen Beiträge zur Auswahl erscheinen.
            </div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" action="/admin/submissions">
            <?= csrfField() ?>

            <div class="row g-3">
                <div class="col-md-5">
                    <label for="paper_id" class="form-label">
                        Beitrag (<?= (int)$activeTagung['nummer'] ?>. Jahrestagung,
                        <?= (int)$activeTagung['jahr'] ?>) *
                    </label>
                    <select class="form-select" id="paper_id" name="paper_id" required>
                        <option value="">— Beitrag wählen —</option>
                        <?php foreach ($papersForUpload as $p):
                            $label = $p['code'] . ' — ' . mb_strimwidth($p['titel'], 0, 60, '…');
                            if (!empty($p['hauptautor'])) $label .= ' (' . $p['hauptautor'] . ')';
                            $hint = $p['hat_pdf'] ? ' ✓ hat schon PDF' : '';
                        ?>
                            <option value="<?= e($p['id']) ?>"><?= e($label . $hint) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="uploader_email" class="form-label">
                        Absender-Email (optional)
                    </label>
                    <input type="email" class="form-control" id="uploader_email" name="uploader_email"
                           placeholder="aus E-Mail-Header übernehmen">
                    <div class="form-text">
                        Leer lassen → Kontakt-E-Mail des Beitrags wird genutzt.
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="pdf_file" class="form-label">Manuskript-PDF *</label>
                    <input type="file" class="form-control" id="pdf_file" name="pdf_file"
                           accept=".pdf,application/pdf" required>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-cloud-upload"></i> Einsortieren &amp; Vorschau
                </button>
                <span class="text-muted small ms-2">
                    Wird im Status <em>„Ausstehend"</em> abgelegt — Sie geben anschließend frei.
                </span>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     Eingegangene Manuskripte — Liste
     ============================================================ -->
<ul class="nav nav-tabs mb-3">
    <?php foreach (['pending' => 'Ausstehend', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'expired' => 'Abgelaufen', 'all' => 'Alle'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="?filter=<?= $key ?>">
            <?= $label ?>
            <?php if ($key !== 'all'): ?>
                <span class="badge bg-secondary ms-1"><?= (int)$counts[$key] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php if (empty($rows)): ?>
<div class="alert alert-light text-center py-4">
    <i class="bi bi-inbox display-6 text-muted"></i>
    <p class="text-muted mb-0 mt-2">Keine Manuskripte mit Status <strong><?= e($filter) ?></strong>.</p>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Titel</th>
                    <th>Absender</th>
                    <th>Datei</th>
                    <th>Eingespielt</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['code']) ?></strong>
                        <small class="text-muted d-block"><?= $r['tagung_nummer'] ?>.</small></td>
                    <td title="<?= e($r['titel']) ?>"><?= e(mb_strimwidth($r['titel'], 0, 60, '…')) ?></td>
                    <td><?= e($r['uploader_email']) ?></td>
                    <td>
                        <?php if (!empty($r['filename_original'])): ?>
                            <i class="bi bi-file-earmark-pdf text-danger"></i>
                            <small><?= e($r['filename_original']) ?></small><br>
                            <small class="text-muted"><?= number_format(($r['file_size'] ?? 0) / 1024, 1, ',', '.') ?> KB</small>
                        <?php else: ?>
                            <small class="text-muted">— ohne Datei —</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= e($r['uploaded_at'] ?: $r['requested_at']) ?></small>
                    </td>
                    <td>
                        <?php
                        $badge = match($r['status']) {
                            'pending'  => 'bg-warning text-dark',
                            'approved' => 'bg-success',
                            'rejected' => 'bg-danger',
                            'expired'  => 'bg-secondary',
                            default    => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $badge ?>"><?= e($r['status']) ?></span>
                    </td>
                    <td>
                        <?php if (!empty($r['filename_stored'])): ?>
                        <a href="/admin/submissions/<?= e($r['token']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> Prüfen
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
