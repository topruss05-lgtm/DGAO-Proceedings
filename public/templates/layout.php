<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e(t('site.description')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Outfit:wght@300;400;500;600;700;800&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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

    <link rel="alternate" hreflang="de" href="<?= e(BASE_URL . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?lang=de') ?>">
    <link rel="alternate" hreflang="en" href="<?= e(BASE_URL . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?lang=en') ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e(BASE_URL . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
</head>
<body class="d-flex flex-column min-vh-100" data-lang="<?= currentLang() ?>">

    <nav class="navbar navbar-expand-sm site-nav p-0">
        <div class="container container-narrow">
            <a href="/" class="navbar-brand site-nav-logo">
                <img src="/assets/images/logo-dgao-proceedings.gif"
                     alt="DGaO-Proceedings" class="header-logo-img">
            </a>
            <button class="navbar-toggler py-2" type="button" id="navbarToggler"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="<?= t('nav.aria_label') ?>">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/archiv') ? ' active' : '' ?>" href="/archiv"><?= t('nav.archiv') ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/suche') ? ' active' : '' ?>" href="/suche"><?= t('nav.suche') ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/autoren') ? ' active' : '' ?>" href="/autoren"><?= t('nav.autoren') ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= isActivePage('/statistik') ? ' active' : '' ?>" href="/statistik"><?= t('nav.statistik') ?></a>
                    </li>
                </ul>
                <a href="<?= e(langSwitchUrl()) ?>" class="btn btn-sm lang-toggle ms-auto"
                   title="<?= currentLang() === 'de' ? 'Switch to English' : 'Auf Deutsch wechseln' ?>">
                    <i class="bi bi-globe2"></i> <?= t('lang.switch') ?>
                </a>
            </div>
        </div>
    </nav>

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
                    <a href="/kontakt"><?= t('footer.kontakt') ?></a>
                    <a href="/impressum"><?= t('footer.impressum') ?></a>
                    <a href="/datenschutz"><?= t('footer.datenschutz') ?></a>
                    <a href="https://www.dgao.de/" target="_blank" rel="noopener">DGaO</a>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
