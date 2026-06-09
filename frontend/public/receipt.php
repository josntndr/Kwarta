<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Monthly Receipt';
$userId = current_user_id();
$selectedMonth = $_GET['month'] ?? current_month();

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = current_month();
}

$stmt = $pdo->prepare('
    INSERT INTO monthly_receipt_logs (user_id, selected_month)
    VALUES (:user_id, :selected_month)
');
$stmt->execute(['user_id' => $userId, 'selected_month' => $selectedMonth]);

$monthLabel = month_label($selectedMonth);
$generatedAt = date('F j, Y - g:i A');
$userName = current_user_name();

$stmt = $pdo->prepare('
    SELECT t.*, c.name AS category_name
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
    ORDER BY t.transaction_date ASC, t.id ASC
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$expenseRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN t.type = "income" THEN t.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN t.type = "expense" THEN t.amount ELSE 0 END), 0) AS expenses,
        COUNT(CASE WHEN t.type = "expense" THEN 1 END) AS expense_count
    FROM transactions t
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$summary = $stmt->fetch();

$totalIncome = (float) $summary['income'];
$totalExpenses = (float) $summary['expenses'];
$remainingBalance = $totalIncome - $totalExpenses;
$expenseCount = (int) $summary['expense_count'];

$stmt = $pdo->prepare('
    SELECT c.name, COALESCE(SUM(t.amount), 0) AS total
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
    GROUP BY c.id, c.name
    ORDER BY total DESC
    LIMIT 1
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$topCategory = $stmt->fetch();
$highestCategory = $topCategory ? $topCategory['name'] . ' (' . peso((float) $topCategory['total']) . ')' : 'No expenses yet';

$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(t.amount), 0)
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
      AND LOWER(c.name) = "savings"
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$totalSavings = (float) $stmt->fetchColumn();

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel receipt-toolbar no-print">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">Monthly Expense Receipt</h1>
            <p class="page-header-subtitle">Generate a receipt-style summary for a selected month.</p>
        </div>
    </div>
    <form class="page-header-actions" method="get">
        <div>
            <label class="form-label" for="month">Month and Year</label>
            <input class="form-control" id="month" type="month" name="month" value="<?= e($selectedMonth) ?>">
        </div>
        <button class="btn btn-success" type="submit"><i class="bi bi-receipt"></i> Generate</button>
        <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print Receipt</button>
        <a class="btn btn-outline-primary" href="receipt-pdf.php?month=<?= e($selectedMonth) ?>"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a>
        <a class="btn btn-outline-secondary" href="dashboard.php">Back</a>
    </form>
</section>

<section class="monthly-receipt-card">
    <div class="receipt-brand">
        <?= mascot_img('logo', 'receipt-logo', 'Kwarta mascot logo') ?>
        <div>
            <div class="receipt-app-name">Kwarta</div>
            <div class="receipt-title">Monthly Expense Summary</div>
        </div>
    </div>

    <div class="receipt-divider"></div>

    <div class="receipt-meta-grid">
        <div><span>Month</span><strong><?= e($monthLabel) ?></strong></div>
        <div><span>Generated</span><strong><?= e($generatedAt) ?></strong></div>
        <div><span>User</span><strong><?= e($userName) ?></strong></div>
        <div><span>Transactions</span><strong><?= (int) $expenseCount ?></strong></div>
    </div>

    <div class="receipt-divider"></div>

    <h2 class="receipt-section-title">Expenses</h2>
    <div class="receipt-list">
        <?php if (!$expenseRows): ?>
            <div class="receipt-empty">No expenses recorded for <?= e($monthLabel) ?>.</div>
        <?php endif; ?>

        <?php foreach ($expenseRows as $index => $row): ?>
            <div class="receipt-line-item">
                <div class="receipt-item-main">
                    <span class="receipt-item-number"><?= $index + 1 ?>.</span>
                    <div>
                        <strong><?= e($row['category_name']) ?></strong>
                        <span><?= e($row['notes'] ?: 'No notes') ?></span>
                        <small><?= e(date('F j, Y', strtotime($row['transaction_date']))) ?></small>
                    </div>
                </div>
                <div class="receipt-item-amount"><?= e(peso((float) $row['amount'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="receipt-divider"></div>

    <div class="receipt-totals">
        <div><span>Total Expenses</span><strong><?= e(peso($totalExpenses)) ?></strong></div>
        <div><span>Total Income</span><strong><?= e(peso($totalIncome)) ?></strong></div>
        <div><span>Remaining Balance</span><strong><?= e(peso($remainingBalance)) ?></strong></div>
        <div><span>Total Savings</span><strong><?= e(peso($totalSavings)) ?></strong></div>
        <div><span>Highest Spending Category</span><strong><?= e($highestCategory) ?></strong></div>
    </div>

    <div class="receipt-divider"></div>

    <p class="receipt-footer-note">Keep tracking, keep saving, and keep leveling up your Kwarta habits.</p>
</section>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
