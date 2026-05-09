<?php
require_once __DIR__ . '/../../submissions.php';

$token = $params['token'];
$sub = loadSubmission($token);

if (!$sub) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$pageTitle = 'Manuskript eingereicht — ' . SITE_NAME;
$canonicalUrl = BASE_URL . '/einreichen/' . $token . '/done';
?>

<div class="text-center py-4">
    <i class="bi bi-cloud-check display-1 text-success"></i>
    <h1 class="h4 mt-3">Vielen Dank!</h1>
    <p class="text-muted">
        Dein Manuskript wurde erfolgreich hochgeladen. Das DGaO-Team prüft es
        und schaltet es nach Freigabe öffentlich frei. Du erhältst keine separate
        Bestätigungsmail.
    </p>

    <div class="card mt-4 mx-auto" style="max-width: 480px;">
        <div class="card-body text-start">
            <h2 class="h6 text-muted">Beitrag</h2>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="badge bg-secondary"><?= e($sub['code']) ?></span>
                <small class="text-muted"><?= $sub['tagung_nummer'] ?>. Jahrestagung</small>
            </div>
            <p class="mb-2"><strong><?= e($sub['titel']) ?></strong></p>
            <p class="small text-muted mb-2">
                Datei: <?= e($sub['filename_original']) ?>
                (<?= number_format(($sub['file_size'] ?? 0) / 1024, 1, ',', '.') ?> KB)
            </p>
            <p class="small text-muted mb-0">
                Hochgeladen: <?= e($sub['uploaded_at']) ?>
            </p>
        </div>
    </div>

    <div class="mt-4">
        <a href="/" class="btn btn-outline-secondary btn-sm">Zur Startseite</a>
        <a href="/einreichen/<?= e($token) ?>" class="btn btn-outline-accent btn-sm">
            Datei ersetzen
        </a>
    </div>
</div>
