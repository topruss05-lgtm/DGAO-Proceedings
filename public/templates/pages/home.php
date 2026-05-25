<?php
require_once __DIR__ . '/../../optics.php';

$pageTitle    = SITE_NAME;
$canonicalUrl = BASE_URL . '/';
$fullWidthLayout = true;

$extraHead = '<link rel="stylesheet" href="/assets/css/home-hero.css?v=' . @filemtime(__DIR__ . '/../../assets/css/home-hero.css') . '">';

$tagungen = getAllTagungen();
$recent   = array_slice($tagungen, 0, 5);
$stats    = getSiteStats();

$nf = currentLang() === 'en'
    ? fn($n) => number_format($n, 0, '.', ',')
    : fn($n) => number_format($n, 0, ',', '.');

$oldestYear = !empty($tagungen) ? $tagungen[count($tagungen) - 1]['jahr'] : '1900';

// Desktop layout: search bar in the LEFT column (Springer-style). Entry
// nun weiter links im Glas (350 statt 470) und θ=75° bleibt — dadurch
// ist der sichtbare Eingangs-Strahl kompakter (~94 px Höhe statt 126 px)
// und sitzt insgesamt höher, der Prismen-Durchlauf liegt deutlich
// linker. Spektral-Fan exit verläuft trotzdem rechts vom Titel (x=702
// bis 747 auf Höhe Titel-Unterkante y=151 vs. Text-Rechtsrand ~658).
// Linse folgt der natürlichen Cluster-Position der Strahlen.
$dScene = dgao_compute_optics([
    'vb_w'      => 1280,
    'vb_h'      => 560,
    'glass'     => ['x' => 80, 'y' => 230, 'w' => 520, 'h' => 100, 'r' => 14],
    'theta_deg' => 75,
    'entry_x'   => 350,
]);
$dLens     = ['cx' => 820, 'cy' => 124, 'f' => 130];
$dLensRays = dgao_lens_rays($dScene['rays'], $dLens, $dScene['thetaRad']);

// Mobile portrait. θ raised to 65° (was 52°) so the dispersion fan
// sweeps further to the right and clears the full-width title overlay
// at the top of the stage instead of crossing through it.
$mScene = dgao_compute_optics([
    'vb_w'      => 600,
    'vb_h'      => 760,
    'glass'     => ['x' => 60, 'y' => 410, 'w' => 480, 'h' => 100, 'r' => 14],
    'theta_deg' => 65,
    'entry_x'   => 135,
]);
?>
<!-- home-hero styles in /assets/css/home-hero.css, geladen ueber layout.php $extraHead -->

<!-- 1. HERO — refraction + dispersion through liquid-glass search bar -->
<section class="hero" aria-labelledby="hero-title">
    <div class="hero__bg" aria-hidden="true"></div>

    <div class="hero__stage">
        <?= dgao_render_scene('desktop', $dScene, $dLens, $dLensRays) ?>
        <?= dgao_render_scene('mobile',  $mScene) ?>

        <div class="hero__overlay">
            <div class="hero__text">
                <p class="hero__eyebrow"><?= sprintf(t('home.hero.eyebrow'), e(SITE_ISSN)) ?></p>

                <h1 class="hero__title" id="hero-title">
                    <a href="https://www.dgao.de/" target="_blank" rel="noopener"
                       class="hero__title-link"
                       aria-label="Zur Webseite der Deutschen Gesellschaft f&uuml;r angewandte Optik">
                        <span class="hero__title-line hero__title-line--1"><?= t('home.hero.h1_l1') ?></span>
                        <span class="hero__title-line hero__title-line--2"><?= t('home.hero.h1_l2') ?></span>
                    </a>
                    <span class="hero__title-line hero__title-line--3"><?= t('home.hero.h1_l3') ?></span>
                </h1>
            </div>

            <form action="/suche" method="get" class="hero__search-form" role="search">
                <label for="hero-search-input" class="hero__search-label">
                    <?= currentLang() === 'en' ? 'Search articles, authors, conferences' : 'Beitr&auml;ge, Autoren oder Tagungen suchen' ?>
                </label>
                <div class="hero__search">
                    <i class="bi bi-search hero__search-icon" aria-hidden="true"></i>
                    <input id="hero-search-input"
                           type="search"
                           name="q"
                           class="hero__search-input"
                           placeholder="<?= t('home.search_placeholder') ?>"
                           aria-label="<?= t('nav.suche') ?>"
                           autocomplete="off">
                    <button type="submit" class="hero__search-btn" aria-label="<?= t('home.search_btn') ?>">
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </form>

            <div class="hero__stats">
                <div>
                    <span class="hero__stat-num"><?= $nf($stats['papers']) ?></span>
                    <span class="hero__stat-lbl"><?= t('home.stat.papers') ?></span>
                </div>
                <div>
                    <span class="hero__stat-num"><?= $stats['tagungen'] ?></span>
                    <span class="hero__stat-lbl"><?= t('home.stat.conferences') ?></span>
                </div>
                <div>
                    <span class="hero__stat-num"><?= $nf($stats['autoren']) ?></span>
                    <span class="hero__stat-lbl"><?= t('home.stat.authors') ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="hero__edge" aria-hidden="true"></div>
</section>

<!-- 2. NEWS — latest announcements -->
<section class="v4-news" aria-labelledby="news-heading">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal" id="news-heading"><?= t('home.section_news') ?></h2>
        <hr class="v4-section-bar v4-reveal">

        <?php $newsItems = getActiveNews(3); ?>
        <?php if (!empty($newsItems)): ?>
            <ol class="v4-news-list">
                <?php foreach ($newsItems as $i => $n): ?>
                    <li class="v4-news-item v4-reveal v4-rd<?= $i + 1 ?>">
                        <time class="v4-news-date" datetime="<?= e($n['display_date']) ?>">
                            <?= e(formatDateLong($n['display_date'])) ?>
                        </time>
                        <h3 class="v4-news-title">
                            <?php if (!empty($n['link_url'])): ?>
                                <a href="<?= e($n['link_url']) ?>"><?= e($n['title']) ?></a>
                            <?php else: ?>
                                <?= e($n['title']) ?>
                            <?php endif; ?>
                        </h3>
                        <?php if (!empty($n['body'])): ?>
                            <p class="v4-news-text"><?= e($n['body']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <!-- Fallback solange news-Tabelle leer ist: bisherige hardcoded Lang-Items. -->
            <ol class="v4-news-list">
                <li class="v4-news-item v4-reveal v4-rd1">
                    <time class="v4-news-date"><?= t('home.news.0_date') ?></time>
                    <h3 class="v4-news-title"><?= t('home.news.0_title') ?></h3>
                    <p class="v4-news-text"><?= t('home.news.0_text') ?></p>
                </li>
                <li class="v4-news-item v4-reveal v4-rd2">
                    <time class="v4-news-date"><?= t('home.news.1_date') ?></time>
                    <h3 class="v4-news-title"><?= t('home.news.1_title') ?></h3>
                    <p class="v4-news-text"><?= t('home.news.1_text') ?></p>
                </li>
            </ol>
        <?php endif; ?>
    </div>
</section>

<!-- 3. CURRENT CONFERENCES -->
<section class="v4-conferences">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal"><?= t('home.section_current') ?></h2>
        <hr class="v4-section-bar v4-reveal">

        <div class="v4-conf-grid">
            <div class="v4-reveal v4-rd1">
                <article class="v4-conf-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener"
                       class="v4-conf-img-link"
                       aria-label="127. Jahrestagung der DGaO an der HAW Hamburg">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="v4-conf-img">
                    </a>
                    <div class="v4-conf-body">
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener"
                           class="v4-conf-btn v4-conf-btn--haw">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </article>
            </div>

            <div class="v4-reveal v4-rd2">
                <article class="v4-conf-card">
                    <a href="/archiv/126" class="v4-conf-img-link"
                       aria-label="126. Jahrestagung der DGaO, Uni Stuttgart 2025 &mdash; Archiv">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="v4-conf-img">
                    </a>
                    <div class="v4-conf-body">
                        <a href="/archiv/126" class="v4-conf-btn">
                            <?= t('home.conf_126_btn') ?>
                        </a>
                    </div>
                </article>
            </div>
        </div>
    </div>
</section>

<!-- 3. ARCHIVE -->
<section class="v4-archive">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal"><?= t('home.section_archive') ?></h2>
        <hr class="v4-section-bar v4-reveal">

        <ul class="v4-archive-list">
            <?php foreach ($recent as $i => $t_item): ?>
            <li class="v4-reveal v4-rd<?= min($i + 1, 5) ?>">
                <a href="/archiv/<?= $t_item['nummer'] ?>" class="v4-archive-item">
                    <span class="v4-archive-year"><?= $t_item['jahr'] ?></span>
                    <span class="v4-archive-loc">
                        <?php if ($t_item['ort']): ?>
                            <?= e($t_item['ort']) ?>
                        <?php endif; ?>
                        <span class="v4-archive-nr"><?= $t_item['nummer'] ?>.&nbsp;<?= t('home.tagung_suffix') ?></span>
                    </span>
                    <span class="v4-archive-badge"><?= $t_item['paper_anzahl'] ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <a href="/archiv" class="v4-archive-more v4-reveal">
            <?= t('home.show_all') ?>
        </a>
    </div>
</section>
