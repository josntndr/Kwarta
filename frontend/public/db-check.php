<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT'];
$missing = [];

foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        $missing[] = $key;
    }
}

$isProduction = getenv('VERCEL') === '1' || getenv('APP_ENV') === 'production';
$host = getenv('DB_HOST') ?: '';
$localHostUsed = in_array($host, ['localhost', '127.0.0.1'], true);

echo "Kwarta Database Check\n";
echo "=====================\n";
echo 'Environment: ' . ($isProduction ? 'production' : 'local/development') . "\n";
echo 'DB_HOST set: ' . ($host !== '' ? 'yes' : 'no') . "\n";
echo 'DB_NAME set: ' . ((getenv('DB_NAME') ?: '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_USER set: ' . ((getenv('DB_USER') ?: '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_PASSWORD set: ' . ((getenv('DB_PASSWORD') ?: '') !== '' ? 'yes' : 'no') . "\n";
echo 'DB_PORT: ' . ((string) (getenv('DB_PORT') ?: 'not set')) . "\n";

if ($isProduction && $localHostUsed) {
    echo "Status: failed\n";
    echo "Problem: DB_HOST points to a local-only database. Vercel production needs an online MySQL host.\n";
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
