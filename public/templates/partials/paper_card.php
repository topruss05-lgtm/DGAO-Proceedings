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
<a href="/paper/<?= e($p['id']) ?>" class="text-decoration-none d-block mb-3">
    <div class="paper-card">
        <div class="card-eyebrow">
            <?= e($p['code']) ?> &middot; <?= typeLabel($p['typ']) ?>
            <?php if ($showTagung): ?>
                &middot; <?= e($tagungLabel) ?>
            <?php endif; ?>
        </div>
        <h3 class="card-title text-clamp-2"><?= e($p['titel']) ?></h3>
        <div class="card-authors text-truncate"><?= e($p['autoren_text']) ?></div>
        <?php if ($p['hat_pdf'] || !empty($p['zeit'])): ?>
        <div class="card-footer-bar">
            <span class="badge <?= typeBadgeClass($p['typ']) ?>"><?= typeLabel($p['typ']) ?></span>
            <?php if ($p['hat_pdf']): ?>
                <span class="badge badge-pdf"><i class="bi bi-file-earmark-pdf"></i> PDF</span>
            <?php endif; ?>
            <?php if (!empty($p['zeit'])): ?>
                <small class="text-muted"><?= e($p['zeit']) ?></small>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</a>
