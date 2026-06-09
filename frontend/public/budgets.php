<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Budgets';
$userId = current_user_id();
$month = $_GET['month'] ?? current_month();
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = current_month();
}

$errors = [];
$expenseCategories = get_categories($pdo, 'expense');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'save';
    $categoryId = (int) ($_POST['category_id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM budgets WHERE user_id = :user_id AND category_id = :category_id AND month = :month');
        $stmt->execute(['user_id' => $userId, 'category_id' => $categoryId, 'month' => $month]);
        flash('success', 'Budget removed.');
        redirect('budgets.php?month=' . urlencode($month));
    }

    $amount = validate_amount($_POST['amount'] ?? '');

    if (!$categoryId || !category_belongs_to_type($pdo, $categoryId, 'expense')) {
        $errors[] = 'Please choose an expense category.';
    }

    if ($amount === null) {
        $errors[] = 'Budget amount must be greater than zero.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('
            INSERT INTO budgets (user_id, category_id, month, amount)
            VALUES (:user_id, :category_id, :month, :amount)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'month' => $month,
            'amount' => $amount,
        ]);

        award_xp($pdo, $userId, 'budget_save', 'Set a category budget', 30);
        evaluate_achievements($pdo, $userId);
        flash('success', 'Budget saved. +30 XP');
        redirect('budgets.php?month=' . urlencode($month));
    }
}

$stmt = $pdo->prepare('
    SELECT c.id AS category_id,
           c.name AS category_name,
           b.amount AS budget_amount,
           COALESCE(SUM(t.amount), 0) AS spent_amount
    FROM categories c
    LEFT JOIN budgets b
        ON b.category_id = c.id
       AND b.user_id = :budget_user_id
       AND b.month = :budget_month
    LEFT JOIN transactions t
        ON t.category_id = c.id
       AND t.user_id = :transaction_user_id
       AND t.type = "expense"
       AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :transaction_month
    WHERE c.type IN ("expense", "both")
    GROUP BY c.id, c.name, b.amount
    ORDER BY c.name
');
$stmt->execute([
    'budget_user_id' => $userId,
    'budget_month' => $month,
    'transaction_user_id' => $userId,
    'transaction_month' => $month,
]);
$budgets = $stmt->fetchAll();

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-wallet" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">Budget Management</h1>
            <p class="page-header-subtitle">Set spending limits and track your monthly budget progress.</p>
        </div>
    </div>
    <div class="page-header-actions">
        <form method="get">
            <label class="form-label text-muted-small" for="budget_month">Budget Month</label>
            <input class="form-control" id="budget_month" type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()">
        </form>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card content-card">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Set Budget</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save">
                    <div class="mb-3">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <?php foreach ($expenseCategories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="amount">Monthly Amount</label>
                        <input class="form-control" id="amount" type="number" step="0.01" min="0.01" name="amount" required>
                    </div>
                    <button class="btn btn-success w-100" type="submit">Save Budget</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card content-card">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3"><?= e(month_label($month)) ?> Progress</h2>
                <div class="row g-3">
                    <?php foreach ($budgets as $budget): ?>
                        <?php
                        $budgetAmount = (float) ($budget['budget_amount'] ?? 0);
                        $spentAmount = (float) $budget['spent_amount'];
                        $percentage = $budgetAmount > 0 ? min(100, round(($spentAmount / $budgetAmount) * 100)) : 0;
                        $statusClass = $budgetAmount > 0 && $spentAmount >= $budgetAmount ? 'budget-danger' : ($budgetAmount > 0 && $percentage >= 80 ? 'budget-warning' : '');
                        $barClass = $budgetAmount > 0 && $spentAmount >= $budgetAmount ? 'bg-danger' : ($percentage >= 80 ? 'bg-warning' : 'bg-success');
                        ?>
                        <div class="col-md-6">
                            <div class="border rounded-2 p-3 h-100 <?= e($statusClass) ?>">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <div class="fw-semibold"><?= e($budget['category_name']) ?></div>
                                        <div class="text-muted-small">
                                            <?= e(peso($spentAmount)) ?> spent
                                            <?php if ($budgetAmount > 0): ?>
                                                of <?= e(peso($budgetAmount)) ?>
                                            <?php else: ?>
                                                with no budget set
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($budgetAmount > 0): ?>
                                        <form method="post" onsubmit="return confirm('Remove this budget?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id" value="<?= (int) $budget['category_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar <?= e($barClass) ?>" style="width: <?= (int) $percentage ?>%"></div>
                                </div>
                                <?php if ($budgetAmount > 0 && $spentAmount >= $budgetAmount): ?>
                                    <span class="badge text-bg-danger">Over budget</span>
                                <?php elseif ($budgetAmount > 0 && $percentage >= 80): ?>
                                    <span class="badge text-bg-warning">Close to limit</span>
                                <?php elseif ($budgetAmount > 0): ?>
                                    <span class="badge text-bg-success"><?= (int) $percentage ?>% used</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Not set</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
