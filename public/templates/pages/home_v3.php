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

$extraHead = <<<'CSS'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300;12..96,400;12..96,500;12..96,600;12..96,700;12..96,800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
<style>
/* ==============================================
   DGaO-Proceedings – home_v3 "Optics Spectral"
   Bold scientific aesthetic — prism / light / spectrum
   All classes v3- prefixed, fully self-contained
   ============================================== */

/* --- Design Tokens --- */
:root {
    --v3-dark-deep:    #080b16;
    --v3-dark:         #0d1025;
    --v3-dark-mid:     #111433;
    --v3-dark-surface: #181c3a;
    --v3-light:        #f5f4f0;
    --v3-light-cool:   #eef0f4;
    --v3-white:        #ffffff;

    --v3-text-on-dark:      #e2e0db;
    --v3-text-muted-dark:   #7c7f92;
    --v3-text-on-light:     #1a1d2e;
    --v3-text-muted-light:  #64687d;

    --v3-accent:       #4338ca;
    --v3-accent-light: #5b50e6;
    --v3-accent-glow:  rgba(67, 56, 202, 0.18);
    --v3-accent-subtle:rgba(67, 56, 202, 0.06);

    /* Spectrum (ROYGBIV reversed — violet to red) */
    --v3-violet:  #7c3aed;
    --v3-blue:    #2563eb;
    --v3-cyan:    #0891b2;
    --v3-green:   #059669;
    --v3-yellow:  #ca8a04;
    --v3-orange:  #ea580c;
    --v3-red:     #dc2626;

    --v3-spectrum: linear-gradient(90deg,
        var(--v3-violet), var(--v3-blue), var(--v3-cyan),
        var(--v3-green), var(--v3-yellow), var(--v3-orange), var(--v3-red));

    --v3-font-display: 'Bricolage Grotesque', 'Source Sans 3', system-ui, sans-serif;
    --v3-font-body:    'DM Sans', 'Source Sans 3', system-ui, sans-serif;

    --v3-ease-out: cubic-bezier(0.22, 1, 0.36, 1);
    --v3-ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* ============================
   LAYOUT OVERRIDES
   ============================ */

/*
 * CRITICAL: Site header stays WHITE so the logo
 * (which has an opaque white background) blends naturally.
 * The dark spectral theme starts from the NAV downward.
 */
.site-header {
    background-color: var(--v3-white) !important;
    border-bottom: none !important;
}

.site-header .header-logo-img {
    height: 72px;
    width: auto;
}

/* Navigation — dark with spectral top border (the "prism edge") */
.site-nav {
    background-color: var(--v3-dark) !important;
    border-top: 3px solid transparent !important;
    border-image: var(--v3-spectrum) 1 !important;
    border-bottom: 1px solid rgba(67, 56, 202, 0.1) !important;
}

.site-nav .nav-link {
    color: var(--v3-text-muted-dark) !important;
    font-family: var(--v3-font-display) !important;
    font-weight: 500 !important;
    font-size: 0.82rem !important;
    letter-spacing: 0.06em !important;
    transition: color 0.2s var(--v3-ease-out), border-color 0.2s var(--v3-ease-out) !important;
}

.site-nav .nav-link:hover {
    color: var(--v3-text-on-dark) !important;
    border-bottom-color: var(--v3-accent-light) !important;
}

.site-nav .nav-link.active {
    color: var(--v3-white) !important;
    font-weight: 600 !important;
    border-bottom-color: var(--v3-accent) !important;
}

/* Language toggle */
.lang-toggle {
    border-color: rgba(67, 56, 202, 0.2) !important;
    color: var(--v3-text-muted-dark) !important;
    font-family: var(--v3-font-display) !important;
    transition: all 0.2s var(--v3-ease-out) !important;
}

.lang-toggle:hover {
    color: var(--v3-text-on-dark) !important;
    border-color: var(--v3-accent) !important;
    background-color: rgba(67, 56, 202, 0.08) !important;
}

/* Navbar toggler (mobile hamburger) on dark bg */
.site-nav .navbar-toggler {
    border-color: rgba(67, 56, 202, 0.2) !important;
}

.site-nav .navbar-toggler-icon {
    filter: invert(1) !important;
}

/* Footer — very dark, spectral top border */
.site-footer {
    background-color: var(--v3-dark-deep) !important;
    border-top: 3px solid transparent !important;
    border-image: var(--v3-spectrum) 1 !important;
    color: var(--v3-text-muted-dark) !important;
}

.site-footer a {
    color: rgba(226, 224, 219, 0.6) !important;
    transition: color 0.2s !important;
}

.site-footer a:hover {
    color: var(--v3-white) !important;
}

/* ============================
   KEYFRAMES
   ============================ */
@keyframes v3-shimmer {
    0%   { background-position: -200% center; }
    100% { background-position: 200% center; }
}

@keyframes v3-fadeInUp {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes v3-fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

@keyframes v3-scaleIn {
    from { opacity: 0; transform: scale(0.92); }
    to   { opacity: 1; transform: scale(1); }
}

@keyframes v3-pulseGlow {
    0%, 100% { opacity: 0.4; }
    50%      { opacity: 0.8; }
}

@keyframes v3-rayDrift {
    0%   { transform: translateX(-5%) rotate(0deg); }
    50%  { transform: translateX(5%) rotate(0.5deg); }
    100% { transform: translateX(-5%) rotate(0deg); }
}

/* ============================
   1. HERO — DARK
   Deep blue-black with CSS light rays
   ============================ */
.v3-hero {
    position: relative;
    background: linear-gradient(170deg, #080b16 0%, #0d1025 40%, #111433 100%);
    padding: 5.5rem 0 4.5rem;
    overflow: hidden;
    text-align: center;
}

/* Dot grid texture overlay */
.v3-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0.035;
    background-image:
        radial-gradient(circle, var(--v3-text-on-dark) 1px, transparent 1px);
    background-size: 24px 24px;
    pointer-events: none;
}

/* Light ray effect — multiple refracted beams */
.v3-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    animation: v3-rayDrift 20s ease-in-out infinite;
    background:
        /* Violet ray */
        linear-gradient(118deg, transparent 35%, rgba(124, 58, 237, 0.07) 38%, transparent 40%),
        /* Blue ray */
        linear-gradient(121deg, transparent 36%, rgba(37, 99, 235, 0.06) 39.5%, transparent 41.5%),
        /* Cyan ray */
        linear-gradient(124deg, transparent 37%, rgba(8, 145, 178, 0.055) 41%, transparent 43%),
        /* Green ray */
        linear-gradient(127deg, transparent 38%, rgba(5, 150, 105, 0.04) 42.5%, transparent 44.5%),
        /* Yellow ray */
        linear-gradient(130deg, transparent 39%, rgba(202, 138, 4, 0.04) 44%, transparent 46%),
        /* Orange ray */
        linear-gradient(133deg, transparent 40%, rgba(234, 88, 12, 0.05) 45.5%, transparent 47.5%),
        /* Red ray */
        linear-gradient(136deg, transparent 41%, rgba(220, 38, 38, 0.06) 47%, transparent 49%);
}

/* Ambient glow in bottom-right (suggests light source) */
.v3-hero-glow {
    position: absolute;
    bottom: -20%;
    right: -10%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(67, 56, 202, 0.06) 0%, transparent 70%);
    pointer-events: none;
    animation: v3-pulseGlow 8s ease-in-out infinite;
}

.v3-hero-inner {
    position: relative;
    z-index: 1;
    max-width: 760px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v3-hero-eyebrow {
    font-family: var(--v3-font-display);
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--v3-accent-light);
    text-transform: uppercase;
    letter-spacing: 0.18em;
    margin: 0 0 1.25rem;
    animation: v3-fadeInUp 0.7s var(--v3-ease-out) 0.1s both;
}

.v3-hero-title {
    font-family: var(--v3-font-display);
    font-weight: 800;
    font-size: 3.4rem;
    color: var(--v3-white);
    margin: 0 0 0.6rem;
    letter-spacing: -0.03em;
    line-height: 1.08;
    animation: v3-fadeInUp 0.7s var(--v3-ease-out) 0.2s both;
}

.v3-hero-tagline {
    font-family: var(--v3-font-body);
    font-size: 1.1rem;
    font-weight: 300;
    color: var(--v3-text-muted-dark);
    margin: 0 0 2.25rem;
    line-height: 1.55;
    max-width: 520px;
    margin-left: auto;
    margin-right: auto;
    animation: v3-fadeInUp 0.7s var(--v3-ease-out) 0.35s both;
}

/* Spectrum bar divider */
.v3-spectrum-bar {
    display: block;
    height: 3px;
    max-width: 220px;
    margin: 0 auto;
    border: none;
    border-radius: 2px;
    background: var(--v3-spectrum);
    background-size: 200% 100%;
    animation: v3-shimmer 6s linear infinite, v3-fadeIn 0.8s var(--v3-ease-out) 0.5s both;
}

/* ============================
   2. STATS — DARK (slightly lighter)
   ============================ */
.v3-stats {
    background: var(--v3-dark);
    border-top: 1px solid rgba(67, 56, 202, 0.06);
    padding: 3.5rem 0;
}

.v3-stats-grid {
    display: flex;
    justify-content: center;
    gap: 4rem;
    max-width: 720px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v3-stat-item {
    text-align: center;
    flex: 1;
    animation: v3-fadeInUp 0.6s var(--v3-ease-out) both;
}

.v3-stat-item:nth-child(1) { animation-delay: 0.15s; }
.v3-stat-item:nth-child(2) { animation-delay: 0.3s; }
.v3-stat-item:nth-child(3) { animation-delay: 0.45s; }

/* Small spectral-segment bar above each stat */
.v3-stat-accent {
    display: block;
    height: 3px;
    width: 40px;
    margin: 0 auto 1.1rem;
    border: none;
    border-radius: 2px;
}

.v3-stat-accent--a {
    background: linear-gradient(90deg, var(--v3-violet), var(--v3-blue));
}

.v3-stat-accent--b {
    background: linear-gradient(90deg, var(--v3-cyan), var(--v3-green));
}

.v3-stat-accent--c {
    background: linear-gradient(90deg, var(--v3-yellow), var(--v3-red));
}

.v3-stat-number {
    display: block;
    font-family: var(--v3-font-display);
    font-weight: 800;
    font-size: 2.8rem;
    color: var(--v3-white);
    line-height: 1;
    margin-bottom: 0.4rem;
    letter-spacing: -0.02em;
}

.v3-stat-label {
    display: block;
    font-family: var(--v3-font-display);
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--v3-text-muted-dark);
    text-transform: uppercase;
    letter-spacing: 0.12em;
}

/* ============================
   3. SEARCH — TRANSITION (dark → light)
   ============================ */
.v3-search-section {
    background: linear-gradient(180deg, var(--v3-dark) 0%, #1a1e3a 15%, #3a3d60 35%, #8a8da8 50%, var(--v3-light) 70%, var(--v3-light) 100%);
    padding: 5rem 0 3.5rem;
}

.v3-search-card {
    max-width: 620px;
    margin: 0 auto;
    background: var(--v3-white);
    border-radius: 14px;
    padding: 2.5rem 2.25rem 2rem;
    position: relative;
    overflow: hidden;
    transition: transform 0.35s var(--v3-ease-out), box-shadow 0.35s var(--v3-ease-out);
    box-shadow:
        0 1px 2px rgba(0,0,0,0.04),
        0 4px 12px rgba(0,0,0,0.06),
        0 16px 48px rgba(0,0,0,0.1);
}

/* Spectral top border on search card */
.v3-search-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--v3-spectrum);
    background-size: 200% 100%;
    animation: v3-shimmer 6s linear infinite;
}

.v3-search-card:focus-within {
    transform: scale(1.012);
    box-shadow:
        0 1px 2px rgba(0,0,0,0.04),
        0 4px 12px rgba(0,0,0,0.06),
        0 16px 48px rgba(0,0,0,0.1),
        0 0 0 3px var(--v3-accent-glow);
}

.v3-search-heading {
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--v3-text-on-light);
    text-align: center;
    margin: 0 0 1.5rem;
    line-height: 1.4;
}

.v3-search-heading strong {
    color: var(--v3-accent);
    font-weight: 700;
}

.v3-search-form-wrap {
    display: flex;
    align-items: center;
    border: 2px solid #dcdee6;
    border-radius: 10px;
    overflow: hidden;
    transition: border-color 0.25s var(--v3-ease-out);
    background: var(--v3-white);
}

.v3-search-form-wrap:focus-within {
    border-color: var(--v3-accent);
}

.v3-search-icon {
    padding-left: 1rem;
    color: var(--v3-text-muted-light);
    font-size: 1.05rem;
    flex-shrink: 0;
}

.v3-search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.85rem 0.75rem;
    font-family: var(--v3-font-body);
    font-size: 0.95rem;
    color: var(--v3-text-on-light);
    outline: none;
    min-width: 0;
}

.v3-search-input::placeholder {
    color: #a0a3b5;
}

.v3-search-btn {
    flex-shrink: 0;
    border: none;
    background-color: var(--v3-accent);
    color: var(--v3-white);
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.85rem 1.4rem;
    cursor: pointer;
    transition: background-color 0.2s;
    letter-spacing: 0.02em;
}

.v3-search-btn:hover {
    background-color: var(--v3-accent-light);
}

.v3-search-note {
    font-family: var(--v3-font-body);
    font-size: 0.82rem;
    color: var(--v3-text-muted-light);
    text-align: center;
    margin: 1.25rem 0 0;
    line-height: 1.5;
}

/* Welcome text + trust indicators below the card */
.v3-trust-row {
    display: flex;
    justify-content: center;
    gap: 2.5rem;
    max-width: 620px;
    margin: 2.5rem auto 0;
    padding: 0 1.5rem;
}

.v3-trust-item {
    text-align: center;
    flex: 1;
    max-width: 200px;
}

.v3-trust-icon {
    font-size: 1.4rem;
    display: inline-block;
    margin-bottom: 0.5rem;
    color: var(--v3-accent);
}

.v3-trust-title {
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 0.78rem;
    color: var(--v3-text-on-light);
    margin: 0 0 0.2rem;
    letter-spacing: 0.01em;
}

.v3-trust-desc {
    font-family: var(--v3-font-body);
    font-size: 0.72rem;
    color: var(--v3-text-muted-light);
    line-height: 1.45;
    margin: 0;
}

.v3-trust-desc a {
    color: var(--v3-accent);
    text-decoration: underline;
    text-decoration-color: rgba(67, 56, 202, 0.3);
    text-underline-offset: 2px;
    transition: text-decoration-color 0.2s;
}

.v3-trust-desc a:hover {
    text-decoration-color: var(--v3-accent);
}

/* ============================
   4. CONFERENCES — LIGHT
   ============================ */
.v3-conferences {
    background-color: var(--v3-light);
    padding: 3.5rem 0 4rem;
}

.v3-section-inner {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v3-section-label {
    font-family: var(--v3-font-display);
    font-weight: 700;
    font-size: 1.4rem;
    color: var(--v3-text-on-light);
    margin: 0 0 0.35rem;
    letter-spacing: -0.01em;
}

.v3-heading-rule {
    display: block;
    height: 3px;
    width: 72px;
    border: none;
    border-radius: 2px;
    background: var(--v3-spectrum);
    background-size: 200% 100%;
    animation: v3-shimmer 6s linear infinite;
    margin: 0 0 2rem;
}

.v3-conf-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.v3-conf-card {
    background: var(--v3-white);
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    transition: transform 0.3s var(--v3-ease-out), box-shadow 0.3s var(--v3-ease-out);
    box-shadow: 0 1px 3px rgba(0,0,0,0.03), 0 4px 16px rgba(0,0,0,0.04);
}

/* Spectral top border */
.v3-conf-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--v3-spectrum);
    z-index: 1;
}

.v3-conf-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.04), 0 12px 32px rgba(0,0,0,0.08);
}

.v3-conf-img-link {
    display: block;
    overflow: hidden;
}

.v3-conf-img {
    display: block;
    width: 100%;
    height: auto;
    transition: opacity 0.25s;
}

.v3-conf-img-link:hover .v3-conf-img {
    opacity: 0.88;
}

.v3-conf-body {
    padding: 1.25rem 1.5rem 1.5rem;
}

.v3-conf-title {
    font-family: var(--v3-font-display);
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--v3-text-on-light);
    margin: 0 0 0.35rem;
}

.v3-conf-text {
    font-family: var(--v3-font-body);
    font-size: 0.85rem;
    color: var(--v3-text-muted-light);
    margin: 0 0 0.85rem;
    line-height: 1.5;
}

.v3-conf-btn {
    display: inline-block;
    background-color: var(--v3-accent);
    color: var(--v3-white);
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 0.8rem;
    padding: 0.5rem 1.1rem;
    border-radius: 6px;
    text-decoration: none;
    letter-spacing: 0.01em;
    transition: background-color 0.2s, transform 0.15s;
}

.v3-conf-btn:hover {
    background-color: var(--v3-accent-light);
    color: var(--v3-white);
    transform: translateY(-1px);
}

.v3-conf-notice {
    background-color: rgba(67, 56, 202, 0.05);
    border: 1px solid rgba(67, 56, 202, 0.1);
    border-radius: 8px;
    padding: 0.7rem 1rem;
    font-family: var(--v3-font-body);
    font-size: 0.78rem;
    color: var(--v3-text-on-light);
    margin-top: 0.75rem;
    line-height: 1.55;
}

.v3-conf-notice strong {
    color: var(--v3-accent);
}

/* ============================
   5. ARCHIVE TIMELINE — WHITE
   ============================ */
.v3-archive {
    background-color: var(--v3-white);
    padding: 3.5rem 0 4rem;
    border-top: 1px solid #e8e7e3;
}

.v3-timeline {
    position: relative;
    padding-left: 44px;
    margin-top: 0.25rem;
}

/* Vertical spectral gradient line */
.v3-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 4px;
    bottom: 4px;
    width: 2px;
    border-radius: 1px;
    background: linear-gradient(180deg,
        var(--v3-violet), var(--v3-blue), var(--v3-cyan),
        var(--v3-green), var(--v3-yellow), var(--v3-orange), var(--v3-red));
}

.v3-tl-item {
    position: relative;
    padding: 0.2rem 0 1rem 0.75rem;
    transition: transform 0.25s var(--v3-ease-out);
}

.v3-tl-item:last-child {
    padding-bottom: 0;
}

/* Timeline node dot */
.v3-tl-item::before {
    content: '';
    position: absolute;
    left: -36px;
    top: 0.75rem;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--v3-white);
    border: 2.5px solid var(--v3-accent);
    transition: background-color 0.25s var(--v3-ease-out),
                transform 0.25s var(--v3-ease-spring),
                border-color 0.25s;
    z-index: 1;
    box-shadow: 0 0 0 3px var(--v3-white);
}

.v3-tl-item:hover::before {
    background: var(--v3-accent);
    border-color: var(--v3-accent-light);
    transform: scale(1.35);
}

.v3-tl-item:hover {
    transform: translateX(5px);
}

.v3-tl-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-decoration: none;
    padding: 0.65rem 1rem;
    border-radius: 8px;
    transition: background-color 0.2s;
}

.v3-tl-link:hover {
    background-color: var(--v3-light);
}

.v3-tl-year {
    font-family: var(--v3-font-display);
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--v3-text-on-light);
}

.v3-tl-meta {
    font-family: var(--v3-font-body);
    font-size: 0.85rem;
    color: var(--v3-text-muted-light);
    margin-left: 0.5rem;
}

.v3-tl-count {
    font-family: var(--v3-font-display);
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--v3-accent);
    flex-shrink: 0;
}

.v3-tl-more {
    margin-top: 1.5rem;
    padding-left: 0.75rem;
}

.v3-btn-outline {
    display: inline-block;
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--v3-accent);
    border: 2px solid var(--v3-accent);
    border-radius: 8px;
    padding: 0.5rem 1.25rem;
    text-decoration: none;
    transition: background-color 0.25s var(--v3-ease-out),
                color 0.25s var(--v3-ease-out),
                transform 0.15s;
}

.v3-btn-outline:hover {
    background-color: var(--v3-accent);
    color: var(--v3-white);
    transform: translateY(-1px);
}

/* ============================
   6. ACTION CARDS — DARK (bookend)
   ============================ */
.v3-actions {
    background: linear-gradient(180deg, var(--v3-dark) 0%, var(--v3-dark-deep) 100%);
    padding: 4.5rem 0 5rem;
}

.v3-actions-header {
    text-align: center;
    margin-bottom: 2.5rem;
}

.v3-actions-title {
    font-family: var(--v3-font-display);
    font-weight: 700;
    font-size: 1.35rem;
    color: var(--v3-white);
    margin: 0 0 0.5rem;
}

.v3-actions-bar {
    display: block;
    height: 3px;
    width: 60px;
    margin: 0 auto;
    border: none;
    border-radius: 2px;
    background: var(--v3-spectrum);
    background-size: 200% 100%;
    animation: v3-shimmer 6s linear infinite;
}

.v3-actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    max-width: 960px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.v3-action-card {
    background: var(--v3-dark-surface);
    border-radius: 12px;
    padding: 2.25rem 1.75rem;
    text-align: center;
    text-decoration: none;
    display: block;
    border: 1px solid rgba(67, 56, 202, 0.06);
    transition: box-shadow 0.35s var(--v3-ease-out),
                transform 0.25s var(--v3-ease-out),
                border-color 0.35s;
    position: relative;
    overflow: hidden;
}

/* Subtle inner glow on hover */
.v3-action-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 12px;
    opacity: 0;
    transition: opacity 0.35s;
    background: radial-gradient(ellipse at center, rgba(67, 56, 202, 0.06) 0%, transparent 70%);
    pointer-events: none;
}

.v3-action-card:hover::after {
    opacity: 1;
}

.v3-action-card:hover {
    transform: translateY(-3px);
    border-color: rgba(67, 56, 202, 0.15);
    box-shadow:
        0 0 24px rgba(67, 56, 202, 0.12),
        0 0 48px rgba(67, 56, 202, 0.05);
}

.v3-action-icon {
    font-size: 2rem;
    display: inline-block;
    margin-bottom: 1rem;
    background-image: linear-gradient(135deg, var(--v3-violet), var(--v3-blue), var(--v3-cyan));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.v3-action-title {
    font-family: var(--v3-font-display);
    font-weight: 600;
    font-size: 1.05rem;
    color: var(--v3-white);
    margin: 0 0 0.5rem;
    position: relative;
}

.v3-action-desc {
    font-family: var(--v3-font-body);
    font-size: 0.82rem;
    color: var(--v3-text-muted-dark);
    line-height: 1.55;
    margin: 0;
    position: relative;
}

/* ============================
   RESPONSIVE — TABLET
   ============================ */
@media (max-width: 767.98px) {
    .v3-hero {
        padding: 3.75rem 0 3rem;
    }

    .v3-hero-title {
        font-size: 2.4rem;
    }

    .v3-hero-tagline {
        font-size: 1rem;
    }

    .v3-stats-grid {
        gap: 2rem;
    }

    .v3-stat-number {
        font-size: 2.2rem;
    }

    .v3-conf-grid {
        grid-template-columns: 1fr;
        max-width: 480px;
        margin-left: auto;
        margin-right: auto;
    }

    .v3-actions-grid {
        grid-template-columns: 1fr;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }

    .v3-trust-row {
        flex-direction: column;
        align-items: center;
        gap: 1.5rem;
    }

    .v3-trust-item {
        max-width: 260px;
    }
}

/* ============================
   RESPONSIVE — MOBILE
   ============================ */
@media (max-width: 575.98px) {
    .v3-hero {
        padding: 2.75rem 0 2.25rem;
    }

    .v3-hero-title {
        font-size: 1.9rem;
        letter-spacing: -0.02em;
    }

    .v3-hero-eyebrow {
        font-size: 0.62rem;
    }

    .v3-hero-tagline {
        font-size: 0.9rem;
    }

    .v3-stats {
        padding: 2.25rem 0;
    }

    .v3-stats-grid {
        flex-direction: column;
        align-items: center;
        gap: 1.5rem;
    }

    .v3-stat-number {
        font-size: 2rem;
    }

    .v3-search-section {
        padding: 3.5rem 0 2.5rem;
    }

    .v3-search-card {
        padding: 1.75rem 1.25rem 1.5rem;
        border-radius: 10px;
        margin: 0 0.75rem;
    }

    .v3-search-heading {
        font-size: 0.95rem;
    }

    .v3-search-btn {
        padding: 0.85rem 1rem;
        font-size: 0.8rem;
    }

    .v3-conferences {
        padding: 2.5rem 0 3rem;
    }

    .v3-archive {
        padding: 2.5rem 0 3rem;
    }

    .v3-timeline {
        padding-left: 34px;
    }

    .v3-timeline::before {
        left: 11px;
    }

    .v3-tl-item::before {
        left: -30px;
        width: 10px;
        height: 10px;
    }

    .v3-tl-link {
        padding: 0.55rem 0.75rem;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .v3-tl-year {
        font-size: 0.95rem;
    }

    .v3-actions {
        padding: 3rem 0 3.5rem;
    }

    .v3-action-card {
        padding: 1.75rem 1.25rem;
    }

    .site-header .header-logo-img {
        height: 56px !important;
    }
}
</style>
CSS;
?>

<!-- =============================================
     SECTION 1: HERO (DARK)
     Deep dark w/ CSS refracted light rays, dot grid texture
     ============================================= -->
<section class="v3-hero">
    <div class="v3-hero-glow"></div>
    <div class="v3-hero-inner">
        <p class="v3-hero-eyebrow">ISSN <?= SITE_ISSN ?></p>
        <h1 class="v3-hero-title">DGaO-Proceedings</h1>
        <p class="v3-hero-tagline"><?= t('home.tagline') ?></p>
        <hr class="v3-spectrum-bar">
    </div>
</section>

<!-- =============================================
     SECTION 2: STATS (DARK)
     Big typographic numbers, spectrum-segment accents
     ============================================= -->
<section class="v3-stats">
    <div class="v3-stats-grid">
        <div class="v3-stat-item">
            <hr class="v3-stat-accent v3-stat-accent--a">
            <span class="v3-stat-number"><?= $nf($stats['papers']) ?></span>
            <span class="v3-stat-label"><?= t('home.stat.papers') ?></span>
        </div>
        <div class="v3-stat-item">
            <hr class="v3-stat-accent v3-stat-accent--b">
            <span class="v3-stat-number"><?= $stats['tagungen'] ?></span>
            <span class="v3-stat-label"><?= t('home.stat.conferences') ?></span>
        </div>
        <div class="v3-stat-item">
            <hr class="v3-stat-accent v3-stat-accent--c">
            <span class="v3-stat-number"><?= $nf($stats['autoren']) ?></span>
            <span class="v3-stat-label"><?= t('home.stat.authors') ?></span>
        </div>
    </div>
</section>

<!-- =============================================
     SECTION 3: SEARCH (TRANSITION dark → light)
     Floating white card with spectral border
     ============================================= -->
<section class="v3-search-section">
    <div style="padding: 0 1.5rem;">
        <div class="v3-search-card">
            <p class="v3-search-heading">
                <?= sprintf(
                    t('home.landing.search_across'),
                    '<strong>' . $nf($stats['papers']) . '</strong>',
                    '<strong>' . $nf($stats['autoren']) . '</strong>',
                    '<strong>' . $stats['tagungen'] . '</strong>'
                ) ?>
            </p>
            <form action="/suche" method="get">
                <div class="v3-search-form-wrap">
                    <i class="bi bi-search v3-search-icon"></i>
                    <input type="search" name="q" class="v3-search-input"
                           placeholder="<?= t('home.search_placeholder') ?>"
                           aria-label="<?= t('nav.suche') ?>">
                    <button type="submit" class="v3-search-btn">
                        <?= t('home.search_btn') ?>
                    </button>
                </div>
            </form>
            <p class="v3-search-note"><?= t('home.participation_note') ?></p>
        </div>

        <!-- Trust indicators -->
        <div class="v3-trust-row">
            <div class="v3-trust-item">
                <i class="bi bi-unlock v3-trust-icon"></i>
                <p class="v3-trust-title"><?= t('home.landing.open_access') ?></p>
                <p class="v3-trust-desc"><?= t('home.landing.open_access_desc') ?></p>
            </div>
            <div class="v3-trust-item">
                <i class="bi bi-people v3-trust-icon"></i>
                <p class="v3-trust-title"><?= t('home.landing.peer_community') ?></p>
                <p class="v3-trust-desc"><?= t('home.landing.peer_community_desc') ?></p>
            </div>
            <div class="v3-trust-item">
                <i class="bi bi-calendar-range v3-trust-icon"></i>
                <p class="v3-trust-title"><?= t('home.landing.since_year') ?> <?= e($oldestYear) ?></p>
                <p class="v3-trust-desc"><?= t('home.welcome') ?> <a href="https://www.dgao.de/" target="_blank" rel="noopener"><?= t('home.dgao_name') ?></a></p>
            </div>
        </div>
    </div>
</section>

<!-- =============================================
     SECTION 4: CONFERENCES (LIGHT)
     Two cards with spectral top borders, hover lift
     ============================================= -->
<section class="v3-conferences">
    <div class="v3-section-inner">
        <h2 class="v3-section-label"><?= t('home.section_current') ?></h2>
        <hr class="v3-heading-rule">

        <div class="v3-conf-grid">
            <!-- Current conference -->
            <div>
                <div class="v3-conf-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v3-conf-img-link">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="v3-conf-img">
                    </a>
                    <div class="v3-conf-body">
                        <h3 class="v3-conf-title"><?= t('home.conf_127_title') ?></h3>
                        <p class="v3-conf-text"><?= t('home.conf_127_text') ?></p>
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v3-conf-btn">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v3-conf-notice"><?= t('home.conf_127_alert') ?></div>
            </div>

            <!-- Previous conference -->
            <div>
                <div class="v3-conf-card">
                    <a href="/archiv/126" class="v3-conf-img-link">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="v3-conf-img">
                    </a>
                    <div class="v3-conf-body">
                        <h3 class="v3-conf-title"><?= t('home.conf_126_title') ?></h3>
                        <p class="v3-conf-text"><?= t('home.conf_126_text') ?></p>
                        <a href="/archiv/126" class="v3-conf-btn">
                            <?= t('home.conf_126_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v3-conf-notice"><?= t('home.conf_126_alert') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- =============================================
     SECTION 5: ARCHIVE TIMELINE (WHITE)
     Vertical spectral gradient line, node dots, hover slide
     ============================================= -->
<section class="v3-archive">
    <div class="v3-section-inner">
        <h2 class="v3-section-label"><?= t('home.section_archive') ?></h2>
        <hr class="v3-heading-rule">

        <div class="v3-timeline">
            <?php foreach ($recent as $t_item): ?>
            <div class="v3-tl-item">
                <a href="/archiv/<?= $t_item['nummer'] ?>" class="v3-tl-link">
                    <span>
                        <span class="v3-tl-year"><?= $t_item['jahr'] ?></span>
                        <?php if ($t_item['ort']): ?>
                            <span class="v3-tl-meta">&ndash; <?= e($t_item['ort']) ?></span>
                        <?php endif; ?>
                        <span class="v3-tl-meta">(<?= $t_item['nummer'] ?>.&nbsp;<?= t('home.tagung_suffix') ?>)</span>
                    </span>
                    <span class="v3-tl-count"><?= $t_item['paper_anzahl'] ?></span>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="v3-tl-more">
            <a href="/archiv" class="v3-btn-outline"><?= t('home.show_all') ?></a>
        </div>
    </div>
</section>

<!-- =============================================
     SECTION 6: ACTION CARDS (DARK bookend)
     Three cards with indigo glow on hover, spectral icons
     ============================================= -->
<section class="v3-actions">
    <div class="v3-actions-header">
        <h2 class="v3-actions-title"><?php
            echo currentLang() === 'en' ? 'Explore the Archive' : 'Archiv entdecken';
        ?></h2>
        <hr class="v3-actions-bar">
    </div>
    <div class="v3-actions-grid">
        <a href="/archiv" class="v3-action-card">
            <i class="bi bi-archive v3-action-icon"></i>
            <h3 class="v3-action-title"><?= t('home.landing.browse_archive') ?></h3>
            <p class="v3-action-desc"><?= t('home.landing.browse_archive_desc') ?></p>
        </a>
        <a href="/suche" class="v3-action-card">
            <i class="bi bi-search v3-action-icon"></i>
            <h3 class="v3-action-title"><?= t('home.landing.search_papers') ?></h3>
            <p class="v3-action-desc"><?= t('home.landing.search_papers_desc') ?></p>
        </a>
        <a href="/autoren" class="v3-action-card">
            <i class="bi bi-person-lines-fill v3-action-icon"></i>
            <h3 class="v3-action-title"><?= t('home.landing.explore_authors') ?></h3>
            <p class="v3-action-desc"><?= t('home.landing.explore_authors_desc') ?></p>
        </a>
    </div>
</section>
