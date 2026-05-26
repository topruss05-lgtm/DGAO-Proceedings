<?php
$adminPageTitle = 'Cleanup-Dashboard';
$db = getDbAdmin();

// Ensure merge_review_queue table exists (created lazily by processSubagentVerdicts)
$db->exec("CREATE TABLE IF NOT EXISTS merge_review_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL DEFAULT 'author' CHECK (kind IN ('author','institution')),
    cluster_json TEXT NOT NULL,
    verdict_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

$cntAutoren        = (int) $db->query('SELECT COUNT(*) FROM autoren')->fetchColumn();
$cntAutorAliase    = (int) $db->query('SELECT COUNT(*) FROM autor_aliase')->fetchColumn();
$cntInstitutionen  = (int) $db->query('SELECT COUNT(*) FROM institutionen')->fetchColumn();
$cntInstitutAliase = (int) $db->query('SELECT COUNT(*) FROM institut_aliase')->fetchColumn();
$cntRedirects      = (int) $db->query('SELECT COUNT(*) FROM autor_id_redirects')->fetchColumn();
$cntPending        = (int) $db->query("SELECT COUNT(*) FROM merge_review_queue WHERE status = 'pending'")->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Cleanup-Dashboard</h1>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Diese &Uuml;bersicht zeigt den aktuellen Stand der Autoren- und Institutions-Konsolidierung.
    Die Review-Queue enth&auml;lt Subagent-Vorschl&auml;ge, die manuelle Best&auml;tigung ben&ouml;tigen.
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntAutoren) ?></div>
            <div class="stat-label">Autoren</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntAutorAliase) ?></div>
            <div class="stat-label">Autor-Aliase</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntInstitutionen) ?></div>
            <div class="stat-label">Institutionen</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntInstitutAliase) ?></div>
            <div class="stat-label">Institut-Aliase</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntRedirects) ?></div>
            <div class="stat-label">ID-Redirects</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($cntPending) ?></div>
            <div class="stat-label">Pending (Queue)</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Aktionen</strong></div>
    <div class="card-body">
        <a href="/admin/cleanup/queue" class="btn btn-primary">
            <i class="bi bi-list-check"></i>
            Review-Queue ansehen
            <?php if ($cntPending > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?= $cntPending ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>
