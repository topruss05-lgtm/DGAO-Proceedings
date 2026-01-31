<?php
$autorId = $params['id'];
$db = getDb();

$stmt = $db->prepare('SELECT id, vorname, nachname FROM autoren WHERE id = ?');
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
    ORDER BY t.jahr DESC, p.code
');
$stmt->execute([$autorId]);
$papers = $stmt->fetchAll();

$pageTitle    = $autorName . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/autor/' . $autor['id']);
$metaTags = [
    ['name' => 'description', 'content' => $autorName . ' - ' . count($papers) . ' Beiträge in DGaO-Proceedings'],
];
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/autoren">Autoren</a></li>
        <li class="breadcrumb-item active"><?= e($autorName) ?></li>
    </ol>
</nav>

<h1 class="h3 mb-1"><?= e($autorName) ?></h1>
<p class="text-muted mb-4"><?= count($papers) ?> <?= count($papers) === 1 ? 'Beitrag' : 'Beiträge' ?></p>

<div>
    <?php foreach ($papers as $p):
        $showTagung = true;
        $tagungLabel = $p['tagung_nummer'] . '. Tagung (' . $p['jahr'] . ')';
    ?>
        <?php require __DIR__ . '/../partials/paper_card.php'; ?>
    <?php endforeach; ?>
</div>
