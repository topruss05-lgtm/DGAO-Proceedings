<?php
$pageTitle    = SITE_NAME;
$canonicalUrl = BASE_URL . '/';
$fullWidthLayout = true;

$tagungen = getAllTagungen();
$recent   = array_slice($tagungen, 0, 5);
$stats    = getSiteStats();

$nf = currentLang() === 'en'
    ? fn($n) => number_format($n, 0, '.', ',')
    : fn($n) => number_format($n, 0, ',', '.');
?>

<!-- Hero Section -->
<section class="section-hero">
    <div class="container container-narrow py-5 text-center">
        <h1 class="hero-home-title">DGaO-Proceedings</h1>
        <p class="hero-home-issn mb-1">ISSN: <?= SITE_ISSN ?></p>
        <p class="hero-home-tagline"><?= t('home.tagline') ?></p>

        <!-- Statistiken -->
        <div class="stats-bar stats-bar--hero">
            <div class="stat-item">
                <i class="bi bi-file-earmark-text stat-icon"></i>
                <span class="stat-number"><?= $nf($stats['papers']) ?></span>
                <span class="stat-label"><?= t('home.stat.papers') ?></span>
            </div>
            <div class="stat-item">
                <i class="bi bi-calendar-event stat-icon"></i>
                <span class="stat-number"><?= $stats['tagungen'] ?></span>
                <span class="stat-label"><?= t('home.stat.conferences') ?></span>
            </div>
            <div class="stat-item">
                <i class="bi bi-people stat-icon"></i>
                <span class="stat-number"><?= $nf($stats['autoren']) ?></span>
                <span class="stat-label"><?= t('home.stat.authors') ?></span>
            </div>
        </div>
    </div>
</section>

<!-- Such-Sektion -->
<section class="section-search">
    <div class="container container-narrow py-4">
        <p class="lead text-center mb-4">
            <?= t('home.welcome') ?>
            <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="accent-link"><?= t('home.dgao_name') ?></a>
        </p>

        <!-- Integrierte Suche -->
        <form action="/suche" method="get" class="search-form-integrated mx-auto mb-4">
            <div class="search-input-group">
                <i class="bi bi-search search-input-icon"></i>
                <input type="search" name="q" class="form-control search-input-integrated"
                       placeholder="<?= t('home.search_placeholder') ?>" aria-label="<?= t('nav.suche') ?>">
                <button type="submit" class="btn btn-accent search-submit-btn">
                    <?= t('home.search_btn') ?>
                </button>
            </div>
        </form>

        <p class="text-muted text-center small mb-0">
            <?= t('home.participation_note') ?>
        </p>
    </div>
</section>

<!-- Tagungs-Sektion -->
<section class="section-conferences">
    <div class="container container-narrow py-4">
        <h2 class="section-heading"><?= t('home.section_current') ?></h2>

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
                        <h3 class="conference-card-title"><?= t('home.conf_127_title') ?></h3>
                        <p class="conference-card-text"><?= t('home.conf_127_text') ?></p>
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener"
                           class="btn btn-accent btn-sm">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <?= t('home.conf_127_alert') ?>
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
                        <h3 class="conference-card-title"><?= t('home.conf_126_title') ?></h3>
                        <p class="conference-card-text"><?= t('home.conf_126_text') ?></p>
                        <a href="/archiv/126" class="btn btn-accent btn-sm">
                            <?= t('home.conf_126_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <?= t('home.conf_126_alert') ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Archiv-Sektion -->
<section class="section-archive">
    <div class="container container-narrow py-4">
        <h2 class="section-heading"><?= t('home.section_archive') ?></h2>
        <div class="list-group list-group-flush mb-4">
            <?php foreach ($recent as $t_item): ?>
            <a href="/archiv/<?= $t_item['nummer'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center archive-item">
                <span>
                    <strong><?= $t_item['jahr'] ?></strong>
                    <?php if ($t_item['ort']): ?>
                        <span class="text-muted">&ndash; <?= e($t_item['ort']) ?></span>
                    <?php endif; ?>
                    <small class="text-muted ms-1">(<?= $t_item['nummer'] ?>.&nbsp;<?= t('home.tagung_suffix') ?>)</small>
                </span>
                <span class="badge badge-count-new rounded-pill"><?= $t_item['paper_anzahl'] ?></span>
            </a>
            <?php endforeach; ?>
            <a href="/archiv" class="list-group-item list-group-item-action fw-semibold accent-link">
                <?= t('home.show_all') ?>
            </a>
        </div>
    </div>
</section>
