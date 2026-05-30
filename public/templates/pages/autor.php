<?php
$autorId = $params['id'];
$db = getDb();

// 301-Redirect für gemergte alte IDs (Phase-2 Auto-Merge).
// autor_id_redirects mapping wird beim Merge gefüllt.
$redirStmt = $db->prepare("SELECT neue_id FROM autor_id_redirects WHERE alte_id = ?");
$redirStmt->execute([(int) $autorId]);
$neue = $redirStmt->fetchColumn();
if ($neue !== false) {
    header('Location: /autor/' . (int) $neue, true, 301);
    exit;
}

$stmt = $db->prepare("
    SELECT a.id, a.vorname, a.nachname, a.anzeige_name, a.orcid_id
    FROM autoren a
    WHERE a.id = ?
");
$stmt->execute([$autorId]);
$autor = $stmt->fetch();

if (!$autor) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$autorName = formatAutorName($autor);
$autorAffils = getAutorAffiliations((int)$autor['id']);

$stmt = $db->prepare('
    SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel,
           p.hat_pdf, p.pdf_dateiname, t.jahr
    FROM papers p
    JOIN paper_autoren pa ON pa.paper_id = p.id
    JOIN tagungen t ON t.nummer = p.tagung_nummer
    WHERE pa.autor_id = ?
    ORDER BY t.jahr DESC, substr(p.code,1,1), CAST(substr(p.code,2) AS INTEGER)
');
$stmt->execute([$autorId]);
$papers = $stmt->fetchAll();
attachPaperAutoren($papers);

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

<h1 class="h3 mb-1"><?= e($autorName) ?>
<?php if (!empty($autor['orcid_id'])): ?>
    <a href="<?= e($autor['orcid_id']) ?>" target="_blank" rel="noopener" class="ms-2 small badge bg-success-subtle text-success-emphasis" title="ORCID">
        ORCID
    </a>
<?php endif; ?>
</h1>
<?php if ($autorAffils): ?>
    <div class="text-muted mb-1">
    <?php foreach ($autorAffils as $i => $af):
        $jahre = '';
        if (!empty($af['jahr_von']) && !empty($af['jahr_bis'])) {
            $jahre = $af['jahr_von'] === $af['jahr_bis']
                ? ' <small>(' . (int)$af['jahr_von'] . ')</small>'
                : ' <small>(' . (int)$af['jahr_von'] . '–' . (int)$af['jahr_bis'] . ')</small>';
        }
    ?>
        <div><i class="bi bi-building"></i> <?= e($af['name']) ?><?= $jahre ?></div>
    <?php endforeach; ?>
    </div>
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
