<?php

declare(strict_types=1);

/**
 * Live-Suggest-Endpoint fuer die /suche-Combobox (Google-style Autocomplete).
 *
 * Liefert JSON mit den Top-Treffern in vier Kategorien:
 *   - authors  (Top 5): Substring-Match auf Nachname/Vorname/Affiliation
 *                       (typischer User-Workflow: "Fraunhofer" findet alle
 *                       Fraunhofer-Autor:innen, "Müller" deren Personen)
 *   - papers   (Top 6): Substring-Match auf Titel oder Hauptautor
 *   - tagungen (Top 3): Substring-Match auf Ort + Jahr
 *   - keywords (Top 4): Substring-Match auf Keyword
 *
 * Aufgerufen ueber /api/suggest?q=... (siehe router.php).
 * Cache-Control: no-store — Suggestions sind benutzerspezifisch + dynamisch.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$q = trim((string)($_GET['q'] ?? ''));

// Empty/zu kurze Query -> leere Antwort (keine Suggestions, wie Google).
if (mb_strlen($q) < 2) {
    echo json_encode(
        ['authors' => [], 'papers' => [], 'tagungen' => [], 'keywords' => []],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$db   = getDb();
$like = '%' . $q . '%';

// Stripping helper for display labels (Sternchen-Markers raus).
$strip = static fn(?string $s): string => trim(preg_replace('/\*+/', '', (string)$s));

// --- Authors via alias_norm (matches Schreibvarianten, ß/ss, diacritics)
//     Plus institution-substring match (kuerzel, name_de, name_en, alias_norm)
//     so "Fraunhofer" finds all Fraunhofer-affiliated authors.
$qNorm   = normalizeForAliasMatch($q);
$lang    = $_SESSION['lang'] ?? 'de';
$instCol = $lang === 'en' ? 'i.name_en' : 'i.name_de';

$stmtA = $db->prepare("
    SELECT a.id, a.vorname, a.nachname,
           (SELECT COALESCE(NULLIF($instCol, ''), i.name_de)
            FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1
            LIMIT 1) AS aff,
           COUNT(DISTINCT pa.paper_id) AS papers
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE EXISTS (
            SELECT 1 FROM autor_aliase al
            WHERE al.autor_id = a.id AND al.alias_norm LIKE :qnorm
          )
       OR EXISTS (
            SELECT 1 FROM autor_institutionen ai
            JOIN institutionen i ON i.id = ai.institut_id
            LEFT JOIN institut_aliase ia ON ia.institut_id = i.id
            WHERE ai.autor_id = a.id
              AND (   i.name_de LIKE :q   COLLATE NOCASE
                   OR i.name_en LIKE :q2  COLLATE NOCASE
                   OR i.kuerzel LIKE :q3  COLLATE NOCASE
                   OR ia.alias_norm LIKE :qnorm2)
          )
    GROUP BY a.id
    HAVING papers > 0
    ORDER BY papers DESC, a.nachname COLLATE NOCASE
    LIMIT 5
");
$stmtA->execute([
    ':qnorm'  => '%' . $qNorm . '%',
    ':qnorm2' => '%' . $qNorm . '%',
    ':q'      => '%' . $q . '%',
    ':q2'     => '%' . $q . '%',
    ':q3'     => '%' . $q . '%',
]);

$authors = [];
foreach ($stmtA as $row) {
    $name = trim((string) $row['nachname']);
    if ($row['vorname'] !== '' && $row['vorname'] !== null) {
        $name .= ', ' . trim((string) $row['vorname']);
    }
    $authors[] = [
        'id'          => (int) $row['id'],
        'name'        => $name,
        'affiliation' => (string) ($row['aff'] ?? ''),
        'papers'      => (int) $row['papers'],
        'url'         => '/autor/' . (int) $row['id'],
    ];
}

// --- Papers (Titel + Hauptautor) ---
$stmtP = $db->prepare("
    SELECT p.id, p.code, p.titel, p.hauptautor, p.tagung_nummer
    FROM papers p
    WHERE p.titel      LIKE :q1 COLLATE NOCASE
       OR p.hauptautor LIKE :q2 COLLATE NOCASE
    ORDER BY p.tagung_nummer DESC, p.code
    LIMIT 6
");
$stmtP->execute([':q1' => $like, ':q2' => $like]);
$papers = [];
foreach ($stmtP as $row) {
    $papers[] = [
        'id'             => (string)$row['id'],
        'code'           => (string)$row['code'],
        'titel'          => (string)$row['titel'],
        'hauptautor'     => $strip($row['hauptautor'] ?? ''),
        'tagung_nummer'  => (int)$row['tagung_nummer'],
        'url'            => '/paper/' . urlencode((string)$row['id']),
    ];
}

// --- Tagungen (Ort + Jahr) ---
$stmtT = $db->prepare("
    SELECT nummer, jahr, ort FROM tagungen
    WHERE ort LIKE :q1 COLLATE NOCASE
       OR CAST(jahr AS TEXT) LIKE :q2
    ORDER BY nummer DESC
    LIMIT 3
");
$stmtT->execute([':q1' => $like, ':q2' => $like]);
$tagungen = [];
foreach ($stmtT as $row) {
    $tagungen[] = [
        'nummer' => (int)$row['nummer'],
        'jahr'   => (int)$row['jahr'],
        'ort'    => $row['ort'] ?: '',
        'url'    => '/archiv/' . (int)$row['nummer'],
    ];
}

// --- Keywords (nur falls Treffer existieren — viele User suchen direkt
//     nach Themen wie "Holografie" oder "Interferometrie").
$stmtK = $db->prepare("
    SELECT k.id, k.keyword, COUNT(DISTINCT pk.paper_id) AS papers
    FROM keywords k
    JOIN paper_keywords pk ON pk.keyword_id = k.id
    WHERE k.keyword LIKE :q COLLATE NOCASE
    GROUP BY k.id
    HAVING papers > 0
    ORDER BY papers DESC, k.keyword COLLATE NOCASE
    LIMIT 4
");
$stmtK->execute([':q' => $like]);
$keywords = [];
foreach ($stmtK as $row) {
    $keywords[] = [
        'id'      => (int)$row['id'],
        'keyword' => (string)$row['keyword'],
        'papers'  => (int)$row['papers'],
        // Keyword klick -> Suche mit q=keyword, beschraenkt auf alle Felder.
        'url'     => '/suche?q=' . rawurlencode((string)$row['keyword']),
    ];
}

echo json_encode(
    ['authors' => $authors, 'papers' => $papers, 'tagungen' => $tagungen, 'keywords' => $keywords],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
