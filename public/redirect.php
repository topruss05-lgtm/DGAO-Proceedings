<?php

declare(strict_types=1);

$abstractId = (int)($_GET['id'] ?? 0);

if (!$abstractId) {
    http_response_code(400);
    echo 'Fehlender id-Parameter';
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT id FROM papers WHERE alte_abstract_id = ?');
$stmt->execute([$abstractId]);
$paper = $stmt->fetch();

if (!$paper) {
    http_response_code(404);
    echo 'Paper nicht gefunden';
    exit;
}

header('Location: /paper/' . $paper['id'], true, 301);
exit;
