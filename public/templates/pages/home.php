<?php
$pageTitle    = SITE_NAME;
$canonicalUrl = BASE_URL . '/';

$tagungen = getAllTagungen();
$recent   = array_slice($tagungen, 0, 5);
$stats    = getSiteStats();
?>

<!-- Statistik-Leiste -->
<div class="stats-bar stats-bar--full mb-4">
    <div class="stat-item">
        <span class="stat-number"><?= number_format($stats['papers'], 0, ',', '.') ?></span>
        <span class="stat-label">Beitr&auml;ge</span>
    </div>
    <div class="stat-item">
        <span class="stat-number"><?= $stats['tagungen'] ?></span>
        <span class="stat-label">Tagungen</span>
    </div>
    <div class="stat-item">
        <span class="stat-number"><?= number_format($stats['autoren'], 0, ',', '.') ?></span>
        <span class="stat-label">Autoren</span>
    </div>
</div>

<!-- Willkommen -->
<p class="lead mb-4">
    Willkommen auf den Seiten der wissenschaftlichen Online-Zeitschrift
    &laquo;DGaO-Proceedings&raquo; der <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="accent-link">Deutschen Gesellschaft f&uuml;r angewandte Optik e.V.</a>
</p>

<!-- Suche -->
<div class="mb-5">
    <label class="form-label fw-semibold">Suche im Archiv</label>
    <form action="/suche" method="get" class="row g-2 max-w-search">
        <div class="col">
            <input type="search" name="q" class="form-control search-input"
                   placeholder="Titel, Autor oder Stichwort&hellip;" aria-label="Suche">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-accent">
                <i class="bi bi-search"></i> Suchen
            </button>
        </div>
    </form>
</div>

<!-- Info -->
<p class="text-muted mb-5">
    Alle aktiven Teilnehmer der DGaO-Jahrestagungen haben mit dieser Zeitschrift die M&ouml;glichkeit,
    ihre Ergebnisse zu ver&ouml;ffentlichen. Wir kommen damit einem h&auml;ufig von DGaO-Teilnehmern
    ge&auml;u&szlig;erten Wunsch nach.
</p>

<!-- Aktuelle Tagung -->
<h2 class="section-heading">Aktuelle Tagung</h2>
<div class="row align-items-center g-3 mb-5">
    <div class="col-md-3">
        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="conference-banner d-block">
            <img src="/assets/images/haw-hamburg-2026.png"
                 alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                 class="img-fluid rounded shadow-sm">
        </a>
    </div>
    <div class="col-md-9">
        <p>Die Beitragseinreichung ist abgeschlossen.</p>
        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener"
           class="btn btn-outline-accent btn-sm">
            Zur Jahrestagung &rarr;
        </a>
    </div>
</div>

<!-- Letzte Tagung -->
<h2 class="section-heading">Letzte Tagung</h2>
<div class="row align-items-center g-3 mb-5">
    <div class="col-md-3">
        <a href="/archiv/126" class="conference-banner d-block">
            <img src="/assets/images/dgao-stuttgart-2025.png"
                 alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                 class="img-fluid rounded shadow-sm">
        </a>
    </div>
    <div class="col-md-9">
        <p>Der Upload der Proceedings ist abgeschlossen.</p>
        <a href="/archiv/126" class="btn btn-outline-accent btn-sm">
            Zu den Beitr&auml;gen der 126.&nbsp;Tagung &rarr;
        </a>
    </div>
</div>

<!-- Archiv -->
<h2 class="section-heading">Archiv</h2>
<div class="list-group list-group-flush mb-4">
    <?php foreach ($recent as $t): ?>
    <a href="/archiv/<?= $t['nummer'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center archive-item">
        <span>
            <strong><?= $t['jahr'] ?></strong>
            <?php if ($t['ort']): ?>
                <span class="text-muted">&ndash; <?= e($t['ort']) ?></span>
            <?php endif; ?>
            <small class="text-muted ms-1">(<?= $t['nummer'] ?>.&nbsp;Tagung)</small>
        </span>
        <span class="badge badge-count rounded-pill"><?= $t['paper_anzahl'] ?></span>
    </a>
    <?php endforeach; ?>
    <a href="/archiv" class="list-group-item list-group-item-action fw-semibold accent-link">
        Alle Tagungen anzeigen &rarr;
    </a>
</div>
