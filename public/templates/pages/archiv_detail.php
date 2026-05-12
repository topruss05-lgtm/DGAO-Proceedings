<?php
$nummer = $params['nummer'];
$db = getDb();

$stmt = $db->prepare('SELECT nummer, jahr, ort, datum_von, datum_bis, vorlage_phase_aktiv FROM tagungen WHERE nummer = ?');
$stmt->execute([$nummer]);
$tagung = $stmt->fetch();

if (!$tagung) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$stmt = $db->prepare('
    SELECT id, code, typ, titel, autoren_text, hauptautor,
           zeit, raum, datum, hat_pdf, pdf_dateiname
    FROM papers
    WHERE tagung_nummer = ?
    ORDER BY
        CASE typ
            WHEN \'hauptvortrag\' THEN 1
            WHEN \'sondervortrag\' THEN 2
            WHEN \'vortrag\' THEN 3
            WHEN \'poster\' THEN 4
        END,
        code
');
$stmt->execute([$nummer]);
$papers = $stmt->fetchAll();

$pageTitle    = $tagung['nummer'] . '. ' . t('archiv_detail.jahrestagung') . ' (' . $tagung['jahr'] . ') - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/archiv/' . $tagung['nummer']);
$metaTags = [
    ['name' => 'description', 'content' => $tagung['nummer'] . '. ' . t('archiv_detail.jahrestagung_der_dgao')
        . ($tagung['ort'] ? ', ' . $tagung['ort'] : '') . ' ' . $tagung['jahr']
        . ' - ' . count($papers) . ' ' . t('archiv_detail.meta_suffix')],
];
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/archiv"><?= t('archiv_detail.breadcrumb') ?></a></li>
        <li class="breadcrumb-item active"><?= $tagung['nummer'] ?>. <?= t('archiv_detail.jahrestagung') ?></li>
    </ol>
</nav>

<div class="mb-4">
    <h1 class="h3"><?= $tagung['nummer'] ?>. <?= t('archiv_detail.jahrestagung') ?></h1>
    <p class="text-muted">
        <?php if ($tagung['ort']): ?><?= e($tagung['ort']) ?>, <?php endif; ?>
        <?= $tagung['jahr'] ?>
        <?php if ($tagung['datum_von']): ?>
            &middot; <?= formatDateLong($tagung['datum_von']) ?>
            <?php if ($tagung['datum_bis']): ?> &ndash; <?= formatDateLong($tagung['datum_bis']) ?><?php endif; ?>
        <?php endif; ?>
        &middot; <?= count($papers) ?> <?= t('archiv_detail.beitraege') ?>
    </p>
</div>

<?php if (!empty($tagung['vorlage_phase_aktiv'])): ?>
<aside class="card border-0 mb-4" style="background: linear-gradient(135deg, rgba(8,145,178,.06), rgba(124,58,237,.05)); border-left: 3px solid var(--accent, #b42e42) !important;">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-uppercase small fw-semibold text-muted" style="letter-spacing:.1em;">
                    <i class="bi bi-pencil-square"></i> <?= t('archiv_detail.vorlage_eyebrow') ?>
                </div>
                <div class="h6 mb-1 mt-1"><?= t('archiv_detail.vorlage_title') ?></div>
                <div class="small text-muted"><?= t('archiv_detail.vorlage_desc') ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="/manuskript-vorlage/word/de" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-word"></i> Word DE
                </a>
                <a href="/manuskript-vorlage/word/en" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-word"></i> Word EN
                </a>
                <a href="/manuskript-vorlage/latex/de" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-zip"></i> LaTeX DE
                </a>
                <a href="/manuskript-vorlage/latex/en" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-zip"></i> LaTeX EN
                </a>
                <a href="/einreichen" class="btn btn-sm btn-link">
                    <?= t('archiv_detail.vorlage_more') ?> &rarr;
                </a>
            </div>
        </div>
    </div>
</aside>
<?php endif; ?>

<div class="d-flex gap-2 mb-3">
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn active" data-sort="chronologisch"><?= t('archiv_detail.sort_chrono') ?></button>
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="titel"><?= t('archiv_detail.sort_title') ?></button>
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="autor"><?= t('archiv_detail.sort_author') ?></button>
</div>



<div id="paper-list">
    <?php foreach ($papers as $i => $p):
        $showTagung = false;
    ?>
    <div data-title="<?= e($p['titel']) ?>"
         data-author="<?= e($p['autoren_text']) ?>"
         data-sort-order="<?= $i ?>">
        <?php require __DIR__ . '/../partials/paper_card.php'; ?>
    </div>
    <?php endforeach; ?>
</div>
