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
            substr(code,1,1), CAST(substr(code,2) AS INTEGER)
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

<header class="tagung-header">
    <div class="tagung-header__eyebrow">
        <?= t('archiv_detail.jahrestagung') ?> &middot; <?= $tagung['jahr'] ?>
    </div>
    <h1 class="tagung-header__title">
        <?= $tagung['nummer'] ?>. <?= t('archiv_detail.jahrestagung') ?>
        <?php if ($tagung['ort']): ?>
            <span class="tagung-header__location"><?= e($tagung['ort']) ?></span>
        <?php endif; ?>
    </h1>
    <dl class="tagung-header__meta">
        <?php if ($tagung['datum_von']): ?>
        <div class="tagung-header__meta-item">
            <dt class="tagung-header__meta-label"><?= t('archiv_detail.meta_dates') ?? 'Datum' ?></dt>
            <dd class="tagung-header__meta-value">
                <?= formatDateLong($tagung['datum_von']) ?><?php if ($tagung['datum_bis']): ?> &ndash; <?= formatDateLong($tagung['datum_bis']) ?><?php endif; ?>
            </dd>
        </div>
        <?php endif; ?>
        <div class="tagung-header__meta-item">
            <dt class="tagung-header__meta-label"><?= t('archiv_detail.beitraege') ?></dt>
            <dd class="tagung-header__meta-value"><?= count($papers) ?></dd>
        </div>
    </dl>
</header>

<?php if (!empty($tagung['vorlage_phase_aktiv'])): ?>
<aside class="tagung-submit">
    <div class="tagung-submit__body">
        <div class="tagung-submit__eyebrow">
            <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i>
            <?= t('archiv_detail.einreichen_eyebrow') ?>
        </div>
        <div class="tagung-submit__title"><?= t('archiv_detail.einreichen_title') ?></div>
        <p class="tagung-submit__desc"><?= t('archiv_detail.einreichen_desc') ?></p>
    </div>
    <a href="/einreichen" class="btn btn-primary tagung-submit__cta">
        <i class="bi bi-envelope-arrow-up" aria-hidden="true"></i> <?= t('nav.einreichen') ?>
    </a>
</aside>
<?php endif; ?>

<div class="tagung-sort">
    <span class="tagung-sort__label"><?= t('archiv_detail.sort_label') ?? 'Sortieren' ?></span>
    <div class="tagung-sort__buttons">
        <button type="button" class="btn btn-sm btn-outline-secondary sort-btn active" data-sort="chronologisch"><?= t('archiv_detail.sort_chrono') ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="titel"><?= t('archiv_detail.sort_title') ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="autor"><?= t('archiv_detail.sort_author') ?></button>
    </div>
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
