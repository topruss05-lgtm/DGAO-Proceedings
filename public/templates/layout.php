<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e(SITE_DESCRIPTION) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/custom.css" rel="stylesheet">

    <?php foreach ($metaTags as $tag): ?>
    <?= renderMetaTag($tag) ?>
    <?php endforeach; ?>
    <?php if (!empty($canonicalUrl)): ?>
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <?= $extraHead ?? '' ?>

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
</head>
<body>

    <!-- Sidebar -->
    <aside class="site-sidebar" id="siteSidebar">
        <button type="button" class="sidebar-close d-lg-none" id="sidebarClose"
                aria-label="Navigation schliessen">
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="sidebar-logo">
            <a href="/">
                <img src="/assets/images/logo-dgao-proceedings.gif"
                     alt="DGaO-Proceedings" class="sidebar-logo-img">
            </a>
        </div>

        <nav class="sidebar-nav" aria-label="Hauptnavigation">
            <ul class="sidebar-nav-list">
                <li>
                    <a class="sidebar-link<?= isActivePage('/archiv') ? ' active' : '' ?>" href="/archiv">
                        <i class="bi bi-archive me-2"></i>Archiv
                    </a>
                </li>
                <li>
                    <a class="sidebar-link<?= isActivePage('/suche') ? ' active' : '' ?>" href="/suche">
                        <i class="bi bi-search me-2"></i>Suche
                    </a>
                </li>
                <li>
                    <a class="sidebar-link<?= isActivePage('/autoren') ? ' active' : '' ?>" href="/autoren">
                        <i class="bi bi-people me-2"></i>Autoren
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-footer-meta">
                ISSN <?= SITE_ISSN ?>
            </div>
            <div class="sidebar-footer-links">
                <a href="/kontakt">Kontakt</a>
                <a href="/impressum">Impressum</a>
                <a href="/datenschutz">Datenschutz</a>
                <a href="https://www.dgao.de/" target="_blank" rel="noopener">DGaO</a>
            </div>
        </div>
    </aside>

    <!-- Backdrop (mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Content wrapper -->
    <div class="site-content-wrapper d-flex flex-column min-vh-100">

        <!-- Mobile top bar -->
        <header class="mobile-topbar d-lg-none">
            <button type="button" class="mobile-topbar-toggle" id="sidebarToggle"
                    aria-label="Navigation oeffnen" aria-controls="siteSidebar" aria-expanded="false">
                <i class="bi bi-list"></i>
            </button>
            <a href="/" class="mobile-topbar-logo">
                <img src="/assets/images/logo-dgao-proceedings.gif"
                     alt="DGaO-Proceedings" class="mobile-topbar-logo-img">
            </a>
        </header>

        <main class="flex-grow-1">
            <?php if (!empty($fullWidthLayout)): ?>
                <?= $pageContent ?>
            <?php else: ?>
                <div class="container container-narrow py-4">
                    <?= $pageContent ?>
                </div>
            <?php endif; ?>
        </main>

        <footer class="site-footer mt-auto">
            <div class="container container-narrow py-3">
                <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between small">
                    <span>DGaO-Proceedings &middot; ISSN <?= SITE_ISSN ?></span>
                    <div class="d-flex gap-3 mt-2 mt-sm-0">
                        <a href="/kontakt">Kontakt</a>
                        <a href="/impressum">Impressum</a>
                        <a href="/datenschutz">Datenschutz</a>
                        <a href="https://www.dgao.de/" target="_blank" rel="noopener">DGaO</a>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script src="/assets/js/app.js"></script>
</body>
</html>
