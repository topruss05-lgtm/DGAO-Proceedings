<?php
$pageTitle    = 'Suche - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/suche');

$q = trim($_GET['q'] ?? '');
$results = [];
$searched = false;

if (mb_strlen($q) >= 2) {
    $searched = true;
    $db = getDb();
    $sanitized = sanitizeFtsQuery($q);

    if ($sanitized) {
        try {
            $stmt = $db->prepare('
                SELECT p.id, p.tagung_nummer, p.code, p.typ, p.titel, p.autoren_text,
                       p.hat_pdf, p.pdf_dateiname
                FROM papers_fts fts
                JOIN papers p ON p.rowid = fts.rowid
                WHERE papers_fts MATCH :q
                ORDER BY rank
                LIMIT 100
            ');
            $stmt->execute([':q' => $sanitized]);
            $results = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('FTS query failed: ' . $e->getMessage());
            $likeQ = '%' . $q . '%';
            $stmt = $db->prepare('
                SELECT id, tagung_nummer, code, typ, titel, autoren_text, hat_pdf, pdf_dateiname
                FROM papers
                WHERE titel LIKE :q1 OR autoren_text LIKE :q2 OR abstract_text LIKE :q3
                LIMIT 100
            ');
            $stmt->execute([':q1' => $likeQ, ':q2' => $likeQ, ':q3' => $likeQ]);
            $results = $stmt->fetchAll();
        }
    }
}
?>

<h1 class="h3 mb-4">Suche</h1>

<form action="/suche" method="get" class="row g-2 mb-4">
    <div class="col">
        <input type="search" name="q" class="form-control" value="<?= e($q) ?>"
               placeholder="Titel, Autor oder Stichwort suchen&hellip;" autofocus>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-search"></i> Suchen
        </button>
    </div>
</form>

<?php if ($searched): ?>
    <p class="text-muted mb-3">
        <?= count($results) ?> <?= count($results) === 1 ? 'Ergebnis' : 'Ergebnisse' ?> gefunden
    </p>

    <?php if (empty($results)): ?>
        <p class="text-muted">Keine Ergebnisse gefunden.</p>
    <?php else: ?>
        <div>
            <?php foreach ($results as $p):
                $showTagung = true;
                $tagungLabel = (string)$p['tagung_nummer'];
            ?>
                <?php require __DIR__ . '/../partials/paper_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
