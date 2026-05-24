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
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/router.php';

$route  = matchRoute($_SERVER['REQUEST_URI']);
$page   = $route['page'];
$params = $route['params'];

$isAdmin = str_starts_with($page, 'admin/');

// Admin-Bereich: Auth + Helpers laden
if ($isAdmin) {
    require_once __DIR__ . '/admin/auth.php';
    require_once __DIR__ . '/admin/helpers.php';

    // Login und Logout ohne Auth-Check
    if ($page === 'admin/login') {
        // Login-POST verarbeiten
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrf();
            if (adminLogin($_POST['user'] ?? '', $_POST['password'] ?? '')) {
                header('Location: /admin');
                exit;
            }
            $loginError = 'Ungültiger Benutzername oder Passwort.';
        }
        ob_start();
        require __DIR__ . '/admin/templates/pages/login.php';
        $pageContent = ob_get_clean();
        $adminPageTitle = 'Login - Admin';
        require __DIR__ . '/admin/templates/layout.php';
        exit;
    }

    if ($page === 'admin/logout') {
        adminLogout();
        header('Location: /admin/login');
        exit;
    }

    // Alle anderen Admin-Seiten: Login erforderlich
    requireAdmin();

    try {
        $adminPageTitle = 'Admin';
        ob_start();

        $templateFile = __DIR__ . '/admin/templates/pages/' . str_replace('admin/', '', $page) . '.php';
        if (file_exists($templateFile)) {
            require $templateFile;
        } else {
            http_response_code(404);
            echo '<h1>Seite nicht gefunden</h1>';
        }

        $pageContent = ob_get_clean();
        require __DIR__ . '/admin/templates/layout.php';
    } catch (Throwable $e) {
        error_log('Admin error: ' . $e);
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        ob_start();
        echo '<div class="alert alert-danger">Ein interner Fehler ist aufgetreten. Details siehe Server-Log.</div>';
        $pageContent = ob_get_clean();
        $adminPageTitle = 'Fehler - Admin';
        require __DIR__ . '/admin/templates/layout.php';
    }
    exit;
}

// --- Frontend (unverändert) ---
try {
    if ($page === 'sitemap') {
        require __DIR__ . '/sitemap.php';
        exit;
    }

    if ($page === 'api_suggest') {
        require __DIR__ . '/suggest.php';
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

    if ($page === 'paper_template') {
        require __DIR__ . '/paper_template_download.php';
        exit;
    }

    if ($page === 'manuskript_vorlage_download') {
        require __DIR__ . '/manuskript_vorlage_download.php';
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

} catch (Throwable $e) {
    error_log('Frontend error: ' . $e);
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
