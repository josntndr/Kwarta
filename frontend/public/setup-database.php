<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = kwarta_env(['SETUP_TOKEN', 'APP_SECRET']);
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (!$expectedToken || !is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo "Forbidden. Add SETUP_TOKEN in Vercel, then open setup-database.php?token=YOUR_TOKEN to run migrations.\n";
    exit;
}

if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo "Database connection is unavailable.\n";
    exit;
}

try {
    $result = kwarta_run_migrations($pdo, true);

    echo "Kwarta database setup\n";
    echo "=====================\n";
    echo "Version: {$result['version']}\n";
    echo "Applied: " . ($result['applied'] ? 'yes' : 'no') . "\n";
    echo "Message: {$result['message']}\n";
} catch (Throwable $error) {
    error_log('[Kwarta setup database] ' . $error->getMessage());
    http_response_code(500);
    echo "Database setup failed. Check Vercel logs for details.\n";
}
