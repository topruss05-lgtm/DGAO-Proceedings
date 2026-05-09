<?php
require_once __DIR__ . '/../../../submissions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    return;
}
verifyCsrf();

$token  = $params['token'];
$action = $params['action'];

if ($action === 'approve') {
    $result = approveSubmission($token, 'admin');
    if ($result['ok']) {
        setFlash('success', 'Einreichung freigegeben — Datei nach ' . $result['final_name'] . ' verschoben.');
    } else {
        setFlash('danger', 'Fehler bei Freigabe: ' . $result['error']);
    }
} elseif ($action === 'reject') {
    $note = trim($_POST['note'] ?? '');
    if ($note === '') {
        setFlash('danger', 'Bitte eine Begründung angeben.');
        header('Location: /admin/submissions/' . $token);
        exit;
    }
    $result = rejectSubmission($token, 'admin', $note);
    if ($result['ok']) {
        setFlash('warning', 'Einreichung abgelehnt.');
    } else {
        setFlash('danger', 'Fehler beim Ablehnen: ' . $result['error']);
    }
}

header('Location: /admin/submissions');
exit;
