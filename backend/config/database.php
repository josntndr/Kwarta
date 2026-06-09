<?php

declare(strict_types=1);

$pdo = null;
$databaseConnectionError = null;

function kwarta_env(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return (string) $value;
        }
    }

    return $default;
}

function kwarta_is_guest_route(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    $guestScripts = ['index.php', 'login.php', 'register.php'];
    $guestPaths = ['/', '/login', '/register', '/index.php', '/login.php', '/register.php'];

    return in_array($script, $guestScripts, true) || in_array($requestPath, $guestPaths, true);
}

function kwarta_database_unavailable(string $publicMessage, ?Throwable $error = null): void
{
    global $databaseConnectionError;

    $databaseConnectionError = $publicMessage;

    if ($error !== null) {
        error_log('[Kwarta database] ' . $error->getMessage());
    }

    if (!kwarta_is_guest_route() && isset($_SESSION['user_id'])) {
        http_response_code(503);
        exit($publicMessage);
    }
}

$databaseUrl = kwarta_env(['DATABASE_URL', 'MYSQL_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']);
$dbHost = kwarta_env(['DB_HOST', 'MYSQLHOST'], '127.0.0.1');
$dbPort = kwarta_env(['DB_PORT', 'MYSQLPORT'], '3306');
$dbName = kwarta_env(['DB_NAME', 'MYSQLDATABASE'], 'kwarta');
$dbUser = kwarta_env(['DB_USER', 'MYSQLUSER'], 'root');
$dbPass = kwarta_env(['DB_PASSWORD', 'MYSQLPASSWORD', 'DB_PASS'], '');
$isProduction = getenv('VERCEL') === '1' || kwarta_env(['APP_ENV']) === 'production';

if ($databaseUrl !== null) {
    $parts = parse_url($databaseUrl);

    if ($parts !== false) {
        $dbHost = isset($parts['host']) ? (string) $parts['host'] : $dbHost;
        $dbPort = isset($parts['port']) ? (string) $parts['port'] : $dbPort;
        $dbUser = isset($parts['user']) ? rawurldecode((string) $parts['user']) : $dbUser;
        $dbPass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : $dbPass;

        if (isset($parts['path']) && trim((string) $parts['path'], '/') !== '') {
            $dbName = trim((string) $parts['path'], '/');
        }
    }
}

$productionDbHost = kwarta_env(['DB_HOST', 'MYSQLHOST']) !== null || $databaseUrl !== null;

if ($isProduction && !$productionDbHost) {
    kwarta_database_unavailable(
        'Kwarta is online, but the production database is not configured yet. Add DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in Vercel Project Settings.'
    );
} else {
    try {
        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        kwarta_database_unavailable(
            'Kwarta is online, but it cannot connect to the production database yet. Please verify DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in Vercel.',
            $e
        );
    }
}
