<?php
$adminPageTitle = 'Papers';
$db = getDb();

// Filter aus Query-String
$tagungFilter  = (int)($_GET['tagung'] ?? 0);
$sessionFilter = (int)($_GET['session'] ?? 0);
$q             = trim((string)($_GET['q'] ?? ''));
$sort          = (string)($_GET['sort'] ?? 'tagung_neu');
$currentPage   = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 50;

$tagungen = $db->query('SELECT nummer, jahr, ort FROM tagungen ORDER BY nummer DESC')->fetchAll();

// Sessions für gewählte Tagung (für Drilldown)
$sessions = [];
if ($tagungFilter > 0) {
    $sStmt = $db->prepare('SELECT id, titel, saal FROM sessions WHERE tagung_nummer = ? ORDER BY sortorder');
    $sStmt->execute([$tagungFilter]);
    $sessions = $sStmt->fetchAll();
}

// Zentrale Such-Helper aus helpers.php
$pag = paginate(0, $perPage, $currentPage);  // erst dummy, dann update
$result = searchPapers([
    'q'       => $q,
    'tagung'  => $tagungFilter,
    'session' => $sessionFilter,
    'sort'    => $sort,
    'limit'   => $perPage,
    'offset'  => $pag['offset'],
]);
$pag = paginate($result['total'], $perPage, $currentPage);
$papers = $result['rows'];
$total  = $result['total'];

$queryString = http_build_query(array_filter([
    'tagung'  => $tagungFilter ?: null,
    'session' => $sessionFilter ?: null,
    'q'       => $q ?: null,
    'sort'    => $sort !== 'tagung_neu' ? $sort : null,
]));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Papers <small class="text-muted">(<?= $total ?>)</small></h1>
    <a href="/admin/papers/neu" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neues Paper
    </a>
</div>

<form method="get" action="/admin/papers" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Volltext-Suche</label>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Titel, Autor, Abstract…" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Tagung</label>
            <select name="tagung" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">— alle —</option>
                <?php foreach ($tagungen as $t): ?>
                <option value="<?= $t['nummer'] ?>" <?= $t['nummer'] == $tagungFilter ? 'selected' : '' ?>>
                    <?= $t['nummer'] ?>. Tagung (<?= e($t['jahr']) ?>, <?= e($t['ort'] ?? '') ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Session</label>
            <select name="session" class="form-select form-select-sm" <?= empty($sessions) ? 'disabled' : '' ?>>
                <option value="0">— alle Sessions —</option>
                <?php foreach ($sessions as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $s['id'] == $sessionFilter ? 'selected' : '' ?>>
                    <?= e($s['titel']) ?><?= $s['saal'] ? ' (' . e($s['saal']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1">Sort</label>
            <select name="sort" class="form-select form-select-sm">
                <option value="tagung_neu" <?= $sort === 'tagung_neu' ? 'selected' : '' ?>>Tagung (neu)</option>
                <option value="tagung_alt" <?= $sort === 'tagung_alt' ? 'selected' : '' ?>>Tagung (alt)</option>
                <option value="titel_az"   <?= $sort === 'titel_az'   ? 'selected' : '' ?>>Titel A-Z</option>
                <option value="relevanz"   <?= $sort === 'relevanz'   ? 'selected' : '' ?>>Relevanz</option>
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Suchen</button>
            <?php if ($queryString): ?>
                <a href="/admin/papers" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> Filter zurücksetzen</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Tabelle -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Tagung</th>
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
                    <td><span class="text-muted small"><?= e($p['tagung_nummer']) ?></span></td>
                    <td><span class="badge bg-secondary"><?= e($p['typ']) ?></span></td>
                    <td title="<?= e($p['titel']) ?>">
                        <a href="/paper/<?= e($p['id']) ?>" target="_blank" class="text-decoration-none">
                            <?= e(mb_strimwidth($p['titel'], 0, 60, '…')) ?>
                        </a>
                    </td>
                    <td title="<?= e($p['autoren_text']) ?>"><?= e(mb_strimwidth($p['autoren_text'], 0, 35, '…')) ?></td>
                    <td><?= $p['hat_pdf'] ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/papers/<?= e($p['id']) ?>/affils" class="btn btn-sm btn-outline-info" title="Autor-Affiliation-Zuordnung">
                            <i class="bi bi-diagram-3"></i>
                        </a>
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
                <tr><td colspan="7" class="text-center text-muted py-3">
                    <?= $queryString ? 'Keine Treffer.' : 'Keine Papers.' ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($pag, "/admin/papers?{$queryString}") ?>
