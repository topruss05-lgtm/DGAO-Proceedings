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
// Inline-Anzeige im Admin-Detail-iframe ist UX-Anforderung. PDFs koennen
// eingebettetes JS enthalten — wir sandboxen die Auslieferung daher:
// CSP sandbox verbietet Scripts/Forms/Top-Level-Navigation aus dem PDF.
$origName = basename($sub['filename_original'] ?: 'submission.pdf');
$safeName = preg_replace('/[^A-Za-z0-9._\-]+/', '_', $origName);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
readfile($path);
exit;
