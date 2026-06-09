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

enforce_session_timeout();
