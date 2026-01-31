<?php

declare(strict_types=1);

function matchRoute(string $uri): array
{
    $path = parse_url($uri, PHP_URL_PATH);
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';

    $staticRoutes = [
        '/'            => 'home',
        '/archiv'      => 'archiv',
        '/autoren'     => 'autoren',
        '/suche'       => 'suche',
        '/impressum'   => 'impressum',
        '/datenschutz' => 'datenschutz',
    ];

    if (isset($staticRoutes[$path])) {
        return ['page' => $staticRoutes[$path], 'params' => []];
    }

    if (preg_match('#^/archiv/(\d+)$#', $path, $m)) {
        return ['page' => 'archiv_detail', 'params' => ['nummer' => (int)$m[1]]];
    }

    if (preg_match('#^/paper/([\w-]+)$#', $path, $m)) {
        return ['page' => 'paper', 'params' => ['id' => $m[1]]];
    }

    if (preg_match('#^/autor/(\d+)$#', $path, $m)) {
        return ['page' => 'autor', 'params' => ['id' => (int)$m[1]]];
    }

    if ($path === '/sitemap.xml') {
        return ['page' => 'sitemap', 'params' => []];
    }

    if ($path === '/redirect/abstract') {
        return ['page' => 'redirect_abstract', 'params' => []];
    }

    // Legacy redirects (also in .htaccess for Apache)
    if (preg_match('#^/archiv/(\d+)_chronologisch_d\.php$#', $path, $m)) {
        return ['page' => 'legacy_redirect', 'params' => ['url' => '/archiv/' . $m[1]]];
    }

    if (preg_match('#^/pdfs/(\d+)/(.+)$#', $path, $m)) {
        return ['page' => 'legacy_redirect', 'params' => ['url' => '/download/' . $m[1] . '/' . $m[2]]];
    }

    if ($path === '/abstract/abstract_only.php') {
        return ['page' => 'legacy_redirect', 'params' => ['url' => '/redirect/abstract?' . ($_SERVER['QUERY_STRING'] ?? '')]];
    }

    return ['page' => '404', 'params' => []];
}
