<?php

declare(strict_types=1);

/**
 * Live-Suggest-Endpoint fuer die /suche-Combobox (Google-style Autocomplete).
 *
 * Liefert JSON mit den Top-Treffern in drei Kategorien:
 *   - authors  (Top 5): Substring-Match auf Nachname/Vorname/Affiliation
 *                       (typischer User-Workflow: "Fraunhofer" findet alle
 *                       Fraunhofer-Autor:innen, "Müller" deren Personen)
 *   - papers   (Top 6): Substring-Match auf Titel oder Hauptautor
 *   - tagungen (Top 3): Substring-Match auf Ort + Jahr
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
        ['authors' => [], 'papers' => [], 'tagungen' => []],
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

// Author-Match: Affil aus paper_autor_institutionen (Source of Truth seit v10).
$stmtA = $db->prepare("
    SELECT a.id, a.vorname, a.nachname, a.anzeige_name,
           (SELECT $instCol
              FROM paper_autor_institutionen pai
              JOIN institutionen i ON i.id = pai.institut_id
              JOIN papers p ON p.id = pai.paper_id
              JOIN tagungen t ON t.nummer = p.tagung_nummer
              WHERE pai.autor_id = a.id
              ORDER BY t.jahr DESC LIMIT 1) AS aff,
           COUNT(DISTINCT pa.paper_id) AS papers
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE EXISTS (
            SELECT 1 FROM autor_aliase al
            WHERE al.autor_id = a.id AND al.alias_norm LIKE :qnorm
          )
       OR EXISTS (
            SELECT 1 FROM paper_autor_institutionen pai
            JOIN institutionen i ON i.id = pai.institut_id
            LEFT JOIN institut_aliase ia ON ia.institut_id = i.id
            WHERE pai.autor_id = a.id
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
    $authors[] = [
        'id'          => (int) $row['id'],
        'name'        => formatAutorNameNachLast($row),
        'affiliation' => (string) ($row['aff'] ?? ''),
        'papers'      => (int) $row['papers'],
        'url'         => '/autor/' . (int) $row['id'],
    ];
}

// --- Papers (Titel + Hauptautor aus paper_autoren) ---
$stmtP = $db->prepare("
    SELECT p.id, p.code, p.titel, p.tagung_nummer,
           (SELECT COALESCE(NULLIF(a.anzeige_name, ''), TRIM(a.vorname || ' ' || a.nachname))
              FROM paper_autoren pa JOIN autoren a ON a.id = pa.autor_id
              WHERE pa.paper_id = p.id AND pa.ist_hauptautor = 1
              ORDER BY pa.position LIMIT 1) AS hauptautor
    FROM papers p
    WHERE p.titel LIKE :q1 COLLATE NOCASE
       OR EXISTS (SELECT 1 FROM paper_autoren pa
                  JOIN autoren a ON a.id = pa.autor_id
                  WHERE pa.paper_id = p.id
                    AND (a.vorname || ' ' || a.nachname) LIKE :q2 COLLATE NOCASE)
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

echo json_encode(
    ['authors' => $authors, 'papers' => $papers, 'tagungen' => $tagungen],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
