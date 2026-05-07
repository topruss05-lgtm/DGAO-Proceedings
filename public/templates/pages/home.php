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
   Homepage — "Corporate Spectral" Hero
   Dark prismatic hero with integrated search.
   Floating card bridges the dark-to-light edge.
   Global tokens from custom.css.
   ============================================= */

/* --- HERO — Dark prismatic with warm undertone --- */
.v4-hero {
    position: relative;
    background: transparent;
    padding: 5.5rem 0 9.5rem;
    overflow: visible;
    text-align: center;
    z-index: 1;
}

/* --- Fixed prisma background — stays in place while content scrolls over --- */
.v4-hero-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    background: linear-gradient(170deg, #0b0910 0%, #0e0d20 40%, #141230 100%);
    pointer-events: none;
}

/* Dot grid texture — fine white points */
.v4-hero-bg::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0.055;
    background-image: radial-gradient(circle, rgba(255,255,255,0.9) 1px, transparent 1px);
    background-size: 24px 24px;
    pointer-events: none;
}

/* Prismatic light rays — realistic dispersion spectrum, overlapping bands.
   Subtle intensity breathing simulates natural light scintillation —
   the way dispersed light gently fluctuates through optical media. */
.v4-hero-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    opacity: 0.9;
    animation: v4-rayBreathe 40s ease-in-out infinite;
    background:
        linear-gradient(118deg, transparent 30%, rgba(138, 43, 226, 0.16) 37%, transparent 44%),
        linear-gradient(121deg, transparent 32%, rgba(30, 90, 255, 0.14) 39%, transparent 46%),
        linear-gradient(124deg, transparent 33%, rgba(0, 210, 255, 0.13) 41%, transparent 49%),
        linear-gradient(127deg, transparent 34%, rgba(0, 255, 65, 0.11) 43%, transparent 52%),
        linear-gradient(130deg, transparent 35%, rgba(255, 240, 0, 0.12) 45%, transparent 55%),
        linear-gradient(133deg, transparent 36%, rgba(255, 150, 0, 0.14) 47%, transparent 58%),
        linear-gradient(137deg, transparent 37%, rgba(255, 20, 0, 0.18) 49%, transparent 62%);
}

/* Bloom glow — blurred soft luminosity layer.
   Breathes at a different rate than rays, creating
   subtle constructive/destructive interference depth. */
.v4-hero-bloom {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    filter: blur(40px);
    opacity: 0.6;
    animation: v4-bloomBreathe 55s ease-in-out infinite;
    background:
        linear-gradient(118deg, transparent 28%, rgba(138, 43, 226, 0.20) 37%, transparent 46%),
        linear-gradient(121deg, transparent 30%, rgba(30, 90, 255, 0.17) 39%, transparent 48%),
        linear-gradient(124deg, transparent 31%, rgba(0, 210, 255, 0.16) 41%, transparent 51%),
        linear-gradient(127deg, transparent 32%, rgba(0, 255, 65, 0.13) 43%, transparent 54%),
        linear-gradient(130deg, transparent 33%, rgba(255, 240, 0, 0.14) 45%, transparent 57%),
        linear-gradient(133deg, transparent 34%, rgba(255, 150, 0, 0.17) 47%, transparent 60%),
        linear-gradient(137deg, transparent 35%, rgba(255, 20, 0, 0.22) 49%, transparent 64%);
}

/* Rays: gentle intensity oscillation — barely perceptible */
@keyframes v4-rayBreathe {
    0%, 100% { opacity: 0.9; }
    50%      { opacity: 1; }
}

/* Bloom: slower, offset rhythm — creates depth through phase difference */
@keyframes v4-bloomBreathe {
    0%, 100% { opacity: 0.6; }
    50%      { opacity: 0.78; }
}

/* Ambient glow — warm burgundy-rose pulse */
.v4-hero-glow {
    position: absolute;
    bottom: -20%;
    right: -10%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    z-index: 1;
    background: radial-gradient(circle, rgba(134, 46, 66, 0.10) 0%, transparent 70%);
    pointer-events: none;
    opacity: 0.8;
}

/* --- Hero inner content --- */
.v4-hero-inner {
    position: relative;
    z-index: 2;
    max-width: 780px;
    margin: 0 auto;
    padding: 0 2rem;
}

/* Eyebrow — desaturated rose for contrast on dark */
.v4-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-family: var(--font-display);
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: #c4828f;
    margin-bottom: 1.5rem;
}

.v4-hero-eyebrow::before,
.v4-hero-eyebrow::after {
    content: '';
    display: inline-block;
    width: 28px;
    height: 2px;
    background: rgba(255, 255, 255, 0.15);
    flex-shrink: 0;
    border-radius: 1px;
}

/* Title — warm white */
.v4-hero h1 {
    font-family: var(--font-display);
    font-size: 3.6rem;
    font-weight: 800;
    color: #f5f4f0;
    letter-spacing: -0.04em;
    line-height: 1.05;
    margin: 0 0 1rem;
}

/* Tagline — readable on dark, weight 400 */
.v4-hero-tagline {
    font-family: var(--font-body);
    font-size: 1.08rem;
    font-weight: 400;
    color: rgba(255, 255, 255, 0.65);
    line-height: 1.65;
    max-width: 480px;
    margin: 0 auto 2.75rem;
}

/* Tagline link — editorial underline on dark glass */
.v4-hero-tagline-link {
    color: rgba(255, 255, 255, 0.72);
    text-decoration: underline;
    text-decoration-color: rgba(255, 255, 255, 0.18);
    text-underline-offset: 3px;
    text-decoration-thickness: 1px;
    transition: color 0.3s var(--ease),
                text-decoration-color 0.3s var(--ease);
}

.v4-hero-tagline-link:hover {
    color: rgba(255, 255, 255, 0.92);
    text-decoration-color: rgba(255, 255, 255, 0.45);
}

/* Spectrum bar — burgundy-anchored with slow shimmer */
.v4-hero-bar {
    display: block;
    height: 3px;
    max-width: 110px;
    margin: 0 auto;
    border: none;
    border-radius: 2px;
    background: linear-gradient(90deg,
        #862e42 0%, #7c3aed 15%, #2563eb 30%,
        #0891b2 45%, #059669 55%, #ca8a04 70%,
        #ea580c 85%, #862e42 100%
    );
    background-size: 100% 100%;
}

/* --- SEARCH CARD — floating white, sole bridge between dark hero and light content --- */
.v4-search-card {
    position: relative;
    z-index: 3;
    background: #ffffff;
    border-radius: 14px;
    border-top: 3px solid var(--accent);
    padding: 2rem 2rem 1.5rem;
    max-width: 600px;
    margin: 3.5rem auto -3.5rem;
    box-shadow:
        0 2px 8px rgba(0, 0, 0, 0.10),
        0 8px 24px rgba(0, 0, 0, 0.10),
        0 24px 56px rgba(0, 0, 0, 0.08),
        0 0 0 1px rgba(255, 255, 255, 0.06);
    text-align: center;
}

.v4-search-heading {
    font-family: var(--font-display);
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 1.15rem;
    letter-spacing: -0.01em;
}

.v4-search-form { position: relative; }

.v4-search-wrap {
    display: flex;
    align-items: center;
    background: var(--white);
    border: 2px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    transition: border-color 0.3s var(--ease), box-shadow 0.3s var(--ease);
}

.v4-search-wrap:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(134,46,66,0.06);
}

.v4-search-icon {
    position: absolute;
    left: 1.15rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
    font-size: 1.05rem;
    pointer-events: none;
    z-index: 2;
    transition: color 0.25s;
}

.v4-search-wrap:focus-within ~ .v4-search-icon,
.v4-search-wrap:focus-within .v4-search-icon {
    color: var(--accent);
}

.v4-search-input {
    flex: 1;
    border: none !important;
    background: transparent;
    padding: 1rem 1rem 1rem 3.1rem;
    font-family: var(--font-display);
    font-size: 0.95rem;
    font-weight: 400;
    color: var(--text);
    min-width: 0;
    outline: none;
    box-shadow: none !important;
}

.v4-search-input::placeholder { color: var(--text-light); font-weight: 400; }
.v4-search-input:focus { box-shadow: none !important; outline: none; }

.v4-search-btn {
    flex-shrink: 0;
    background: var(--accent);
    color: var(--white);
    border: none;
    padding: 1rem 1.6rem;
    font-family: var(--font-display);
    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}

.v4-search-btn:hover { background: var(--accent-light); }

.v4-search-meta {
    font-family: var(--font-body);
    font-size: 0.82rem;
    color: var(--text-muted);
    margin-top: 0.9rem;
    line-height: 1.55;
}

.v4-search-meta strong { color: var(--text-mid); font-weight: 600; }

/* --- STATS — with afterglow from hero --- */
.v4-stats {
    position: relative;
    z-index: 2;
    background-color: var(--white);
    background-image: radial-gradient(ellipse at center top, rgba(134, 46, 66, 0.03) 0%, transparent 50%);
    padding: 4.75rem 0 2.75rem;
    border-bottom: 1px solid var(--border-light);
}

.v4-stats-inner {
    max-width: 780px;
    margin: 0 auto;
    padding: 0 2rem;
    display: flex;
    justify-content: center;
    align-items: baseline;
    gap: 4rem;
}

.v4-stat { text-align: center; }

.v4-stat-bar {
    display: block;
    height: 3px;
    width: 36px;
    margin: 0 auto 0.9rem;
    border: none;
    border-radius: 2px;
    background: var(--accent);
}

.v4-stat-number {
    font-family: var(--font-display);
    font-size: 2.6rem;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
    letter-spacing: -0.03em;
    margin-bottom: 0.3rem;
}

.v4-stat-label {
    font-family: var(--font-display);
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.11em;
}

.v4-stat-divider {
    width: 1px;
    align-self: stretch;
    min-height: 36px;
    background: var(--border);
}

/* --- CONFERENCES --- */
.v4-conferences {
    position: relative;
    z-index: 2;
    background: var(--paper);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--border-light);
}

.v4-section-inner {
    max-width: 840px;
    margin: 0 auto;
    padding: 0 2rem;
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
}

.v4-conf-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.v4-conf-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.v4-conf-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--accent);
    z-index: 1;
}

.v4-conf-img-wrap {
    display: block;
    overflow: hidden;
    border-bottom: 1px solid var(--border-light);
    line-height: 0;
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
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--white);
    background: var(--accent);
    border: none;
    border-radius: 6px;
    padding: 0.5rem 1.1rem;
    text-decoration: none;
    transition: background 0.2s;
}

.v4-conf-btn:hover {
    background: var(--accent-light);
    color: var(--white);
    text-decoration: none;
}

.v4-conf-alert {
    font-family: var(--font-body);
    font-size: 0.88rem;
    color: var(--text);
    background: rgba(134, 46, 66, 0.05);
    border: none;
    border-left: 4px solid var(--accent);
    border-radius: 0 8px 8px 0;
    padding: 1.05rem 1.35rem;
    margin-top: 0.85rem;
    line-height: 1.65;
    position: relative;
    box-shadow:
        0 1px 4px rgba(134, 46, 66, 0.045),
        inset 0 1px 0 rgba(134, 46, 66, 0.06);
    transition: background 0.25s var(--ease),
                box-shadow 0.25s var(--ease);
}

.v4-conf-alert:hover {
    background: rgba(134, 46, 66, 0.075);
    box-shadow:
        0 2px 8px rgba(134, 46, 66, 0.06),
        inset 0 1px 0 rgba(134, 46, 66, 0.08);
}

.v4-conf-alert::before {
    content: '';
    position: absolute;
    top: 0;
    left: -4px;
    right: 0;
    height: 2.5px;
    background: linear-gradient(90deg, var(--accent) 0%, rgba(134, 46, 66, 0.12) 100%);
    border-radius: 0 8px 0 0;
}

.v4-conf-alert strong {
    color: var(--accent);
    font-weight: 700;
    font-size: 0.92rem;
    letter-spacing: -0.005em;
    background: rgba(134, 46, 66, 0.06);
    padding: 0.1rem 0.4rem;
    border-radius: 3px;
    margin-right: 0.15rem;
}

/* --- ARCHIVE (section wrapper — homepage only) --- */
.v4-archive {
    position: relative;
    z-index: 2;
    background: var(--white);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--border-light);
}
/* v4-archive-item styles are in custom.css */

/* --- QUICK LINKS + TRUST --- */
.v4-features {
    position: relative;
    z-index: 2;
    background: var(--paper);
    padding: 3.25rem 0 3.75rem;
}

.v4-trust {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    padding-top: 2.25rem;
    border-top: 1px solid var(--border);
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
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
    line-height: 1.3;
}

.v4-trust-desc {
    font-family: var(--font-body);
    font-size: 0.74rem;
    color: var(--text-muted);
    margin: 0.1rem 0 0;
    line-height: 1.45;
}

/* --- RESPONSIVE — TABLET --- */
@media (max-width: 767.98px) {
    .v4-hero { padding: 4.5rem 0 8rem; }
    .v4-hero h1 { font-size: 2.4rem; }
    .v4-hero-tagline { font-size: 0.98rem; }
    .v4-hero-bar { max-width: 90px; }

    .v4-search-card {
        padding: 1.5rem 1.5rem 1.25rem;
        margin: 2rem auto -2.5rem;
        max-width: 520px;
    }
    .v4-search-heading { font-size: 1rem; }
    .v4-search-wrap { flex-direction: column; border-radius: 10px; }
    .v4-search-input { width: 100%; padding: 0.9rem 1rem 0.9rem 3rem; }
    .v4-search-btn {
        width: 100%; padding: 0.85rem; text-align: center;
        display: flex; justify-content: center;
        border-top: 1px solid var(--border-light);
    }
    .v4-search-icon { top: 1.05rem; transform: none; }

    .v4-stats { padding-top: 3.75rem; }
    .v4-stats-inner { gap: 2.25rem; flex-wrap: wrap; }
    .v4-stat-number { font-size: 2rem; }
    .v4-stat-divider { display: none; }

    .v4-conf-grid { grid-template-columns: 1fr; gap: 1.25rem; }
    .v4-trust { grid-template-columns: 1fr; gap: 1.25rem; }
}

/* --- RESPONSIVE — MOBILE --- */
@media (max-width: 575.98px) {
    .v4-hero { padding: 3rem 0 7rem; }
    .v4-hero h1 { font-size: 1.95rem; margin-bottom: 0.75rem; }
    .v4-hero-eyebrow { font-size: 0.62rem; margin-bottom: 1.15rem; }
    .v4-hero-tagline { font-size: 0.96rem; margin-bottom: 2rem; }
    .v4-hero-bar { max-width: 80px; }

    .v4-search-card {
        padding: 1.25rem 1.15rem 1rem;
        max-width: calc(100% - 2.5rem);
        margin: 1.75rem auto -2.5rem;
        border-radius: 12px;
    }
    .v4-search-heading { font-size: 0.92rem; margin-bottom: 0.9rem; }

    .v4-hero-inner, .v4-section-inner { padding: 0 1.25rem; }
    .v4-stats { padding-top: 3.5rem; }
    .v4-stats-inner { padding: 0 1.25rem; gap: 1.75rem; }
    .v4-stat-number { font-size: 1.75rem; }
    .v4-stat-bar { width: 28px; margin-bottom: 0.6rem; }
    .v4-conf-body { padding: 1rem 1.15rem 1.25rem; }
}
</style>
STYLES;
?>

<!-- 1. HERO with integrated search -->
<section class="v4-hero">
    <div class="v4-hero-bg" aria-hidden="true"></div>
    <div class="v4-hero-bloom" aria-hidden="true"></div>
    <div class="v4-hero-glow" aria-hidden="true"></div>
    <div class="v4-hero-inner">
        <div class="v4-hero-eyebrow">ISSN <?= SITE_ISSN ?></div>
        <h1>DGaO-Proceedings</h1>
        <p class="v4-hero-tagline"><?= t('home.tagline') ?></p>

        <div class="v4-search-card">
            <div class="v4-search-heading"><?= t('home.landing.search_papers') ?></div>
            <form action="/suche" method="get" class="v4-search-form">
                <div class="v4-search-wrap">
                    <i class="bi bi-search v4-search-icon"></i>
                    <input type="search" name="q" class="v4-search-input form-control"
                           placeholder="<?= t('home.search_placeholder') ?>"
                           aria-label="<?= t('nav.suche') ?>"
                           autocomplete="off">
                    <button type="submit" class="v4-search-btn">
                        <?= t('home.search_btn') ?>
                    </button>
                </div>
            </form>
            <p class="v4-search-meta">
                <?= sprintf(
                    t('home.landing.search_across'),
                    '<strong>' . $nf($stats['papers']) . '</strong>',
                    '<strong>' . $nf($stats['autoren']) . '</strong>',
                    '<strong>' . $stats['tagungen'] . '</strong>'
                ) ?>
            </p>
        </div>
    </div>
</section>

<!-- 2. STATS -->
<section class="v4-stats">
    <div class="v4-stats-inner v4-reveal">
        <div class="v4-stat">
            <hr class="v4-stat-bar">
            <div class="v4-stat-number"><?= $nf($stats['papers']) ?></div>
            <div class="v4-stat-label"><?= t('home.stat.papers') ?></div>
        </div>
        <div class="v4-stat-divider"></div>
        <div class="v4-stat">
            <hr class="v4-stat-bar">
            <div class="v4-stat-number"><?= $stats['tagungen'] ?></div>
            <div class="v4-stat-label"><?= t('home.stat.conferences') ?></div>
        </div>
        <div class="v4-stat-divider"></div>
        <div class="v4-stat">
            <hr class="v4-stat-bar">
            <div class="v4-stat-number"><?= $nf($stats['autoren']) ?></div>
            <div class="v4-stat-label"><?= t('home.stat.authors') ?></div>
        </div>
    </div>
</section>

<!-- 3. CURRENT CONFERENCES -->
<section class="v4-conferences">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal"><?= t('home.section_current') ?></h2>
        <hr class="v4-section-bar v4-reveal">

        <div class="v4-conf-grid">
            <div class="v4-reveal v4-rd1">
                <div class="v4-conf-card">
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
                </div>
            </div>

            <div class="v4-reveal v4-rd2">
                <div class="v4-conf-card">
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
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 4. ARCHIVE -->
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

<!-- 5. TRUST -->
<section class="v4-features">
    <div class="v4-section-inner">
        <div class="v4-trust" style="border-top: none; padding-top: 0;">
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

