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

$oldestYear = !empty($tagungen) ? $tagungen[count($tagungen) - 1]['jahr'] : '1900';

$extraHead = '
<link href="https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&display=swap" rel="stylesheet">
<style>
/* =================================================
   V1 — THE ARCHIVE ROOM
   Dark Scholarly / Academic Prestige
   White header → Gold threshold → Deep navy interior
   ================================================= */

:root {
    --v1-night:       #0d1017;
    --v1-deep:        #131722;
    --v1-chamber:     #191e2c;
    --v1-shelf:       #212736;
    --v1-alcove:      #2a3044;
    --v1-gold:        #c4a24e;
    --v1-gold-pale:   #d4b668;
    --v1-gold-dim:    #9e843f;
    --v1-gold-ghost:  rgba(196, 162, 78, 0.06);
    --v1-gold-glow:   rgba(196, 162, 78, 0.14);
    --v1-text:        #ddd9d0;
    --v1-text-mid:    #a9a49a;
    --v1-text-quiet:  #706c64;
    --v1-line:        #2a2f3d;
    --v1-line-faint:  #222736;
    --v1-serif:       "Crimson Pro", "Libre Baskerville", Georgia, serif;
    --v1-sans:        "Source Sans 3", "Segoe UI", sans-serif;
}

/* ————— SHELL OVERRIDES ————— */

body {
    background: var(--v1-deep) !important;
    color: var(--v1-text) !important;
}

/* Header stays WHITE — logo blends naturally */
.site-header {
    background: #ffffff !important;
    border-bottom: none !important;
    box-shadow: none !important;
}

/* Nav becomes the dark threshold */
.site-nav {
    background: var(--v1-night) !important;
    border-top: 2px solid var(--v1-gold) !important;
    border-bottom: 1px solid var(--v1-line) !important;
}

.site-nav .nav-link {
    color: var(--v1-text-mid) !important;
    font-family: var(--v1-sans) !important;
    transition: color 0.2s, border-color 0.2s !important;
}

.site-nav .nav-link:hover {
    color: var(--v1-gold-pale) !important;
    border-bottom-color: var(--v1-gold-dim) !important;
}

.site-nav .nav-link.active {
    color: var(--v1-gold) !important;
    font-weight: 600 !important;
    border-bottom-color: var(--v1-gold) !important;
}

.site-nav .navbar-toggler {
    border-color: var(--v1-line) !important;
}

.site-nav .navbar-toggler-icon {
    filter: invert(0.75) !important;
}

.lang-toggle {
    color: var(--v1-text-quiet) !important;
    border-color: var(--v1-line) !important;
}

.lang-toggle:hover {
    color: var(--v1-gold) !important;
    border-color: var(--v1-gold-dim) !important;
    background: var(--v1-gold-ghost) !important;
}

/* Footer */
.site-footer {
    background: var(--v1-night) !important;
    border-top: 2px solid var(--v1-gold) !important;
    color: var(--v1-text-quiet) !important;
}

.site-footer a {
    color: var(--v1-text-mid) !important;
}

.site-footer a:hover {
    color: var(--v1-gold) !important;
}


/* ————— HERO ————— */

.v1-hero {
    background: var(--v1-deep);
    position: relative;
    padding: 5.5rem 0 4.5rem;
    overflow: hidden;
}

/* Fine crosshatch — like engraved paper */
.v1-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    opacity: 0.35;
    background-image:
        linear-gradient(45deg, var(--v1-line-faint) 1px, transparent 1px),
        linear-gradient(-45deg, var(--v1-line-faint) 1px, transparent 1px);
    background-size: 32px 32px;
    pointer-events: none;
}

/* Warm radial glow from center */
.v1-hero::after {
    content: "";
    position: absolute;
    top: -20%;
    left: 25%;
    width: 50%;
    height: 140%;
    background: radial-gradient(ellipse at center, rgba(196,162,78,0.045) 0%, transparent 65%);
    pointer-events: none;
}

.v1-hero-content {
    position: relative;
    z-index: 1;
    text-align: center;
    max-width: 700px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v1-title {
    font-family: var(--v1-serif);
    font-weight: 600;
    font-size: 3.4rem;
    color: var(--v1-text);
    letter-spacing: -0.025em;
    line-height: 1.1;
    margin: 0;
}

.v1-issn {
    font-family: var(--v1-sans);
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--v1-gold);
    text-transform: uppercase;
    letter-spacing: 0.25em;
    margin: 0.6rem 0 0;
}

.v1-divider {
    display: block;
    width: 80px;
    height: 0;
    border: none;
    border-top: 1px solid var(--v1-gold);
    margin: 1.6rem auto;
    opacity: 0.5;
}

.v1-tagline {
    font-family: var(--v1-serif);
    font-weight: 400;
    font-style: italic;
    font-size: 1.05rem;
    color: var(--v1-text-mid);
    line-height: 1.55;
    margin: 0;
}

.v1-since {
    font-family: var(--v1-sans);
    font-size: 0.7rem;
    color: var(--v1-text-quiet);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    margin: 1.8rem 0 0;
}

.v1-since em {
    color: var(--v1-gold-dim);
    font-style: normal;
}


/* ————— STATS ————— */

.v1-stats {
    background: var(--v1-chamber);
    border-top: 1px solid var(--v1-line);
    border-bottom: 1px solid var(--v1-line);
    padding: 2.75rem 0;
}

.v1-stats-row {
    display: flex;
    justify-content: center;
    gap: 4.5rem;
    max-width: 660px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v1-stat {
    text-align: center;
    position: relative;
    padding-top: 1.1rem;
}

.v1-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 36px;
    height: 2px;
    background: var(--v1-gold);
}

.v1-stat-num {
    display: block;
    font-family: var(--v1-serif);
    font-style: italic;
    font-weight: 500;
    font-size: 2.8rem;
    color: var(--v1-gold);
    line-height: 1.1;
    letter-spacing: -0.02em;
}

.v1-stat-label {
    display: block;
    font-family: var(--v1-sans);
    font-size: 0.65rem;
    font-weight: 600;
    color: var(--v1-text-quiet);
    text-transform: uppercase;
    letter-spacing: 0.2em;
    margin-top: 0.3rem;
}


/* ————— SEARCH ————— */

.v1-search {
    background: var(--v1-deep);
    padding: 3.5rem 0;
}

.v1-search-inner {
    max-width: 660px;
    margin: 0 auto;
    padding: 0 1.5rem;
    text-align: center;
}

.v1-welcome {
    font-family: var(--v1-sans);
    font-size: 1rem;
    color: var(--v1-text-mid);
    line-height: 1.7;
    margin: 0 0 2rem;
}

.v1-welcome a {
    color: var(--v1-gold);
    text-decoration-line: underline;
    text-decoration-color: rgba(196,162,78,0.3);
    text-underline-offset: 0.15em;
    transition: text-decoration-color 0.2s;
}

.v1-welcome a:hover {
    text-decoration-color: var(--v1-gold);
}

.v1-search-bar {
    display: flex;
    align-items: center;
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line);
    border-radius: 3px;
    overflow: hidden;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.v1-search-bar:focus-within {
    border-color: var(--v1-gold-dim);
    box-shadow: 0 0 0 3px var(--v1-gold-glow);
}

.v1-search-icon {
    color: var(--v1-text-quiet);
    padding: 0 0 0 1rem;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.v1-search-input {
    flex: 1;
    background: transparent;
    border: none;
    padding: 0.9rem 0.85rem;
    font-family: var(--v1-sans);
    font-size: 0.95rem;
    color: var(--v1-text);
    outline: none;
    min-width: 0;
}

.v1-search-input::placeholder { color: var(--v1-text-quiet); }

.v1-search-btn {
    flex-shrink: 0;
    background: var(--v1-gold);
    border: none;
    color: var(--v1-night);
    padding: 0.9rem 1.4rem;
    font-family: var(--v1-sans);
    font-weight: 600;
    font-size: 0.85rem;
    letter-spacing: 0.03em;
    cursor: pointer;
    transition: background 0.2s;
}

.v1-search-btn:hover { background: var(--v1-gold-pale); }

.v1-search-meta {
    font-size: 0.78rem;
    color: var(--v1-text-quiet);
    margin: 1.1rem 0 0;
}


/* ————— CONFERENCES ————— */

.v1-confs {
    background: var(--v1-chamber);
    border-top: 1px solid var(--v1-line);
    padding: 3.5rem 0;
}

.v1-confs-inner {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v1-heading {
    font-family: var(--v1-serif);
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--v1-text);
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--v1-gold-dim);
    display: inline-block;
    margin-bottom: 1.6rem;
}

.v1-card {
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line);
    border-left: 3px solid var(--v1-gold-dim);
    border-radius: 2px;
    overflow: hidden;
    transition: transform 0.25s, box-shadow 0.25s, border-left-color 0.25s;
    display: flex;
    flex-direction: column;
}

.v1-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    border-left-color: var(--v1-gold);
}

.v1-card-img {
    display: block;
    overflow: hidden;
}

.v1-card-img img {
    width: 100%;
    height: auto;
    display: block;
    opacity: 0.82;
    transition: opacity 0.3s;
}

.v1-card:hover .v1-card-img img { opacity: 0.95; }

.v1-card-body {
    padding: 1.3rem 1.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.v1-card-title {
    font-family: var(--v1-serif);
    font-size: 1rem;
    font-weight: 600;
    color: var(--v1-text);
    margin: 0 0 0.4rem;
}

.v1-card-text {
    font-family: var(--v1-sans);
    font-size: 0.86rem;
    color: var(--v1-text-quiet);
    flex: 1;
    margin: 0 0 0.8rem;
}

.v1-btn-gold {
    display: inline-block;
    width: fit-content;
    background: transparent;
    color: var(--v1-gold);
    border: 1px solid var(--v1-gold-dim);
    padding: 0.42rem 0.95rem;
    font-family: var(--v1-sans);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    border-radius: 2px;
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}

.v1-btn-gold:hover {
    background: var(--v1-gold);
    color: var(--v1-night);
}

.v1-alert {
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line);
    border-left: 2px solid var(--v1-gold-dim);
    border-radius: 2px;
    color: var(--v1-text-mid);
    font-size: 0.8rem;
    padding: 0.65rem 0.9rem;
    margin-top: 0.65rem;
    line-height: 1.5;
}

.v1-alert strong { color: var(--v1-gold); font-weight: 600; }


/* ————— ARCHIVE ————— */

.v1-archive {
    background: var(--v1-deep);
    border-top: 1px solid var(--v1-line);
    padding: 3.5rem 0 3rem;
}

.v1-archive-inner {
    max-width: 660px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v1-list { list-style: none; padding: 0; margin: 0; }

.v1-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line-faint);
    border-left: 3px solid transparent;
    margin-bottom: -1px;
    text-decoration: none;
    color: var(--v1-text);
    transition: transform 0.2s, border-left-color 0.2s, background 0.2s;
}

.v1-list-item:first-child { border-radius: 2px 2px 0 0; }
.v1-list-item:last-child  { border-radius: 0 0 2px 2px; }

.v1-list-item:hover {
    transform: translateX(4px);
    border-left-color: var(--v1-gold);
    background: var(--v1-alcove);
    color: var(--v1-text);
    z-index: 1;
    position: relative;
}

.v1-list-year {
    font-family: var(--v1-serif);
    font-weight: 700;
    font-size: 0.95rem;
}

.v1-list-meta {
    font-size: 0.86rem;
    color: var(--v1-text-mid);
    margin-left: 0.3rem;
}

.v1-list-suffix {
    font-size: 0.76rem;
    color: var(--v1-text-quiet);
    margin-left: 0.3rem;
}

.v1-list-count {
    font-family: var(--v1-serif);
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--v1-gold);
    flex-shrink: 0;
    min-width: 2rem;
    text-align: right;
}

.v1-show-all {
    display: block;
    text-align: center;
    padding: 0.8rem;
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line-faint);
    border-top: none;
    border-radius: 0 0 2px 2px;
    font-family: var(--v1-sans);
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--v1-gold);
    text-decoration: none;
    transition: background 0.2s;
}

.v1-show-all:hover {
    background: var(--v1-alcove);
    color: var(--v1-gold-pale);
}


/* ————— PILLARS ————— */

.v1-pillars {
    background: var(--v1-chamber);
    border-top: 1px solid var(--v1-line);
    padding: 3.5rem 0 4rem;
}

.v1-pillars-inner {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v1-pillars-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
}

.v1-pillar {
    display: block;
    text-align: center;
    text-decoration: none;
    color: var(--v1-text);
    padding: 2.2rem 1.3rem 2rem;
    background: var(--v1-shelf);
    border: 1px solid var(--v1-line);
    border-top: 2px solid var(--v1-gold-dim);
    border-radius: 2px;
    transition: transform 0.25s, box-shadow 0.25s, border-top-color 0.25s;
}

.v1-pillar:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    border-top-color: var(--v1-gold);
    color: var(--v1-text);
}

.v1-pillar-icon {
    display: block;
    font-size: 1.5rem;
    color: var(--v1-gold);
    margin-bottom: 0.8rem;
}

.v1-pillar-title {
    font-family: var(--v1-serif);
    font-size: 0.92rem;
    font-weight: 600;
    margin-bottom: 0.35rem;
}

.v1-pillar-desc {
    font-family: var(--v1-sans);
    font-size: 0.8rem;
    color: var(--v1-text-quiet);
    line-height: 1.5;
    margin: 0;
}


/* ————— ENTRANCE ————— */

@keyframes v1Rise {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

.v1-hero-content > * {
    animation: v1Rise 0.55s ease-out both;
}
.v1-hero-content > :nth-child(1) { animation-delay: 0s; }
.v1-hero-content > :nth-child(2) { animation-delay: 0.06s; }
.v1-hero-content > :nth-child(3) { animation-delay: 0.12s; }
.v1-hero-content > :nth-child(4) { animation-delay: 0.18s; }
.v1-hero-content > :nth-child(5) { animation-delay: 0.24s; }


/* ————— RESPONSIVE ————— */

@media (max-width: 767.98px) {
    .v1-hero { padding: 3.5rem 0 3rem; }
    .v1-title { font-size: 2.5rem; }
    .v1-stats-row { gap: 2.5rem; }
    .v1-stat-num { font-size: 2.2rem; }
    .v1-pillars-grid { grid-template-columns: 1fr; max-width: 380px; margin: 0 auto; }
    .v1-card { max-width: 420px; margin-left: auto; margin-right: auto; }
}

@media (max-width: 575.98px) {
    .v1-hero { padding: 2.5rem 0 2rem; }
    .v1-title { font-size: 2rem; }
    .v1-tagline { font-size: 0.95rem; }
    .v1-stats-row { flex-direction: column; align-items: center; gap: 1.6rem; }
    .v1-stat-num { font-size: 2rem; }
    .v1-search-btn { padding: 0.9rem 1rem; font-size: 0.82rem; }
    .site-header .header-logo-img { height: 56px; }
}
</style>
';
?>

<!-- ============================== HERO ============================== -->
<section class="v1-hero">
    <div class="v1-hero-content">
        <h1 class="v1-title">DGaO-Proceedings</h1>
        <p class="v1-issn">ISSN <?= SITE_ISSN ?></p>
        <hr class="v1-divider">
        <p class="v1-tagline"><?= t('home.tagline') ?></p>
        <p class="v1-since"><?= t('home.landing.since_year') ?> <em><?= e($oldestYear) ?></em></p>
    </div>
</section>

<!-- ============================== STATS ============================== -->
<section class="v1-stats">
    <div class="v1-stats-row">
        <div class="v1-stat">
            <span class="v1-stat-num"><?= $nf($stats['papers']) ?></span>
            <span class="v1-stat-label"><?= t('home.stat.papers') ?></span>
        </div>
        <div class="v1-stat">
            <span class="v1-stat-num"><?= $stats['tagungen'] ?></span>
            <span class="v1-stat-label"><?= t('home.stat.conferences') ?></span>
        </div>
        <div class="v1-stat">
            <span class="v1-stat-num"><?= $nf($stats['autoren']) ?></span>
            <span class="v1-stat-label"><?= t('home.stat.authors') ?></span>
        </div>
    </div>
</section>

<!-- ============================== SEARCH ============================== -->
<section class="v1-search">
    <div class="v1-search-inner">
        <p class="v1-welcome">
            <?= t('home.welcome') ?>
            <a href="https://www.dgao.de/" target="_blank" rel="noopener"><?= t('home.dgao_name') ?></a>
        </p>

        <form action="/suche" method="get">
            <div class="v1-search-bar">
                <i class="bi bi-search v1-search-icon"></i>
                <input type="search" name="q" class="v1-search-input"
                       placeholder="<?= t('home.search_placeholder') ?>"
                       aria-label="<?= t('nav.suche') ?>">
                <button type="submit" class="v1-search-btn"><?= t('home.search_btn') ?></button>
            </div>
        </form>

        <p class="v1-search-meta">
            <?= sprintf(t('home.landing.search_across'), $nf($stats['papers']), $nf($stats['autoren']), $stats['tagungen']) ?>
        </p>
    </div>
</section>

<!-- ============================== CONFERENCES ============================== -->
<section class="v1-confs">
    <div class="v1-confs-inner">
        <h2 class="v1-heading"><?= t('home.section_current') ?></h2>

        <div class="row g-4 mb-2">
            <div class="col-md-6">
                <div class="v1-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v1-card-img">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg">
                    </a>
                    <div class="v1-card-body">
                        <h3 class="v1-card-title"><?= t('home.conf_127_title') ?></h3>
                        <p class="v1-card-text"><?= t('home.conf_127_text') ?></p>
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v1-btn-gold">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v1-alert"><?= t('home.conf_127_alert') ?></div>
            </div>
            <div class="col-md-6">
                <div class="v1-card">
                    <a href="/archiv/126" class="v1-card-img">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart">
                    </a>
                    <div class="v1-card-body">
                        <h3 class="v1-card-title"><?= t('home.conf_126_title') ?></h3>
                        <p class="v1-card-text"><?= t('home.conf_126_text') ?></p>
                        <a href="/archiv/126" class="v1-btn-gold"><?= t('home.conf_126_btn') ?></a>
                    </div>
                </div>
                <div class="v1-alert"><?= t('home.conf_126_alert') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- ============================== ARCHIVE ============================== -->
<section class="v1-archive">
    <div class="v1-archive-inner">
        <h2 class="v1-heading"><?= t('home.section_archive') ?></h2>

        <ul class="v1-list">
            <?php foreach ($recent as $t_item): ?>
            <li>
                <a href="/archiv/<?= $t_item['nummer'] ?>" class="v1-list-item">
                    <span>
                        <span class="v1-list-year"><?= $t_item['jahr'] ?></span>
                        <?php if ($t_item['ort']): ?>
                            <span class="v1-list-meta">&ndash; <?= e($t_item['ort']) ?></span>
                        <?php endif; ?>
                        <span class="v1-list-suffix">(<?= $t_item['nummer'] ?>.&nbsp;<?= t('home.tagung_suffix') ?>)</span>
                    </span>
                    <span class="v1-list-count"><?= $t_item['paper_anzahl'] ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <a href="/archiv" class="v1-show-all"><?= t('home.show_all') ?></a>
    </div>
</section>

<!-- ============================== PILLARS ============================== -->
<section class="v1-pillars">
    <div class="v1-pillars-inner">
        <div class="v1-pillars-grid">
            <a href="/archiv" class="v1-pillar">
                <i class="bi bi-archive v1-pillar-icon"></i>
                <div class="v1-pillar-title"><?= t('home.landing.browse_archive') ?></div>
                <p class="v1-pillar-desc"><?= t('home.landing.browse_archive_desc') ?></p>
            </a>
            <a href="/suche" class="v1-pillar">
                <i class="bi bi-search v1-pillar-icon"></i>
                <div class="v1-pillar-title"><?= t('home.landing.search_papers') ?></div>
                <p class="v1-pillar-desc"><?= t('home.landing.search_papers_desc') ?></p>
            </a>
            <a href="/autoren" class="v1-pillar">
                <i class="bi bi-people v1-pillar-icon"></i>
                <div class="v1-pillar-title"><?= t('home.landing.explore_authors') ?></div>
                <p class="v1-pillar-desc"><?= t('home.landing.explore_authors_desc') ?></p>
            </a>
        </div>
    </div>
</section>
