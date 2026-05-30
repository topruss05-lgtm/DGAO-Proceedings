<?php
declare(strict_types=1);

/**
 * AJAX-Endpoint fuer Tom-Select-Combobox in paper_edit / autor_edit.
 * Liefert Top-15 Autoren-Matches als JSON (id, label, sublabel).
 *
 * Query-Param: q (>= 2 Zeichen). Match ueber Nachname/Vorname/Alias/Anzeige-Name.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDb();
$qLike = '%' . $q . '%';
$qNorm = function_exists('normalizeForAliasMatch')
    ? '%' . normalizeForAliasMatch($q) . '%'
    : '%' . mb_strtolower($q) . '%';

$stmt = $db->prepare("
    SELECT a.id, a.vorname, a.nachname, a.anzeige_name,
           COUNT(DISTINCT pa.paper_id) AS n_papers,
           (SELECT i.name_de
              FROM paper_autor_institutionen pai
              JOIN institutionen i ON i.id = pai.institut_id
              JOIN papers p ON p.id = pai.paper_id
              JOIN tagungen t ON t.nummer = p.tagung_nummer
              WHERE pai.autor_id = a.id
              ORDER BY t.jahr DESC LIMIT 1) AS aff
    FROM autoren a
    LEFT JOIN paper_autoren pa ON pa.autor_id = a.id
    WHERE a.nachname LIKE :q1 COLLATE NOCASE
       OR a.vorname  LIKE :q2 COLLATE NOCASE
       OR a.anzeige_name LIKE :q3 COLLATE NOCASE
       OR EXISTS (SELECT 1 FROM autor_aliase al
                  WHERE al.autor_id = a.id AND al.alias_norm LIKE :qnorm)
    GROUP BY a.id
    ORDER BY n_papers DESC, a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
    LIMIT 15
");
$stmt->execute([
    ':q1'    => $qLike,
    ':q2'    => $qLike,
    ':q3'    => $qLike,
    ':qnorm' => $qNorm,
]);

$out = [];
foreach ($stmt as $row) {
    $label = formatAutorName($row);
    $sub = $row['aff']
        ? $row['aff'] . ' · ' . $row['n_papers'] . ' Paper' . ($row['n_papers'] == 1 ? '' : 's')
        : ($row['n_papers'] . ' Paper' . ($row['n_papers'] == 1 ? '' : 's'));
    $out[] = [
        'id'       => (int)$row['id'],
        'label'    => $label,
        'sublabel' => $sub,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
