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
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(403);
        die('Ungültiges CSRF-Token');
    }
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
