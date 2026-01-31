<?php
$adminPageTitle = 'Keywords';
$db = getDb();

$search = trim($_GET['q'] ?? '');
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;

if (!empty($search)) {
    $countStmt = $db->prepare('SELECT COUNT(*) FROM keywords WHERE keyword LIKE ?');
    $searchParam = '%' . $search . '%';
    $countStmt->execute([$searchParam]);
} else {
    $countStmt = $db->query('SELECT COUNT(*) FROM keywords');
}
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, $perPage, $currentPage);

if (!empty($search)) {
    $stmt = $db->prepare('
        SELECT k.id, k.keyword,
               COUNT(pk.paper_id) as paper_count
        FROM keywords k
        LEFT JOIN paper_keywords pk ON pk.keyword_id = k.id
        WHERE k.keyword LIKE ?
        GROUP BY k.id
        ORDER BY k.keyword
        LIMIT ? OFFSET ?
    ');
    $stmt->execute([$searchParam, $pag['per_page'], $pag['offset']]);
} else {
    $stmt = $db->prepare('
        SELECT k.id, k.keyword,
               COUNT(pk.paper_id) as paper_count
        FROM keywords k
        LEFT JOIN paper_keywords pk ON pk.keyword_id = k.id
        GROUP BY k.id
        ORDER BY k.keyword
        LIMIT ? OFFSET ?
    ');
    $stmt->execute([$pag['per_page'], $pag['offset']]);
}
$keywords = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Keywords</h1>
    <a href="/admin/keywords/merge" class="btn btn-outline-secondary">
        <i class="bi bi-intersect"></i> Keywords zusammenführen
    </a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="/admin/keywords" class="d-flex gap-3">
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 300px;"
                   placeholder="Keyword suchen..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Suchen</button>
            <?php if (!empty($search)): ?>
            <a href="/admin/keywords" class="btn btn-sm btn-outline-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span class="text-muted small align-self-center"><?= $total ?> Keywords</span>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Keyword</th>
                    <th>Papers</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $kw): ?>
                <tr>
                    <td class="text-muted"><?= $kw['id'] ?></td>
                    <td><?= e($kw['keyword']) ?></td>
                    <td><?= $kw['paper_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/keywords/<?= $kw['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/admin/keywords/<?= $kw['id'] ?>/delete" class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($pag, "/admin/keywords?" . (!empty($search) ? "q=" . urlencode($search) . "&" : '')) ?>
