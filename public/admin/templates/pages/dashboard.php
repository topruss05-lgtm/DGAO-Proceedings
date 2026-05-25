<?php
$adminPageTitle = 'Dashboard';
$db = getDb();
$stats = getSiteStats();

$recentTagungen = $db->query('
    SELECT t.nummer, t.jahr, t.ort, t.datum_von, t.datum_bis,
           COUNT(p.id) as paper_count
    FROM tagungen t
    LEFT JOIN papers p ON p.tagung_nummer = t.nummer
    GROUP BY t.nummer
    ORDER BY t.nummer DESC
    LIMIT 5
')->fetchAll();

$activeNewsCount = countActiveNews();
?>

<h1 class="mb-4">Dashboard</h1>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['tagungen'] ?></div>
            <div class="stat-label">Tagungen</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['papers']) ?></div>
            <div class="stat-label">Papers</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['autoren']) ?></div>
            <div class="stat-label">Autoren</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-value"><?= $db->query('SELECT COUNT(*) FROM keywords')->fetchColumn() ?></div>
            <div class="stat-label">Keywords</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-value"><?= $activeNewsCount ?></div>
            <div class="stat-label"><a href="/admin/news" class="text-decoration-none">News (aktiv)</a></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Letzte Tagungen</strong>
                <a href="/admin/tagungen" class="btn btn-sm btn-outline-secondary">Alle anzeigen</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nr.</th>
                            <th>Jahr</th>
                            <th>Ort</th>
                            <th>Papers</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTagungen as $t): ?>
                        <tr>
                            <td><?= $t['nummer'] ?></td>
                            <td><?= $t['jahr'] ?></td>
                            <td><?= e($t['ort'] ?? '') ?></td>
                            <td><?= $t['paper_count'] ?></td>
                            <td>
                                <a href="/admin/tagungen/<?= $t['nummer'] ?>/edit" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><strong>Schnellaktionen</strong></div>
            <div class="card-body d-grid gap-2">
                <a href="/admin/tagungen/neu" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Neue Tagung &amp; Tagungsband-PDF
                </a>
                <a href="/admin/papers/neu" class="btn btn-outline-secondary">
                    <i class="bi bi-plus-circle"></i> Neues Paper (manuell)
                </a>
                <a href="/admin/news/neu" class="btn btn-outline-secondary">
                    <i class="bi bi-megaphone"></i> Neue News
                </a>
            </div>
        </div>
    </div>
</div>
