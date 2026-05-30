<?php
$adminPageTitle = 'Affiliationen';
$db = getDb();

$q     = trim((string)($_GET['q'] ?? ''));
$land  = trim((string)($_GET['land'] ?? ''));
$typ   = trim((string)($_GET['typ'] ?? ''));
$sort  = (string)($_GET['sort'] ?? 'papers_desc');
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// WHERE-Klauseln bauen
$where = [];
$bind  = [];
if ($q !== '') {
    $where[] = '(i.name_de LIKE ? OR i.name_en LIKE ? OR i.kuerzel LIKE ? OR i.universitaet LIKE ?
                 OR EXISTS (SELECT 1 FROM institut_aliase ia WHERE ia.institut_id = i.id AND ia.alias_text LIKE ?))';
    $like = '%' . $q . '%';
    $bind = array_merge($bind, [$like, $like, $like, $like, $like]);
}
if ($land !== '') {
    $where[] = 'i.land = ?';
    $bind[] = $land;
}
if ($typ !== '') {
    $where[] = 'i.typ = ?';
    $bind[] = $typ;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Sortierung
$orderBy = match ($sort) {
    'name_az'      => 'i.name_de COLLATE NOCASE ASC',
    'autoren_desc' => 'n_autoren DESC, i.name_de COLLATE NOCASE',
    'papers_desc'  => 'n_papers  DESC, i.name_de COLLATE NOCASE',
    default        => 'n_papers DESC, i.name_de COLLATE NOCASE',
};

// Count
$cStmt = $db->prepare("SELECT COUNT(*) FROM institutionen i $whereSql");
$cStmt->execute($bind);
$total = (int)$cStmt->fetchColumn();
$pag = paginate($total, $perPage, $page);

// Rows mit Aggregaten
$sql = "
    SELECT i.id, i.name_de, i.kuerzel, i.ort, i.land, i.typ, i.parent_id,
           (SELECT COUNT(DISTINCT autor_id) FROM paper_autor_institutionen pai WHERE pai.institut_id = i.id) AS n_autoren,
           (SELECT COUNT(DISTINCT paper_id) FROM paper_autor_institutionen pai WHERE pai.institut_id = i.id) AS n_papers,
           (SELECT COUNT(*) FROM institut_aliase ia WHERE ia.institut_id = i.id) AS n_aliase
    FROM institutionen i
    $whereSql
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";
$bindAll = array_merge($bind, [$pag['per_page'], $pag['offset']]);
$stmt = $db->prepare($sql);
foreach ($bindAll as $i => $v) {
    $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$institute = $stmt->fetchAll();

// Filter-Optionen
$laender = $db->query("SELECT DISTINCT land FROM institutionen WHERE land IS NOT NULL AND land != '' ORDER BY land")->fetchAll(PDO::FETCH_COLUMN);
$typen   = $db->query("SELECT DISTINCT typ FROM institutionen WHERE typ IS NOT NULL AND typ != '' ORDER BY typ")->fetchAll(PDO::FETCH_COLUMN);

$queryString = http_build_query(array_filter([
    'q'    => $q ?: null,
    'land' => $land ?: null,
    'typ'  => $typ ?: null,
    'sort' => $sort !== 'papers_desc' ? $sort : null,
]));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Affiliationen <small class="text-muted">(<?= $total ?>)</small></h1>
    <a href="/admin/institute/neu" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue Institution
    </a>
</div>

<form method="get" action="/admin/institute" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-muted mb-1">Volltext-Suche</label>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Kürzel, Universität, Alias…" class="form-control form-control-sm">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Land</label>
            <select name="land" class="form-select form-select-sm">
                <option value="">— alle —</option>
                <?php foreach ($laender as $l): ?>
                <option value="<?= e($l) ?>" <?= $l === $land ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Typ</label>
            <select name="typ" class="form-select form-select-sm">
                <option value="">— alle —</option>
                <?php foreach ($typen as $tval): ?>
                <option value="<?= e($tval) ?>" <?= $tval === $typ ? 'selected' : '' ?>><?= e($tval) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Sort</label>
            <select name="sort" class="form-select form-select-sm">
                <option value="papers_desc"  <?= $sort === 'papers_desc'  ? 'selected' : '' ?>>Papers (meiste)</option>
                <option value="autoren_desc" <?= $sort === 'autoren_desc' ? 'selected' : '' ?>>Autoren (meiste)</option>
                <option value="name_az"      <?= $sort === 'name_az'      ? 'selected' : '' ?>>Name A-Z</option>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($queryString): ?>
            <a href="/admin/institute" class="btn btn-sm btn-outline-secondary" title="Filter zurücksetzen"><i class="bi bi-x-circle"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Kürzel</th>
                    <th>Ort</th>
                    <th>Land</th>
                    <th>Typ</th>
                    <th class="text-end">Autoren</th>
                    <th class="text-end">Papers</th>
                    <th class="text-end">Aliase</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($institute as $i): ?>
                <tr>
                    <td>
                        <a href="/admin/institute/<?= (int)$i['id'] ?>/edit" class="text-decoration-none">
                            <?= e($i['name_de']) ?>
                        </a>
                        <?php if ($i['parent_id']): ?>
                        <i class="bi bi-arrow-return-right text-muted small ms-1" title="Sub-Institut"></i>
                        <?php endif; ?>
                    </td>
                    <td><code class="small"><?= e($i['kuerzel'] ?? '') ?></code></td>
                    <td class="text-muted small"><?= e($i['ort'] ?? '') ?></td>
                    <td class="text-muted small"><?= e($i['land'] ?? '') ?></td>
                    <td class="text-muted small"><?= e($i['typ'] ?? '') ?></td>
                    <td class="text-end"><?= (int)$i['n_autoren'] ?></td>
                    <td class="text-end"><?= (int)$i['n_papers'] ?></td>
                    <td class="text-end text-muted small"><?= (int)$i['n_aliase'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/institute/<?= (int)$i['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/admin/institute/<?= (int)$i['id'] ?>/delete" class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($institute)): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">
                    <?= $queryString ? 'Keine Treffer.' : 'Keine Institutionen.' ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($pag, "/admin/institute?{$queryString}") ?>
