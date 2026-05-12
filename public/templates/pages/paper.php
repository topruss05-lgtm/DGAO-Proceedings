<?php
$id = $params['id'];
$db = getDb();

// Paper with tagung info
$stmt = $db->prepare('
    SELECT p.*, t.jahr, t.ort, t.vorlage_phase_aktiv
    FROM papers p
    JOIN tagungen t ON t.nummer = p.tagung_nummer
    WHERE p.id = ?
');
$stmt->execute([$id]);
$paper = $stmt->fetch();

if (!$paper) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

// Keywords
$stmt = $db->prepare('
    SELECT k.keyword
    FROM keywords k
    JOIN paper_keywords pk ON pk.keyword_id = k.id
    WHERE pk.paper_id = ?
');
$stmt->execute([$id]);
$keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Authors
$stmt = $db->prepare('
    SELECT a.id, a.vorname, a.nachname, a.affiliation, pa.position, pa.ist_hauptautor
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE pa.paper_id = ?
    ORDER BY pa.position
');
$stmt->execute([$id]);
$autoren = $stmt->fetchAll();

// Page title and SEO
$year = $paper['datum'] ? substr($paper['datum'], 0, 4) : (string)$paper['jahr'];
$paperUrl = BASE_URL . '/paper/' . $paper['id'];
$pdfFullUrl = fullPdfUrl($paper);

$pageTitle    = $paper['titel'] . ' - ' . SITE_NAME;
$canonicalUrl = $paperUrl;

$metaTags = [
    // Highwire Press (Google Scholar)
    ['name' => 'citation_title', 'content' => $paper['titel']],
    ['name' => 'citation_publication_date', 'content' => $year],
    ['name' => 'citation_journal_title', 'content' => 'DGaO-Proceedings'],
    ['name' => 'citation_issn', 'content' => SITE_ISSN],
    ['name' => 'citation_publisher', 'content' => SITE_PUBLISHER],
    ['name' => 'citation_conference_title', 'content' => $paper['tagung_nummer'] . '. ' . t('paper.jahrestagung_der_dgao')],
    // Dublin Core
    ['name' => 'DC.title', 'content' => $paper['titel']],
    ['name' => 'DC.creator', 'content' => $paper['autoren_text']],
    ['name' => 'DC.date', 'content' => $year],
    ['name' => 'DC.publisher', 'content' => SITE_PUBLISHER],
    ['name' => 'DC.type', 'content' => 'Text'],
    ['name' => 'DC.format', 'content' => 'text/html'],
    ['name' => 'DC.identifier', 'content' => $paperUrl],
    ['name' => 'DC.language', 'content' => 'de'],
    // Open Graph
    ['property' => 'og:title', 'content' => $paper['titel']],
    ['property' => 'og:type', 'content' => 'article'],
    ['property' => 'og:url', 'content' => $paperUrl],
    ['property' => 'og:site_name', 'content' => 'DGaO-Proceedings'],
];

// Individual citation_author tags
$authorNames = array_filter(array_map('trim', preg_split('/,\s*/', $paper['autoren_text'])), fn($a) => strlen($a) > 1);
foreach ($authorNames as $author) {
    $metaTags[] = ['name' => 'citation_author', 'content' => $author];
}

if ($paper['abstract_text']) {
    $metaTags[] = ['name' => 'DC.description', 'content' => mb_substr($paper['abstract_text'], 0, 500)];
    $metaTags[] = ['property' => 'og:description', 'content' => mb_substr($paper['abstract_text'], 0, 200)];
    $metaTags[] = ['name' => 'description', 'content' => mb_substr($paper['abstract_text'], 0, 200)];
}

if ($pdfFullUrl) {
    $metaTags[] = ['name' => 'citation_pdf_url', 'content' => $pdfFullUrl];
}

if (!empty($keywords)) {
    $kwString = implode(', ', $keywords);
    $metaTags[] = ['name' => 'keywords', 'content' => $kwString];
    $metaTags[] = ['name' => 'DC.subject', 'content' => $kwString];
}

$bibtex = generateBibtex($paper);
$pdfRelUrl = pdfUrl($paper);
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/archiv"><?= t('paper.breadcrumb_archiv') ?></a></li>
        <li class="breadcrumb-item"><a href="/archiv/<?= $paper['tagung_nummer'] ?>"><?= $paper['tagung_nummer'] ?>. <?= t('paper.jahrestagung') ?></a></li>
        <li class="breadcrumb-item active"><?= e($paper['code']) ?></li>
    </ol>
</nav>

<article class="article-detail">

    <div class="article-meta d-flex flex-wrap align-items-center gap-2">
        <span class="badge <?= typeBadgeClass($paper['typ']) ?>"><?= typeLabel($paper['typ']) ?></span>
        <span class="badge <?= typeBadgeClass($paper['typ']) ?>"><?= e($paper['code']) ?></span>
        <?php if ($paper['zeit']): ?>
            <small><?= e($paper['zeit']) ?></small>
        <?php endif; ?>
        <?php if ($paper['raum']): ?>
            <small><?= t('paper.raum') ?> <?= e($paper['raum']) ?></small>
        <?php endif; ?>
        <?php if ($paper['datum']): ?>
            <small><?= formatDateLong($paper['datum']) ?></small>
        <?php endif; ?>
    </div>

    <h1 class="h4 mb-3"><?= e($paper['titel']) ?></h1>

    <div class="mb-3">
        <?php foreach ($autoren as $a): ?>
            <a href="/autor/<?= $a['id'] ?>" class="accent-link me-2"<?php if (!empty($a['affiliation'])): ?> title="<?= e($a['affiliation']) ?>"<?php endif; ?>>
                <?= e(trim($a['vorname'] . ' ' . $a['nachname'])) ?><?php if ($a['ist_hauptautor']): ?> <i class="bi bi-star-fill text-warning small"></i><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($paper['affiliationen']): ?>
    <p class="text-muted small mb-3"><?= nl2br(e($paper['affiliationen'])) ?></p>
    <?php endif; ?>

    <?php if ($paper['kontakt_email']): ?>
    <p class="small mb-3">
        <i class="bi bi-envelope"></i>
        <a href="mailto:<?= e($paper['kontakt_email']) ?>" class="accent-link"><?= e($paper['kontakt_email']) ?></a>
    </p>
    <?php endif; ?>

    <?php if ($paper['abstract_text']): ?>
    <div class="mb-4">
        <h2 class="h6 fw-bold">Abstract</h2>
        <div class="abstract-block">
            <p><?= e($paper['abstract_text']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($keywords)): ?>
    <div class="mb-4">
        <h2 class="h6 fw-bold">Keywords</h2>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach ($keywords as $kw): ?>
                <span class="badge keyword-badge"><?= e($kw) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$pdfRelUrl): ?>
    <div class="alert alert-light border d-flex align-items-start gap-2 small">
        <i class="bi bi-info-circle text-muted"></i>
        <div>
            <strong>Manuskript noch nicht eingereicht.</strong>
            Der Vortragende kann unter <a href="/einreichen" class="accent-link">/einreichen</a> mit Code (<code><?= e($paper['code']) ?></code>) und der hinterlegten E-Mail-Adresse einen Upload-Link anfordern.
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if ($pdfRelUrl): ?>
        <a href="<?= e($pdfRelUrl) ?>" class="btn btn-accent btn-sm" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf"></i> <?= t('paper.pdf_download') ?>
        </a>
        <?php endif; ?>

        <?php if (!empty($paper['vorlage_phase_aktiv'])): ?>
        <div class="dropdown">
            <button class="btn btn-outline-accent btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download"></i> <?= t('paper.tpl_button') ?>
            </button>
            <ul class="dropdown-menu">
                <li><h6 class="dropdown-header"><?= t('paper.tpl_latex_header') ?></h6></li>
                <li><a class="dropdown-item" href="/paper/<?= e($paper['id']) ?>/template/latex/de"><i class="bi bi-file-earmark-zip"></i> <?= t('vorlage.dl_de_zip') ?></a></li>
                <li><a class="dropdown-item" href="/paper/<?= e($paper['id']) ?>/template/latex/en"><i class="bi bi-file-earmark-zip"></i> <?= t('vorlage.dl_en_zip') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header"><?= t('paper.tpl_word_header') ?></h6></li>
                <li><a class="dropdown-item" href="/paper/<?= e($paper['id']) ?>/template/word/de"><i class="bi bi-file-earmark-word"></i> <?= t('vorlage.dl_de_docx') ?></a></li>
                <li><a class="dropdown-item" href="/paper/<?= e($paper['id']) ?>/template/word/en"><i class="bi bi-file-earmark-word"></i> <?= t('vorlage.dl_en_docx') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item small text-muted" href="/einreichen?paper=<?= e($paper['id']) ?>"><?= t('paper.tpl_more') ?></a></li>
            </ul>
        </div>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-secondary btn-sm" id="bibtex-toggle-btn">
            <i class="bi bi-quote"></i> <?= t('paper.cite_bibtex') ?>
        </button>
    </div>

    <div id="bibtex-block" class="d-none mb-3">
        <div class="bibtex-block" id="bibtex-output"><?= e($bibtex) ?></div>
        <button type="button" class="btn btn-sm btn-outline-accent mt-2" id="bibtex-copy-btn">
            <i class="bi bi-clipboard"></i> <?= t('paper.copy_clipboard') ?>
        </button>
    </div>

    <div class="text-muted small border-top pt-3">
        <?= $paper['tagung_nummer'] ?>. <?= t('paper.jahrestagung_der_dgao') ?>
        <?php if ($paper['ort']): ?>&middot; <?= e($paper['ort']) ?><?php endif; ?>
        &middot; <?= $year ?>
    </div>

</article>
