<?php
// POST-only action controller: approve or reject a merge_review_queue entry.
// Pattern follows submission_action.php.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    return;
}
verifyCsrf();

$db     = getDbAdmin();
$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    setFlash('danger', 'Ungültige Anfrage (id oder action fehlt/ungültig).');
    header('Location: /admin/cleanup/queue');
    exit;
}

// Load the queue entry
$stmt = $db->prepare("SELECT * FROM merge_review_queue WHERE id = ? AND status = 'pending'");
$stmt->execute([$id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    setFlash('warning', "Eintrag #{$id} nicht gefunden oder nicht mehr pending.");
    header('Location: /admin/cleanup/queue');
    exit;
}

if ($action === 'approve') {
    $verdict = json_decode((string) $entry['verdict_json'], true) ?? [];
    $groups  = (array) ($verdict['groups'] ?? []);

    if (empty($groups)) {
        // No explicit groups in verdict — fall back to all candidate ids in cluster
        $cluster    = json_decode((string) $entry['cluster_json'], true) ?? [];
        $candidates = (array) ($cluster['candidates'] ?? []);
        $allIds     = array_values(array_unique(array_map(
            fn($c) => (int) ($c['id'] ?? 0),
            $candidates
        )));
        $allIds = array_filter($allIds, fn($id) => $id > 0);
        if (count($allIds) >= 2) {
            $groups = [$allIds];
        }
    }

    require_once __DIR__ . '/../../../../bin/cleanup_auto_merge.php';

    $mergeCount = 0;
    $errors     = [];
    foreach ($groups as $group) {
        $ids = array_values(array_unique(array_map('intval', (array) $group)));
        $ids = array_filter($ids, fn($i) => $i > 0);
        if (count($ids) < 2) continue;
        try {
            mergeAuthorCluster($db, array_values($ids));
            $mergeCount++;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            error_log("cleanup_action approve error (queue id={$id}): " . $e->getMessage());
        }
    }

    if (!empty($errors)) {
        setFlash('danger', "Merge teilweise fehlgeschlagen (" . count($errors) . " Fehler). Details im Server-Log. Eintrag bleibt pending.");
        header('Location: /admin/cleanup/queue');
        exit;
    }

    $db->prepare("UPDATE merge_review_queue SET status = 'approved' WHERE id = ?")
       ->execute([$id]);

    setFlash('success', "Eintrag #{$id} genehmigt — {$mergeCount} Gruppe(n) zusammengef&uuml;hrt.");

} elseif ($action === 'reject') {
    $db->prepare("UPDATE merge_review_queue SET status = 'rejected' WHERE id = ?")
       ->execute([$id]);

    setFlash('warning', "Eintrag #{$id} abgelehnt.");
}

header('Location: /admin/cleanup/queue');
exit;
