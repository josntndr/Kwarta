<?php

require_once __DIR__ . '/../../../backend/includes/auth.php';

require_admin();

$pageTitle = 'Admin Profile';
$adminId = current_user_id();

$stmt = $pdo->prepare('
    SELECT id, name, email, role, status, created_at, last_login_at
    FROM users
    WHERE id = :id
    LIMIT 1
');
$stmt->execute(['id' => $adminId]);
$admin = $stmt->fetch();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id = :id');
$stmt->execute(['id' => $adminId]);
$activityCount = (int) $stmt->fetchColumn();

require_once __DIR__ . '/../../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="profile-header-main">
        <?= mascot_img('rewards', 'profile-header-avatar', 'Kwarta admin avatar') ?>
        <div class="page-header-copy">
            <span class="level-pill mb-2"><i class="bi bi-shield-lock"></i> Admin Profile</span>
            <h1 class="page-header-title"><?= e($admin['name'] ?? current_user_name()) ?></h1>
            <p class="page-header-subtitle"><?= e($admin['email'] ?? '') ?></p>
            <div class="page-header-meta">
                <span class="page-header-chip">Role: <?= e(ucfirst($admin['role'] ?? 'admin')) ?></span>
                <span class="page-header-chip">Status: <?= e(ucfirst($admin['status'] ?? 'active')) ?></span>
                <span class="page-header-chip"><?= $activityCount ?> admin actions</span>
            </div>
        </div>
    </div>
</section>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Account Security</h2>
                <div class="settings-list">
                    <div class="settings-row"><span>Shared login page</span><strong>Enabled</strong></div>
                    <div class="settings-row"><span>Admin route guard</span><strong>Enabled</strong></div>
                    <div class="settings-row"><span>Session timeout</span><strong>30 minutes</strong></div>
                    <div class="settings-row"><span>Admin registration</span><strong>Disabled</strong></div>
                    <div class="settings-row"><span>Role changes</span><strong>Database only</strong></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Admin Details</h2>
                <div class="settings-list">
                    <div class="settings-row"><span>Joined</span><strong><?= e(isset($admin['created_at']) ? date('M d, Y', strtotime($admin['created_at'])) : 'Unknown') ?></strong></div>
                    <div class="settings-row"><span>Last Login</span><strong><?= !empty($admin['last_login_at']) ? e(date('M d, Y g:i A', strtotime($admin['last_login_at']))) : 'Never' ?></strong></div>
                    <div class="settings-row"><span>Financial data access</span><strong>Aggregate only</strong></div>
                    <a class="btn btn-outline-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../backend/includes/footer.php'; ?>
