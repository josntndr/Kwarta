<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Dashboard';
$userId = current_user_id();
$month = $_GET['month'] ?? current_month();
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = current_month();
}

$stmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS total_expenses
    FROM transactions
    WHERE user_id = :user_id
');
$stmt->execute(['user_id' => $userId]);
$totals = $stmt->fetch();
$totalIncome = (float) $totals['total_income'];
$totalExpenses = (float) $totals['total_expenses'];
$balance = $totalIncome - $totalExpenses;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_current_money') {
    verify_csrf();
    $currentMoneyInput = validate_non_negative_amount($_POST['current_money'] ?? '');

    if ($currentMoneyInput === null) {
        flash('error', 'Current Money must be zero or greater.');
    } else {
        update_current_money($pdo, $userId, $currentMoneyInput);
        award_xp($pdo, $userId, 'wallet_update', 'Updated Current Money', 5);
        flash('success', 'Current Money updated. +5 XP');
    }

    redirect('dashboard.php?month=' . urlencode($month));
}

$currentMoney = get_current_money($pdo, $userId, $balance);

$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE user_id = :user_id
      AND type = "expense"
      AND DATE_FORMAT(transaction_date, "%Y-%m") = :month
');
$stmt->execute(['user_id' => $userId, 'month' => $month]);
$monthlySpending = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) AS monthly_income,
        COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS monthly_expenses
    FROM transactions
    WHERE user_id = :user_id
      AND DATE_FORMAT(transaction_date, "%Y-%m") = :month
');
$stmt->execute(['user_id' => $userId, 'month' => $month]);
$monthlyTotals = $stmt->fetch();
$monthlyIncome = (float) $monthlyTotals['monthly_income'];
$monthlyExpenses = (float) $monthlyTotals['monthly_expenses'];
$monthlySavings = $monthlyIncome - $monthlyExpenses;

$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(b.amount), 0) AS budget_total,
           COALESCE(SUM(spent.spent_amount), 0) AS spent_total
    FROM budgets b
    LEFT JOIN (
        SELECT category_id, SUM(amount) AS spent_amount
        FROM transactions
        WHERE user_id = :spent_user_id
          AND type = "expense"
          AND DATE_FORMAT(transaction_date, "%Y-%m") = :spent_month
        GROUP BY category_id
    ) spent ON spent.category_id = b.category_id
    WHERE b.user_id = :budget_user_id
      AND b.month = :budget_month
');
$stmt->execute([
    'spent_user_id' => $userId,
    'spent_month' => $month,
    'budget_user_id' => $userId,
    'budget_month' => $month,
]);
$budgetSummary = $stmt->fetch();
$budgetTotal = (float) $budgetSummary['budget_total'];
$budgetSpent = (float) $budgetSummary['spent_total'];
$budgetProgress = $budgetTotal > 0 ? min(100, round(($budgetSpent / $budgetTotal) * 100)) : 0;

$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(target_amount), 0) AS target_total,
           COALESCE(SUM(saved_amount), 0) AS saved_total
    FROM savings_goals
    WHERE user_id = :user_id
');
$stmt->execute(['user_id' => $userId]);
$savingsSummary = $stmt->fetch();
$savingsTarget = (float) $savingsSummary['target_total'];
$savingsSaved = (float) $savingsSummary['saved_total'];
$savingsProgress = $savingsTarget > 0 ? min(100, round(($savingsSaved / $savingsTarget) * 100)) : 0;

$stmt = $pdo->prepare('
    SELECT t.*, c.name AS category_name
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 6
');
$stmt->execute(['user_id' => $userId]);
$recentTransactions = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT c.name, COALESCE(SUM(t.amount), 0) AS total
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND t.type = "expense"
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
    GROUP BY c.id, c.name
    ORDER BY total DESC
');
$stmt->execute(['user_id' => $userId, 'month' => $month]);
$categoryRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT DATE_FORMAT(transaction_date, "%Y-%m") AS month_key,
           COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) AS income,
           COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expenses
    FROM transactions
    WHERE user_id = :user_id
      AND DATE_FORMAT(transaction_date, "%Y-%m") >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), "%Y-%m")
    GROUP BY month_key
    ORDER BY month_key
');
$stmt->execute(['user_id' => $userId]);
$monthRows = $stmt->fetchAll();
$monthMap = [];
foreach ($monthRows as $row) {
    $monthMap[$row['month_key']] = $row;
}

$labels = [];
$incomeSeries = [];
$expenseSeries = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $labels[] = date('M Y', strtotime($key . '-01'));
    $incomeSeries[] = isset($monthMap[$key]) ? (float) $monthMap[$key]['income'] : 0;
    $expenseSeries[] = isset($monthMap[$key]) ? (float) $monthMap[$key]['expenses'] : 0;
}

$categoryLabels = array_column($categoryRows, 'name');
$categoryValues = array_map('floatval', array_column($categoryRows, 'total'));
$game = gamification_profile($pdo, $userId);
$stats = $game['stats'];
$unlockedAchievements = array_filter($game['achievements'], static fn(array $achievement): bool => $achievement['unlocked_at'] !== null);
$featuredAchievements = array_slice($game['achievements'], 0, 3);
$featuredChallenges = array_slice($game['challenges'], 0, 3);

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-dashboard" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <span class="level-pill mb-2"><i class="bi bi-stars"></i> Level <?= (int) $stats['level'] ?>: <?= e(level_title((int) $stats['level'])) ?></span>
            <h1 class="page-header-title">Dashboard</h1>
            <p class="page-header-subtitle">Quick view of your balance, progress, and financial activity for <?= e(month_label($month)) ?>.</p>
            <div class="page-header-meta">
                <span class="page-header-chip"><?= (int) $stats['xp'] ?> XP total</span>
                <span class="page-header-chip"><?= (int) $stats['level_progress'] ?>% to next level</span>
                <span class="page-header-chip"><?= (int) $stats['current_streak'] ?> day streak</span>
                <span class="page-header-chip"><?= count($unlockedAchievements) ?> badges unlocked</span>
            </div>
        </div>
    </div>
    <div class="page-header-actions">
        <div class="page-header-progress">
            <div class="d-flex justify-content-between text-muted-small mb-1">
                <span>Level Progress</span>
                <strong><?= (int) $stats['level_progress'] ?>%</strong>
            </div>
            <div class="progress" aria-label="Level progress">
                <div class="progress-bar bg-success" style="width: <?= (int) $stats['level_progress'] ?>%"></div>
            </div>
        </div>
        <a class="btn btn-success" href="transaction-form.php"><i class="bi bi-plus-circle"></i> Log Transaction</a>
    </div>
</section>

<section class="current-money-card pixel-card mb-4">
    <div class="row g-4 align-items-center">
        <div class="col-lg-3 text-center">
            <?= mascot_img('money', 'mascot-money-img', 'Kwarta current money mascot') ?>
        </div>
        <div class="col-lg-5">
            <span class="level-pill mb-3"><i class="bi bi-wallet2"></i> Current Money</span>
            <div class="balance-label text-muted-small text-uppercase fw-bold">Available Balance</div>
            <div class="current-money-amount"><?= e(peso($currentMoney)) ?></div>
            <p class="mascot-message mb-0">
                <?= $monthlySavings >= 0
                    ? 'Nice! You saved ' . e(peso($monthlySavings)) . ' this month.'
                    : 'Careful! Expenses are ahead by ' . e(peso(abs($monthlySavings))) . ' this month.'
                ?>
            </p>
            <form class="current-money-form mt-3" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_current_money">
                <label class="form-label" for="current_money">Edit Current Money</label>
                <div class="input-group">
                    <span class="input-group-text">PHP</span>
                    <input class="form-control" id="current_money" type="number" step="0.01" min="0" name="current_money" value="<?= e((string) $currentMoney) ?>" required>
                    <button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save</button>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <div class="money-summary-grid">
                <div>
                    <span>Income</span>
                    <strong><?= e(peso($monthlyIncome)) ?></strong>
                </div>
                <div>
                    <span>Expenses</span>
                    <strong><?= e(peso($monthlyExpenses)) ?></strong>
                </div>
                <div>
                    <span>Ledger Balance</span>
                    <strong><?= e(peso($balance)) ?></strong>
                </div>
                <div>
                    <span>Current Money</span>
                    <strong><?= e(peso($currentMoney)) ?></strong>
                </div>
            </div>
            <div class="mt-3">
                <div class="d-flex justify-content-between text-muted-small mb-1">
                    <span>Monthly Savings Progress</span>
                    <span><?= $monthlyIncome > 0 ? (int) max(0, min(100, round(($monthlySavings / $monthlyIncome) * 100))) : 0 ?>%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar bg-success" style="width: <?= $monthlyIncome > 0 ? (int) max(0, min(100, round(($monthlySavings / $monthlyIncome) * 100))) : 0 ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon pixel-money coin"><i class="bi bi-coin"></i></div>
                <div>
                    <div class="text-muted-small">Total Income</div>
                    <div class="h4 fw-bold mb-0"><?= e(peso($totalIncome)) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon expense pixel-money bag"><i class="bi bi-bag"></i></div>
                <div>
                    <div class="text-muted-small">Total Expenses</div>
                    <div class="h4 fw-bold mb-0"><?= e(peso($totalExpenses)) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon balance pixel-money wallet"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="text-muted-small">Current Balance</div>
                    <div class="h4 fw-bold mb-0"><?= e(peso($balance)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="pixel-icon pixel-money piggy"><i class="bi bi-piggy-bank"></i></span>
                    <div>
                        <div class="text-muted-small">Monthly Savings</div>
                        <div class="h4 fw-bold mb-0"><?= e(peso($monthlySavings)) ?></div>
                    </div>
                </div>
                <div class="text-muted-small">You earned <?= e(peso($monthlyIncome)) ?> and spent <?= e(peso($monthlyExpenses)) ?> this month.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>Budget Meter</strong>
                    <span class="badge <?= $budgetProgress >= 100 ? 'text-bg-danger' : ($budgetProgress >= 80 ? 'text-bg-warning' : 'text-bg-success') ?>">
                        <?= (int) $budgetProgress ?>%
                    </span>
                </div>
                <div class="progress mb-2">
                    <div class="progress-bar <?= $budgetProgress >= 100 ? 'bg-danger' : ($budgetProgress >= 80 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= (int) $budgetProgress ?>%"></div>
                </div>
                <div class="text-muted-small"><?= e(peso($budgetSpent)) ?> used of <?= e(peso($budgetTotal)) ?> monthly budgets.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>Savings Cart Meter</strong>
                    <span class="badge text-bg-success"><?= (int) $savingsProgress ?>%</span>
                </div>
                <div class="progress mb-2">
                    <div class="progress-bar bg-success" style="width: <?= (int) $savingsProgress ?>%"></div>
                </div>
                <div class="text-muted-small"><?= e(peso($savingsSaved)) ?> saved of <?= e(peso($savingsTarget)) ?> total cart items.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Active Money Quests</h2>
                        <div class="text-muted-small">Complete missions to earn XP and build better habits.</div>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary" href="gamification.php">Quest Log</a>
                </div>
                <div class="row g-3">
                    <?php foreach ($featuredChallenges as $challenge): ?>
                        <div class="col-md-4">
                            <div class="quest-card">
                                <div class="fw-bold mb-1"><?= e($challenge['name']) ?></div>
                                <div class="text-muted-small mb-2"><?= e($challenge['description']) ?></div>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-success" style="width: <?= (int) $challenge['progress'] ?>%"></div>
                                </div>
                                <span class="badge <?= $challenge['complete'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= (int) $challenge['progress'] ?>% complete
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Badge Shelf</h2>
                <div class="d-grid gap-2">
                    <?php foreach ($featuredAchievements as $achievement): ?>
                        <div class="achievement-tile <?= $achievement['unlocked_at'] ? '' : 'locked' ?>">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="pixel-icon"><i class="bi bi-<?= $achievement['unlocked_at'] ? 'award' : 'lock' ?>"></i></span>
                                <strong><?= e($achievement['name']) ?></strong>
                            </div>
                            <div class="text-muted-small"><?= e($achievement['description']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Income vs Expenses</h2>
                        <div class="text-muted-small">Last 6 months</div>
                    </div>
                    <form method="get">
                        <input class="form-control form-control-sm" type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <div class="chart-box">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-1">Spending Categories</h2>
                <div class="text-muted-small mb-3"><?= e(peso($monthlySpending)) ?> spent this month</div>
                <div class="chart-box">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card content-card mt-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h5 fw-bold mb-0">Recent Transactions</h2>
            <a class="btn btn-sm btn-outline-secondary" href="transactions.php">View All</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentTransactions): ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <?= mascot_img('empty', 'mascot-empty-img', 'Kwarta empty transaction mascot') ?>
                                    <div>
                                        <strong>No transactions yet.</strong>
                                        <div class="text-muted-small">Your mascot is ready. Log your first income or expense to start earning XP.</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($recentTransactions as $transaction): ?>
                        <tr>
                            <td><?= e(date('M d, Y', strtotime($transaction['transaction_date']))) ?></td>
                            <td><?= e($transaction['category_name']) ?></td>
                            <td>
                                <span class="badge <?= $transaction['type'] === 'income' ? 'badge-soft-success' : 'badge-soft-danger' ?>">
                                    <?= e(ucfirst($transaction['type'])) ?>
                                </span>
                            </td>
                            <td class="text-end fw-semibold"><?= e(peso((float) $transaction['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    renderBarChart('monthlyChart', <?= json_encode($labels) ?>, <?= json_encode($incomeSeries) ?>, <?= json_encode($expenseSeries) ?>);
    renderDoughnutChart('categoryChart', <?= json_encode($categoryLabels ?: ['No expenses']) ?>, <?= json_encode($categoryValues ?: [1]) ?>);
});
</script>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
