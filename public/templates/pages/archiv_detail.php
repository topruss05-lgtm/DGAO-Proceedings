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

$papers  = getPapersByTagung((int) $nummer);
$groups  = groupPapersForArchiveDetail($papers);
$authors = getAuthorsByTagung((int) $nummer);

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

<div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="archiv-controls">
    <div class="d-flex gap-2" id="archiv-sort-buttons">
        <button type="button" class="btn btn-sm btn-outline-secondary sort-btn active"
                data-sort="programm"><?= e(t('archiv_detail.sort_chrono')) ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary sort-btn"
                data-sort="autor"><?= e(t('archiv_detail.sort_author')) ?></button>
    </div>
    <div class="flex-grow-1"></div>
    <div class="position-relative" id="archiv-author-filter" style="min-width: 240px;">
        <input type="search" list="archiv-author-list" id="archiv-author-input"
               class="form-control form-control-sm"
               placeholder="<?= e(t('archiv_detail.filter_author_placeholder')) ?>"
               autocomplete="off">
        <datalist id="archiv-author-list">
            <?php foreach ($authors as $a): ?>
                <option value="<?= e($a['label']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
</div>

<div id="paper-list-programm">
    <?php foreach ($groups as $g): ?>
        <details open class="archiv-session" data-group-key="<?= e($g['key']) ?>">
            <summary class="archiv-session__summary">
                <span class="archiv-session__title">
                    <?= e($g['titel']) ?>
                </span>
                <span class="archiv-session__meta text-muted small">
                    <?php if ($g['type'] === 'session'): ?>
                        <?php if (!empty($g['datum'])): ?>
                            <?= e(formatDate($g['datum'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($g['zeit_von'])): ?>
                            &middot; <?= e($g['zeit_von']) ?>
                        <?php endif; ?>
                        <?php if (!empty($g['saal'])): ?>
                            &middot; Saal <?= e($g['saal']) ?>
                        <?php endif; ?>
                        &middot;
                    <?php endif; ?>
                    <?= count($g['papers']) ?> <?= t('archiv_detail.beitraege') ?>
                </span>
            </summary>
            <div class="archiv-session__papers">
                <?php foreach ($g['papers'] as $p):
                    $showTagung = false;
                ?>
                <div class="archiv-item"
                     data-author="<?= e(mb_strtolower(preg_replace('/\*+/', '', (string)$p['autoren_text']))) ?>">
                    <?php require __DIR__ . '/../partials/paper_card.php'; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>

<div id="paper-list-autor" class="d-none">
    <?php
    // Flache, alphabetisch nach erstem Autor sortierte Liste
    $flat = $papers;
    usort($flat, function ($a, $b) {
        $aKey = mb_strtolower(preg_replace('/\*+/', '', (string)($a['hauptautor'] ?: $a['autoren_text'])));
        $bKey = mb_strtolower(preg_replace('/\*+/', '', (string)($b['hauptautor'] ?: $b['autoren_text'])));
        return $aKey <=> $bKey;
    });
    foreach ($flat as $p):
        $showTagung = false;
    ?>
    <div class="archiv-item"
         data-author="<?= e(mb_strtolower(preg_replace('/\*+/', '', (string)$p['autoren_text']))) ?>">
        <?php require __DIR__ . '/../partials/paper_card.php'; ?>
    </div>
    <?php endforeach; ?>
</div>

<p id="archiv-empty" class="text-muted d-none"><?= e(t('archiv_detail.no_matches')) ?></p>
