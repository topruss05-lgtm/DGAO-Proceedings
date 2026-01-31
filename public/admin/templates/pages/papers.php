<?php
$adminPageTitle = 'Papers';
$db = getDb();

// Filter
$tagungFilter = (int)($_GET['tagung'] ?? 0);
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// Tagungen für Dropdown
$tagungen = $db->query('SELECT nummer, jahr, ort FROM tagungen ORDER BY nummer DESC')->fetchAll();

// Standard: neueste Tagung
if ($tagungFilter === 0 && !empty($tagungen)) {
    $tagungFilter = $tagungen[0]['nummer'];
}

// Papers zählen
$countStmt = $db->prepare('SELECT COUNT(*) FROM papers WHERE tagung_nummer = ?');
$countStmt->execute([$tagungFilter]);
$total = (int)$countStmt->fetchColumn();

$pag = paginate($total, $perPage, $currentPage);

// Papers laden
$stmt = $db->prepare('
    SELECT id, code, typ, titel, autoren_text, hat_pdf, datum, zeit
    FROM papers
    WHERE tagung_nummer = ?
    ORDER BY code
    LIMIT ? OFFSET ?
');
$stmt->execute([$tagungFilter, $pag['per_page'], $pag['offset']]);
$papers = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Papers</h1>
    <a href="/admin/papers/neu" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neues Paper
    </a>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="/admin/papers" class="d-flex align-items-center gap-3">
            <label for="tagung" class="form-label mb-0 text-nowrap">Tagung:</label>
            <select name="tagung" id="tagung" class="form-select form-select-sm" style="max-width: 300px;" onchange="this.form.submit()">
                <?php foreach ($tagungen as $t): ?>
                <option value="<?= $t['nummer'] ?>" <?= $t['nummer'] == $tagungFilter ? 'selected' : '' ?>>
                    <?= $t['nummer'] ?>. Tagung (<?= e($t['jahr']) ?>, <?= e($t['ort'] ?? '') ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <span class="text-muted small"><?= $total ?> Papers</span>
        </form>
    </div>
</div>

<!-- Tabelle -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Typ</th>
                    <th>Titel</th>
                    <th>Autoren</th>
                    <th>PDF</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($papers as $p): ?>
                <tr>
                    <td><strong><?= e($p['code']) ?></strong></td>
                    <td><span class="badge bg-secondary"><?= e($p['typ']) ?></span></td>
                    <td title="<?= e($p['titel']) ?>"><?= e(mb_strimwidth($p['titel'], 0, 55, '...')) ?></td>
                    <td title="<?= e($p['autoren_text']) ?>"><?= e(mb_strimwidth($p['autoren_text'], 0, 35, '...')) ?></td>
                    <td><?= $p['hat_pdf'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/papers/<?= e($p['id']) ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/admin/papers/<?= e($p['id']) ?>/delete" class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($papers)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Keine Papers für diese Tagung.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($pag, "/admin/papers?tagung={$tagungFilter}") ?>
