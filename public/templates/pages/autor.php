<?php
$autorId = $params['id'];
$db = getDb();

$stmt = $db->prepare('SELECT id, vorname, nachname, affiliation FROM autoren WHERE id = ?');
$stmt->execute([$autorId]);
$autor = $stmt->fetch();

if (!$autor) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$autorName = trim($autor['vorname'] . ' ' . $autor['nachname']);

$stmt = $db->prepare('
    SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
           p.hat_pdf, p.pdf_dateiname, t.jahr
    FROM papers p
    JOIN paper_autoren pa ON pa.paper_id = p.id
    JOIN tagungen t ON t.nummer = p.tagung_nummer
    WHERE pa.autor_id = ?
    ORDER BY t.jahr DESC, substr(p.code,1,1), CAST(substr(p.code,2) AS INTEGER)
');
$stmt->execute([$autorId]);
$papers = $stmt->fetchAll();

$pageTitle    = $autorName . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/autor/' . $autor['id']);
$metaTags = [
    ['name' => 'description', 'content' => $autorName . ' - ' . count($papers) . ' ' . t('autor.meta_suffix')],
];
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/autoren"><?= t('autor.breadcrumb') ?></a></li>
        <li class="breadcrumb-item active"><?= e($autorName) ?></li>
    </ol>
</nav>

<h1 class="h3 mb-1"><?= e($autorName) ?></h1>
<?php if (!empty($autor['affiliation'])): ?>
<p class="text-muted mb-1"><i class="bi bi-building"></i> <?= e($autor['affiliation']) ?></p>
<?php endif; ?>
<p class="text-muted mb-4"><?= count($papers) ?> <?= count($papers) === 1 ? t('autor.beitrag_singular') : t('autor.beitrag_plural') ?></p>

<div>
    <?php foreach ($papers as $p):
        $showTagung = true;
        $tagungLabel = $p['tagung_nummer'] . '. ' . t('autor.tagung') . ' (' . $p['jahr'] . ')';
    ?>
        <?php require __DIR__ . '/../partials/paper_card.php'; ?>
    <?php endforeach; ?>
</div>
