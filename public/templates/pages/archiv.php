<?php
$pageTitle    = t('archiv.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/archiv');

$tagungen = getAllTagungen();
?>

<h1 class="h3 mb-4"><?= t('archiv.title') ?></h1>

<div class="list-group list-group-flush">
    <?php foreach ($tagungen as $tg): ?>
    <a href="/archiv/<?= $tg['nummer'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center archive-item">
        <div>
            <strong><?= $tg['nummer'] ?>. <?= t('archiv.jahrestagung') ?></strong>
            <span class="text-muted ms-2">
                <?php if ($tg['ort']): ?>
                    <?= e($tg['ort']) ?>,
                <?php endif; ?>
                <?= $tg['jahr'] ?>
            </span>
            <?php if ($tg['datum_von']): ?>
                <small class="text-muted d-none d-sm-inline ms-2">
                    (<?= formatDate($tg['datum_von']) ?><?php if ($tg['datum_bis']): ?> &ndash; <?= formatDate($tg['datum_bis']) ?><?php endif; ?>)
                </small>
            <?php endif; ?>
        </div>
        <span class="badge badge-count rounded-pill"><?= $tg['paper_anzahl'] ?></span>
    </a>
    <?php endforeach; ?>
</div>
