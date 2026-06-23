<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Run this migration from the command line.\n";
    exit;
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection is unavailable.\n");
    exit(1);
}

$result = kwarta_run_migrations($pdo, true);

echo "Kwarta database migration\n";
echo "=========================\n";
echo "Version: {$result['version']}\n";
echo "Applied: " . ($result['applied'] ? 'yes' : 'no') . "\n";
echo "Message: {$result['message']}\n";
