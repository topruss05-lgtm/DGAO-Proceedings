<?php
declare(strict_types=1);

/**
 * AJAX-Endpoint fuer Tom-Select-Combobox: Institute-Suche.
 * Top-15 Matches als JSON (id, label, sublabel).
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
    SELECT i.id, i.name_de, i.kuerzel, i.ort, i.land,
           (SELECT COUNT(DISTINCT paper_id) FROM paper_autor_institutionen pai WHERE pai.institut_id = i.id) AS n_papers
    FROM institutionen i
    WHERE i.name_de LIKE :q1 COLLATE NOCASE
       OR i.name_en LIKE :q2 COLLATE NOCASE
       OR i.kuerzel LIKE :q3 COLLATE NOCASE
       OR i.universitaet LIKE :q4 COLLATE NOCASE
       OR EXISTS (SELECT 1 FROM institut_aliase ia
                  WHERE ia.institut_id = i.id AND ia.alias_norm LIKE :qnorm)
    ORDER BY n_papers DESC, i.name_de COLLATE NOCASE
    LIMIT 15
");
$stmt->execute([
    ':q1'    => $qLike,
    ':q2'    => $qLike,
    ':q3'    => $qLike,
    ':q4'    => $qLike,
    ':qnorm' => $qNorm,
]);

$out = [];
foreach ($stmt as $row) {
    $label = $row['name_de'];
    if ($row['kuerzel']) $label .= ' (' . $row['kuerzel'] . ')';
    $subParts = array_filter([
        $row['ort'],
        $row['land'],
        $row['n_papers'] . ' Paper' . ($row['n_papers'] == 1 ? '' : 's'),
    ]);
    $out[] = [
        'id'       => (int)$row['id'],
        'label'    => $label,
        'sublabel' => implode(' · ', $subParts),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
