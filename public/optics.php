<?php
declare(strict_types=1);

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
