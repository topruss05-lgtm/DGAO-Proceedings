<?php
/**
 * Paper card partial.
 *
 * Expected variables:
 *   $p - Paper row (id, tagung_nummer, code, typ, titel, autoren_text, hat_pdf, pdf_dateiname)
 *   $showTagung - bool, show conference badge (default false)
 *   $tagungLabel - string, custom tagung badge text (optional)
 */
$showTagung = $showTagung ?? false;
$tagungLabel = $tagungLabel ?? ((string)($p['tagung_nummer'] ?? ''));
?>
<a href="/paper/<?= e($p['id']) ?>" class="text-decoration-none d-block mb-2">
    <div class="bg-white border rounded p-3 card-hover">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <?php if ($showTagung): ?>
                <span class="badge bg-secondary"><?= e($tagungLabel) ?></span>
            <?php endif; ?>
            <span class="badge <?= typeBadgeClass($p['typ']) ?>"><?= e($p['code']) ?></span>
            <span class="badge <?= typeBadgeClass($p['typ']) ?>"><?= typeLabel($p['typ']) ?></span>
            <?php if (!empty($p['zeit'])): ?>
                <small class="text-muted"><?= e($p['zeit']) ?></small>
            <?php endif; ?>
            <?php if ($p['hat_pdf']): ?>
                <span class="badge badge-pdf"><i class="bi bi-file-earmark-pdf"></i> PDF</span>
            <?php endif; ?>
        </div>
        <h3 class="h6 mb-1 text-dark text-clamp-2"><?= e($p['titel']) ?></h3>
        <small class="text-muted text-truncate d-block"><?= e($p['autoren_text']) ?></small>
    </div>
</a>
