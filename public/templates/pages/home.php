<?php
$pageTitle    = SITE_NAME;
$canonicalUrl = BASE_URL . '/';
$fullWidthLayout = true;

$tagungen = getAllTagungen();
$recent   = array_slice($tagungen, 0, 5);
$stats    = getSiteStats();
?>

<!-- Hero Section -->
<section class="section-hero">
    <div class="container container-narrow py-5 text-center">
        <h1 class="hero-home-title">DGaO-Proceedings</h1>
        <p class="hero-home-issn mb-1">ISSN: <?= SITE_ISSN ?></p>
        <p class="hero-home-tagline">Die Online-Zeitschrift der Deutschen Gesellschaft f&uuml;r angewandte Optik e.V.</p>

        <!-- Statistiken -->
        <div class="stats-bar stats-bar--hero">
            <div class="stat-item">
                <i class="bi bi-file-earmark-text stat-icon"></i>
                <span class="stat-number"><?= number_format($stats['papers'], 0, ',', '.') ?></span>
                <span class="stat-label">Beitr&auml;ge</span>
            </div>
            <div class="stat-item">
                <i class="bi bi-calendar-event stat-icon"></i>
                <span class="stat-number"><?= $stats['tagungen'] ?></span>
                <span class="stat-label">Tagungen</span>
            </div>
            <div class="stat-item">
                <i class="bi bi-people stat-icon"></i>
                <span class="stat-number"><?= number_format($stats['autoren'], 0, ',', '.') ?></span>
                <span class="stat-label">Autoren</span>
            </div>
        </div>
    </div>
</section>

<!-- Such-Sektion -->
<section class="section-search">
    <div class="container container-narrow py-4">
        <p class="lead text-center mb-4">
            Willkommen auf den Seiten der wissenschaftlichen Online-Zeitschrift
            &laquo;DGaO-Proceedings&raquo; der <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="accent-link">Deutschen Gesellschaft f&uuml;r angewandte Optik e.V.</a>
        </p>

        <!-- Integrierte Suche -->
        <form action="/suche" method="get" class="search-form-integrated mx-auto mb-4">
            <div class="search-input-group">
                <i class="bi bi-search search-input-icon"></i>
                <input type="search" name="q" class="form-control search-input-integrated"
                       placeholder="Titel, Autor oder Stichwort&hellip;" aria-label="Suche">
                <button type="submit" class="btn btn-accent search-submit-btn">
                    Suchen
                </button>
            </div>
        </form>

        <p class="text-muted text-center small mb-0">
            Alle aktiven Teilnehmer der DGaO-Jahrestagungen haben mit dieser Zeitschrift die M&ouml;glichkeit,
            ihre Ergebnisse zu ver&ouml;ffentlichen.
        </p>
    </div>
</section>

<!-- Tagungs-Sektion -->
<section class="section-conferences">
    <div class="container container-narrow py-4">
        <h2 class="section-heading">Aktuelle &amp; Letzte Tagung</h2>

        <div class="row g-4 mb-4">
            <!-- Aktuelle Tagung -->
            <div class="col-md-6">
                <div class="conference-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="conference-card-img-link">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="conference-card-img">
                    </a>
                    <div class="conference-card-body">
                        <h3 class="conference-card-title">127. Jahrestagung</h3>
                        <p class="conference-card-text">Die Beitragseinreichung ist abgeschlossen.</p>
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener"
                           class="btn btn-accent btn-sm">
                            Zur Jahrestagung &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Letzte Tagung -->
            <div class="col-md-6">
                <div class="conference-card">
                    <a href="/archiv/126" class="conference-card-img-link">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="conference-card-img">
                    </a>
                    <div class="conference-card-body">
                        <h3 class="conference-card-title">126. Jahrestagung</h3>
                        <p class="conference-card-text">Der Upload der Proceedings ist abgeschlossen.</p>
                        <a href="/archiv/126" class="btn btn-accent btn-sm">
                            Zu den Beitr&auml;gen &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Archiv-Sektion -->
<section class="section-archive">
    <div class="container container-narrow py-4">
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
                <span class="badge badge-count-new rounded-pill"><?= $t['paper_anzahl'] ?></span>
            </a>
            <?php endforeach; ?>
            <a href="/archiv" class="list-group-item list-group-item-action fw-semibold accent-link">
                Alle Tagungen anzeigen &rarr;
            </a>
        </div>
    </div>
</section>
