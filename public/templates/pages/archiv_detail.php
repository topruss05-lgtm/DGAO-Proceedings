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

// Affiliations pro Paper für Filter — aus autor_institutionen → institutionen
// (alle Verknüpfungen pro Autor, nicht nur ist_aktuell, damit der Filter
// historisch korrekt funktioniert).
$affilByPaperId = [];
$affilStmt = $db->prepare('
    SELECT pa.paper_id, GROUP_CONCAT(i.name_de, " | ") AS affils
    FROM paper_autoren pa
    JOIN autor_institutionen ai ON ai.autor_id = pa.autor_id
    JOIN institutionen i ON i.id = ai.institut_id
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
        $p['abstract_text'] ?? '',
    ];
    $hay = mb_strtolower(implode(' ', array_filter($parts, fn($s) => $s !== '')));
    // Sternchen + Newlines + Mehrfach-Whitespace normalisieren.
    $hay = preg_replace('/\*+/', '', $hay);
    $hay = preg_replace('/\s+/u', ' ', $hay);
    return trim($hay);
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
<?php
// Suggestions als JSON ans JS uebergeben — kein datalist (Mobile/Safari-Bugs).
$suggestions = [];
foreach ($authors as $a) {
    $suggestions[] = $a['label'];
}
foreach (array_keys($affilSet) as $aff) {
    $suggestions[] = $aff;
}
?>
<form role="search" class="archiv-filter mb-3" onsubmit="return false;">
    <label for="archiv-filter-input" class="form-label small text-muted mb-1">
        <i class="bi bi-search"></i> <?= e(t('archiv_detail.filter_label')) ?>
    </label>
    <div class="archiv-filter__wrap position-relative">
        <input type="text"
               id="archiv-filter-input"
               class="form-control archiv-filter__input pe-5"
               role="combobox"
               aria-expanded="false"
               aria-autocomplete="list"
               aria-controls="archiv-filter-listbox"
               aria-activedescendant=""
               placeholder="<?= e(t('archiv_detail.filter_placeholder')) ?>"
               autocomplete="off"
               spellcheck="false">
        <button type="button"
                id="archiv-filter-clear"
                class="archiv-filter__clear"
                aria-label="<?= e(t('archiv_detail.filter_clear_label')) ?>"
                hidden>
            <i class="bi bi-x-circle-fill"></i>
        </button>
        <ul id="archiv-filter-listbox"
            class="archiv-filter__listbox"
            role="listbox"
            aria-label="<?= e(t('archiv_detail.filter_label')) ?>"
            hidden></ul>
    </div>
    <p id="archiv-filter-status" class="archiv-filter__status small text-muted mt-2 mb-0" aria-live="polite">
        <?= count($papers) ?> <?= t('archiv_detail.beitraege') ?>
    </p>
    <script type="application/json" id="archiv-filter-data"><?= jsonForScript($suggestions) ?></script>
</form>

<div id="paper-list">
    <?php foreach ($groups as $g): ?>
        <details class="archiv-session" data-group-key="<?= e($g['key']) ?>">
            <summary class="archiv-session__summary">
                <span class="archiv-session__title">
                    <?= e($g['titel']) ?>
                </span>
                <span class="archiv-session__count" aria-label="<?= count($g['papers']) ?> <?= e(t('archiv_detail.beitraege')) ?>">
                    <?= count($g['papers']) ?>
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
