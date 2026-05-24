<?php

declare(strict_types=1);

function matchRoute(string $uri): array
{
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';

    $staticRoutes = [
        '/'                    => 'home',
        '/archiv'              => 'archiv',
        '/autoren'             => 'autoren',
        '/statistik'           => 'statistik',
        '/suche'               => 'suche',
        '/impressum'           => 'impressum',
        '/datenschutz'         => 'datenschutz',
        '/kontakt'             => 'kontakt',
    ];

    // Alter Link /manuskript-vorlage → /einreichen#vorlage (Beitragseinreichung kombiniert beides)
    if ($path === '/manuskript-vorlage') {
        return ['page' => 'legacy_redirect', 'params' => ['url' => '/einreichen#vorlage']];
    }

    if (isset($staticRoutes[$path])) {
        return ['page' => $staticRoutes[$path], 'params' => []];
    }

    if (preg_match('#^/archiv/(\d+)$#', $path, $m)) {
        return ['page' => 'archiv_detail', 'params' => ['nummer' => (int)$m[1]]];
    }

    if (preg_match('#^/paper/([\w-]+)$#', $path, $m)) {
        return ['page' => 'paper', 'params' => ['id' => $m[1]]];
    }

    if (preg_match('#^/paper/([\w-]+)/template/(latex|word|kit)(?:/(de|en))?$#', $path, $m)) {
        return ['page' => 'paper_template', 'params' => [
            'id'     => $m[1],
            'format' => $m[2],
            'lang'   => $m[3] ?? 'de',
        ]];
    }

    // Blanke Manuskript-Vorlagen — nur verfügbar bei aktiver Vorlagen-Phase
    // /manuskript-vorlage/kit             → komplettes Author-Kit (DE+EN+alle Files) als ZIP
    // /manuskript-vorlage/{latex|word|copyright}/{de|en} → einzelne Files
    if ($path === '/manuskript-vorlage/kit') {
        return ['page' => 'manuskript_vorlage_download', 'params' => [
            'format' => 'kit',
            'lang'   => 'de',
        ]];
    }
    if (preg_match('#^/manuskript-vorlage/(latex|word|copyright)(?:/(de|en))?$#', $path, $m)) {
        return ['page' => 'manuskript_vorlage_download', 'params' => [
            'format' => $m[1],
            'lang'   => $m[2] ?? 'de',
        ]];
    }

    // --- Author-Submission-Workflow ---
    // Beiträge gehen per Mail an sekretariat@dgao.de; Admin sortiert sie über
    // /admin/submissions ein. Kein öffentlicher Web-Upload mehr.
    if ($path === '/einreichen') {
        return ['page' => 'einreichen', 'params' => []];
    }
    // Legacy Magic-Link-URLs → leite auf die Info-Seite weiter, falls noch jemand
    // einen alten Mail-Link anklickt.
    if (preg_match('#^/einreichen/[a-f0-9]{64}(?:/done)?$#', $path)) {
        return ['page' => 'legacy_redirect', 'params' => ['url' => '/einreichen']];
    }

    if (preg_match('#^/autor/(\d+)$#', $path, $m)) {
        return ['page' => 'autor', 'params' => ['id' => (int)$m[1]]];
    }

    if ($path === '/sitemap.xml') {
        return ['page' => 'sitemap', 'params' => []];
    }

    if ($path === '/api/suggest') {
        return ['page' => 'api_suggest', 'params' => []];
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

    // --- Admin-Routen ---
    $adminStaticRoutes = [
        '/admin'             => 'admin/dashboard',
        '/admin/login'       => 'admin/login',
        '/admin/logout'      => 'admin/logout',
        '/admin/tagungen'    => 'admin/tagungen',
        '/admin/papers'      => 'admin/papers',
        '/admin/autoren'     => 'admin/autoren',
        '/admin/keywords'    => 'admin/keywords',
        '/admin/submissions' => 'admin/submissions',
    ];

    // Submission Detail/Action
    if (preg_match('#^/admin/submissions/([a-f0-9]{64})$#', $path, $m)) {
        return ['page' => 'admin/submission_detail', 'params' => ['token' => $m[1]]];
    }
    if (preg_match('#^/admin/submissions/([a-f0-9]{64})/(approve|reject)$#', $path, $m)) {
        return ['page' => 'admin/submission_action', 'params' => ['token' => $m[1], 'action' => $m[2]]];
    }
    if (preg_match('#^/admin/submissions/([a-f0-9]{64})/preview$#', $path, $m)) {
        return ['page' => 'admin/submission_preview', 'params' => ['token' => $m[1]]];
    }

    if (isset($adminStaticRoutes[$path])) {
        return ['page' => $adminStaticRoutes[$path], 'params' => []];
    }

    // Tagungen CRUD
    if ($path === '/admin/tagungen/neu') {
        return ['page' => 'admin/tagung_edit', 'params' => ['nummer' => null]];
    }
    if (preg_match('#^/admin/tagungen/(\d+)/edit$#', $path, $m)) {
        return ['page' => 'admin/tagung_edit', 'params' => ['nummer' => (int)$m[1]]];
    }
    if (preg_match('#^/admin/tagungen/(\d+)/delete$#', $path, $m)) {
        return ['page' => 'admin/tagung_delete', 'params' => ['nummer' => (int)$m[1]]];
    }

    // Papers CRUD
    if ($path === '/admin/papers/neu') {
        return ['page' => 'admin/paper_edit', 'params' => ['id' => null]];
    }
    if (preg_match('#^/admin/papers/([\w-]+)/edit$#', $path, $m)) {
        return ['page' => 'admin/paper_edit', 'params' => ['id' => $m[1]]];
    }
    if (preg_match('#^/admin/papers/([\w-]+)/delete$#', $path, $m)) {
        return ['page' => 'admin/paper_delete', 'params' => ['id' => $m[1]]];
    }

    // Autoren CRUD
    if (preg_match('#^/admin/autoren/(\d+)/edit$#', $path, $m)) {
        return ['page' => 'admin/autor_edit', 'params' => ['id' => (int)$m[1]]];
    }
    if (preg_match('#^/admin/autoren/(\d+)/delete$#', $path, $m)) {
        return ['page' => 'admin/autor_delete', 'params' => ['id' => (int)$m[1]]];
    }
    if ($path === '/admin/autoren/merge') {
        return ['page' => 'admin/autor_merge', 'params' => []];
    }

    // Keywords CRUD
    if (preg_match('#^/admin/keywords/(\d+)/edit$#', $path, $m)) {
        return ['page' => 'admin/keyword_edit', 'params' => ['id' => (int)$m[1]]];
    }
    if (preg_match('#^/admin/keywords/(\d+)/delete$#', $path, $m)) {
        return ['page' => 'admin/keyword_delete', 'params' => ['id' => (int)$m[1]]];
    }
    if ($path === '/admin/keywords/merge') {
        return ['page' => 'admin/keyword_merge', 'params' => []];
    }

    return ['page' => '404', 'params' => []];
}
