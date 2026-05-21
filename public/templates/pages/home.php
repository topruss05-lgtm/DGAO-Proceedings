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

/* ============================================================
   Hero optics — Snell's-law refraction + dispersion through a
   parallel-faced glass slab (the search bar). Real BK7 has
   Δn ≈ 0.02 across the visible range, far too small to see in a
   90-px-thick slab; we use Δn ≈ 0.26 so the spectrum reads
   visually. Snell's law itself is computed exactly per colour.

   On desktop the parallel rainbow stripes pass through a thin
   biconvex lens with chromatic aberration (per-colour focal
   length), producing the classic crossing fan beyond focus.
   ============================================================ */

function dgao_compute_optics(array $cfg): array {
    $vbW      = $cfg['vb_w'];
    $vbH      = $cfg['vb_h'];
    $glass    = $cfg['glass'];                  // x, y, w, h, r
    $thetaAir = deg2rad($cfg['theta_deg']);
    $entryX   = $cfg['entry_x'];

    $entryY  = $glass['y'] + $glass['h'];
    $sourceY = $vbH + 120;
    $sourceX = $entryX - ($sourceY - $entryY) * tan($thetaAir);

    // Visible spectrum. Real BK7 has Δn ≈ 0.02; we use Δn ≈ 0.6 so
    // the dispersion spread is wide enough that individual colours
    // stay distinguishable rather than blurring back into white.
    $spectrum = [
        ['n' => 1.35, 'rgb' => '#ff2a2a'],
        ['n' => 1.45, 'rgb' => '#ff7a18'],
        ['n' => 1.55, 'rgb' => '#ffd400'],
        ['n' => 1.65, 'rgb' => '#3eea75'],
        ['n' => 1.75, 'rgb' => '#1ec8ff'],
        ['n' => 1.85, 'rgb' => '#3a6dff'],
        ['n' => 1.95, 'rgb' => '#a040ff'],
    ];

    $rays = [];
    foreach ($spectrum as $s) {
        // Snell at entry (air→glass)
        $thetaGlass = asin(sin($thetaAir) / $s['n']);
        // Travel through the slab to the parallel top face
        $exitX = $entryX + $glass['h'] * tan($thetaGlass);
        $exitY = $glass['y'];
        // After exit (glass→air): same θ as incoming, so direction = original.
        $endY  = -120;
        $endX  = $exitX + ($exitY - $endY) * tan($thetaAir);

        $rays[] = [
            'color' => $s['rgb'],
            'n'     => $s['n'],
            'midX'  => round($exitX, 2),
            'midY'  => round($exitY, 2),
            'endX'  => round($endX, 2),
            'endY'  => $endY,
        ];
    }

    return [
        'vbW'      => $vbW,
        'vbH'      => $vbH,
        'glass'    => $glass,
        'source'   => ['x' => round($sourceX, 2), 'y' => $sourceY],
        'entry'    => ['x' => $entryX, 'y' => $entryY],
        'rays'     => $rays,
        'thetaDeg' => $cfg['theta_deg'],
        'thetaRad' => $thetaAir,
    ];
}

/** Bend each parallel ray through a thin biconvex lens with chromatic
 *  aberration. Returns hit point on the lens plane plus a far end past
 *  the per-colour focal point (so the crossing fan is visible). */
function dgao_lens_rays(array $rays, array $lens, float $thetaRad): array {
    $axisDx =  sin($thetaRad);
    $axisDy = -cos($thetaRad);
    $cx     = $lens['cx'];
    $cy     = $lens['cy'];
    $fBase  = $lens['f'];

    // Optisch korrekte Konvergenz: jeder Strahl geht durch SEINEN
    // Brennpunkt (chromatische Aberration: rot fokussiert weiter weg,
    // violett näher). Die Strahlen kreuzen sich, kombinieren visuell zu
    // einem weißen Hotspot (alle Farben überlagern sich beim mittleren
    // Brennpunkt) und divergieren danach wieder aufgefächert.
    $out = [];
    $n   = count($rays);
    foreach ($rays as $i => $r) {
        $f  = $fBase * (1.0 - 0.32 * ($i / max($n - 1, 1)));
        $fx = $cx + $f * $axisDx;
        $fy = $cy + $f * $axisDy;

        // Auftreff-Punkt auf der Linsen-Ebene
        $d    = ($cx - $r['midX']) * $axisDx + ($cy - $r['midY']) * $axisDy;
        $hitX = $r['midX'] + $d * $axisDx;
        $hitY = $r['midY'] + $d * $axisDy;

        // Refraktierter Strahl: durch fx/fy, hinter dem Brennpunkt weiter
        // (divergiert) — Trace so dass die Fan-Geometrie sichtbar wird,
        // aber Strahlen am Rand des Viewports bleiben.
        $rdx  = $fx - $hitX;  $rdy  = $fy - $hitY;
        $rLen = sqrt($rdx * $rdx + $rdy * $rdy) ?: 1;
        $rdx /= $rLen;        $rdy /= $rLen;
        $trace = $f * 1.55;
        $endX  = $hitX + $trace * $rdx;
        $endY  = $hitY + $trace * $rdy;

        $out[] = [
            'color' => $r['color'],
            'midX'  => $r['midX'],
            'midY'  => $r['midY'],
            'hitX'  => round($hitX, 2),
            'hitY'  => round($hitY, 2),
            'endX'  => round($endX, 2),
            'endY'  => round($endY, 2),
            'fx'    => round($fx, 2),
            'fy'    => round($fy, 2),
        ];
    }
    return $out;
}

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

$extraHead = <<<'STYLES'
<style>
/* =============================================
   Homepage — Optics Refraction Hero
   Dark stage; white beam refracts and disperses through an Apple-
   style liquid-glass search bar. Mobile shows just the prism;
   desktop adds a biconvex lens with chromatic aberration.
   Geometry is computed in PHP (Snell's law) and rendered as SVG.
   The interactive form is positioned in % so it stays in lock-
   step with the SVG glass shape (stage uses aspect-ratio).
   ============================================= */

.hero {
    position: relative;
    background: #050811;
    color: #fff;
    overflow: hidden;
    isolation: isolate;
    padding: clamp(2rem, 4vw, 3.25rem) 0 clamp(2rem, 4vw, 3.25rem);
}
/* Mobile: Hero darf direkt am Header andocken, sonst klafft über dem
   Titelblock eine tote schwarze Zone unter dem Hamburger. */
@media (max-width: 767.98px) {
    .hero { padding: 0.25rem 0 0.5rem; }
}

/* ---------- atmospheric background ----------
   WICHTIG: Der Hero muss am OBEREN Rand denselben Ton wie die dunkle
   Home-Navbar treffen (#050811), sonst entsteht eine sichtbare Stoßkante.
   Deshalb sind alle Glow-/Vignette-Quellen so positioniert, dass sie die
   oberen 25 % der Hero nicht einfärben oder abdunkeln. Der zentrale
   Radial-Gradient läuft VON OBEN nach unten (statt aus der Mitte), damit
   die Top-Linie überall exakt #050811 ist. */
.hero__bg {
    position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 65% 45% at 10% 100%,
            rgba(255, 215, 175, 0.16) 0%, rgba(255, 200, 150, 0) 60%),
        radial-gradient(ellipse 55% 45% at 92% 55%,
            rgba(120, 180, 255, 0.12) 0%, rgba(80, 130, 220, 0) 65%),
        linear-gradient(180deg, #050811 0%, #050811 18%, #0a1024 60%, #050811 100%);
}
.hero__bg::before, .hero__bg::after {
    content: ''; position: absolute; inset: 0; pointer-events: none;
}
.hero__bg::before {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='1.4' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 0.18 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    opacity: 0.45;
    mix-blend-mode: overlay;
    /* Noise startet erst unterhalb der Navbar-Übergangszone, damit am
       Top-Edge nichts flackert und der Übergang nahtlos bleibt. */
    -webkit-mask-image: linear-gradient(180deg, transparent 0%, transparent 12%, #000 28%, #000 100%);
            mask-image: linear-gradient(180deg, transparent 0%, transparent 12%, #000 28%, #000 100%);
}
/* Vignette nur unten/in der Mitte unten — KEIN Darken am Top-Rand. */
.hero__bg::after {
    background: radial-gradient(ellipse 95% 90% at 50% 85%,
        transparent 50%, rgba(0, 0, 0, 0.45) 100%);
}

/* ---------- locked-aspect stage ---------- */
.hero__stage {
    position: relative;
    z-index: 1;
    width: min(100% - 2rem, 1280px);
    margin: 0 auto;
    aspect-ratio: 1280 / 560;
}
@media (max-width: 767.98px) {
    .hero__stage {
        width: min(100% - 1rem, 600px);
        aspect-ratio: 600 / 760;
    }
}

/* ---------- SVG optics ---------- */
.hero__optics {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    pointer-events: none;
    overflow: visible;
}
.hero__optics--mobile  { display: none; }
@media (max-width: 767.98px) {
    .hero__optics--desktop { display: none; }
    .hero__optics--mobile  { display: block; }
}
.dgao-grid line { stroke: rgba(255,255,255,0.025); stroke-width: 1; }
.dgao-axis      { stroke: rgba(255,255,255,0.10); stroke-width: 1; stroke-dasharray: 2 4; }
.dgao-anno      {
    font-family: 'Outfit', system-ui, sans-serif;
    font-size: 9.5px;
    font-weight: 500;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    fill: rgba(255,255,255,0.42);
}
.dgao-anno--n   { font-size: 10.5px; letter-spacing: 0.05em; text-transform: none; }
.dgao-focus-dot { fill: rgba(255,255,255,0.85); }

/* ---------- text overlay ---------- */
.hero__overlay { position: absolute; inset: 0; }

.hero__text {
    position: absolute;
    top: 6%;
    left: 5.5%;
    width: clamp(280px, 46%, 540px);
    z-index: 3;
}

.hero__eyebrow {
    margin: 0 0 1.4rem;
    font-family: var(--font-display);
    font-size: 0.7rem;
    font-weight: 500;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
    display: flex;
    align-items: center;
    gap: 0.7rem;
}
.hero__eyebrow::before {
    content: '';
    width: 28px;
    height: 1px;
    background: rgba(255, 255, 255, 0.32);
}

.hero__title {
    margin: 0;
    font-family: var(--font-display);
    font-weight: 700;
    font-size: clamp(1.85rem, 3.1vw, 2.5rem);
    line-height: 1.08;
    letter-spacing: -0.025em;
}
.hero__title-line { display: block; }
.hero__title-line--1 { color: #ffffff; }
.hero__title-line--2 { color: rgba(255, 255, 255, 0.78); }
.hero__title-line--3 { color: rgba(255, 255, 255, 0.55); }

/* Die ersten zwei Zeilen sind ein Link zur dgao.de — visuell
   identisch zum nicht-verlinkten Verhalten, nur per Cursor + sanftem
   Opazitäts-Dip beim Hover als Affordance erkennbar. */
.hero__title-link {
    color: inherit;
    text-decoration: none;
    display: block;
    transition: opacity 0.22s var(--ease);
}
.hero__title-link:hover { opacity: 0.78; text-decoration: none; }
.hero__title-link:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.6);
    outline-offset: 4px;
    border-radius: 2px;
}

/* ---------- liquid-glass search form ----------
   Positioned in % to align with the SVG glass coordinates.
   Desktop glass at viewBox (360, 235, 560, 90) on 1280×560.
   Mobile glass at viewBox (60, 415, 480, 90) on 600×760. */
.hero__search-form {
    position: absolute;
    top:    41.07%;     /* desktop glass.y / vb.h = 230/560 */
    left:   6.25%;      /* glass.x / vb.w  =  80/1280 */
    width:  40.625%;    /* glass.w / vb.w  = 520/1280 */
    height: 17.86%;     /* glass.h / vb.h  = 100/560  */
    z-index: 4;
    margin: 0;
}
@media (max-width: 767.98px) {
    .hero__search-form {
        top:    53.95%; /* mobile glass.y / vb.h = 410/760 */
        left:   10%;    /* glass.x / vb.w  = 60/600  */
        width:  80%;    /* glass.w / vb.w  = 480/600 */
        height: 13.16%; /* glass.h / vb.h  = 100/760 */
    }
}

/* Visually hidden but still announced to assistive tech: the search
   icon + placeholder make the form's purpose obvious to sighted users
   and a printed label was crowding the spectrum exit area. */
.hero__search-label {
    position: absolute;
    width: 1px; height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Wissenschaftlich-präzises Glas statt iOS-Pille:
   leicht abgerundetes Rechteck (wie ein optisches Element / Glasplatte),
   kräftigerer Backdrop-Blur (Inhalte HINTER der Form werden geblurrt —
   die Suche-Eingabe selbst liegt darüber und bleibt knackscharf), eine
   einzelne dünne Hairline-Border statt buntem Verlaufsrahmen. */
.hero__search {
    position: relative;
    width: 100%; height: 100%;
    display: flex;
    align-items: center;
    padding: 7px 7px 7px clamp(16px, 1.6vw, 24px);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.18);
    -webkit-backdrop-filter: blur(8px) saturate(1.4);
            backdrop-filter: blur(8px) saturate(1.4);
    box-shadow:
        inset 0 1px 0    rgba(255, 255, 255, 0.18),
        0 12px 36px -14px rgba(0, 0, 0, 0.55);
    transition: border-color 0.25s var(--ease), box-shadow 0.25s var(--ease);
}

.hero__search:focus-within {
    border-color: rgba(255, 255, 255, 0.32);
    box-shadow:
        inset 0 1px 0     rgba(255, 255, 255, 0.22),
        0 14px 44px -12px rgba(0, 0, 0, 0.6),
        0 0 0 3px         rgba(120, 180, 255, 0.16);
}

.hero__search-icon {
    flex-shrink: 0;
    margin-right: 14px;
    color: rgba(255, 255, 255, 0.65);
    font-size: 1.05rem;
    line-height: 1;
    z-index: 1;
}

.hero__search-input {
    flex: 1; min-width: 0;
    height: 100%;
    background: transparent;
    border: 0; outline: 0;
    color: #fff;
    font-family: var(--font-body);
    font-size: clamp(0.95rem, 1vw, 1rem);
    font-weight: 400;
    letter-spacing: 0.005em;
    padding: 0;
    z-index: 1;
}
.hero__search-input::placeholder { color: rgba(255, 255, 255, 0.55); }
.hero__search-input::-webkit-search-cancel-button { display: none; }

.hero__search-btn {
    flex-shrink: 0;
    height: 100%;
    aspect-ratio: 1;
    margin-left: 6px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.10);
    color: #fff;
    font-size: 1rem; line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center; justify-content: center;
    z-index: 1;
    transition: background 0.18s var(--ease), border-color 0.18s var(--ease);
}
.hero__search-btn:hover {
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.30);
}
.hero__search-btn:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.7);
    outline-offset: 2px;
}

/* ---------- stats ---------- */
.hero__stats {
    position: absolute;
    left: 5.5%;
    right: 5.5%;
    bottom: 6%;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, auto));
    column-gap: clamp(1.75rem, 4vw, 4rem);
    padding-top: 1.4rem;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    z-index: 3;
}
.hero__stat-num {
    display: block;
    font-family: var(--font-display);
    font-size: clamp(1.3rem, 1.8vw, 1.65rem);
    font-weight: 700;
    color: #fff;
    line-height: 1;
    margin-bottom: 0.35rem;
    font-feature-settings: 'tnum' 1, 'lnum' 1;
}
.hero__stat-lbl {
    display: block;
    font-family: var(--font-display);
    font-size: 0.7rem;
    font-weight: 500;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
}

@media (max-width: 767.98px) {
    /* Titel direkt unter den Hamburger setzen + linksbündig auf Höhe
       der Suchbox (left=10%), damit Titel und Suche eine sauberer
       vertikale Linie bilden. Ein Hauch Luft (top=6%) verhindert,
       dass der Titel direkt am Hamburger-Strich klebt. */
    .hero__text { top: 6%; left: 10%; width: 80%; }
    .hero__eyebrow { font-size: 0.62rem; margin-bottom: 0.55rem; }
    .hero__title { font-size: clamp(1.7rem, 7vw, 2.2rem); }
    .hero__lead  { font-size: 0.92rem; }
    .hero__stats {
        bottom: 4%;
        column-gap: 1.4rem;
        padding-top: 1rem;
    }
    .hero__stat-num { font-size: 1.15rem; }
    .hero__stat-lbl { font-size: 0.6rem; letter-spacing: 0.1em; }
}

/* ---------- bottom hairline ---------- */
.hero__edge {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    height: 1px;
    background: rgba(255, 255, 255, 0.08);
    z-index: 2;
}

/* ---------- subtle photon shimmer on the white beam ---------- */
@keyframes dgao-shimmer {
    0%, 100% { opacity: 0.92; }
    50%      { opacity: 1; }
}
.dgao-ray-white { animation: dgao-shimmer 4.5s ease-in-out infinite; }

/* --- DOWNSTREAM SECTIONS --- */
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

/* --- NEWS — compact editorial grid --- */
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
    transition: box-shadow 0.25s var(--ease),
                transform 0.25s var(--ease);
}

.v4-conf-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

/* Bild ist auf jeder Viewport-Größe der Haupt-Klick-Target. Auf Mobile
   verschwindet zusätzlich der Button-Block, das Bild bleibt das einzige
   Tap-Element. */
.v4-conf-img-link {
    display: block;
    line-height: 0;
    overflow: hidden;
    border-bottom: 1px solid var(--border-light);
    transition: opacity 0.22s var(--ease);
}
.v4-conf-img-link:hover { opacity: 0.92; }
.v4-conf-img-link:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: -2px;
}

.v4-conf-img {
    width: 100%;
    height: auto;
    display: block;
    transition: transform 0.4s var(--ease);
}
.v4-conf-card:hover .v4-conf-img { transform: scale(1.01); }

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
    transition: background 0.18s var(--ease),
                transform 0.18s var(--ease);
}
.v4-conf-btn:hover {
    background: var(--accent-light);
    color: #fff;
    text-decoration: none;
    transform: translateY(-1px);
}

/* HAW Hamburg verwendet ihre Hochschulfarbe (#004C98). Konsistent zur
   Mutterseite, signalisiert "externer Partner" optisch ohne Erklärung. */
.v4-conf-btn--haw {
    background: #004C98;
}
.v4-conf-btn--haw:hover {
    background: #003B79;
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
    /* Auf Mobile sind die Bilder allein das Klick-Target — Button-Block
       weg, Bild übernimmt komplett (Stacked-Look bleibt sauber). */
    .v4-conf-body { display: none; }
    .v4-conf-img-link { border-bottom: 0; }
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

/** Render one optics scene (defs + grid + beam + glass spectrum + exit
 *  rays + optional lens) as SVG markup. */
function dgao_render_scene(string $variant, array $scene, ?array $lens = null, ?array $lensRays = null): string {
    $g = $scene['glass'];
    $src = $scene['source']; $entry = $scene['entry'];
    $vbW = $scene['vbW']; $vbH = $scene['vbH'];
    $rays = $scene['rays'];

    $defsId      = "dgao-defs-{$variant}";
    $clipId      = "dgao-glass-clip-{$variant}";
    $glowId      = "dgao-glow-{$variant}";
    $glowSoftId  = "dgao-glow-soft-{$variant}";

    $svg  = '<svg class="hero__optics hero__optics--' . $variant . '" viewBox="0 0 ' . $vbW . ' ' . $vbH;
    $svg .= '" preserveAspectRatio="xMidYMid meet" aria-hidden="true">';

    // ---- defs ----
    // Tight glow for the white beam only (gives it a candle-like halo).
    // The colour rays are drawn as crisp solid strokes so they read as
    // distinct hues instead of merging back into white.
    $svg .= '<defs>';
    $svg .= '<filter id="' . $glowId . '" x="-50%" y="-50%" width="200%" height="200%">';
    $svg .= '<feGaussianBlur stdDeviation="1.4" result="b"/>';
    $svg .= '<feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>';
    $svg .= '</filter>';
    $svg .= '<filter id="' . $glowSoftId . '" x="-50%" y="-50%" width="200%" height="200%">';
    $svg .= '<feGaussianBlur stdDeviation="3.5" result="b"/>';
    $svg .= '<feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>';
    $svg .= '</filter>';
    $svg .= '<clipPath id="' . $clipId . '">';
    $svg .= sprintf(
        '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>',
        $g['x'], $g['y'], $g['w'], $g['h'], $g['r'], $g['r']
    );
    $svg .= '</clipPath>';
    $svg .= '</defs>';

    // ---- subtle optical-bench grid ----
    $svg .= '<g class="dgao-grid">';
    for ($x = 80; $x < $vbW; $x += 80) {
        $svg .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $vbH . '"/>';
    }
    for ($y = 80; $y < $vbH; $y += 80) {
        $svg .= '<line x1="0" y1="' . $y . '" x2="' . $vbW . '" y2="' . $y . '"/>';
    }
    $svg .= '</g>';

    // ---- glass-bottom normal (small dashed marker for the science feel) ----
    $svg .= sprintf(
        '<line class="dgao-axis" x1="%d" y1="%d" x2="%d" y2="%d"/>',
        $entry['x'], $entry['y'] - 14, $entry['x'], $entry['y'] + 24
    );

    // ---- 1. White incoming beam ----
    // Halo gives the candle-like glow; core is the actual beam line.
    $svg .= sprintf(
        '<line x1="%.2f" y1="%.2f" x2="%d" y2="%d" stroke="rgba(255,255,255,0.14)" stroke-width="6" stroke-linecap="round" filter="url(#%s)"/>',
        $src['x'], $src['y'], $entry['x'], $entry['y'], $glowSoftId
    );
    $svg .= sprintf(
        '<line class="dgao-ray-white" x1="%.2f" y1="%.2f" x2="%d" y2="%d" stroke="rgba(255,255,255,0.96)" stroke-width="2" stroke-linecap="round" filter="url(#%s)"/>',
        $src['x'], $src['y'], $entry['x'], $entry['y'], $glowId
    );
    // Entry hot spot — gives a clear refraction point for the eye
    $svg .= sprintf(
        '<circle cx="%d" cy="%d" r="2.5" fill="#ffffff" filter="url(#%s)"/>',
        $entry['x'], $entry['y'], $glowId
    );

    // ---- 2. Inside-glass spectrum fan (clipped to glass shape) ----
    // Crisp solid strokes — no glow — so the seven colours stay visually
    // distinct even when bunched together.
    $svg .= '<g clip-path="url(#' . $clipId . ')">';
    foreach ($rays as $r) {
        $svg .= sprintf(
            '<line x1="%d" y1="%d" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="2.2" stroke-linecap="round" opacity="0.95"/>',
            $entry['x'], $entry['y'], $r['midX'], $r['midY'], $r['color']
        );
    }
    $svg .= '</g>';

    // ---- 3. Exit rays (parallel rainbow stripes after Snell at top face) ----
    if ($lensRays === null) {
        foreach ($rays as $r) {
            $svg .= sprintf(
                '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%d" stroke="%s" stroke-width="2.4" stroke-linecap="round" opacity="0.95"/>',
                $r['midX'], $r['midY'], $r['endX'], $r['endY'], $r['color']
            );
        }
    } else {
        // Desktop: parallele Rainbow-Stripes bis zur Linsen-Ebene → jeder
        // Strahl bricht durch SEINEN per-Wellenlänge-Brennpunkt und divergiert
        // dahinter (chromatische Aberration). Standardkonvention im Lehrbuch:
        // jeder Strahl bleibt farbig und fadet sauber kurz hinter seinem
        // Fokus aus — KEIN falscher "weißer Brennpunkt" (der wäre nur in
        // Newton-Rekombinationsgeometrie korrekt, nicht hier).
        foreach ($lensRays as $lr) {
            // Pre-lens (parallel)
            $svg .= sprintf(
                '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="2.4" stroke-linecap="round" opacity="0.95"/>',
                $lr['midX'], $lr['midY'], $lr['hitX'], $lr['hitY'], $lr['color']
            );
            // Post-lens: durch Brennpunkt, am Ende sauberer Fade-out.
            $gradId = $variant . '-rg-' . substr(md5($lr['color']), 0, 6);
            $svg .= sprintf(
                '<defs><linearGradient id="%s" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" gradientUnits="userSpaceOnUse">'
                . '<stop offset="0%%"   stop-color="%s" stop-opacity="0.95"/>'
                . '<stop offset="65%%"  stop-color="%s" stop-opacity="0.75"/>'
                . '<stop offset="100%%" stop-color="%s" stop-opacity="0"/>'
                . '</linearGradient></defs>',
                $gradId, $lr['hitX'], $lr['hitY'], $lr['endX'], $lr['endY'],
                $lr['color'], $lr['color'], $lr['color']
            );
            $svg .= sprintf(
                '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="url(#%s)" stroke-width="2.2" stroke-linecap="round"/>',
                $lr['hitX'], $lr['hitY'], $lr['endX'], $lr['endY'], $gradId
            );
        }
    }

    // ---- 4. Lens (desktop only) ----
    if ($lens !== null && $lensRays !== null) {
        $axisDeg = $scene['thetaDeg']; // major axis perpendicular to optical axis ⇒ rotated by θ
        $diam = 130;  $thick = 36;
        // Outer body
        $svg .= sprintf(
            '<ellipse cx="%d" cy="%d" rx="%.1f" ry="%.1f" transform="rotate(%d %d %d)" fill="rgba(255,255,255,0.10)" stroke="rgba(255,255,255,0.55)" stroke-width="1.2"/>',
            $lens['cx'], $lens['cy'], $diam / 2, $thick / 2,
            $axisDeg, $lens['cx'], $lens['cy']
        );
        // Inner specular highlight (gives that glass body feel)
        $svg .= sprintf(
            '<ellipse cx="%d" cy="%d" rx="%.1f" ry="%.1f" transform="rotate(%d %d %d)" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="0.7" opacity="0.7"/>',
            $lens['cx'], $lens['cy'], ($diam / 2) - 4, ($thick / 2) - 2.2,
            $axisDeg, $lens['cx'], $lens['cy']
        );
        // Tiny optical-axis tick marks at the lens centre
        $svg .= sprintf(
            '<circle cx="%d" cy="%d" r="1.4" fill="rgba(255,255,255,0.7)"/>',
            $lens['cx'], $lens['cy']
        );

        // Brennpunkt-Markierungen entfernt — die Strahlen enden sauber an
        // ihrem jeweiligen Fokus, ohne zusätzliche farbige Punkte.

    }

    $svg .= '</svg>';
    return $svg;
}
?>

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

