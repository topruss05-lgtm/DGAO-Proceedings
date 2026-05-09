<?php
require_once __DIR__ . '/../../../submissions.php';

$adminPageTitle = 'Einreichungen';

$db = getDb();
$filter = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'expired', 'all'], true)) {
    $filter = 'pending';
}

$sql = 'SELECT s.*, p.code, p.titel, p.tagung_nummer, p.hauptautor
        FROM submissions s
        JOIN papers p ON p.id = s.paper_id';
$sqlParams = [];
if ($filter !== 'all') {
    $sql .= ' WHERE s.status = ?';
    $sqlParams[] = $filter;
}
$sql .= ' ORDER BY s.uploaded_at DESC NULLS LAST, s.requested_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($sqlParams);
$rows = $stmt->fetchAll();

// Counts pro Status
$counts = $db->query(
    "SELECT status, COUNT(*) as n FROM submissions GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = array_merge(['pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0], $counts);
?>

<h1 class="mb-4"><i class="bi bi-cloud-upload"></i> Manuskript-Einreichungen</h1>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['pending' => 'Ausstehend', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'expired' => 'Abgelaufen', 'all' => 'Alle'] as $key => $label): ?>
    <li class="nav-item">
        <a class="nav-link <?= $filter === $key ? 'active' : '' ?>" href="?filter=<?= $key ?>">
            <?= $label ?>
            <?php if ($key !== 'all'): ?>
                <span class="badge bg-secondary ms-1"><?= (int)$counts[$key] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php if (empty($rows)): ?>
<div class="alert alert-light text-center py-4">
    <i class="bi bi-inbox display-6 text-muted"></i>
    <p class="text-muted mb-0 mt-2">Keine Einreichungen mit Status <strong><?= e($filter) ?></strong>.</p>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Titel</th>
                    <th>E-Mail</th>
                    <th>Datei</th>
                    <th>Eingereicht</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['code']) ?></strong>
                        <small class="text-muted d-block"><?= $r['tagung_nummer'] ?>.</small></td>
                    <td title="<?= e($r['titel']) ?>"><?= e(mb_strimwidth($r['titel'], 0, 60, '…')) ?></td>
                    <td><?= e($r['uploader_email']) ?></td>
                    <td>
                        <?php if (!empty($r['filename_original'])): ?>
                            <i class="bi bi-file-earmark-pdf text-danger"></i>
                            <small><?= e($r['filename_original']) ?></small><br>
                            <small class="text-muted"><?= number_format(($r['file_size'] ?? 0) / 1024, 1, ',', '.') ?> KB</small>
                        <?php else: ?>
                            <small class="text-muted">— noch nicht hochgeladen —</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= e($r['uploaded_at'] ?: $r['requested_at']) ?></small>
                    </td>
                    <td>
                        <?php
                        $badge = match($r['status']) {
                            'pending'  => 'bg-warning text-dark',
                            'approved' => 'bg-success',
                            'rejected' => 'bg-danger',
                            'expired'  => 'bg-secondary',
                            default    => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $badge ?>"><?= e($r['status']) ?></span>
                    </td>
                    <td>
                        <?php if (!empty($r['filename_stored'])): ?>
                        <a href="/admin/submissions/<?= e($r['token']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> Prüfen
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
