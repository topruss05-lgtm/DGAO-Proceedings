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
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* =============================================
   DGaO-Proceedings v2 — Clean Modern Science
   Font: Sora (headings + body) + Space Grotesk (numbers + mono accents)
   Accent: Deep Ocean #1e3a5f / Electric Blue #2563eb
   ============================================= */

/* --- Brand stripe at very top of viewport --- */
body::before {
    content: '';
    display: block;
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #1e3a5f 0%, #2563eb 40%, #3b82f6 70%, #1e3a5f 100%);
    z-index: 9999;
    pointer-events: none;
}

/* --- Override layout header (keep white for logo) --- */
.site-header {
    background: #ffffff !important;
    border-bottom: 1px solid #eaecf0 !important;
    box-shadow: none !important;
    position: relative;
    z-index: 100;
}

.site-header .header-logo-img {
    height: 64px !important;
}

/* --- Override nav --- */
.site-nav {
    background: #ffffff !important;
    border-top: none !important;
    border-bottom: 1px solid #eaecf0 !important;
    font-family: 'Sora', sans-serif !important;
}

.site-nav .nav-link {
    font-family: 'Sora', sans-serif !important;
    font-weight: 500 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.06em !important;
    text-transform: uppercase !important;
    color: #4b5563 !important;
    padding: 0.75rem 1rem !important;
    border-bottom: 2px solid transparent !important;
    transition: color 0.2s ease, border-color 0.2s ease !important;
}

.site-nav .nav-link:hover {
    color: #1e3a5f !important;
    border-bottom-color: #2563eb !important;
}

.site-nav .nav-link.active {
    color: #1e3a5f !important;
    font-weight: 600 !important;
    border-bottom-color: #2563eb !important;
}

.lang-toggle {
    font-family: 'Sora', sans-serif !important;
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    letter-spacing: 0.08em !important;
    color: #6b7280 !important;
    border-color: #d1d5db !important;
}

.lang-toggle:hover {
    color: #1e3a5f !important;
    border-color: #2563eb !important;
    background: #eff6ff !important;
}

/* --- Override footer --- */
.site-footer {
    background: #0f172a !important;
    border-top: 3px solid #2563eb !important;
    color: rgba(255,255,255,0.5) !important;
    font-family: 'Sora', sans-serif !important;
    font-size: 0.78rem !important;
}

.site-footer a {
    color: rgba(255,255,255,0.6) !important;
    transition: color 0.2s !important;
}

.site-footer a:hover {
    color: #ffffff !important;
}

/* =============================================
   KEYFRAME ANIMATIONS
   ============================================= */

@keyframes v2-fadeUp {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes v2-fadeIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}

@keyframes v2-searchPulse {
    0%, 100% { box-shadow: 0 2px 16px rgba(37, 99, 235, 0.06); }
    50%      { box-shadow: 0 4px 32px rgba(37, 99, 235, 0.14); }
}

@keyframes v2-dotPulse {
    0%, 100% { opacity: 0.15; }
    50%      { opacity: 0.35; }
}

/* Entry animations */
.v2-anim {
    opacity: 0;
    animation: v2-fadeUp 0.65s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
.v2-anim-d1 { animation-delay: 0.06s; }
.v2-anim-d2 { animation-delay: 0.12s; }
.v2-anim-d3 { animation-delay: 0.20s; }
.v2-anim-d4 { animation-delay: 0.28s; }
.v2-anim-d5 { animation-delay: 0.36s; }
.v2-anim-d6 { animation-delay: 0.44s; }

/* Scroll reveal */
.v2-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity 0.55s cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}
.v2-reveal.v2-visible {
    opacity: 1;
    transform: translateY(0);
}
.v2-reveal-d1 { transition-delay: 0.05s; }
.v2-reveal-d2 { transition-delay: 0.10s; }
.v2-reveal-d3 { transition-delay: 0.15s; }
.v2-reveal-d4 { transition-delay: 0.20s; }
.v2-reveal-d5 { transition-delay: 0.25s; }

/* =============================================
   1. HERO
   ============================================= */

.v2-hero {
    background: #fafaf8;
    padding: 4.5rem 0 3.5rem;
    position: relative;
    overflow: hidden;
}

/* Subtle radial glow */
.v2-hero::after {
    content: '';
    position: absolute;
    top: -20%; right: -10%;
    width: 60%;
    height: 140%;
    background: radial-gradient(ellipse at 70% 30%, rgba(37, 99, 235, 0.035) 0%, transparent 65%);
    pointer-events: none;
}

.v2-hero-inner {
    max-width: 840px;
    margin: 0 auto;
    padding: 0 2rem;
    position: relative;
    z-index: 1;
}

.v2-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.7rem;
    font-weight: 500;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #2563eb;
    margin-bottom: 1.25rem;
}

.v2-hero-eyebrow::before {
    content: '';
    display: inline-block;
    width: 28px;
    height: 2px;
    background: #2563eb;
    flex-shrink: 0;
}

.v2-hero h1 {
    font-family: 'Sora', sans-serif;
    font-size: 3.4rem;
    font-weight: 700;
    color: #111827;
    letter-spacing: -0.04em;
    line-height: 1.08;
    margin: 0 0 0.6rem;
}

.v2-hero-issn {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.76rem;
    font-weight: 500;
    color: #9ca3af;
    letter-spacing: 0.08em;
    margin-bottom: 1rem;
}

.v2-hero-tagline {
    font-family: 'Sora', sans-serif;
    font-size: 1.1rem;
    font-weight: 400;
    color: #4b5563;
    line-height: 1.65;
    max-width: 520px;
}

.v2-hero-rule {
    width: 48px;
    height: 3px;
    background: #2563eb;
    border: none;
    margin: 2.25rem 0 0;
    opacity: 1;
}

/* Decorative dot grid */
.v2-hero-dots {
    position: absolute;
    bottom: 1.5rem;
    right: 3rem;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    animation: v2-dotPulse 6s ease-in-out infinite;
    z-index: 0;
}

.v2-hero-dots span {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #2563eb;
}

/* =============================================
   2. SEARCH
   ============================================= */

.v2-search {
    background: #ffffff;
    padding: 3.5rem 0 3rem;
    border-bottom: 1px solid #eaecf0;
    position: relative;
}

.v2-search-inner {
    max-width: 660px;
    margin: 0 auto;
    padding: 0 2rem;
}

.v2-search-heading {
    font-family: 'Sora', sans-serif;
    font-size: 1.3rem;
    font-weight: 600;
    color: #111827;
    margin-bottom: 1.5rem;
    letter-spacing: -0.015em;
}

.v2-search-form {
    position: relative;
}

.v2-search-wrap {
    display: flex;
    align-items: center;
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.2s ease;
    animation: v2-searchPulse 5s ease-in-out infinite;
}

.v2-search-wrap:focus-within {
    border-color: #2563eb;
    box-shadow: 0 4px 24px rgba(37, 99, 235, 0.14);
    transform: scale(1.008);
    animation: none;
}

.v2-search-icon {
    position: absolute;
    left: 1.2rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 1.1rem;
    pointer-events: none;
    z-index: 2;
    transition: color 0.2s;
}

.v2-search-wrap:focus-within ~ .v2-search-icon,
.v2-search-wrap:focus-within .v2-search-icon {
    color: #2563eb;
}

.v2-search-input {
    flex: 1;
    border: none !important;
    background: transparent;
    padding: 1.05rem 1rem 1.05rem 3.2rem;
    font-family: 'Sora', sans-serif;
    font-size: 1rem;
    font-weight: 400;
    color: #111827;
    min-width: 0;
    outline: none;
    box-shadow: none !important;
}

.v2-search-input::placeholder {
    color: #9ca3af;
    font-weight: 400;
}

.v2-search-input:focus {
    box-shadow: none !important;
    outline: none;
}

.v2-search-btn {
    flex-shrink: 0;
    background: #1e3a5f;
    color: #ffffff;
    border: none;
    padding: 1.05rem 1.8rem;
    font-family: 'Sora', sans-serif;
    font-size: 0.88rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
}

.v2-search-btn:hover {
    background: #2563eb;
}

.v2-search-meta {
    font-family: 'Sora', sans-serif;
    font-size: 0.84rem;
    color: #6b7280;
    margin-top: 1rem;
    line-height: 1.55;
}

.v2-search-meta strong {
    color: #374151;
    font-weight: 600;
}

/* =============================================
   3. STATS
   ============================================= */

.v2-stats {
    background: #fafaf8;
    padding: 3rem 0;
    border-bottom: 1px solid #eaecf0;
}

.v2-stats-inner {
    max-width: 840px;
    margin: 0 auto;
    padding: 0 2rem;
    display: flex;
    align-items: baseline;
    gap: 4.5rem;
}

.v2-stat {
    text-align: left;
}

.v2-stat-number {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 2.8rem;
    font-weight: 700;
    color: #111827;
    line-height: 1;
    letter-spacing: -0.03em;
}

.v2-stat-label {
    font-family: 'Sora', sans-serif;
    font-size: 0.74rem;
    font-weight: 500;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-top: 0.3rem;
}

.v2-stat-divider {
    width: 1px;
    align-self: stretch;
    background: #d1d5db;
    min-height: 40px;
}

/* =============================================
   4. CURRENT CONFERENCES
   ============================================= */

.v2-conferences {
    background: #ffffff;
    padding: 3.5rem 0;
    border-bottom: 1px solid #eaecf0;
}

.v2-section-inner {
    max-width: 840px;
    margin: 0 auto;
    padding: 0 2rem;
}

.v2-section-title {
    font-family: 'Sora', sans-serif;
    font-size: 1.45rem;
    font-weight: 700;
    color: #111827;
    letter-spacing: -0.02em;
    margin: 0 0 0.3rem;
}

.v2-section-rule {
    width: 32px;
    height: 3px;
    background: #2563eb;
    border: none;
    margin: 0 0 2rem;
    opacity: 1;
}

.v2-conf-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.v2-conf-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    transition: box-shadow 0.3s cubic-bezier(0.22, 1, 0.36, 1),
                transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
}

.v2-conf-card:hover {
    box-shadow: 0 10px 36px rgba(0, 0, 0, 0.07);
    transform: translateY(-3px);
}

.v2-conf-card-img-link {
    display: block;
    overflow: hidden;
    border-bottom: 1px solid #e5e7eb;
    line-height: 0;
}

.v2-conf-card-img {
    width: 100%;
    height: auto;
    display: block;
    transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.3s;
}

.v2-conf-card:hover .v2-conf-card-img {
    transform: scale(1.04);
    opacity: 0.9;
}

.v2-conf-card-body {
    padding: 1.25rem 1.5rem 1.5rem;
}

.v2-conf-card-title {
    font-family: 'Sora', sans-serif;
    font-size: 1.02rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 0.45rem;
    letter-spacing: -0.01em;
}

.v2-conf-card-text {
    font-family: 'Sora', sans-serif;
    font-size: 0.86rem;
    color: #6b7280;
    line-height: 1.55;
    margin: 0 0 1rem;
}

.v2-conf-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-family: 'Sora', sans-serif;
    font-size: 0.82rem;
    font-weight: 600;
    color: #ffffff;
    background: #1e3a5f;
    border: none;
    border-radius: 6px;
    padding: 0.5rem 1.15rem;
    text-decoration: none;
    transition: background 0.2s, transform 0.15s;
}

.v2-conf-btn:hover {
    background: #2563eb;
    color: #ffffff;
    transform: translateX(2px);
    text-decoration: none;
}

.v2-conf-alert {
    font-family: 'Sora', sans-serif;
    font-size: 0.78rem;
    color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 6px;
    padding: 0.65rem 1rem;
    margin-top: 0.75rem;
    line-height: 1.5;
}

.v2-conf-alert strong {
    font-weight: 600;
}

/* =============================================
   5. ARCHIVE
   ============================================= */

.v2-archive {
    background: #fafaf8;
    padding: 3.5rem 0;
    border-bottom: 1px solid #eaecf0;
}

.v2-archive-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.v2-archive-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1rem;
    border-left: 3px solid transparent;
    border-bottom: 1px solid #e5e7eb;
    background: transparent;
    text-decoration: none;
    color: #111827;
    transition: all 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.v2-archive-item:first-child {
    border-top: 1px solid #e5e7eb;
}

.v2-archive-item:hover {
    background: #ffffff;
    border-left-color: #2563eb;
    padding-left: 1.4rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.035);
    text-decoration: none;
    color: #111827;
}

.v2-archive-year {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: #111827;
    min-width: 52px;
}

.v2-archive-loc {
    font-family: 'Sora', sans-serif;
    font-size: 0.86rem;
    color: #6b7280;
    flex: 1;
    margin-left: 1rem;
}

.v2-archive-nr {
    font-family: 'Sora', sans-serif;
    font-size: 0.73rem;
    color: #9ca3af;
    margin-left: 0.5rem;
}

.v2-archive-badge {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.8rem;
    font-weight: 600;
    color: #2563eb;
    background: #eff6ff;
    border-radius: 20px;
    padding: 0.18rem 0.65rem;
    min-width: 34px;
    text-align: center;
    flex-shrink: 0;
    margin-left: 1rem;
    transition: background 0.2s, color 0.2s;
}

.v2-archive-item:hover .v2-archive-badge {
    background: #2563eb;
    color: #ffffff;
}

.v2-archive-show-all {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-family: 'Sora', sans-serif;
    font-size: 0.86rem;
    font-weight: 600;
    color: #2563eb;
    text-decoration: none;
    margin-top: 1.25rem;
    padding: 0.4rem 0;
    transition: color 0.2s, gap 0.25s;
}

.v2-archive-show-all:hover {
    color: #1e3a5f;
    gap: 0.55rem;
    text-decoration: none;
}

/* =============================================
   6. QUICK LINKS + TRUST
   ============================================= */

.v2-quicklinks {
    background: #ffffff;
    padding: 3.5rem 0 4rem;
}

.v2-ql-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 3rem;
}

.v2-ql-card {
    display: block;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 1.75rem 1.5rem;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    position: relative;
    overflow: hidden;
}

/* Accent top bar on hover */
.v2-ql-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: #2563eb;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

.v2-ql-card:hover {
    border-color: #d1d5db;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.055);
    transform: translateY(-3px);
    text-decoration: none;
}

.v2-ql-card:hover::before {
    transform: scaleX(1);
}

.v2-ql-icon {
    font-size: 1.45rem;
    color: #2563eb;
    margin-bottom: 1rem;
    display: block;
    transition: transform 0.3s;
}

.v2-ql-card:hover .v2-ql-icon {
    transform: scale(1.1);
}

.v2-ql-title {
    font-family: 'Sora', sans-serif;
    font-size: 0.98rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 0.4rem;
    letter-spacing: -0.01em;
}

.v2-ql-desc {
    font-family: 'Sora', sans-serif;
    font-size: 0.82rem;
    color: #6b7280;
    line-height: 1.55;
    margin: 0;
}

/* --- Trust indicators --- */
.v2-trust {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    padding-top: 2.5rem;
    border-top: 1px solid #eaecf0;
}

.v2-trust-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.v2-trust-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #1e3a5f;
    font-size: 1rem;
}

.v2-trust-label {
    font-family: 'Sora', sans-serif;
    font-size: 0.84rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
    line-height: 1.3;
}

.v2-trust-desc {
    font-family: 'Sora', sans-serif;
    font-size: 0.74rem;
    color: #6b7280;
    margin: 0.15rem 0 0;
    line-height: 1.45;
}

/* =============================================
   RESPONSIVE — TABLET
   ============================================= */

@media (max-width: 767.98px) {
    .v2-hero {
        padding: 3rem 0 2.5rem;
    }

    .v2-hero h1 {
        font-size: 2.35rem;
    }

    .v2-hero-tagline {
        font-size: 0.98rem;
    }

    .v2-hero-dots {
        display: none;
    }

    .v2-search-heading {
        font-size: 1.1rem;
    }

    .v2-search-wrap {
        flex-direction: column;
        border-radius: 10px;
    }

    .v2-search-input {
        width: 100%;
        padding: 0.95rem 1rem 0.95rem 3rem;
        font-size: 0.95rem;
    }

    .v2-search-btn {
        width: 100%;
        padding: 0.9rem;
        border-top: 1px solid #e5e7eb;
        text-align: center;
        display: flex;
        justify-content: center;
    }

    .v2-search-icon {
        top: 1.1rem;
        transform: none;
    }

    .v2-stats-inner {
        gap: 2.5rem;
        flex-wrap: wrap;
    }

    .v2-stat-number {
        font-size: 2.1rem;
    }

    .v2-stat-divider {
        display: none;
    }

    .v2-conf-grid {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }

    .v2-ql-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .v2-trust {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }
}

/* =============================================
   RESPONSIVE — MOBILE
   ============================================= */

@media (max-width: 575.98px) {
    .v2-hero h1 {
        font-size: 1.9rem;
    }

    .v2-hero-eyebrow {
        font-size: 0.65rem;
    }

    .v2-hero-inner,
    .v2-search-inner,
    .v2-section-inner {
        padding: 0 1.25rem;
    }

    .v2-stats-inner {
        padding: 0 1.25rem;
        gap: 2rem;
    }

    .v2-stat-number {
        font-size: 1.8rem;
    }

    .v2-archive-item {
        padding: 0.7rem 0.75rem;
    }

    .v2-archive-loc {
        font-size: 0.8rem;
    }

    .v2-conf-card-body {
        padding: 1rem 1.15rem 1.25rem;
    }

    .v2-ql-card {
        padding: 1.5rem 1.25rem;
    }
}
</style>
STYLES;
?>

<!-- =============================================
     1. HERO
     ============================================= -->
<section class="v2-hero">
    <div class="v2-hero-inner">
        <div class="v2-hero-eyebrow v2-anim v2-anim-d1">
            <?= currentLang() === 'en' ? 'Scientific Open Access Journal' : 'Wissenschaftliche Open-Access-Zeitschrift' ?>
        </div>
        <h1 class="v2-anim v2-anim-d2">DGaO-Proceedings</h1>
        <div class="v2-hero-issn v2-anim v2-anim-d3">ISSN <?= SITE_ISSN ?></div>
        <p class="v2-hero-tagline v2-anim v2-anim-d4"><?= t('home.tagline') ?></p>
        <hr class="v2-hero-rule v2-anim v2-anim-d5">

        <!-- Decorative dot grid -->
        <div class="v2-hero-dots" aria-hidden="true">
            <?php for ($i = 0; $i < 25; $i++): ?><span></span><?php endfor; ?>
        </div>
    </div>
</section>

<!-- =============================================
     2. SEARCH
     ============================================= -->
<section class="v2-search">
    <div class="v2-search-inner">
        <div class="v2-search-heading v2-anim v2-anim-d3"><?= t('home.landing.search_papers') ?></div>
        <form action="/suche" method="get" class="v2-search-form v2-anim v2-anim-d4">
            <div class="v2-search-wrap">
                <i class="bi bi-search v2-search-icon"></i>
                <input type="search" name="q" class="v2-search-input form-control"
                       placeholder="<?= t('home.search_placeholder') ?>"
                       aria-label="<?= t('nav.suche') ?>"
                       autocomplete="off">
                <button type="submit" class="v2-search-btn">
                    <?= t('home.search_btn') ?>
                </button>
            </div>
        </form>
        <p class="v2-search-meta v2-anim v2-anim-d5">
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
     3. STATS
     ============================================= -->
<section class="v2-stats">
    <div class="v2-stats-inner v2-reveal">
        <div class="v2-stat">
            <div class="v2-stat-number"><?= $nf($stats['papers']) ?></div>
            <div class="v2-stat-label"><?= t('home.stat.papers') ?></div>
        </div>
        <div class="v2-stat-divider"></div>
        <div class="v2-stat">
            <div class="v2-stat-number"><?= $stats['tagungen'] ?></div>
            <div class="v2-stat-label"><?= t('home.stat.conferences') ?></div>
        </div>
        <div class="v2-stat-divider"></div>
        <div class="v2-stat">
            <div class="v2-stat-number"><?= $nf($stats['autoren']) ?></div>
            <div class="v2-stat-label"><?= t('home.stat.authors') ?></div>
        </div>
    </div>
</section>

<!-- =============================================
     4. CURRENT CONFERENCES
     ============================================= -->
<section class="v2-conferences">
    <div class="v2-section-inner">
        <h2 class="v2-section-title v2-reveal"><?= t('home.section_current') ?></h2>
        <hr class="v2-section-rule v2-reveal">

        <div class="v2-conf-grid">
            <!-- 127th Conference -->
            <div class="v2-reveal v2-reveal-d1">
                <div class="v2-conf-card">
                    <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v2-conf-card-img-link">
                        <img src="/assets/images/haw-hamburg-2026.png"
                             alt="127. Jahrestagung der DGaO &ndash; HAW Hamburg, 26.&ndash;30. Mai 2026"
                             class="v2-conf-card-img">
                    </a>
                    <div class="v2-conf-card-body">
                        <h3 class="v2-conf-card-title"><?= t('home.conf_127_title') ?></h3>
                        <p class="v2-conf-card-text"><?= t('home.conf_127_text') ?></p>
                        <a href="https://dgao.de/jahrestagung/" target="_blank" rel="noopener" class="v2-conf-btn">
                            <?= t('home.conf_127_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v2-conf-alert"><?= t('home.conf_127_alert') ?></div>
            </div>

            <!-- 126th Conference -->
            <div class="v2-reveal v2-reveal-d2">
                <div class="v2-conf-card">
                    <a href="/archiv/126" class="v2-conf-card-img-link">
                        <img src="/assets/images/dgao-stuttgart-2025.png"
                             alt="126. Jahrestagung der DGaO &ndash; Uni Stuttgart, 10.&ndash;14. Juni 2025"
                             class="v2-conf-card-img">
                    </a>
                    <div class="v2-conf-card-body">
                        <h3 class="v2-conf-card-title"><?= t('home.conf_126_title') ?></h3>
                        <p class="v2-conf-card-text"><?= t('home.conf_126_text') ?></p>
                        <a href="/archiv/126" class="v2-conf-btn">
                            <?= t('home.conf_126_btn') ?>
                        </a>
                    </div>
                </div>
                <div class="v2-conf-alert"><?= t('home.conf_126_alert') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- =============================================
     5. ARCHIVE
     ============================================= -->
<section class="v2-archive">
    <div class="v2-section-inner">
        <h2 class="v2-section-title v2-reveal"><?= t('home.section_archive') ?></h2>
        <hr class="v2-section-rule v2-reveal">

        <ul class="v2-archive-list">
            <?php foreach ($recent as $i => $t_item): ?>
            <li class="v2-reveal v2-reveal-d<?= min($i + 1, 5) ?>">
                <a href="/archiv/<?= $t_item['nummer'] ?>" class="v2-archive-item">
                    <span class="v2-archive-year"><?= $t_item['jahr'] ?></span>
                    <span class="v2-archive-loc">
                        <?php if ($t_item['ort']): ?>
                            <?= e($t_item['ort']) ?>
                        <?php endif; ?>
                        <span class="v2-archive-nr"><?= $t_item['nummer'] ?>.&nbsp;<?= t('home.tagung_suffix') ?></span>
                    </span>
                    <span class="v2-archive-badge"><?= $t_item['paper_anzahl'] ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <a href="/archiv" class="v2-archive-show-all v2-reveal">
            <?= t('home.show_all') ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>
</section>

<!-- =============================================
     6. QUICK LINKS + TRUST INDICATORS
     ============================================= -->
<section class="v2-quicklinks">
    <div class="v2-section-inner">
        <div class="v2-ql-grid">
            <a href="/archiv" class="v2-ql-card v2-reveal v2-reveal-d1">
                <span class="v2-ql-icon"><i class="bi bi-collection"></i></span>
                <h3 class="v2-ql-title"><?= t('home.landing.browse_archive') ?></h3>
                <p class="v2-ql-desc"><?= t('home.landing.browse_archive_desc') ?></p>
            </a>
            <a href="/suche" class="v2-ql-card v2-reveal v2-reveal-d2">
                <span class="v2-ql-icon"><i class="bi bi-search"></i></span>
                <h3 class="v2-ql-title"><?= t('home.landing.search_papers') ?></h3>
                <p class="v2-ql-desc"><?= t('home.landing.search_papers_desc') ?></p>
            </a>
            <a href="/autoren" class="v2-ql-card v2-reveal v2-reveal-d3">
                <span class="v2-ql-icon"><i class="bi bi-people"></i></span>
                <h3 class="v2-ql-title"><?= t('home.landing.explore_authors') ?></h3>
                <p class="v2-ql-desc"><?= t('home.landing.explore_authors_desc') ?></p>
            </a>
        </div>

        <!-- Trust indicators -->
        <div class="v2-trust">
            <div class="v2-trust-item v2-reveal v2-reveal-d1">
                <div class="v2-trust-icon">
                    <i class="bi bi-unlock"></i>
                </div>
                <div>
                    <div class="v2-trust-label"><?= t('home.landing.open_access') ?></div>
                    <div class="v2-trust-desc"><?= t('home.landing.open_access_desc') ?></div>
                </div>
            </div>
            <div class="v2-trust-item v2-reveal v2-reveal-d2">
                <div class="v2-trust-icon">
                    <i class="bi bi-mortarboard"></i>
                </div>
                <div>
                    <div class="v2-trust-label"><?= t('home.landing.peer_community') ?></div>
                    <div class="v2-trust-desc"><?= t('home.landing.peer_community_desc') ?></div>
                </div>
            </div>
            <div class="v2-trust-item v2-reveal v2-reveal-d3">
                <div class="v2-trust-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <div class="v2-trust-label"><?= t('home.landing.since_year') ?> <?= $oldestYear ?></div>
                    <div class="v2-trust-desc"><?= t('home.participation_note') ?></div>
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
    var els = document.querySelectorAll('.v2-reveal');
    if (!els.length) return;

    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) {
                if (e.isIntersecting) {
                    e.target.classList.add('v2-visible');
                    io.unobserve(e.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        els.forEach(function(el) { io.observe(el); });
    } else {
        els.forEach(function(el) { el.classList.add('v2-visible'); });
    }
})();
</script>
