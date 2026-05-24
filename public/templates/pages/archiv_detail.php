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

$papers = getPapersByTagung((int) $nummer);

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
<aside class="card border-0 mb-4" style="background: linear-gradient(135deg, rgba(8,145,178,.06), rgba(124,58,237,.05)); border-left: 3px solid var(--accent) !important;">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-uppercase small fw-semibold text-muted" style="letter-spacing:.1em;">
                    <i class="bi bi-envelope-arrow-up"></i> <?= t('archiv_detail.einreichen_eyebrow') ?>
                </div>
                <div class="h6 mb-1 mt-1"><?= t('archiv_detail.einreichen_title') ?></div>
                <div class="small text-muted"><?= t('archiv_detail.einreichen_desc') ?></div>
            </div>
            <a href="/einreichen" class="btn btn-primary">
                <i class="bi bi-envelope-arrow-up"></i> <?= t('nav.einreichen') ?>
            </a>
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
