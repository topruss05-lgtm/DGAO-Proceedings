<?php
$pageTitle    = t('archiv.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/archiv');

$tagungen = getAllTagungen();
?>

<h1 class="h3 mb-4"><?= t('archiv.title') ?></h1>

<ul class="v4-archive-list">
    <?php foreach ($tagungen as $tg): ?>
    <li>
        <a href="/archiv/<?= $tg['nummer'] ?>" class="v4-archive-item">
            <span class="v4-archive-year"><?= $tg['jahr'] ?></span>
            <span class="v4-archive-loc">
                <strong><?= $tg['nummer'] ?>. <?= t('archiv.jahrestagung') ?></strong>
                <?php if ($tg['ort']): ?>
                    &mdash; <?= e($tg['ort']) ?>
                <?php endif; ?>
                <?php if ($tg['datum_von']): ?>
                    <span class="v4-archive-nr d-none d-sm-inline">
                        (<?= formatDate($tg['datum_von']) ?><?php if ($tg['datum_bis']): ?> &ndash; <?= formatDate($tg['datum_bis']) ?><?php endif; ?>)
                    </span>
                <?php endif; ?>
            </span>
            <span class="v4-archive-badge"><?= $tg['paper_anzahl'] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
