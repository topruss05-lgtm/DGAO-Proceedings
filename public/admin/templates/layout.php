<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($adminPageTitle ?? 'Admin') ?> - DGaO-Proceedings</title>
    <meta name="robots" content="noindex, nofollow">

    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/admin/assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin.css') ?>" rel="stylesheet">
    <link rel="icon" type="image/jpeg" href="/assets/images/favicon-64.jpg">
</head>
<body>

<?php if (isAdminLoggedIn()): ?>
<div class="admin-wrapper">
    <nav class="admin-sidebar">
        <div class="sidebar-header">
            <a href="/admin" class="sidebar-brand">DGaO Admin</a>
        </div>
        <ul class="sidebar-nav">
            <li><a href="/admin" class="<?= ($page ?? '') === 'admin/dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard</a></li>
            <?php
            // Pending-Submission-Counter
            $pendingSubs = 0;
            try {
                $db = getDb();
                $pendingSubs = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending' AND filename_stored IS NOT NULL")->fetchColumn();
            } catch (Throwable $e) {
                error_log('admin/layout: pendingSubs count failed: ' . $e);
            }
            ?>
            <li><a href="/admin/submissions" class="<?= str_starts_with($page ?? '', 'admin/submission') ? 'active' : '' ?>">
                <i class="bi bi-envelope-paper"></i> Manuskript-Eingang
                <?php if ($pendingSubs > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $pendingSubs ?></span>
                <?php endif; ?>
            </a></li>
            <li class="sidebar-divider"></li>
            <li><a href="/admin/tagungen" class="<?= str_starts_with($page ?? '', 'admin/tagung') ? 'active' : '' ?>">
                <i class="bi bi-calendar-event"></i> Tagungen</a></li>
            <li><a href="/admin/papers" class="<?= str_starts_with($page ?? '', 'admin/paper') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text"></i> Papers</a></li>
            <li><a href="/admin/autoren" class="<?= str_starts_with($page ?? '', 'admin/autor') ? 'active' : '' ?>">
                <i class="bi bi-people"></i> Autoren</a></li>
            <li><a href="/admin/institute" class="<?= str_starts_with($page ?? '', 'admin/institut') ? 'active' : '' ?>">
                <i class="bi bi-building"></i> Affiliationen</a></li>
            <li><a href="/admin/news" class="<?= str_starts_with($page ?? '', 'admin/news') ? 'active' : '' ?>">
                <i class="bi bi-megaphone"></i> News</a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="/" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Zur Website</a>
            <a href="/admin/logout"><i class="bi bi-box-arrow-left"></i> Logout</a>
        </div>
    </nav>

    <main class="admin-main">
        <?php $flash = getFlash(); if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?= $pageContent ?>
    </main>
</div>

<?php else: ?>
<div class="admin-login-wrapper">
    <?= $pageContent ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous" defer></script>
<script src="/admin/assets/js/admin.js" defer></script>
</body>
</html>
