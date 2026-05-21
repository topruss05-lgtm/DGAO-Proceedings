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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* =============================================
   DGaO-Proceedings v4 — "Corporate Spectral"
   White + DGaO Burgundy-Red corporate palette
   One prismatic hero moment, restrained everywhere else
   Font: Outfit (display) + Source Sans 3 (body)
   All classes v4- prefixed
   ============================================= */

/* --- Design Tokens --- */
:root {
    --v4-white:        #ffffff;
    --v4-paper:        #fafaf8;
    --v4-warm:         #f5f5f2;
    --v4-border:       #e8e7e3;
    --v4-border-light: #f0efeb;

    --v4-text:         #1a1d2e;
    --v4-text-mid:     #3d4155;
    --v4-text-muted:   #6b7280;
    --v4-text-light:   #9ca3af;

    --v4-accent:       #DB092C;
    --v4-accent-light: #F02D4B;
    --v4-accent-pale:  #FCE9EC;
    --v4-accent-dark:  #A30620;
    --v4-accent-glow:  rgba(219, 9, 44, 0.12);
    --v4-accent-muted: #E89AA5;

    --v4-font:  'Outfit', 'Source Sans 3', system-ui, sans-serif;
    --v4-body:  'Source Sans 3', 'Segoe UI', system-ui, sans-serif;
    --v4-serif: 'Libre Baskerville', Georgia, serif;

    --v4-ease:    cubic-bezier(0.22, 1, 0.36, 1);
    --v4-spring:  cubic-bezier(0.34, 1.56, 0.64, 1);
    --v4-smooth:  cubic-bezier(0.4, 0, 0.2, 1);
}

/* ============================
   LAYOUT OVERRIDES — minimal
   ============================ */

.site-header {
    background: var(--v4-white) !important;
    border-bottom: none !important;
    box-shadow: none !important;
}

.site-header .header-logo-img {
    height: 64px !important;
}

/* Nav — white with solid DGaO-red top border */
.site-nav {
    background: var(--v4-white) !important;
    border-top: 3px solid var(--v4-accent) !important;
    border-bottom: 1px solid var(--v4-border) !important;
    border-image: none !important;
}

.site-nav .nav-link {
    font-family: var(--v4-font) !important;
    font-weight: 500 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.06em !important;
    text-transform: uppercase !important;
    color: var(--v4-text-muted) !important;
    transition: color 0.2s var(--v4-ease), border-color 0.2s var(--v4-ease) !important;
    padding: 0.75rem 1rem !important;
    border-bottom: 2px solid transparent !important;
}

.site-nav .nav-link:hover {
    color: var(--v4-accent) !important;
    border-bottom-color: var(--v4-accent-muted) !important;
}

.site-nav .nav-link.active {
    color: var(--v4-accent) !important;
    font-weight: 600 !important;
    border-bottom-color: var(--v4-accent) !important;
}

.lang-toggle {
    font-family: var(--v4-font) !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    letter-spacing: 0.06em !important;
    color: var(--v4-text-muted) !important;
    border-color: var(--v4-border) !important;
    transition: all 0.2s var(--v4-ease) !important;
}

.lang-toggle:hover {
    color: var(--v4-accent) !important;
    border-color: var(--v4-accent) !important;
    background: var(--v4-accent-pale) !important;
}

/* Footer — dark DGaO red with converging white stripes */
.site-footer {
    background: var(--v4-accent-dark) !important;
    border-top: 3px solid var(--v4-accent) !important;
    border-image: none !important;
    color: rgba(255,255,255,0.5) !important;
    font-family: var(--v4-font) !important;
    position: relative !important;
    overflow: hidden !important;
}

/* Converging white stripes — DGaO corporate slide pattern */
.site-footer::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        /* Fan of thin white lines converging from bottom-left */
        linear-gradient(52deg, transparent 0%, transparent 49.6%, rgba(255,255,255,0.07) 49.6%, rgba(255,255,255,0.07) 50%, transparent 50%, transparent 100%),
        linear-gradient(58deg, transparent 0%, transparent 49.6%, rgba(255,255,255,0.06) 49.6%, rgba(255,255,255,0.06) 50.1%, transparent 50.1%, transparent 100%),
        linear-gradient(45deg, transparent 0%, transparent 49.7%, rgba(255,255,255,0.08) 49.7%, rgba(255,255,255,0.08) 50%, transparent 50%, transparent 100%),
        linear-gradient(64deg, transparent 0%, transparent 49.5%, rgba(255,255,255,0.055) 49.5%, rgba(255,255,255,0.055) 50.1%, transparent 50.1%, transparent 100%),
        linear-gradient(38deg, transparent 0%, transparent 49.6%, rgba(255,255,255,0.09) 49.6%, rgba(255,255,255,0.09) 49.9%, transparent 49.9%, transparent 100%),
        linear-gradient(70deg, transparent 0%, transparent 49.6%, rgba(255,255,255,0.045) 49.6%, rgba(255,255,255,0.045) 50.2%, transparent 50.2%, transparent 100%),
        linear-gradient(33deg, transparent 0%, transparent 49.7%, rgba(255,255,255,0.07) 49.7%, rgba(255,255,255,0.07) 50%, transparent 50%, transparent 100%);
    transform-origin: bottom left;
}

.site-footer a {
    color: rgba(255,255,255,0.6) !important;
    transition: color 0.2s !important;
    position: relative;
    z-index: 1;
}

.site-footer a:hover {
    color: #ffffff !important;
}

.site-footer .container {
    position: relative;
    z-index: 1;
}

/* ============================
   KEYFRAMES
   ============================ */

@keyframes v4-fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes v4-fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

@keyframes v4-rayDrift {
    0%   { transform: translateX(-3%) translateY(-1%) rotate(0deg); }
    33%  { transform: translateX(2%) translateY(1%) rotate(0.3deg); }
    66%  { transform: translateX(-1%) translateY(-0.5%) rotate(-0.2deg); }
    100% { transform: translateX(-3%) translateY(-1%) rotate(0deg); }
}

/* Entry animations */
.v4-anim {
    opacity: 0;
    animation: v4-fadeUp 0.6s var(--v4-ease) forwards;
}
.v4-d1 { animation-delay: 0.05s; }
.v4-d2 { animation-delay: 0.12s; }
.v4-d3 { animation-delay: 0.19s; }
.v4-d4 { animation-delay: 0.26s; }
.v4-d5 { animation-delay: 0.33s; }
.v4-d6 { animation-delay: 0.40s; }

/* Scroll reveal */
.v4-reveal {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.55s var(--v4-ease),
                transform 0.55s var(--v4-ease);
}
.v4-reveal.v4-visible {
    opacity: 1;
    transform: translateY(0);
}
.v4-rd1 { transition-delay: 0.04s; }
.v4-rd2 { transition-delay: 0.08s; }
.v4-rd3 { transition-delay: 0.12s; }
.v4-rd4 { transition-delay: 0.16s; }
.v4-rd5 { transition-delay: 0.20s; }

/* ============================
   1. HERO — White with prismatic light
   ============================ */

.v4-hero {
    position: relative;
    background: var(--v4-white);
    padding: 5rem 0 3.5rem;
    overflow: hidden;
    text-align: center;
}

/* Fine paper texture — subtle crosshatch watermark feel */
.v4-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0.022;
    background-image:
        radial-gradient(circle, var(--v4-text) 0.7px, transparent 0.7px);
    background-size: 22px 22px;
    pointer-events: none;
}

/*
 * THE PRISMATIC LIGHT EFFECT — refracted light on white paper.
 * Ghost-like rainbow traces at extremely low opacity.
 * This is the single animated spectacle on the entire page.
 */
.v4-hero::after {
    content: '';
    position: absolute;
    inset: -20% -10% -20% -10%;
    pointer-events: none;
    animation: v4-rayDrift 25s ease-in-out infinite;
    background:
        /* Violet ray — outermost */
        linear-gradient(116deg, transparent 28%, rgba(124, 58, 237, 0.045) 31%, transparent 33.5%),
        /* Blue ray */
        linear-gradient(119deg, transparent 29%, rgba(37, 99, 235, 0.04) 32.5%, transparent 35%),
        /* Cyan ray */
        linear-gradient(122deg, transparent 30%, rgba(8, 145, 178, 0.035) 34%, transparent 36.5%),
        /* Green ray */
        linear-gradient(125deg, transparent 31%, rgba(5, 150, 105, 0.03) 35.5%, transparent 38%),
        /* Yellow ray */
        linear-gradient(128deg, transparent 32%, rgba(202, 138, 4, 0.03) 37%, transparent 39.5%),
        /* Orange ray */
        linear-gradient(131deg, transparent 33%, rgba(234, 88, 12, 0.035) 38.5%, transparent 41%),
        /* Red ray — innermost */
        linear-gradient(134deg, transparent 34%, rgba(220, 38, 38, 0.04) 40%, transparent 42.5%);
}

/* Warm ambient glow — barely perceptible burgundy blush */
.v4-hero-glow {
    position: absolute;
    bottom: -30%;
    right: -10%;
    width: 550px;
    height: 550px;
    border-radius: 50%;
    background: radial-gradient(circle,
        rgba(219, 9, 44, 0.025) 0%,
        rgba(219, 9, 44, 0.012) 35%,
        transparent 65%);
    pointer-events: none;
}

.v4-hero-inner {
    position: relative;
    z-index: 1;
    max-width: 780px;
    margin: 0 auto;
    padding: 0 2rem;
}

.v4-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-family: var(--v4-font);
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--v4-accent);
    margin-bottom: 1.25rem;
}

/* Simple red decorative lines flanking the eyebrow — no shimmer */
.v4-hero-eyebrow::before,
.v4-hero-eyebrow::after {
    content: '';
    display: inline-block;
    width: 28px;
    height: 2px;
    background: var(--v4-accent);
    flex-shrink: 0;
    border-radius: 1px;
}

.v4-hero h1 {
    font-family: var(--v4-font);
    font-size: 3.6rem;
    font-weight: 800;
    color: var(--v4-text);
    letter-spacing: -0.04em;
    line-height: 1.05;
    margin: 0 0 0.5rem;
}

.v4-hero-issn {
    font-family: var(--v4-font);
    font-size: 0.74rem;
    font-weight: 500;
    color: var(--v4-text-light);
    letter-spacing: 0.08em;
    margin-bottom: 0.9rem;
}

.v4-hero-tagline {
    font-family: var(--v4-body);
    font-size: 1.08rem;
    font-weight: 400;
    color: var(--v4-text-muted);
    line-height: 1.65;
    max-width: 480px;
    margin: 0 auto 2.25rem;
}

/* Static red divider — simple, elegant, no animation */
.v4-hero-bar {
    display: block;
    height: 3px;
    max-width: 80px;
    margin: 0 auto;
    border: none;
    border-radius: 2px;
    background: var(--v4-accent);
}

/* ============================
   2. SEARCH — Focal point
   ============================ */

.v4-search {
    background: var(--v4-paper);
    padding: 3rem 0 2.5rem;
    border-top: 1px solid var(--v4-border-light);
    border-bottom: 1px solid var(--v4-border-light);
}

.v4-search-inner {
    max-width: 640px;
    margin: 0 auto;
    padding: 0 2rem;
    text-align: center;
}

.v4-search-heading {
    font-family: var(--v4-font);
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--v4-text);
    margin-bottom: 1.35rem;
    letter-spacing: -0.01em;
}

.v4-search-form {
    position: relative;
}

.v4-search-wrap {
    display: flex;
    align-items: center;
    background: var(--v4-white);
    border: 2px solid var(--v4-border);
    border-radius: 10px;
    overflow: hidden;
    transition: border-color 0.3s var(--v4-ease),
                box-shadow 0.3s var(--v4-ease),
                transform 0.25s var(--v4-ease);
}

/* Clean red glow on focus — no animation cycling */
.v4-search-wrap:focus-within {
    border-color: var(--v4-accent);
    transform: scale(1.005);
    box-shadow: 0 2px 16px rgba(219, 9, 44, 0.08),
                0 0 0 3px rgba(219, 9, 44, 0.06);
}

.v4-search-icon {
    position: absolute;
    left: 1.15rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--v4-text-light);
    font-size: 1.05rem;
    pointer-events: none;
    z-index: 2;
    transition: color 0.25s;
}

.v4-search-wrap:focus-within ~ .v4-search-icon,
.v4-search-wrap:focus-within .v4-search-icon {
    color: var(--v4-accent);
}

.v4-search-input {
    flex: 1;
    border: none !important;
    background: transparent;
    padding: 1rem 1rem 1rem 3.1rem;
    font-family: var(--v4-font);
    font-size: 0.95rem;
    font-weight: 400;
    color: var(--v4-text);
    min-width: 0;
    outline: none;
    box-shadow: none !important;
}

.v4-search-input::placeholder {
    color: var(--v4-text-light);
    font-weight: 400;
}

.v4-search-input:focus {
    box-shadow: none !important;
    outline: none;
}

.v4-search-btn {
    flex-shrink: 0;
    background: var(--v4-accent);
    color: var(--v4-white);
    border: none;
    padding: 1rem 1.6rem;
    font-family: var(--v4-font);
    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}

.v4-search-btn:hover {
    background: var(--v4-accent-light);
}

.v4-search-meta {
    font-family: var(--v4-body);
    font-size: 0.84rem;
    color: var(--v4-text-muted);
    margin-top: 1rem;
    line-height: 1.55;
}

.v4-search-meta strong {
    color: var(--v4-text-mid);
    font-weight: 600;
}

/* ============================
   3. STATS — Typographic with red accent bars
   ============================ */

.v4-stats {
    background: var(--v4-white);
    padding: 2.75rem 0;
    border-bottom: 1px solid var(--v4-border-light);
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

.v4-stat {
    text-align: center;
}

/* Simple red accent bar — same color for all three */
.v4-stat-bar {
    display: block;
    height: 3px;
    width: 36px;
    margin: 0 auto 0.9rem;
    border: none;
    border-radius: 2px;
    background: var(--v4-accent);
}

.v4-stat-number {
    font-family: var(--v4-font);
    font-size: 2.6rem;
    font-weight: 800;
    color: var(--v4-text);
    line-height: 1;
    letter-spacing: -0.03em;
    margin-bottom: 0.3rem;
}

.v4-stat-label {
    font-family: var(--v4-font);
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--v4-text-light);
    text-transform: uppercase;
    letter-spacing: 0.11em;
}

.v4-stat-divider {
    width: 1px;
    align-self: stretch;
    min-height: 36px;
    background: var(--v4-border);
}

/* ============================
   4. CONFERENCES — Cards with solid red top border
   ============================ */

.v4-conferences {
    background: var(--v4-paper);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--v4-border-light);
}

.v4-section-inner {
    max-width: 840px;
    margin: 0 auto;
    padding: 0 2rem;
}

.v4-section-title {
    font-family: var(--v4-font);
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--v4-text);
    letter-spacing: -0.015em;
    margin: 0 0 0.3rem;
}

/* Static red section bar — no shimmer animation */
.v4-section-bar {
    display: block;
    height: 3px;
    width: 56px;
    border: none;
    border-radius: 2px;
    background: var(--v4-accent);
    margin: 0 0 1.75rem;
}

.v4-conf-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.v4-conf-card {
    background: var(--v4-white);
    border: 1px solid var(--v4-border);
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    transition: transform 0.3s var(--v4-ease),
                box-shadow 0.3s var(--v4-ease);
}

/* Solid red top border */
.v4-conf-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--v4-accent);
    z-index: 1;
}

.v4-conf-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.06);
}

.v4-conf-img-link {
    display: block;
    overflow: hidden;
    border-bottom: 1px solid var(--v4-border-light);
    line-height: 0;
}

.v4-conf-img {
    width: 100%;
    height: auto;
    display: block;
    transition: transform 0.45s var(--v4-ease), opacity 0.25s;
}

.v4-conf-card:hover .v4-conf-img {
    transform: scale(1.03);
    opacity: 0.92;
}

.v4-conf-body {
    padding: 1.25rem 1.5rem 1.5rem;
}

.v4-conf-title {
    font-family: var(--v4-font);
    font-size: 1rem;
    font-weight: 600;
    color: var(--v4-text);
    margin: 0 0 0.4rem;
    letter-spacing: -0.005em;
}

.v4-conf-text {
    font-family: var(--v4-body);
    font-size: 0.86rem;
    color: var(--v4-text-muted);
    line-height: 1.55;
    margin: 0 0 0.9rem;
}

.v4-conf-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-family: var(--v4-font);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--v4-white);
    background: var(--v4-accent);
    border: none;
    border-radius: 6px;
    padding: 0.5rem 1.1rem;
    text-decoration: none;
    transition: background 0.2s, transform 0.15s;
}

.v4-conf-btn:hover {
    background: var(--v4-accent-light);
    color: var(--v4-white);
    transform: translateX(2px);
    text-decoration: none;
}

.v4-conf-alert {
    font-family: var(--v4-body);
    font-size: 0.78rem;
    color: var(--v4-text-mid);
    background: var(--v4-accent-pale);
    border: 1px solid rgba(219, 9, 44, 0.1);
    border-radius: 6px;
    padding: 0.6rem 1rem;
    margin-top: 0.75rem;
    line-height: 1.5;
}

.v4-conf-alert strong {
    color: var(--v4-accent);
    font-weight: 600;
}

/* ============================
   5. ARCHIVE — Clean list with red accent hover
   ============================ */

.v4-archive {
    background: var(--v4-white);
    padding: 3.25rem 0 3.5rem;
    border-bottom: 1px solid var(--v4-border-light);
}

.v4-archive-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.v4-archive-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.8rem 1rem;
    border-left: 3px solid transparent;
    border-bottom: 1px solid var(--v4-border-light);
    text-decoration: none;
    color: var(--v4-text);
    transition: all 0.22s var(--v4-ease);
}

.v4-archive-item:first-child {
    border-top: 1px solid var(--v4-border-light);
}

.v4-archive-item:hover {
    background: var(--v4-accent-pale);
    border-left-color: var(--v4-accent);
    padding-left: 1.35rem;
    text-decoration: none;
    color: var(--v4-text);
}

.v4-archive-year {
    font-family: var(--v4-font);
    font-size: 1.02rem;
    font-weight: 700;
    color: var(--v4-text);
    min-width: 48px;
}

.v4-archive-loc {
    font-family: var(--v4-body);
    font-size: 0.86rem;
    color: var(--v4-text-muted);
    flex: 1;
    margin-left: 0.85rem;
}

.v4-archive-nr {
    font-family: var(--v4-font);
    font-size: 0.72rem;
    color: var(--v4-text-light);
    margin-left: 0.5rem;
}

.v4-archive-badge {
    font-family: var(--v4-font);
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--v4-accent);
    background: var(--v4-accent-pale);
    border-radius: 20px;
    padding: 0.15rem 0.6rem;
    flex-shrink: 0;
    margin-left: 1rem;
    transition: background 0.2s, color 0.2s;
}

.v4-archive-item:hover .v4-archive-badge {
    background: var(--v4-accent);
    color: var(--v4-white);
}

.v4-archive-more {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-family: var(--v4-font);
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--v4-accent);
    text-decoration: none;
    margin-top: 1.15rem;
    transition: color 0.2s, gap 0.25s;
}

.v4-archive-more:hover {
    color: var(--v4-accent-light);
    gap: 0.55rem;
    text-decoration: none;
}

/* ============================
   6. QUICK LINKS + TRUST
   ============================ */

.v4-features {
    background: var(--v4-paper);
    padding: 3.25rem 0 3.75rem;
}

.v4-ql-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 2.75rem;
}

.v4-ql-card {
    display: block;
    background: var(--v4-white);
    border: 1px solid var(--v4-border);
    border-radius: 10px;
    padding: 1.75rem 1.5rem;
    text-decoration: none;
    position: relative;
    overflow: hidden;
    transition: all 0.3s var(--v4-ease);
}

/* Solid red top border — reveals on hover via scaleX */
.v4-ql-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--v4-accent);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.35s var(--v4-ease);
}

.v4-ql-card:hover {
    border-color: var(--v4-border);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
    transform: translateY(-3px);
    text-decoration: none;
}

.v4-ql-card:hover::before {
    transform: scaleX(1);
}

/* Icons in DGaO red — simple solid color, no gradient */
.v4-ql-icon {
    font-size: 1.4rem;
    margin-bottom: 0.9rem;
    display: block;
    color: var(--v4-accent);
    transition: transform 0.3s var(--v4-spring);
}

.v4-ql-card:hover .v4-ql-icon {
    transform: scale(1.12);
}

.v4-ql-title {
    font-family: var(--v4-font);
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--v4-text);
    margin: 0 0 0.35rem;
}

.v4-ql-desc {
    font-family: var(--v4-body);
    font-size: 0.82rem;
    color: var(--v4-text-muted);
    line-height: 1.55;
    margin: 0;
}

/* --- Trust indicators --- */
.v4-trust {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    padding-top: 2.25rem;
    border-top: 1px solid var(--v4-border);
}

.v4-trust-item {
    display: flex;
    align-items: flex-start;
    gap: 0.7rem;
}

.v4-trust-icon {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--v4-accent-pale);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--v4-accent);
    font-size: 0.95rem;
}

.v4-trust-label {
    font-family: var(--v4-font);
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--v4-text);
    margin: 0;
    line-height: 1.3;
}

.v4-trust-desc {
    font-family: var(--v4-body);
    font-size: 0.74rem;
    color: var(--v4-text-muted);
    margin: 0.1rem 0 0;
    line-height: 1.45;
}

/* ============================
   RESPONSIVE — TABLET
   ============================ */

@media (max-width: 767.98px) {
    .v4-hero {
        padding: 3.5rem 0 2.5rem;
    }

    .v4-hero h1 {
        font-size: 2.4rem;
    }

    .v4-hero-tagline {
        font-size: 0.98rem;
    }

    .v4-search-wrap {
        flex-direction: column;
        border-radius: 10px;
    }

    .v4-search-input {
        width: 100%;
        padding: 0.9rem 1rem 0.9rem 3rem;
    }

    .v4-search-btn {
        width: 100%;
        padding: 0.85rem;
        text-align: center;
        display: flex;
        justify-content: center;
        border-top: 1px solid var(--v4-border-light);
    }

    .v4-search-icon {
        top: 1.05rem;
        transform: none;
    }

    .v4-stats-inner {
        gap: 2.25rem;
        flex-wrap: wrap;
    }

    .v4-stat-number {
        font-size: 2rem;
    }

    .v4-stat-divider {
        display: none;
    }

    .v4-conf-grid {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }

    .v4-ql-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .v4-trust {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }
}

/* ============================
   RESPONSIVE — MOBILE
   ============================ */

@media (max-width: 575.98px) {
    .v4-hero {
        padding: 2.5rem 0 2rem;
    }

    .v4-hero h1 {
        font-size: 1.95rem;
    }

    .v4-hero-eyebrow {
        font-size: 0.62rem;
    }

    .v4-hero-inner,
    .v4-search-inner,
    .v4-section-inner {
        padding: 0 1.25rem;
    }

    .v4-stats-inner {
        padding: 0 1.25rem;
        gap: 1.75rem;
    }

    .v4-stat-number {
        font-size: 1.75rem;
    }

    .v4-stat-bar {
        width: 28px;
        margin-bottom: 0.6rem;
    }

    .v4-archive-item {
        padding: 0.7rem 0.75rem;
    }

    .v4-archive-loc {
        font-size: 0.8rem;
    }

    .v4-conf-body {
        padding: 1rem 1.15rem 1.25rem;
    }

    .v4-ql-card {
        padding: 1.5rem 1.25rem;
    }

    .site-header .header-logo-img {
        height: 56px !important;
    }
}
</style>
STYLES;
?>

<!-- =============================================
     1. HERO — White with prismatic light traces
     ============================================= -->
<section class="v4-hero">
    <div class="v4-hero-glow" aria-hidden="true"></div>
    <div class="v4-hero-inner">
        <div class="v4-hero-eyebrow v4-anim v4-d1">ISSN <?= SITE_ISSN ?></div>
        <h1 class="v4-anim v4-d2">DGaO-Proceedings</h1>
        <p class="v4-hero-tagline v4-anim v4-d3"><?= t('home.tagline') ?></p>
        <hr class="v4-hero-bar v4-anim v4-d4">
    </div>
</section>

<!-- =============================================
     2. SEARCH — Focal point with red accent
     ============================================= -->
<section class="v4-search">
    <div class="v4-search-inner">
        <div class="v4-search-heading v4-anim v4-d3"><?= t('home.landing.search_papers') ?></div>
        <form action="/suche" method="get" class="v4-search-form v4-anim v4-d4">
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
        <p class="v4-search-meta v4-anim v4-d5">
            <?= sprintf(
                t('home.landing.search_across'),
                '<strong>' . $nf($stats['papers']) . '</strong>',
                '<strong>' . $nf($stats['autoren']) . '</strong>',
                '<strong>' . $stats['tagungen'] . '</strong>'
            ) ?>
        </p>
    </div>
</section>

<!-- =============================================
     3. STATS — Typographic with red bars
     ============================================= -->
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

<!-- =============================================
     4. CURRENT CONFERENCES
     ============================================= -->
<section class="v4-conferences">
    <div class="v4-section-inner">
        <h2 class="v4-section-title v4-reveal"><?= t('home.section_current') ?></h2>
        <hr class="v4-section-bar v4-reveal">

        <div class="v4-conf-grid">
            <!-- 127th Conference -->
            <div class="v4-reveal v4-rd1">
                <div class="v4-conf-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v4-conf-img-link">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="v4-conf-img">
                    </a>
                    <div class="v4-conf-body">
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v4-conf-btn">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v4-conf-alert"><?= t('home.conf_127_alert') ?></div>
            </div>

            <!-- 126th Conference -->
            <div class="v4-reveal v4-rd2">
                <div class="v4-conf-card">
                    <a href="/archiv/126" class="v4-conf-img-link">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="v4-conf-img">
                    </a>
                    <div class="v4-conf-body">
                        <a href="/archiv/126" class="v4-conf-btn">
                            <?= t('home.conf_126_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v4-conf-alert"><?= t('home.conf_126_alert') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- =============================================
     5. ARCHIVE
     ============================================= -->
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

<!-- =============================================
     6. QUICK LINKS + TRUST
     ============================================= -->
<section class="v4-features">
    <div class="v4-section-inner">
        <div class="v4-ql-grid">
            <a href="/archiv" class="v4-ql-card v4-reveal v4-rd1">
                <span class="v4-ql-icon"><i class="bi bi-collection"></i></span>
                <h3 class="v4-ql-title"><?= t('home.landing.browse_archive') ?></h3>
                <p class="v4-ql-desc"><?= t('home.landing.browse_archive_desc') ?></p>
            </a>
            <a href="/suche" class="v4-ql-card v4-reveal v4-rd2">
                <span class="v4-ql-icon"><i class="bi bi-search"></i></span>
                <h3 class="v4-ql-title"><?= t('home.landing.search_papers') ?></h3>
                <p class="v4-ql-desc"><?= t('home.landing.search_papers_desc') ?></p>
            </a>
            <a href="/autoren" class="v4-ql-card v4-reveal v4-rd3">
                <span class="v4-ql-icon"><i class="bi bi-people"></i></span>
                <h3 class="v4-ql-title"><?= t('home.landing.explore_authors') ?></h3>
                <p class="v4-ql-desc"><?= t('home.landing.explore_authors_desc') ?></p>
            </a>
        </div>

        <!-- Trust indicators -->
        <div class="v4-trust">
            <div class="v4-trust-item v4-reveal v4-rd1">
                <div class="v4-trust-icon">
                    <i class="bi bi-unlock"></i>
                </div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.open_access') ?></div>
                    <div class="v4-trust-desc"><?= t('home.landing.open_access_desc') ?></div>
                </div>
            </div>
            <div class="v4-trust-item v4-reveal v4-rd2">
                <div class="v4-trust-icon">
                    <i class="bi bi-mortarboard"></i>
                </div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.peer_community') ?></div>
                    <div class="v4-trust-desc"><?= t('home.landing.peer_community_desc') ?></div>
                </div>
            </div>
            <div class="v4-trust-item v4-reveal v4-rd3">
                <div class="v4-trust-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <div class="v4-trust-label"><?= t('home.landing.since_year') ?> <?= $oldestYear ?></div>
                    <div class="v4-trust-desc"><?= t('home.participation_note') ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =============================================
     SCROLL-TRIGGERED REVEAL
     ============================================= -->
<script>
(function() {
    'use strict';
    var els = document.querySelectorAll('.v4-reveal');
    if (!els.length) return;

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                if (e.isIntersecting) {
                    e.target.classList.add('v4-visible');
                    io.unobserve(e.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

        els.forEach(function(el) { io.observe(el); });
    } else {
        els.forEach(function(el) { el.classList.add('v4-visible'); });
    }
})();
</script>
