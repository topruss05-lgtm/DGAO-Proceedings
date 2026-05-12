<?php

declare(strict_types=1);

session_start();

function isAdminLoggedIn(): bool
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    // Session-Timeout: 4 Stunden
    if (time() - ($_SESSION['admin_login_time'] ?? 0) > 14400) {
        adminLogout();
        return false;
    }
    return true;
}

function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login');
        exit;
    }
}

function adminLogin(string $user, string $password): bool
{
    if ($user === ADMIN_USER && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        return true;
    }
    return false;
}

function adminLogout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// CSRF-Schutz
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    // Wenn der Request größer war als post_max_size, hat PHP $_POST geleert
    // und das Formular kommt nie an. Das CSRF-Token ist dann auch weg —
    // wir würden „Ungültiges CSRF-Token" zeigen statt der eigentlichen Ursache.
    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax    = parsePhpSize((string)ini_get('post_max_size'));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $contentLen > 0 && $postMax > 0 && $contentLen > $postMax) {
        http_response_code(413);
        die(sprintf(
            'Upload zu groß: %s übermittelt, Server-Limit ist %s. Bitte auf dem Server post_max_size und upload_max_filesize hochsetzen (siehe public/.htaccess bzw. public/.user.ini).',
            formatBytes($contentLen),
            ini_get('post_max_size')
        ));
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(403);
        die('Ungültiges CSRF-Token');
    }
}

function parsePhpSize(string $val): int
{
    $val = trim($val);
    if ($val === '') return 0;
    $unit = strtolower($val[strlen($val) - 1]);
    $num  = (int)$val;
    return match ($unit) {
        'g'     => $num * 1024 * 1024 * 1024,
        'm'     => $num * 1024 * 1024,
        'k'     => $num * 1024,
        default => $num,
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// Flash-Messages
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
