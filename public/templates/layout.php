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

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
</head>
<body class="d-flex flex-column min-vh-100" data-lang="<?= currentLang() ?>" data-page="<?= e($pageSlug ?? $page ?? '') ?>">

    <a class="visually-hidden-focusable skip-link" href="#main-content"><?= t('nav.skip') ?? 'Zum Inhalt springen' ?></a>

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
            <div class="container container-narrow py-4">
                <?= $pageContent ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer mt-auto">
        <div class="site-footer__inner">
            <div class="site-footer__brand">
                <a href="/" class="site-footer__wordmark"
                   aria-label="DGaO-Proceedings &ndash; Startseite">
                    <span class="mark">DGaO</span><span class="sep">&middot;</span><span class="tail">Proceedings</span>
                </a>
                <div class="site-footer__issn">
                    ISSN <span class="site-footer__issn-num">1614&#8202;&ndash;&#8202;8436</span>
                    <span class="dot">&middot;</span>
                    <span class="site-footer__oa"><?= t('footer.open_access') ?? 'Open Access' ?></span>
                </div>
                <p class="site-footer__publisher">
                    <span class="site-footer__publisher-label"><?= t('footer.publisher_label') ?? 'Herausgeber' ?></span>
                    Deutsche Gesellschaft f&uuml;r angewandte Optik e.V.<br>
                    c/o Hochschule Pforzheim
                </p>
            </div>

            <nav class="site-footer__links" aria-label="<?= t('footer.aria_label') ?? 'Rechtliches und externe Links' ?>">
                <a href="/kontakt"><?= t('footer.kontakt') ?></a>
                <a href="/impressum"><?= t('footer.impressum') ?></a>
                <a href="/datenschutz"><?= t('footer.datenschutz') ?></a>
                <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="external">dgao.de</a>
            </nav>

            <div class="site-footer__bottom">
                <span>&copy; <?= date('Y') ?> Deutsche Gesellschaft f&uuml;r angewandte Optik e.V.</span>
                <span class="since"><?= t('footer.since') ?? 'Proceedings seit 2004' ?></span>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
    <script src="/assets/js/app.js"></script>
</body>
</html>
