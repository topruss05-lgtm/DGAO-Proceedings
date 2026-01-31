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
<body class="d-flex flex-column min-vh-100">

    <header class="site-header">
        <div class="container container-narrow py-3">
            <a href="/">
                <img src="/assets/images/logo-dgao-proceedings.gif"
                     alt="DGaO-Proceedings" class="header-logo-img">
            </a>
        </div>
    </header>

    <nav class="navbar navbar-expand-sm site-nav p-0">
        <div class="container container-narrow">
            <button class="navbar-toggler py-2" type="button" id="navbarToggler"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/archiv') ? ' active' : '' ?>" href="/archiv">Archiv</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/suche') ? ' active' : '' ?>" href="/suche">Suche</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/autoren') ? ' active' : '' ?>" href="/autoren">Autoren</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <?php if (($pageSlug ?? '') === 'home'): ?>
    <div class="hero-banner">
        <div class="container container-narrow text-center">
            <h1 class="hero-title">DGaO-Proceedings</h1>
            <p class="hero-subtitle">ISSN: <?= SITE_ISSN ?></p>
            <p class="hero-description">Die Online-Zeitschrift der Deutschen Gesellschaft f&uuml;r angewandte Optik e.V.</p>
        </div>
    </div>
    <?php endif; ?>

    <main class="flex-grow-1">
        <div class="container container-narrow py-4">
            <?= $pageContent ?>
        </div>
    </main>

    <footer class="site-footer mt-auto">
        <div class="container container-narrow py-3">
            <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between small">
                <span>DGaO-Proceedings &middot; ISSN <?= SITE_ISSN ?></span>
                <div class="d-flex gap-3 mt-2 mt-sm-0">
                    <a href="/impressum">Impressum</a>
                    <a href="/datenschutz">Datenschutz</a>
                    <a href="https://www.dgao.de/" target="_blank" rel="noopener">DGaO</a>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
