<?php
$pageTitle    = 'Archiv - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/archiv');

$tagungen = getAllTagungen();
?>

<h1 class="h3 mb-4">Archiv</h1>

<div class="list-group list-group-flush">
    <?php foreach ($tagungen as $t): ?>
    <a href="/archiv/<?= $t['nummer'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center archive-item">
        <div>
            <strong><?= $t['nummer'] ?>. Jahrestagung</strong>
            <span class="text-muted ms-2">
                <?php if ($t['ort']): ?>
                    <?= e($t['ort']) ?>,
                <?php endif; ?>
                <?= $t['jahr'] ?>
            </span>
            <?php if ($t['datum_von']): ?>
                <small class="text-muted d-none d-sm-inline ms-2">
                    (<?= formatDate($t['datum_von']) ?><?php if ($t['datum_bis']): ?> &ndash; <?= formatDate($t['datum_bis']) ?><?php endif; ?>)
                </small>
            <?php endif; ?>
        </div>
        <span class="badge badge-count rounded-pill"><?= $t['paper_anzahl'] ?></span>
    </a>
    <?php endforeach; ?>
</div>
