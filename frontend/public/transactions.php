<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Transactions';
$userId = current_user_id();
$categories = get_categories($pdo);
$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
$type = $_GET['type'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$where = ['t.user_id = :user_id'];
$params = ['user_id' => $userId];

if ($categoryId) {
    $where[] = 't.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if (in_array($type, ['income', 'expense'], true)) {
    $where[] = 't.type = :type';
    $params['type'] = $type;
}

if ($startDate !== '' && validate_date($startDate)) {
    $where[] = 't.transaction_date >= :start_date';
    $params['start_date'] = $startDate;
}

if ($endDate !== '' && validate_date($endDate)) {
    $where[] = 't.transaction_date <= :end_date';
    $params['end_date'] = $endDate;
}

$stmt = $pdo->prepare('
    SELECT t.*, c.name AS category_name
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY t.transaction_date DESC, t.id DESC
');
$stmt->execute($params);
$transactions = $stmt->fetchAll();

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-coin" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">Transactions</h1>
            <p class="page-header-subtitle">Manage your income and expense records in one place.</p>
        </div>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-success" href="transaction-form.php"><i class="bi bi-plus-circle"></i> Add Transaction</a>
    </div>
</section>

<div class="card content-card mb-4">
    <div class="card-body">
        <form class="row g-3" method="get">
            <div class="col-md-3">
                <label class="form-label" for="start_date">Start Date</label>
                <input class="form-control" id="start_date" type="date" name="start_date" value="<?= e($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="end_date">End Date</label>
                <input class="form-control" id="end_date" type="date" name="end_date" value="<?= e($endDate) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Income</option>
                    <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expense</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="category_id">Category</label>
                <select class="form-select" id="category_id" name="category_id">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid align-items-end">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Notes</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No transactions found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= e(date('M d, Y', strtotime($transaction['transaction_date']))) ?></td>
                            <td><?= e($transaction['category_name']) ?></td>
                            <td>
                                <span class="badge <?= $transaction['type'] === 'income' ? 'badge-soft-success' : 'badge-soft-danger' ?>">
                                    <?= e(ucfirst($transaction['type'])) ?>
                                </span>
                            </td>
                            <td><?= e($transaction['notes']) ?></td>
                            <td class="text-end fw-semibold"><?= e(peso((float) $transaction['amount'])) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="transaction-form.php?id=<?= (int) $transaction['id'] ?>"><i class="bi bi-pencil"></i></a>
                                <form class="d-inline" method="post" action="transaction-delete.php" onsubmit="return confirm('Delete this transaction?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $transaction['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
