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

// Affiliations pro Paper aus paper_autoren JOIN autoren ziehen (für Filter).
$affilByPaperId = [];
$affilStmt = $db->prepare('
    SELECT pa.paper_id, GROUP_CONCAT(a.affiliation, " | ") AS affils
    FROM paper_autoren pa
    JOIN autoren a ON a.id = pa.autor_id
    WHERE pa.paper_id IN (SELECT id FROM papers WHERE tagung_nummer = ?)
    GROUP BY pa.paper_id
');
$affilStmt->execute([$nummer]);
foreach ($affilStmt as $row) {
    $affilByPaperId[$row['paper_id']] = (string)($row['affils'] ?? '');
}

// Helper: erzeugt einen lowercase-Suchstring fuer einen Paper-Eintrag.
$buildSearchHay = function (array $p) use ($affilByPaperId): string {
    $parts = [
        $p['code'] ?? '',
        $p['titel'] ?? '',
        $p['autoren_text'] ?? '',
        $p['hauptautor'] ?? '',
        $affilByPaperId[$p['id']] ?? '',
        $p['affiliationen'] ?? '',
    ];
    $hay = mb_strtolower(implode(' ', array_filter($parts, fn($s) => $s !== '')));
    return preg_replace('/\*+/', '', $hay);
};

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

<?php
// Suggestion-Datalist: Autoren (Nachname, Vorname) + alle Affiliations.
$authors = getAuthorsByTagung((int) $nummer);
$affilSet = [];
foreach ($affilByPaperId as $a) {
    foreach (explode(' | ', $a) as $aff) {
        $aff = trim($aff);
        if ($aff !== '') $affilSet[$aff] = true;
    }
}
ksort($affilSet);
?>
<div class="archiv-filter mb-3">
    <label for="archiv-filter-input" class="form-label small text-muted mb-1">
        <i class="bi bi-search"></i> <?= e(t('archiv_detail.filter_label')) ?>
    </label>
    <input type="search" id="archiv-filter-input"
           class="form-control"
           list="archiv-filter-suggestions"
           placeholder="<?= e(t('archiv_detail.filter_placeholder')) ?>"
           autocomplete="off">
    <datalist id="archiv-filter-suggestions">
        <?php foreach ($authors as $a): ?>
            <option value="<?= e($a['label']) ?>"></option>
        <?php endforeach; ?>
        <?php foreach (array_keys($affilSet) as $aff): ?>
            <option value="<?= e($aff) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</div>

<div id="paper-list">
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
                <div class="archiv-item" data-search="<?= e($buildSearchHay($p)) ?>">
                    <?php require __DIR__ . '/../partials/paper_card.php'; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>

<p id="archiv-empty" class="text-muted d-none"><?= e(t('archiv_detail.no_matches')) ?></p>
