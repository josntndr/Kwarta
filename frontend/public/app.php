<?php

declare(strict_types=1);

$publicDir = __DIR__;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($requestPath, '/');
if ($path === '/') {
    $path = '/';
}

$routes = [
    '/' => 'index.php',
    '/index' => 'index.php',
    '/index.php' => 'index.php',
    '/login' => 'login.php',
    '/login.php' => 'login.php',
    '/register' => 'register.php',
    '/register.php' => 'register.php',
    '/forgot-password' => 'forgot-password.php',
    '/forgot-password.php' => 'forgot-password.php',
    '/reset-password' => 'reset-password.php',
    '/reset-password.php' => 'reset-password.php',
    '/dashboard' => 'dashboard.php',
    '/dashboard.php' => 'dashboard.php',
    '/transactions' => 'transactions.php',
    '/transactions.php' => 'transactions.php',
    '/transaction/new' => 'transaction-form.php',
    '/transaction-form.php' => 'transaction-form.php',
    '/transaction-delete.php' => 'transaction-delete.php',
    '/budgets' => 'budgets.php',
    '/budgets.php' => 'budgets.php',
    '/savings' => 'savings.php',
    '/savings.php' => 'savings.php',
    '/receipt' => 'receipt.php',
    '/receipt.php' => 'receipt.php',
    '/receipt-pdf' => 'receipt-pdf.php',
    '/receipt-pdf.php' => 'receipt-pdf.php',
    '/gamification' => 'gamification.php',
    '/gamification.php' => 'gamification.php',
    '/profile' => 'profile.php',
    '/profile.php' => 'profile.php',
    '/db-check' => 'db-check.php',
    '/db-check.php' => 'db-check.php',
    '/setup-database' => 'setup-database.php',
    '/setup-database.php' => 'setup-database.php',
    '/logout' => 'logout.php',
    '/logout.php' => 'logout.php',
    '/admin/dashboard' => 'admin/dashboard.php',
    '/admin/dashboard.php' => 'admin/dashboard.php',
    '/admin/stats' => 'admin/stats.php',
    '/admin/stats.php' => 'admin/stats.php',
    '/admin/users' => 'admin/users.php',
    '/admin/users.php' => 'admin/users.php',
    '/admin/activity' => 'admin/activity.php',
    '/admin/activity.php' => 'admin/activity.php',
    '/admin/profile' => 'admin/profile.php',
    '/admin/profile.php' => 'admin/profile.php',
];

$target = $routes[$path] ?? null;

if ($target === null && preg_match('/^\/([A-Za-z0-9_\/-]+\.php)$/', $path, $matches)) {
    $candidate = str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
    if ($candidate !== 'app.php' && is_file($publicDir . DIRECTORY_SEPARATOR . $candidate)) {
        $target = $matches[1];
    }
}

if ($target === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$targetPath = realpath($publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target));
$publicPath = realpath($publicDir);

if ($targetPath === false || $publicPath === false || !str_starts_with($targetPath, $publicPath . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$_SERVER['SCRIPT_NAME'] = '/' . str_replace('\\', '/', $target);
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $targetPath;

require $targetPath;
