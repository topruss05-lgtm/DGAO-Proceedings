<?php
require_once __DIR__ . '/../../../submissions.php';

$token = $params['token'];
$sub = loadSubmission($token);

if (!$sub || empty($sub['filename_stored'])) {
    http_response_code(404);
    echo 'PDF nicht gefunden';
    return;
}

$path = SUBMISSION_UPLOAD_DIR . '/' . $sub['filename_stored'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'Datei nicht im Pending-Verzeichnis';
    return;
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($sub['filename_original'] ?: 'submission.pdf') . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
