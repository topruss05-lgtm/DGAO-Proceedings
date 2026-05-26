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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/custom.css?v=<?= @filemtime(__DIR__ . '/../assets/css/custom.css') ?>" rel="stylesheet">

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

    <link rel="icon" type="image/jpeg" href="/assets/images/favicon-64.jpg?v=<?= @filemtime(__DIR__ . '/../assets/images/favicon-64.jpg') ?>">
    <link rel="apple-touch-icon" href="/assets/images/dgao-logo.jpg?v=<?= @filemtime(__DIR__ . '/../assets/images/dgao-logo.jpg') ?>">
</head>
<body class="d-flex flex-column min-vh-100" data-lang="<?= currentLang() ?>" data-page="<?= e($pageSlug ?? $page ?? '') ?>">

    <a class="visually-hidden-focusable skip-link" href="#main-content"><?= e(t('nav.skip')) ?></a>

    <header class="site-header">
        <!-- Row 1: brand + utility -->
        <div class="site-header__top">
            <div class="site-header__inner">
                <a href="/" class="site-brand" aria-label="DGaO-Proceedings &ndash; Startseite">
                    <span class="site-brand__mark">DGaO</span><span class="site-brand__sep">&middot;</span><span class="site-brand__tail">Proceedings</span>
                    <span class="site-brand__issn">ISSN <?= SITE_ISSN ?></span>
                </a>

                <div class="site-header__util">
                    <a href="<?= e(langSwitchUrl()) ?>" class="site-util-link"
                       title="<?= currentLang() === 'de' ? 'Switch to English' : 'Auf Deutsch wechseln' ?>">
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        <span><?= t('lang.switch') ?></span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Row 2: primary nav -->
        <nav class="site-nav2" aria-label="<?= t('nav.aria_label') ?>">
            <div class="site-header__inner site-nav2__inner">
                <button class="site-nav2__toggle" type="button" id="navbarToggler"
                        aria-controls="navbarNav" aria-expanded="false" aria-label="<?= t('nav.aria_label') ?>">
                    <span class="site-nav2__toggle-icon"></span>
                </button>
                <ul class="site-nav2__list" id="navbarNav">
                    <li><a class="site-nav2__link<?= isActivePage('/archiv') ? ' is-active' : '' ?>" href="/archiv"><?= t('nav.archiv') ?></a></li>
                    <li><a class="site-nav2__link<?= isActivePage('/suche') ? ' is-active' : '' ?>" href="/suche">
                        <i class="bi bi-search" aria-hidden="true"></i> <?= t('nav.suche') ?>
                    </a></li>
                    <li><a class="site-nav2__link<?= isActivePage('/autoren') ? ' is-active' : '' ?>" href="/autoren"><?= t('nav.autoren') ?></a></li>
                    <li><a class="site-nav2__link<?= isActivePage('/statistik') ? ' is-active' : '' ?>" href="/statistik"><?= t('nav.statistik') ?></a></li>
                    <li><a class="site-nav2__link<?= isActivePage('/einreichen') ? ' is-active' : '' ?>" href="/einreichen"><?= t('nav.einreichen') ?></a></li>
                </ul>
            </div>
        </nav>
    </header>

    <main class="flex-grow-1" id="main-content">
        <?php if (!empty($fullWidthLayout)): ?>
            <?= $pageContent ?>
        <?php else: ?>
            <div class="site-content__inner py-4">
                <?= $pageContent ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer mt-auto">
        <div class="site-footer__inner">
            <p class="site-footer__copy">
                &copy; <?= date('Y') ?> Deutsche Gesellschaft für angewandte Optik e.V.
            </p>
            <nav class="site-footer__links" aria-label="<?= e(t('footer.aria_label')) ?>">
                <a href="/kontakt"><?= t('footer.kontakt') ?></a>
                <a href="/impressum"><?= t('footer.impressum') ?></a>
                <a href="/datenschutz"><?= t('footer.datenschutz') ?></a>
                <a href="https://www.dgao.de/" target="_blank" rel="noopener">dgao.de</a>
            </nav>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous" defer></script>
    <script src="/assets/js/app.js" defer></script>
</body>
</html>
