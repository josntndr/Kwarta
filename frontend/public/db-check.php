<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'];
$missing = [];

foreach ($required as $key) {
    $aliases = match ($key) {
        'DB_HOST' => ['DB_HOST', 'MYSQLHOST'],
        'DB_NAME' => ['DB_NAME', 'MYSQLDATABASE'],
        'DB_USER' => ['DB_USER', 'MYSQLUSER'],
        'DB_PASSWORD' => ['DB_PASSWORD', 'MYSQLPASSWORD', 'DB_PASS'],
        'DB_PORT' => ['DB_PORT', 'MYSQLPORT'],
        default => [$key],
    };
    $value = kwarta_env($aliases);
    if ($value === false || trim((string) $value) === '') {
        $missing[] = $key;
    }
}

$isProduction = getenv('VERCEL') === '1' || getenv('APP_ENV') === 'production';
$host = (string) ($dbHost ?? '');
$port = (string) ($dbPort ?? '');
$localHostUsed = in_array($host, ['localhost', '127.0.0.1'], true);
$privateRailwayHostUsed = str_contains($host, '.railway.internal') || str_ends_with($host, 'railway.internal');
$hostHasCopiedLabel = str_contains($host, 'Value:') || str_contains($host, 'Host:') || str_contains($host, 'mysql://') || str_contains($host, ' ');
$portIsInvalid = $port === '' || !ctype_digit($port);

echo "Kwarta Database Check\n";
echo "=====================\n";
echo 'Environment: ' . ($isProduction ? 'production' : 'local/development') . "\n";
echo 'DB_HOST set: ' . ($host !== '' ? 'yes' : 'no') . "\n";
echo 'DB_NAME set: ' . (($dbName ?? '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_USER set: ' . (($dbUser ?? '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_PASSWORD set: ' . (($dbPass ?? '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_PORT set: ' . ($port !== '' ? 'yes' : 'no') . "\n";
echo 'DB_PORT format: ' . (!$portIsInvalid ? 'valid number' : 'invalid, use numbers only') . "\n";

if ($isProduction && $localHostUsed) {
    echo "Status: failed\n";
    echo "Problem: DB_HOST points to a local-only database. Vercel production needs an online MySQL host.\n";
    exit;
}

if ($isProduction && $hostHasCopiedLabel) {
    echo "Status: failed\n";
    echo "Problem: DB_HOST format looks invalid. Paste only the hostname, without labels, spaces, mysql://, username, password, or port.\n";
    echo "Example: roundhouse.proxy.rlwy.net\n";
    exit;
}

if ($isProduction && $privateRailwayHostUsed) {
    echo "Status: failed\n";
    echo "Problem: DB_HOST is a private Railway hostname. Vercel cannot reach *.railway.internal hosts.\n";
    echo "Fix: Use the public Railway TCP proxy host from the Public Networking / Connect tab, usually ending in .proxy.rlwy.net.\n";
    exit;
}

if ($portIsInvalid) {
    echo "Status: failed\n";
    echo "Problem: DB_PORT must be only the port number.\n";
    echo "Example: 51300\n";
    exit;
}

if ($missing) {
    echo "Status: failed\n";
    echo 'Missing variables: ' . implode(', ', $missing) . "\n";
    exit;
}

if (!$pdo instanceof PDO) {
    echo "Status: failed\n";
    echo 'Connection: unavailable' . "\n";
    echo 'Message: ' . ($databaseConnectionError ?: 'Database connection could not be created.') . "\n";

    try {
        new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . (string) ($dbName ?? '') . ';charset=utf8mb4',
            (string) ($dbUser ?? ''),
            (string) ($dbPass ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $error) {
        error_log('[Kwarta db-check detail] ' . $error->getMessage());
        $message = $error->getMessage();

        if (str_contains($message, 'php_network_getaddresses') || str_contains($message, 'Name or service not known')) {
            echo "Likely cause: DB_HOST cannot be resolved from Vercel. Use the public database host, not a private/internal host.\n";
        } elseif (str_contains($message, 'Connection timed out') || str_contains($message, 'No route to host')) {
            echo "Likely cause: DB_HOST or DB_PORT is not publicly reachable from Vercel.\n";
        } elseif (str_contains($message, 'Access denied')) {
            echo "Likely cause: DB_USER or DB_PASSWORD is incorrect.\n";
        } elseif (str_contains($message, 'Unknown database')) {
            echo "Likely cause: DB_NAME is incorrect or the database was not created.\n";
        } else {
            echo "Likely cause: Database host/port/credentials are not accepted by the provider.\n";
        }
    }

    exit;
}

try {
    $pdo->query('SELECT 1')->fetchColumn();
    echo "Connection: success\n";

    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $usersTableExists = (bool) $stmt->fetchColumn();

    echo 'Users table: ' . ($usersTableExists ? 'found' : 'missing') . "\n";
    echo 'Status: ' . ($usersTableExists ? 'ready' : 'database schema missing') . "\n";
} catch (Throwable $error) {
    error_log('[Kwarta db-check] ' . $error->getMessage());
    echo "Status: failed\n";
    echo "Connection: failed during verification\n";
    echo "Message: Check Vercel database variables and confirm database/kwarta.sql was imported.\n";
}
