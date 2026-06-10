<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    $appSecret = getenv('APP_SECRET');
    if ($appSecret) {
        session_name('kwarta_' . substr(hash('sha256', $appSecret), 0, 16));
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function current_user_name(): string
{
    return (string) ($_SESSION['user_name'] ?? 'User');
}

function current_user_role(): string
{
    return (string) ($_SESSION['role'] ?? 'user');
}

function is_admin(): bool
{
    return is_logged_in() && current_user_role() === 'admin';
}

function keep_logged_in(): bool
{
    return !empty($_SESSION['keep_logged_in']);
}

function kwarta_auth_cookie_name(): string
{
    return 'kwarta_auth';
}

function kwarta_auth_secret(): string
{
    $secret = getenv('APP_SECRET') ?: getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: 'kwarta-local-dev-secret';

    return hash('sha256', (string) $secret);
}

function kwarta_base64_url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function kwarta_base64_url_decode(string $value): string
{
    $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 ? strlen($value) + 4 - strlen($value) % 4 : strlen($value), '=', STR_PAD_RIGHT);
    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}

function kwarta_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => (getenv('VERCEL') === '1') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function set_auth_cookie(array $user, bool $keepLoggedIn): void
{
    $isAdmin = ($user['role'] ?? 'user') === 'admin';
    $persistent = !$isAdmin && $keepLoggedIn;
    $expiresAt = time() + ($persistent ? 60 * 60 * 24 * 30 : 1800);
    $cookieExpires = $persistent ? $expiresAt : 0;
    $payload = kwarta_base64_url_encode(json_encode([
        'id' => (int) $user['id'],
        'name' => (string) ($user['name'] ?? 'User'),
        'role' => (string) ($user['role'] ?? 'user'),
        'keep' => $persistent,
        'exp' => $expiresAt,
    ], JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $payload, kwarta_auth_secret());

    setcookie(kwarta_auth_cookie_name(), $payload . '.' . $signature, kwarta_cookie_options($cookieExpires));
}

function clear_auth_cookie(): void
{
    setcookie(kwarta_auth_cookie_name(), '', kwarta_cookie_options(time() - 3600));
}

function restore_auth_cookie(): void
{
    if (is_logged_in() || empty($_COOKIE[kwarta_auth_cookie_name()])) {
        return;
    }

    $parts = explode('.', (string) $_COOKIE[kwarta_auth_cookie_name()], 2);
    if (count($parts) !== 2) {
        clear_auth_cookie();
        return;
    }

    [$payload, $signature] = $parts;
    $expected = hash_hmac('sha256', $payload, kwarta_auth_secret());
    if (!hash_equals($expected, $signature)) {
        clear_auth_cookie();
        return;
    }

    $data = json_decode(kwarta_base64_url_decode($payload), true);
    if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
        clear_auth_cookie();
        return;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        clear_auth_cookie();
        return;
    }

    $stmt = $pdo->prepare('SELECT id, name, role, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) ($data['id'] ?? 0)]);
    $user = $stmt->fetch();

    if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
        clear_auth_cookie();
        return;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['keep_logged_in'] = !empty($data['keep']) && ($_SESSION['role'] ?? 'user') !== 'admin';
    $_SESSION['last_activity_at'] = time();
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_guest(): void
{
    if (is_logged_in()) {
        redirect(is_admin() ? 'admin/dashboard.php' : 'dashboard.php');
    }
}

function require_admin(): void
{
    if (!is_logged_in()) {
        redirect('../login.php');
    }

    if (!is_admin()) {
        flash('error', 'You do not have permission to access the admin area.');
        redirect('../dashboard.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security token. Please go back and try again.');
    }
}

function login_user(array $user, bool $keepLoggedIn = false): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['keep_logged_in'] = ($_SESSION['role'] ?? 'user') === 'admin' ? false : $keepLoggedIn;
    $_SESSION['last_activity_at'] = time();

    $params = session_get_cookie_params();
    $expires = $_SESSION['keep_logged_in'] ? time() + (60 * 60 * 24 * 30) : 0;
    setcookie(session_name(), session_id(), [
        'expires' => $expires,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => (bool) $params['secure'],
        'httponly' => (bool) $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
    set_auth_cookie($user, $keepLoggedIn);
}

function log_admin_activity(PDO $pdo, int $adminId, string $action, ?string $description = null): void
{
    $stmt = $pdo->prepare('
        INSERT INTO admin_activity_logs (admin_id, action, description)
        VALUES (:admin_id, :action, :description)
    ');
    $stmt->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'description' => $description,
    ]);
}

function is_strong_password(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password);
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    clear_auth_cookie();
    session_destroy();
}

function enforce_session_timeout(): void
{
    if (!is_logged_in()) {
        return;
    }

    global $pdo;
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => current_user_id()]);
            $user = $stmt->fetch();

            if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
                logout_user();
                redirect(str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../login.php?inactive=1' : 'login.php?inactive=1');
            }

            $_SESSION['role'] = $user['role'] ?? 'user';
        } catch (PDOException) {
            $_SESSION['role'] = $_SESSION['role'] ?? 'user';
        }
    }

    $timeout = is_admin() ? 1800 : (keep_logged_in() ? 60 * 60 * 24 * 30 : 1800);
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());

    if (time() - $lastActivity > $timeout) {
        logout_user();
        redirect(str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../login.php?expired=1' : 'login.php?expired=1');
    }

    $_SESSION['last_activity_at'] = time();
}

restore_auth_cookie();
enforce_session_timeout();
