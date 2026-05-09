<?php
require_once __DIR__ . '/../../../submissions.php';

$adminPageTitle = 'Submission prüfen';

$token = $params['token'];
$sub = loadSubmission($token);

if (!$sub) {
    http_response_code(404);
    echo '<h1>Submission nicht gefunden</h1>';
    return;
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/admin/submissions">Einreichungen</a></li>
        <li class="breadcrumb-item active"><?= e($sub['code']) ?></li>
    </ol>
</nav>

<h1 class="h3 mb-3"><i class="bi bi-file-earmark-pdf"></i> Einreichung prüfen</h1>

<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 text-muted">Beitrag</h2>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge bg-secondary"><?= e($sub['code']) ?></span>
                    <small class="text-muted"><?= $sub['tagung_nummer'] ?>. Jahrestagung</small>
                </div>
                <p class="mb-2"><strong><?= e($sub['titel']) ?></strong></p>
                <p class="small text-muted mb-2">
                    <?= e($sub['hauptautor'] ?: '') ?>
                </p>
                <a href="/paper/<?= e($sub['paper_id']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                    <i class="bi bi-box-arrow-up-right"></i> Beitragsdetails
                </a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 text-muted">Einreichung</h2>
                <dl class="small mb-0">
                    <dt>Hochgeladen von</dt>
                    <dd><?= e($sub['uploader_email']) ?></dd>
                    <dt>Datei</dt>
                    <dd><?= e($sub['filename_original'] ?: '—') ?>
                        (<?= number_format(($sub['file_size'] ?? 0) / 1024, 1, ',', '.') ?> KB)</dd>
                    <dt>Hochgeladen am</dt>
                    <dd><?= e($sub['uploaded_at'] ?: '—') ?></dd>
                    <dt>Status</dt>
                    <dd><span class="badge bg-warning text-dark"><?= e($sub['status']) ?></span></dd>
                </dl>
            </div>
        </div>

        <?php if ($sub['status'] === 'pending' && !empty($sub['filename_stored'])): ?>
        <div class="card border-success mb-3">
            <div class="card-body">
                <h2 class="h6 text-success">Freigeben</h2>
                <p class="small text-muted mb-2">
                    Datei wird in <code>/download/<?= $sub['tagung_nummer'] ?>/<?= $sub['tagung_nummer'] ?>_<?= strtolower($sub['code']) ?>.pdf</code> verschoben
                    und der Beitrag als <em>publiziert</em> markiert.
                </p>
                <form method="post" action="/admin/submissions/<?= e($token) ?>/approve">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Freigeben
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-danger">
            <div class="card-body">
                <h2 class="h6 text-danger">Ablehnen</h2>
                <form method="post" action="/admin/submissions/<?= e($token) ?>/reject">
                    <?= csrfField() ?>
                    <div class="mb-2">
                        <textarea name="note" class="form-control form-control-sm" rows="3"
                                  placeholder="Begründung (sichtbar für Author beim erneuten Aufruf des Links)"
                                  required></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-x-lg"></i> Ablehnen
                    </button>
                </form>
            </div>
        </div>
        <?php elseif ($sub['status'] !== 'pending'): ?>
        <div class="alert alert-info mb-0">
            Bereits entschieden: <strong><?= e($sub['status']) ?></strong>
            am <?= e($sub['decided_at']) ?> durch <?= e($sub['decided_by']) ?>.
            <?php if (!empty($sub['reviewer_note'])): ?>
                <br><strong>Notiz:</strong> <?= e($sub['reviewer_note']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <h2 class="h6">Vorschau</h2>
        <?php if (!empty($sub['filename_stored'])): ?>
        <iframe src="/admin/submissions/<?= e($token) ?>/preview"
                style="width: 100%; height: 80vh; border: 1px solid #dee2e6; border-radius: .25rem;"></iframe>
        <?php else: ?>
        <div class="alert alert-secondary">
            <i class="bi bi-info-circle"></i> Author hat noch keine Datei hochgeladen.
        </div>
        <?php endif; ?>
    </div>
</div>
