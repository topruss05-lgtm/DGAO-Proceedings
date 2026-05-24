<?php

declare(strict_types=1);

/**
 * Live-Suggest-Endpoint fuer die /suche-Combobox (Google-style Autocomplete).
 *
 * Liefert JSON mit den Top-Treffern in drei Kategorien:
 *   - authors  (Top 5): Substring-Match auf Nachname/Vorname
 *   - papers   (Top 6): Substring-Match auf Titel
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

// --- Authors ---
$stmtA = $db->prepare("
    SELECT a.id, a.vorname, a.nachname, a.affiliation,
           COUNT(DISTINCT pa.paper_id) AS papers
    FROM autoren a
    JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE a.nachname LIKE :q1 COLLATE NOCASE
       OR a.vorname  LIKE :q2 COLLATE NOCASE
    GROUP BY a.id
    HAVING papers > 0
    ORDER BY papers DESC, a.nachname COLLATE NOCASE
    LIMIT 5
");
$stmtA->execute([':q1' => $like, ':q2' => $like]);
$authors = [];
foreach ($stmtA as $row) {
    $name = $strip($row['nachname']);
    if ($row['vorname'] !== '' && $row['vorname'] !== null) {
        $name .= ', ' . $strip($row['vorname']);
    }
    $authors[] = [
        'id'          => (int)$row['id'],
        'name'        => $name,
        'affiliation' => $strip($row['affiliation'] ?? ''),
        'papers'      => (int)$row['papers'],
        'url'         => '/autor/' . (int)$row['id'],
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

echo json_encode(
    ['authors' => $authors, 'papers' => $papers, 'tagungen' => $tagungen],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
