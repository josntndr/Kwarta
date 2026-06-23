<?php

declare(strict_types=1);

$isProductionRuntime = getenv('VERCEL') === '1' || getenv('APP_ENV') === 'production';
if ($isProductionRuntime) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

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

function kwarta_csrf_cookie_name(): string
{
    return 'kwarta_csrf';
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

function kwarta_create_signed_payload(array $data): string
{
    $payload = kwarta_base64_url_encode(json_encode($data, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $payload, kwarta_auth_secret());

    return $payload . '.' . $signature;
}

function kwarta_read_signed_payload(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$payload, $signature] = $parts;
    $expected = hash_hmac('sha256', $payload, kwarta_auth_secret());

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $data = json_decode(kwarta_base64_url_decode($payload), true);

    return is_array($data) ? $data : null;
}

function set_auth_cookie(array $user, bool $keepLoggedIn): void
{
    $isAdmin = ($user['role'] ?? 'user') === 'admin';
    $persistent = !$isAdmin && $keepLoggedIn;
    $expiresAt = time() + ($persistent ? 60 * 60 * 24 * 30 : 1800);
    $cookieExpires = $persistent ? $expiresAt : 0;
    $token = kwarta_create_signed_payload([
        'id' => (int) $user['id'],
        'name' => (string) ($user['name'] ?? 'User'),
        'role' => (string) ($user['role'] ?? 'user'),
        'keep' => $persistent,
        'exp' => $expiresAt,
    ]);

    setcookie(kwarta_auth_cookie_name(), $token, kwarta_cookie_options($cookieExpires));
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

    $data = kwarta_read_signed_payload((string) $_COOKIE[kwarta_auth_cookie_name()]);
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
    $_SESSION['role_checked_at'] = time();
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
    static $requestToken = null;

    if ($requestToken !== null) {
        return $requestToken;
    }

    $requestToken = kwarta_create_signed_payload([
        'nonce' => bin2hex(random_bytes(32)),
        'exp' => time() + 3600,
    ]);

    setcookie(kwarta_csrf_cookie_name(), $requestToken, kwarta_cookie_options(0));

    return $requestToken;
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $data = is_string($token) ? kwarta_read_signed_payload($token) : null;
    $valid = is_string($token)
        && $token !== ''
        && is_array($data)
        && (int) ($data['exp'] ?? 0) >= time();

    if (!$valid) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'login.php'), PHP_URL_PATH) ?: 'login.php';
        redirect($path . '?security=1');
    }
}

function client_ip_address(): string
{
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwardedFor) && $forwardedFor !== '') {
        $first = trim(explode(',', $forwardedFor)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_attempt_status(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare('
        SELECT attempts, locked_until
        FROM login_attempts
        WHERE email = :email AND ip_address = :ip_address
        LIMIT 1
    ');
    $stmt->execute([
        'email' => strtolower($email),
        'ip_address' => client_ip_address(),
    ]);
    $attempt = $stmt->fetch();

    if (!$attempt || empty($attempt['locked_until'])) {
        return ['locked' => false, 'seconds' => 0, 'attempts' => (int) ($attempt['attempts'] ?? 0)];
    }

    $remaining = strtotime((string) $attempt['locked_until']) - time();

    if ($remaining <= 0) {
        clear_login_attempts($pdo, $email);
        return ['locked' => false, 'seconds' => 0, 'attempts' => 0];
    }

    return ['locked' => true, 'seconds' => $remaining, 'attempts' => (int) $attempt['attempts']];
}

function record_failed_login(PDO $pdo, string $email): int
{
    $email = strtolower($email);
    $ipAddress = client_ip_address();

    $stmt = $pdo->prepare('
        SELECT id, attempts
        FROM login_attempts
        WHERE email = :email AND ip_address = :ip_address
        LIMIT 1
    ');
    $stmt->execute([
        'email' => $email,
        'ip_address' => $ipAddress,
    ]);
    $attempt = $stmt->fetch();
    $nextAttempts = (int) ($attempt['attempts'] ?? 0) + 1;
    $lockedUntil = $nextAttempts >= 5 ? date('Y-m-d H:i:s', time() + 60) : null;

    if ($attempt) {
        $stmt = $pdo->prepare('
            UPDATE login_attempts
            SET attempts = :attempts,
                locked_until = :locked_until,
                last_attempt_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            'attempts' => $nextAttempts,
            'locked_until' => $lockedUntil,
            'id' => (int) $attempt['id'],
        ]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO login_attempts (email, ip_address, attempts, locked_until, last_attempt_at)
            VALUES (:email, :ip_address, :attempts, :locked_until, NOW())
        ');
        $stmt->execute([
            'email' => $email,
            'ip_address' => $ipAddress,
            'attempts' => $nextAttempts,
            'locked_until' => $lockedUntil,
        ]);
    }

    return max(0, 5 - $nextAttempts);
}

function clear_login_attempts(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip_address');
    $stmt->execute([
        'email' => strtolower($email),
        'ip_address' => client_ip_address(),
    ]);
}

function create_password_reset(PDO $pdo, string $email): ?string
{
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);

    if (!$stmt->fetch()) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('
        INSERT INTO password_resets (email, token, expires_at)
        VALUES (:email, :token, :expires_at)
    ');
    $stmt->execute([
        'email' => $email,
        'token' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 900),
    ]);

    return $token;
}

function find_password_reset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM password_resets
        WHERE token = :token
          AND used_at IS NULL
          AND expires_at > NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ');
    $stmt->execute(['token' => hash('sha256', $token)]);
    $reset = $stmt->fetch();

    return $reset ?: null;
}

function login_user(array $user, bool $keepLoggedIn = false): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['keep_logged_in'] = ($_SESSION['role'] ?? 'user') === 'admin' ? false : $keepLoggedIn;
    $_SESSION['last_activity_at'] = time();
    $_SESSION['role_checked_at'] = time();

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
    $shouldRefreshUser = (time() - (int) ($_SESSION['role_checked_at'] ?? 0)) > 300;
    if ($shouldRefreshUser && isset($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => current_user_id()]);
            $user = $stmt->fetch();

            if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
                logout_user();
                redirect(str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../login.php?inactive=1' : 'login.php?inactive=1');
            }

            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['role_checked_at'] = time();
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
