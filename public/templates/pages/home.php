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

$extraHead = <<<'STYLES'
<style>
/* =============================================
   Homepage — Editorial split hero
   - Asymmetric: dark burgundy left, spectrum visual right
   - Search + stats inline, left-aligned (editorial, not centered)
   - DGaO touch: optical spectrum + diffraction wavefronts
   ============================================= */

/* --- HERO container — full-bleed, 1280px inner.
   Background carries a soft dispersive atmosphere (DGaO-Optik anchor)
   that survives even when the right-side visual block is hidden on mobile. --- */
.hero {
    position: relative;
    background:
        linear-gradient(132deg, transparent 55%, rgba(124, 58, 237, 0.07) 70%, transparent 88%),
        linear-gradient(138deg, transparent 60%, rgba(8, 145, 178, 0.06) 75%, transparent 92%),
        linear-gradient(144deg, transparent 65%, rgba(202, 138, 4, 0.06) 80%, transparent 96%),
        linear-gradient(150deg, transparent 70%, rgba(234, 88, 12, 0.07) 85%, transparent 100%),
        linear-gradient(156deg, transparent 75%, rgba(180, 46, 66, 0.12) 90%, transparent 100%),
        var(--accent-dark);
    color: #fff;
    overflow: hidden;
    isolation: isolate;
}

.hero__inner {
    position: relative;
    max-width: 1280px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
    align-items: stretch;
}

.hero__inner > * { min-width: 0; }

/* --- LEFT: editorial content --- */
.hero__content {
    position: relative;
    z-index: 2;
    padding: 4rem 3rem 3.25rem 1.5rem;
    max-width: 640px;
    min-width: 0;
}

/* Eyebrow with leading rule */
.hero__eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    font-family: var(--font-display);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.78);
    margin-bottom: 1.25rem;
}
.hero__eyebrow::before {
    content: '';
    width: 36px;
    height: 2px;
    background: var(--accent-light);
    border-radius: 1px;
}

/* H1 — calmer than 3.6rem; serious editorial weight */
.hero__title {
    font-family: var(--font-display);
    font-size: clamp(2rem, 3.6vw, 2.75rem);
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.022em;
    line-height: 1.1;
    margin: 0 0 0.85rem;
}

.hero__tagline {
    font-family: var(--font-body);
    font-size: 1.04rem;
    font-weight: 400;
    color: rgba(255, 255, 255, 0.78);
    line-height: 1.55;
    max-width: 520px;
    margin: 0 0 2.25rem;
}

.hero__tagline a {
    color: #fff;
    text-decoration: underline;
    text-decoration-color: rgba(255, 255, 255, 0.32);
    text-underline-offset: 3px;
    text-decoration-thickness: 1px;
    transition: text-decoration-color 0.2s var(--ease);
}
.hero__tagline a:hover {
    text-decoration-color: rgba(255, 255, 255, 0.85);
    color: #fff;
}

/* --- SEARCH — Springer-style: label above, big input, side button --- */
.hero__search-label {
    display: block;
    font-family: var(--font-display);
    font-size: 0.92rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 0.55rem;
}

.hero__search-form { margin: 0; width: 100%; max-width: 520px; }

.hero__search {
    display: flex;
    align-items: stretch;
    width: 100%;
    background: #fff;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 0 rgba(0, 0, 0, 0.04);
}

.hero__search-input {
    flex: 1 1 0;
    min-width: 0;
    border: 1px solid #1a1d2e;
    border-right: none;
    background: #fff;
    color: var(--text);
    font-family: var(--font-body);
    font-size: 1rem;
    padding: 0.95rem 1.1rem;
    border-radius: 6px 0 0 6px;
    outline: none;
}

.hero__search-input::placeholder {
    color: var(--text-light);
}

.hero__search-input:focus {
    outline: 3px solid #66a3c7;
    outline-offset: -1px;
}

.hero__search-btn {
    flex-shrink: 0;
    border: 1px solid var(--accent);
    background: var(--accent);
    color: #fff;
    padding: 0 1.5rem;
    border-radius: 0 6px 6px 0;
    font-size: 1.15rem;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s var(--ease);
}

.hero__search-btn:hover { background: var(--accent-light); border-color: var(--accent-light); }
.hero__search-btn:focus-visible { outline: 3px solid #66a3c7; outline-offset: 2px; }

/* --- STATS inline — Springer-style three-up under search --- */
.hero__stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, auto));
    column-gap: 3rem;
    row-gap: 1rem;
    margin-top: 2.5rem;
    padding-top: 2rem;
    border-top: 1px solid rgba(255, 255, 255, 0.14);
}

.hero__stat-num {
    display: block;
    font-family: var(--font-display);
    font-size: 1.85rem;
    font-weight: 700;
    color: #fff;
    line-height: 1;
    letter-spacing: -0.025em;
    margin-bottom: 0.35rem;
}

.hero__stat-lbl {
    display: block;
    font-family: var(--font-body);
    font-size: 0.86rem;
    color: rgba(255, 255, 255, 0.7);
    line-height: 1.3;
}

/* --- RIGHT: optical spectrum visual (CSS-only, DGaO-thematic) --- */
.hero__visual {
    position: relative;
    min-height: 380px;
    overflow: hidden;
}

/* Layer 1: dispersion bands (spectrum), diagonal */
.hero__visual::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(118deg, transparent 22%, rgba(124, 58, 237, 0.16) 30%, transparent 38%),
        linear-gradient(122deg, transparent 26%, rgba(37, 99, 235, 0.14) 34%, transparent 42%),
        linear-gradient(126deg, transparent 30%, rgba(8, 145, 178, 0.13) 38%, transparent 46%),
        linear-gradient(130deg, transparent 34%, rgba(5, 150, 105, 0.11) 42%, transparent 50%),
        linear-gradient(134deg, transparent 38%, rgba(202, 138, 4, 0.12) 46%, transparent 54%),
        linear-gradient(138deg, transparent 42%, rgba(234, 88, 12, 0.14) 50%, transparent 58%),
        linear-gradient(142deg, transparent 46%, rgba(180, 46, 66, 0.20) 54%, transparent 64%);
    filter: blur(6px);
    pointer-events: none;
}

/* Layer 2: warm bloom right side, edge-fade left for clean transition */
.hero__visual-glow {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse at 78% 42%, rgba(255, 220, 180, 0.10) 0%, transparent 55%),
        linear-gradient(90deg, var(--accent-dark) 0%, transparent 18%);
    pointer-events: none;
}

/* Layer 4: fine grain for photo-like texture */
.hero__visual-grain {
    position: absolute;
    inset: 0;
    opacity: 0.5;
    background-image: radial-gradient(rgba(255, 255, 255, 0.045) 1px, transparent 1px);
    background-size: 3px 3px;
    pointer-events: none;
    mix-blend-mode: overlay;
}

/* Edge-spectrum accent line on the bottom of hero (DGaO signature) */
.hero__edge {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    height: 3px;
    background: linear-gradient(90deg,
        #b42e42 0%, #7c3aed 16%, #2563eb 32%,
        #0891b2 48%, #059669 60%, #ca8a04 74%,
        #ea580c 88%, #b42e42 100%);
    z-index: 3;
}

/* --- Tablet & below: visual is decorative-only — hide it,
       Burgundy hero stays clean, edge spectrum line is the only DGaO accent --- */
@media (max-width: 991.98px) {
    .hero__inner {
        grid-template-columns: 1fr;
    }
    .hero__content {
        padding: 3rem 1.5rem 2.5rem;
        max-width: none;
    }
    .hero__visual { display: none; }
    .hero__stats { column-gap: 2rem; }
}

@media (max-width: 575.98px) {
    .hero__content { padding: 2.25rem 1.25rem 2rem; }
    .hero__title { font-size: 1.85rem; }
    .hero__tagline { font-size: 0.98rem; margin-bottom: 1.75rem; }
    .hero__stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        column-gap: 1rem;
        margin-top: 1.75rem;
        padding-top: 1.5rem;
    }
    .hero__stat-num { font-size: 1.4rem; }
    .hero__stat-lbl { font-size: 0.78rem; }
}

/* --- DOWNSTREAM SECTIONS (unchanged but cleaned spacing) --- */
.v4-section-inner {
    max-width: 1080px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v4-section-title {
    font-family: var(--font-display);
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.015em;
    margin: 0 0 0.3rem;
}

.v4-section-bar {
    display: block;
    height: 3px;
    width: 56px;
    border: none;
    border-radius: 2px;
    background: var(--accent);
    margin: 0 0 1.75rem;
    opacity: 1;
}

/* --- NEWS — compact editorial grid (Optica/Nature pattern):
       inline date eyebrow + headline + tight excerpt, two columns on desktop --- */
.v4-news {
    background: var(--white);
    padding: 2.75rem 0 2.5rem;
    border-bottom: 1px solid var(--border-light);
}

.v4-news-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 2.5rem;
    border-top: 1px solid var(--border);
}

.v4-news-item {
    padding: 1.05rem 0 1.1rem;
    border-bottom: 1px solid var(--border-light);
    min-width: 0;
}

.v4-news-date {
    display: inline-block;
    font-family: var(--font-display);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 0.3rem;
}

.v4-news-title {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    letter-spacing: -0.005em;
    line-height: 1.35;
    margin: 0 0 0.3rem;
}

.v4-news-text {
    font-family: var(--font-body);
    font-size: 0.88rem;
    color: var(--text-mid);
    line-height: 1.5;
    margin: 0;
}

@media (max-width: 767.98px) {
    .v4-news-list {
        grid-template-columns: 1fr;
        column-gap: 0;
    }
    .v4-news-item { padding: 0.9rem 0 1rem; }
    .v4-news-title { font-size: 0.96rem; }
    .v4-news-text { font-size: 0.85rem; }
}

.v4-conferences {
    background: var(--paper);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--border-light);
}

.v4-conf-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 1.5rem;
}

.v4-conf-grid > * { min-width: 0; }

.v4-conf-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    min-width: 0;
    transition: box-shadow 0.25s var(--ease);
}

.v4-conf-card:hover {
    box-shadow: var(--shadow-md);
}

.v4-conf-img-wrap {
    overflow: hidden;
    border-bottom: 1px solid var(--border-light);
}

.v4-conf-img {
    width: 100%;
    height: auto;
    display: block;
}

.v4-conf-body { padding: 1.25rem 1.5rem 1.5rem; }

.v4-conf-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-family: var(--font-display);
    font-size: 0.86rem;
    font-weight: 600;
    color: #fff;
    background: var(--accent);
    border: none;
    border-radius: 6px;
    padding: 0.55rem 1.1rem;
    text-decoration: none;
    transition: background 0.18s var(--ease);
}
.v4-conf-btn:hover {
    background: var(--accent-light);
    color: #fff;
    text-decoration: none;
}

.v4-archive {
    background: var(--white);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--border-light);
}

.v4-features {
    background: var(--paper);
    padding: 3.25rem 0 3.75rem;
}

.v4-trust {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
}

.v4-trust-item { display: flex; align-items: flex-start; gap: 0.7rem; }

.v4-trust-icon {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--accent-pale);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent);
    font-size: 0.95rem;
}

.v4-trust-label {
    font-family: var(--font-display);
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
    line-height: 1.3;
}

.v4-trust-desc {
    font-family: var(--font-body);
    font-size: 0.78rem;
    color: var(--text-muted);
    margin: 0.15rem 0 0;
    line-height: 1.5;
}

@media (max-width: 767.98px) {
    .v4-conf-grid { grid-template-columns: 1fr; gap: 1.25rem; }
    .v4-trust { grid-template-columns: 1fr; gap: 1.25rem; }
}

/* --- Reduced motion --- */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
STYLES;
?>

<!-- 1. HERO — split layout, search + stats inline -->
<section class="hero" aria-labelledby="hero-title">
    <div class="hero__inner">
        <div class="hero__content">
            <div class="hero__eyebrow">ISSN <?= SITE_ISSN ?> &middot; <?= currentLang() === 'en' ? 'Open access' : 'Frei zug&auml;nglich' ?></div>

            <h1 class="hero__title" id="hero-title">DGaO-Proceedings</h1>

            <p class="hero__tagline"><?= t('home.tagline') ?></p>

            <form action="/suche" method="get" class="hero__search-form" role="search">
                <label for="hero-search-input" class="hero__search-label">
                    <?= currentLang() === 'en' ? 'Search articles, authors, conferences' : 'Beitr&auml;ge, Autoren oder Tagungen suchen' ?>
                </label>
                <div class="hero__search">
                    <input id="hero-search-input"
                           type="search"
                           name="q"
                           class="hero__search-input"
                           placeholder="<?= t('home.search_placeholder') ?>"
                           aria-label="<?= t('nav.suche') ?>"
                           autocomplete="off">
                    <button type="submit" class="hero__search-btn" aria-label="<?= t('home.search_btn') ?>">
                        <i class="bi bi-search" aria-hidden="true"></i>
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

        <div class="hero__visual" aria-hidden="true">
            <div class="hero__visual-glow"></div>
            <div class="hero__visual-grain"></div>
        </div>
    </div>
    <div class="hero__edge" aria-hidden="true"></div>
</section>

<!-- 2. NEWS — latest announcements -->
<section class="v4-news" aria-labelledby="news-heading">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal" id="news-heading"><?= t('home.section_news') ?></h2>
        <hr class="v4-section-bar v4-reveal">

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
                    <div class="v4-conf-img-wrap">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="v4-conf-img">
                    </div>
                    <div class="v4-conf-body">
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v4-conf-btn">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </article>
            </div>

            <div class="v4-reveal v4-rd2">
                <article class="v4-conf-card">
                    <div class="v4-conf-img-wrap">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="v4-conf-img">
                    </div>
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

<!-- 4. TRUST -->
<section class="v4-features">
    <div class="v4-section-inner">
        <div class="v4-trust">
            <div class="v4-trust-item v4-reveal v4-rd1">
                <div class="v4-trust-icon"><i class="bi bi-unlock"></i></div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.open_access') ?></div>
                    <div class="v4-trust-desc"><?= t('home.landing.open_access_desc') ?></div>
                </div>
            </div>
            <div class="v4-trust-item v4-reveal v4-rd2">
                <div class="v4-trust-icon"><i class="bi bi-mortarboard"></i></div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.peer_community') ?></div>
                    <div class="v4-trust-desc"><?= t('home.landing.peer_community_desc') ?></div>
                </div>
            </div>
            <div class="v4-trust-item v4-reveal v4-rd3">
                <div class="v4-trust-icon"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.since_year') ?> <?= $oldestYear ?></div>
                    <div class="v4-trust-desc"><?= t('home.participation_note') ?></div>
                </div>
            </div>
        </div>
    </div>
</section>
