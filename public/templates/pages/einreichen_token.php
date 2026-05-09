<?php
require_once __DIR__ . '/../../submissions.php';

$token = $params['token'];
$sub = loadSubmission($token);

if (!$sub) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$pageTitle = 'Manuskript hochladen — ' . SITE_NAME;
$canonicalUrl = BASE_URL . '/einreichen/' . $token;

// POST = Upload
$uploadError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sub['status'] === 'pending') {
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Bitte eine PDF-Datei auswählen.';
    } else {
        $result = storeSubmissionUpload($sub, $_FILES['pdf']);
        if (!$result['ok']) {
            $uploadError = $result['error'];
        } else {
            header('Location: /einreichen/' . $token . '/done');
            exit;
        }
    }
    // Nach Upload neu laden, damit "schon hochgeladen"-Status sichtbar
    $sub = loadSubmission($token);
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/">Start</a></li>
        <li class="breadcrumb-item"><a href="/einreichen">Einreichen</a></li>
        <li class="breadcrumb-item active">Upload</li>
    </ol>
</nav>

<h1 class="h4 mb-3"><i class="bi bi-cloud-upload"></i> Manuskript hochladen</h1>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6 text-muted mb-2">Beitrag</h2>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="badge bg-secondary"><?= e($sub['code']) ?></span>
            <small class="text-muted"><?= $sub['tagung_nummer'] ?>. Jahrestagung der DGaO</small>
        </div>
        <p class="mb-1"><strong><?= e($sub['titel']) ?></strong></p>
        <p class="small text-muted mb-0"><?= e($sub['hauptautor'] ?: '') ?></p>
    </div>
</div>

<?php if ($sub['status'] === 'expired'): ?>
<div class="alert alert-warning">
    <i class="bi bi-clock-history"></i>
    <strong>Link abgelaufen.</strong> Bitte fordere unter
    <a href="/einreichen">/einreichen</a> einen neuen Link an.
</div>

<?php elseif ($sub['status'] === 'approved'): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i>
    Dein Manuskript wurde bereits eingereicht und freigegeben. Vielen Dank!
</div>

<?php elseif ($sub['status'] === 'rejected'): ?>
<div class="alert alert-danger">
    <i class="bi bi-x-circle"></i>
    Diese Einreichung wurde abgelehnt.
    <?php if (!empty($sub['reviewer_note'])): ?>
        <br><strong>Begründung:</strong> <?= e($sub['reviewer_note']) ?>
    <?php endif; ?>
    <hr>
    Du kannst unter <a href="/einreichen">/einreichen</a> einen neuen Link anfordern und es erneut versuchen.
</div>

<?php else: // pending ?>

<?php if (!empty($sub['filename_stored'])): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Eine Datei (<?= e($sub['filename_original']) ?>) wurde bereits hochgeladen und wartet auf Freigabe durch das DGaO-Team.
    Du kannst sie aber durch einen erneuten Upload <strong>ersetzen</strong>.
</div>
<?php endif; ?>

<?php if ($uploadError): ?>
<div class="alert alert-danger"><?= e($uploadError) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="/einreichen/<?= e($token) ?>" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="pdf" class="form-label">Manuskript-PDF (max. 30 MB)</label>
                <input type="file" class="form-control" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
                <div class="form-text">
                    Bitte nutze die offiziell vorgegebene DGaO-Vorlage (LaTeX/Word).
                    Eine vorausgefüllte Vorlage findest du auf der
                    <a href="/paper/<?= e($sub['paper_id']) ?>">Beitrags-Detailseite</a>.
                </div>
            </div>

            <button type="submit" class="btn btn-accent">
                <i class="bi bi-cloud-upload"></i> PDF hochladen
            </button>
        </form>
    </div>
</div>

<?php endif; ?>

<div class="mt-4 small text-muted">
    Status: <strong><?= e($sub['status']) ?></strong>
    &middot; Gültig bis <?= e($sub['expires_at']) ?>
</div>
