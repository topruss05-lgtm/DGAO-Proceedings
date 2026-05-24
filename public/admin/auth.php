<?php

declare(strict_types=1);

// Session-Hardening BEVOR session_start. Diese Defaults werden nur fuer den
// Admin-Pfad gesetzt (admin/auth.php wird nur dort geladen). secure=true
// setzt voraus, dass das Admin-UI ausschliesslich ueber HTTPS erreichbar ist;
// lokaler Dev-Server unter http://localhost laesst den Cookie sonst fallen.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    // Auf localhost (Dev) ohne TLS: secure off, sonst landet kein Cookie.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

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

// Brute-Force: pro IP max. ATTEMPT_LIMIT Fehlversuche im ATTEMPT_WINDOW.
const ADMIN_LOGIN_ATTEMPT_LIMIT  = 5;
const ADMIN_LOGIN_ATTEMPT_WINDOW = 900; // 15 Minuten

function adminLogin(string $user, string $password): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (loginAttemptsTooMany($ip)) {
        // Fail-slow: Angreifer bekommt das gleiche Timing wie ein echter
        // password_verify, um Rate-Limit-Probing zu erschweren.
        password_verify($password, ADMIN_PASSWORD_HASH);
        return false;
    }

    // Timing-Schutz: password_verify IMMER aufrufen, auch bei falschem
    // Username. hash_equals fuer konstanten Username-Vergleich.
    $userOk = hash_equals(ADMIN_USER, $user);
    $passOk = password_verify($password, ADMIN_PASSWORD_HASH);

    if ($userOk && $passOk) {
        clearLoginAttempts($ip);
        session_regenerate_id(true);
        $_SESSION['admin_logged_in']  = true;
        $_SESSION['admin_login_time'] = time();
        return true;
    }

    recordLoginAttempt($ip);
    return false;
}

function loginAttemptsTooMany(string $ip): bool
{
    $db = getDbAdmin();
    $cutoff = time() - ADMIN_LOGIN_ATTEMPT_WINDOW;
    // Garbage-Collect alte Eintraege bei Gelegenheit (ein Sweep pro Check).
    $db->prepare('DELETE FROM admin_login_attempts WHERE ts < ?')->execute([$cutoff]);
    $stmt = $db->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip = ? AND ts > ?');
    $stmt->execute([$ip, $cutoff]);
    return ((int) $stmt->fetchColumn()) >= ADMIN_LOGIN_ATTEMPT_LIMIT;
}

function recordLoginAttempt(string $ip): void
{
    $db = getDbAdmin();
    $db->prepare('INSERT INTO admin_login_attempts (ip, ts) VALUES (?, ?)')
       ->execute([$ip, time()]);
}

function clearLoginAttempts(string $ip): void
{
    $db = getDbAdmin();
    $db->prepare('DELETE FROM admin_login_attempts WHERE ip = ?')->execute([$ip]);
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
