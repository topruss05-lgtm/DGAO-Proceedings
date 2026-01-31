<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e(SITE_DESCRIPTION) ?>">

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
<body class="d-flex flex-column min-vh-100 bg-light">

    <header class="bg-white">
        <div class="container container-narrow py-3">
            <a href="/">
                <img src="/assets/images/logo-dgao-proceedings.gif"
                     alt="DGaO-Proceedings" class="header-logo-img">
            </a>
        </div>
        <nav class="navbar navbar-expand-sm bg-white border-top border-bottom p-0">
            <div class="container container-narrow">
                <button class="navbar-toggler py-2" type="button" id="navbarToggler"
                        aria-controls="navbarNav" aria-expanded="false" aria-label="Navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav">
                        <li class="nav-item">
                            <a class="nav-link<?= isActivePage('/archiv') ? ' active fw-semibold' : '' ?>" href="/archiv">Archiv</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= isActivePage('/suche') ? ' active fw-semibold' : '' ?>" href="/suche">Suche</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= isActivePage('/autoren') ? ' active fw-semibold' : '' ?>" href="/autoren">Autoren</a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <?php if (($pageSlug ?? '') === 'home'): ?>
    <div class="hero-banner">
        <div class="container container-narrow text-center">
            <h1 class="hero-title">DGaO-Proceedings</h1>
            <p class="hero-subtitle">ISSN: <?= SITE_ISSN ?></p>
        </div>
    </div>
    <?php endif; ?>

    <main class="flex-grow-1">
        <div class="container container-narrow py-4">
            <?= $pageContent ?>
        </div>
    </main>

    <footer class="bg-white border-top mt-auto">
        <div class="container container-narrow py-3">
            <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between text-muted small">
                <span>DGaO-Proceedings &middot; ISSN <?= SITE_ISSN ?></span>
                <div class="d-flex gap-3 mt-2 mt-sm-0">
                    <a href="/impressum" class="text-muted text-decoration-none">Impressum</a>
                    <a href="/datenschutz" class="text-muted text-decoration-none">Datenschutz</a>
                    <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="text-muted text-decoration-none">DGaO</a>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
