<?php

declare(strict_types=1);

// PHP built-in server: serve static files that exist on disk (needed for symlinks)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'pdf'  => 'application/pdf',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'ico'  => 'image/x-icon',
            'txt'  => 'text/plain',
            'xml'  => 'application/xml',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
        }
        readfile($file);
        return;
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/router.php';

$route  = matchRoute($_SERVER['REQUEST_URI']);
$page   = $route['page'];
$params = $route['params'];

try {
    if ($page === 'sitemap') {
        require __DIR__ . '/sitemap.php';
        exit;
    }

    if ($page === 'redirect_abstract') {
        require __DIR__ . '/redirect.php';
        exit;
    }

    if ($page === 'legacy_redirect') {
        header('Location: ' . $params['url'], true, 301);
        exit;
    }

    $pageTitle    = SITE_NAME;
    $pageSlug     = $page;
    $metaTags     = [];
    $extraHead    = '';
    $canonicalUrl = '';

    ob_start();

    if ($page === '404') {
        http_response_code(404);
        require __DIR__ . '/templates/pages/404.php';
    } else {
        $templateFile = __DIR__ . '/templates/pages/' . $page . '.php';
        if (file_exists($templateFile)) {
            require $templateFile;
        } else {
            http_response_code(404);
            require __DIR__ . '/templates/pages/404.php';
        }
    }

    $pageContent = ob_get_clean();
    require __DIR__ . '/templates/layout.php';

} catch (Exception $e) {
    if (ob_get_level() > 0) ob_end_clean();

    http_response_code(500);
    ob_start();
    require __DIR__ . '/templates/pages/500.php';
    $pageContent = ob_get_clean();
    $pageTitle    = 'Fehler - ' . SITE_NAME;
    $metaTags     = [];
    $extraHead    = '';
    $canonicalUrl = '';
    require __DIR__ . '/templates/layout.php';
}
