<?php

require_once __DIR__ . '/../../../backend/includes/auth.php';

require_admin();

$pageTitle = 'Admin Dashboard';
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

$stats = [];
$stats['users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$stats['new_today'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()')->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= :week_start');
$stmt->execute(['week_start' => $weekStart . ' 00:00:00']);
$stats['new_week'] = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= :month_start');
$stmt->execute(['month_start' => $monthStart . ' 00:00:00']);
$stats['new_month'] = (int) $stmt->fetchColumn();

$stats['active_users'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
$stats['inactive_users'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "inactive"')->fetchColumn();
$stats['transactions'] = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$stats['savings_goals'] = (int) $pdo->query('SELECT COUNT(*) FROM savings_goals')->fetchColumn();
$stats['receipts'] = (int) $pdo->query('SELECT COUNT(*) FROM monthly_receipt_logs')->fetchColumn();
$stats['game_users'] = (int) $pdo->query('SELECT COUNT(*) FROM user_game_stats WHERE xp > 0 OR last_activity_date IS NOT NULL')->fetchColumn();

$stmt = $pdo->query('
    SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_key, COUNT(*) AS total
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY month_key
    ORDER BY month_key
');
$registrationRows = $stmt->fetchAll();
$registrationMap = [];
foreach ($registrationRows as $row) {
    $registrationMap[$row['month_key']] = (int) $row['total'];
}

$labels = [];
$registrations = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $labels[] = date('M Y', strtotime($key . '-01'));
    $registrations[] = $registrationMap[$key] ?? 0;
}

$stmt = $pdo->query('
    SELECT l.*, u.name AS admin_name
    FROM admin_activity_logs l
    JOIN users u ON u.id = l.admin_id
    ORDER BY l.created_at DESC
    LIMIT 6
');
$activityRows = $stmt->fetchAll();

require_once __DIR__ . '/../../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-dashboard" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <span class="level-pill mb-2"><i class="bi bi-shield-lock"></i> Secure Admin Side</span>
            <h1 class="page-header-title">Admin Dashboard</h1>
            <p class="page-header-subtitle">Monitor safe website-level statistics without opening private financial records.</p>
        </div>
    </div>
    <div class="page-header-actions">
        <span class="page-header-chip"><?= e(date('F j, Y')) ?></span>
    </div>
</section>

<div class="admin-privacy-note mb-4">
    <strong>Privacy note:</strong> Admin statistics are used only for system monitoring. Personal financial data of users is not accessible from this dashboard.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="game-stat-tile"><span>Total Users</span><strong><?= (int) $stats['users'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>New Today</span><strong><?= (int) $stats['new_today'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>New This Week</span><strong><?= (int) $stats['new_week'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>New This Month</span><strong><?= (int) $stats['new_month'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Active Users</span><strong><?= (int) $stats['active_users'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Inactive Users</span><strong><?= (int) $stats['inactive_users'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Transactions</span><strong><?= (int) $stats['transactions'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Savings Items</span><strong><?= (int) $stats['savings_goals'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Receipts</span><strong><?= (int) $stats['receipts'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Game Hub Users</span><strong><?= (int) $stats['game_users'] ?></strong></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Monthly Registration Chart</h2>
                <div class="chart-box"><canvas id="adminRegistrationChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Basic System Activity</h2>
                <div class="d-grid gap-2">
                    <?php if (!$activityRows): ?>
                        <div class="empty-state">
                            <?= mascot_img('guide', 'mascot-empty-img', 'Kwarta guide mascot') ?>
                            <div><strong>No admin activity yet.</strong><div class="text-muted-small">Actions like activating users will appear here.</div></div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($activityRows as $row): ?>
                        <div class="xp-event">
                            <strong><?= e($row['action']) ?></strong>
                            <div class="text-muted-small"><?= e($row['admin_name']) ?> - <?= e(date('M d, Y g:i A', strtotime($row['created_at']))) ?></div>
                            <?php if (!empty($row['description'])): ?>
                                <div class="text-muted-small"><?= e($row['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('adminRegistrationChart');
    if (!canvas || typeof Chart === 'undefined') return;
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{ label: 'New users', data: <?= json_encode($registrations) ?>, backgroundColor: '#2E8B57' }]
        },
        options: { maintainAspectRatio: false, responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../../backend/includes/footer.php'; ?>
