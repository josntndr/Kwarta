<?php
$pageTitle = $pageTitle ?? 'Kwarta';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAdminSection = str_contains($scriptName, '/admin/');
$assetPrefix = $isAdminSection ? '../' : '';
$navItems = $isAdminSection ? [
    ['href' => 'dashboard.php', 'label' => 'Admin Dashboard', 'icon' => 'dashboard', 'pages' => ['dashboard.php']],
    ['href' => 'stats.php', 'label' => 'User Statistics', 'icon' => 'chart', 'pages' => ['stats.php']],
    ['href' => 'users.php', 'label' => 'User Management', 'icon' => 'avatar', 'pages' => ['users.php']],
    ['href' => 'activity.php', 'label' => 'System Activity', 'icon' => 'receipt', 'pages' => ['activity.php']],
    ['href' => 'profile.php', 'label' => 'Admin Profile', 'icon' => 'game', 'pages' => ['profile.php']],
] : [
    ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'pages' => ['dashboard.php']],
    ['href' => 'transactions.php', 'label' => 'Transactions', 'icon' => 'coin', 'pages' => ['transactions.php', 'transaction-form.php']],
    ['href' => 'budgets.php', 'label' => 'Budget', 'icon' => 'wallet', 'pages' => ['budgets.php']],
    ['href' => 'savings.php', 'label' => 'Savings', 'icon' => 'piggy', 'pages' => ['savings.php']],
    ['href' => 'receipt.php', 'label' => 'Receipt', 'icon' => 'receipt', 'pages' => ['receipt.php']],
    ['href' => 'gamification.php', 'label' => 'Game Hub', 'icon' => 'game', 'pages' => ['gamification.php']],
    ['href' => 'profile.php', 'label' => 'Profile', 'icon' => 'avatar', 'pages' => ['profile.php']],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Kwarta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e($assetPrefix) ?>assets/css/styles.css" rel="stylesheet">
</head>
<body>
<?php if (is_logged_in()): ?>
    <nav class="navbar navbar-expand-xl navbar-dark bg-kwarta sticky-top pixel-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?= $isAdminSection ? 'dashboard.php' : 'dashboard.php' ?>">
                <?= mascot_img('logo', 'app-logo', 'Kwarta mascot logo') ?>
                <span>Kwarta</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav pixel-nav-list me-auto mb-2 mb-xl-0">
                    <?php foreach ($navItems as $item): ?>
                        <?php
                            $isActive = in_array($currentPage, $item['pages'], true);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link pixel-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= e($item['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                                <span class="pixel-nav-icon nav-icon-<?= e($item['icon']) ?>" aria-hidden="true"></span>
                                <span class="nav-label"><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="pixel-nav-actions">
                    <span class="navbar-text player-chip">
                        <span class="pixel-nav-icon nav-icon-avatar" aria-hidden="true"></span>
                        <?= $isAdminSection ? 'Admin' : 'Player' ?> <?= e(current_user_name()) ?>
                    </span>
                    <a class="btn btn-sm btn-light pixel-logout" href="<?= e($assetPrefix) ?>logout.php">
                        <span class="pixel-nav-icon nav-icon-exit" aria-hidden="true"></span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
<?php endif; ?>

<main class="<?= is_logged_in() ? 'container py-4' : e($guestMainClass ?? 'auth-shell') ?>">
    <?php if ($message = flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show pixel-card mascot-alert" role="alert">
            <?= mascot_img('rewards', 'mascot-alert-img', 'Kwarta reward mascot') ?>
            <div><strong>Reward:</strong> <?= e($message) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($message = flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show pixel-card mascot-alert" role="alert">
            <?= mascot_img('guide', 'mascot-alert-img', 'Kwarta guide mascot') ?>
            <div><strong>Alert:</strong> <?= e($message) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
