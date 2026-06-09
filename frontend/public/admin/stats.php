<?php

require_once __DIR__ . '/../../../backend/includes/auth.php';

require_admin();

$pageTitle = 'User Statistics';

$stmt = $pdo->query('
    SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_key, COUNT(*) AS total
    FROM users
    GROUP BY month_key
    ORDER BY month_key DESC
    LIMIT 12
');
$monthlyUsers = array_reverse($stmt->fetchAll());

$labels = array_map(static fn (array $row): string => date('M Y', strtotime($row['month_key'] . '-01')), $monthlyUsers);
$values = array_map(static fn (array $row): int => (int) $row['total'], $monthlyUsers);

$roleCounts = $pdo->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role ORDER BY role')->fetchAll();
$statusCounts = $pdo->query('SELECT status, COUNT(*) AS total FROM users GROUP BY status ORDER BY status')->fetchAll();

require_once __DIR__ . '/../../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-chart" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">User Statistics</h1>
            <p class="page-header-subtitle">Review registration growth, account roles, and account status without opening user finances.</p>
        </div>
    </div>
</section>

<div class="admin-privacy-note mb-4">
    <strong>Privacy note:</strong> This page contains account-level and aggregate statistics only.
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">User Growth</h2>
                <div class="chart-box"><canvas id="userGrowthChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card mb-4">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Roles</h2>
                <div class="settings-list">
                    <?php foreach ($roleCounts as $row): ?>
                        <div class="settings-row"><span><?= e(ucfirst($row['role'])) ?></span><strong><?= (int) $row['total'] ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card content-card">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Account Status</h2>
                <div class="settings-list">
                    <?php foreach ($statusCounts as $row): ?>
                        <div class="settings-row"><span><?= e(ucfirst($row['status'])) ?></span><strong><?= (int) $row['total'] ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('userGrowthChart');
    if (!canvas || typeof Chart === 'undefined') return;
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                label: 'Registered users',
                data: <?= json_encode($values) ?>,
                borderColor: '#2E8B57',
                backgroundColor: 'rgba(46,139,87,0.18)',
                tension: 0.25,
                fill: true
            }]
        },
        options: { maintainAspectRatio: false, responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
});
</script>

<?php require_once __DIR__ . '/../../../backend/includes/footer.php'; ?>
