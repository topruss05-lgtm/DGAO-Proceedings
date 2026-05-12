<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function formatDate(?string $iso): string
{
    if (!$iso) return '';
    $dt = new DateTime($iso);
    return $dt->format('d.m.Y');
}

function formatDateLong(?string $iso): string
{
    if (!$iso) return '';
    $dt = new DateTime($iso);
    $locale = currentLang() === 'en' ? 'en_US' : 'de_DE';
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE);
    return $formatter->format($dt);
}

function typeBadgeClass(string $typ): string
{
    return match ($typ) {
        'hauptvortrag'  => 'badge-hauptvortrag',
        'sondervortrag' => 'badge-sondervortrag',
        'poster'        => 'badge-poster',
        default         => 'badge-vortrag',
    };
}

function typeLabel(string $typ): string
{
    return match ($typ) {
        'hauptvortrag'  => t('type.hauptvortrag'),
        'sondervortrag' => t('type.sondervortrag'),
        'poster'        => t('type.poster'),
        default         => t('type.vortrag'),
    };
}

function generateBibtex(array $paper): string
{
    $key = 'dgao' . $paper['tagung_nummer'] . '-' . strtolower($paper['code']);
    $year = $paper['datum'] ? substr($paper['datum'], 0, 4) : ($paper['jahr'] ?? '');
    $note = ($paper['typ'] === 'poster' ? t('type.poster') : t('type.vortrag')) . ' ' . $paper['code'];

    return "@inproceedings{{$key},\n"
         . "  title     = {{$paper['titel']}},\n"
         . "  author    = {{$paper['autoren_text']}},\n"
         . "  booktitle = {DGaO-Proceedings, {$paper['tagung_nummer']}. Jahrestagung},\n"
         . "  year      = {{$year}},\n"
         . "  publisher = {" . SITE_PUBLISHER . "},\n"
         . "  issn      = {" . SITE_ISSN . "},\n"
         . "  note      = {{$note}}\n"
         . "}";
}

function sanitizeFtsQuery(string $q): ?string
{
    $q = str_replace(['"', "'"], '', $q);
    $words = array_filter(preg_split('/\s+/', trim($q)), fn($w) => mb_strlen($w) >= 2);
    if (empty($words)) return null;
    return implode(' ', array_map(fn($w) => '"' . $w . '"', $words));
}

function pdfUrl(array $paper): ?string
{
    if (!$paper['hat_pdf'] || !$paper['pdf_dateiname']) return null;
    return PDF_BASE_URL . '/' . $paper['tagung_nummer'] . '/' . $paper['pdf_dateiname'];
}

function fullPdfUrl(array $paper): ?string
{
    $rel = pdfUrl($paper);
    return $rel ? BASE_URL . $rel : null;
}

function isActivePage(string $path): bool
{
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $current = rtrim($current, '/') ?: '/';
    $path = rtrim($path, '/') ?: '/';

    if ($path === '/') return $current === '/';
    return str_starts_with($current, $path);
}

function canonicalUrl(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function renderMetaTag(array $tag): string
{
    $attrs = '';
    if (isset($tag['name'])) {
        $attrs .= ' name="' . e($tag['name']) . '"';
    }
    if (isset($tag['property'])) {
        $attrs .= ' property="' . e($tag['property']) . '"';
    }
    return '<meta' . $attrs . ' content="' . e($tag['content']) . '">';
}

function getAllTagungen(): array
{
    $db = getDb();
    return $db->query('
        SELECT t.nummer, t.jahr, t.ort, t.datum_von, t.datum_bis,
               COUNT(p.id) AS paper_anzahl
        FROM tagungen t
        LEFT JOIN papers p ON p.tagung_nummer = t.nummer
        GROUP BY t.nummer
        ORDER BY t.nummer DESC
    ')->fetchAll();
}

/**
 * Tagung, für die der Admin die Vorlagen-Download-Phase aktiviert hat.
 * null = keine aktive Phase (Downloads sind dann gesperrt).
 */
function getCurrentVorlagenTagung(): ?array
{
    $db = getDb();
    $row = $db->query('
        SELECT nummer, jahr, ort, datum_von, datum_bis
        FROM tagungen
        WHERE vorlage_phase_aktiv = 1
        ORDER BY nummer DESC
        LIMIT 1
    ')->fetch();
    return $row ?: null;
}

function getSiteStats(): array
{
    $db = getDb();
    $papers   = (int) $db->query('SELECT COUNT(*) FROM papers')->fetchColumn();
    $tagungen = (int) $db->query('SELECT COUNT(*) FROM tagungen')->fetchColumn();
    $autoren  = (int) $db->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
    return compact('papers', 'tagungen', 'autoren');
}
