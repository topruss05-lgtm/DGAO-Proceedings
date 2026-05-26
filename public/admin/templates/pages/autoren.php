<?php
$adminPageTitle = 'Autoren';
$db = getDb();

$search = trim($_GET['q'] ?? '');
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;

// Zählen — gleiche Such-Logik wie unten (alias_text + institutionen.name_de)
if (!empty($search)) {
    $countStmt = $db->prepare('
        SELECT COUNT(*) FROM autoren a
        WHERE a.nachname LIKE ? OR a.vorname LIKE ?
           OR EXISTS (SELECT 1 FROM autor_aliase al WHERE al.autor_id = a.id AND al.alias_text LIKE ?)
           OR EXISTS (SELECT 1 FROM autor_institutionen ai2
                      JOIN institutionen i2 ON i2.id = ai2.institut_id
                      WHERE ai2.autor_id = a.id AND i2.name_de LIKE ?)
    ');
    $searchParam = '%' . $search . '%';
    $countStmt->execute([$searchParam, $searchParam, $searchParam, $searchParam]);
} else {
    $countStmt = $db->query('SELECT COUNT(*) FROM autoren');
}
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, $perPage, $currentPage);

// Autoren laden — Affiliation via Institutionen-JOIN (aktuelle ist_aktuell=1),
// Suche matcht auch über autor_aliase + alle verknüpften institutionen.name_de.
$affSubq = "(SELECT i.name_de FROM autor_institutionen ai
             JOIN institutionen i ON i.id = ai.institut_id
             WHERE ai.autor_id = a.id AND ai.ist_aktuell = 1 LIMIT 1) AS affiliation";
if (!empty($search)) {
    $stmt = $db->prepare("
        SELECT a.id, a.vorname, a.nachname, $affSubq,
               COUNT(pa.paper_id) as paper_count
        FROM autoren a
        LEFT JOIN paper_autoren pa ON pa.autor_id = a.id
        WHERE a.nachname LIKE ? OR a.vorname LIKE ?
           OR EXISTS (SELECT 1 FROM autor_aliase al WHERE al.autor_id = a.id AND al.alias_text LIKE ?)
           OR EXISTS (SELECT 1 FROM autor_institutionen ai2
                      JOIN institutionen i2 ON i2.id = ai2.institut_id
                      WHERE ai2.autor_id = a.id AND i2.name_de LIKE ?)
        GROUP BY a.id
        ORDER BY a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $pag['per_page'], $pag['offset']]);
} else {
    $stmt = $db->prepare("
        SELECT a.id, a.vorname, a.nachname, $affSubq,
               COUNT(pa.paper_id) as paper_count
        FROM autoren a
        LEFT JOIN paper_autoren pa ON pa.autor_id = a.id
        GROUP BY a.id
        ORDER BY a.nachname COLLATE NOCASE, a.vorname COLLATE NOCASE
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$pag['per_page'], $pag['offset']]);
}
$autoren = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Autoren</h1>
    <a href="/admin/autoren/merge" class="btn btn-outline-secondary">
        <i class="bi bi-intersect"></i> Autoren zusammenführen
    </a>
</div>

<!-- Suche -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="/admin/autoren" class="d-flex gap-3">
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 300px;"
                   placeholder="Autor suchen..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Suchen</button>
            <?php if (!empty($search)): ?>
            <a href="/admin/autoren" class="btn btn-sm btn-outline-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span class="text-muted small align-self-center"><?= $total ?> Autoren</span>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>Vorname</th>
                    <th>Nachname</th>
                    <th>Affiliation</th>
                    <th>Papers</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($autoren as $a): ?>
                <tr>
                    <td><?= e($a['vorname']) ?></td>
                    <td><strong><?= e($a['nachname']) ?></strong></td>
                    <td class="text-muted small text-truncate" style="max-width: 200px;"><?= e($a['affiliation']) ?></td>
                    <td><?= $a['paper_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/admin/autoren/<?= $a['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/admin/autoren/<?= $a['id'] ?>/delete" class="btn btn-sm btn-outline-danger" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= renderPagination($pag, "/admin/autoren?" . (!empty($search) ? "q=" . urlencode($search) . "&" : '')) ?>
