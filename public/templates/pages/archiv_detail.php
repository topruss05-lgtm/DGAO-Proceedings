<?php
$nummer = $params['nummer'];
$db = getDb();

$stmt = $db->prepare('SELECT nummer, jahr, ort, datum_von, datum_bis FROM tagungen WHERE nummer = ?');
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

$pageTitle    = $tagung['nummer'] . '. Jahrestagung (' . $tagung['jahr'] . ') - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/archiv/' . $tagung['nummer']);
$metaTags = [
    ['name' => 'description', 'content' => $tagung['nummer'] . '. Jahrestagung der DGaO'
        . ($tagung['ort'] ? ', ' . $tagung['ort'] : '') . ' ' . $tagung['jahr']
        . ' - ' . count($papers) . ' Beiträge'],
];
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/archiv">Archiv</a></li>
        <li class="breadcrumb-item active"><?= $tagung['nummer'] ?>. Jahrestagung</li>
    </ol>
</nav>

<div class="mb-4">
    <h1 class="h3"><?= $tagung['nummer'] ?>. Jahrestagung</h1>
    <p class="text-muted">
        <?php if ($tagung['ort']): ?><?= e($tagung['ort']) ?>, <?php endif; ?>
        <?= $tagung['jahr'] ?>
        <?php if ($tagung['datum_von']): ?>
            &middot; <?= formatDateLong($tagung['datum_von']) ?>
            <?php if ($tagung['datum_bis']): ?> &ndash; <?= formatDateLong($tagung['datum_bis']) ?><?php endif; ?>
        <?php endif; ?>
        &middot; <?= count($papers) ?> Beiträge
    </p>
</div>

<div class="d-flex gap-2 mb-3">
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn active" data-sort="chronologisch">Chronologisch</button>
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="titel">Titel</button>
    <button type="button" class="btn btn-sm btn-outline-secondary sort-btn" data-sort="autor">Autor</button>
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
