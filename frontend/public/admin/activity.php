<?php

require_once __DIR__ . '/../../../backend/includes/auth.php';

require_admin();

$pageTitle = 'System Activity';

$stmt = $pdo->query('
    SELECT l.*, u.name AS admin_name, u.email AS admin_email
    FROM admin_activity_logs l
    JOIN users u ON u.id = l.admin_id
    ORDER BY l.created_at DESC
    LIMIT 100
');
$logs = $stmt->fetchAll();

require_once __DIR__ . '/../../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">System Activity</h1>
            <p class="page-header-subtitle">Review admin-side actions such as login events and account status updates.</p>
        </div>
    </div>
</section>

<div class="card content-card">
    <div class="card-body">
        <h2 class="h5 fw-bold mb-3">Admin Activity Logs</h2>
        <div class="d-grid gap-2">
            <?php if (!$logs): ?>
                <div class="empty-state">
                    <?= mascot_img('guide', 'mascot-empty-img', 'Kwarta guide mascot') ?>
                    <div><strong>No admin activity yet.</strong><div class="text-muted-small">Logs will appear when admin actions are performed.</div></div>
                </div>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
                <div class="xp-event">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                        <div>
                            <strong><?= e($log['action']) ?></strong>
                            <div class="text-muted-small"><?= e($log['description'] ?? '') ?></div>
                        </div>
                        <div class="text-md-end text-muted-small">
                            <div><?= e($log['admin_name']) ?></div>
                            <div><?= e(date('M d, Y g:i A', strtotime($log['created_at']))) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../backend/includes/footer.php'; ?>
