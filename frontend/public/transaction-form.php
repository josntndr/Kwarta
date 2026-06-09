<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$userId = current_user_id();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;
$pageTitle = $isEdit ? 'Edit Transaction' : 'Add Transaction';
$errors = [];
$transaction = [
    'type' => 'expense',
    'category_id' => '',
    'amount' => '',
    'transaction_date' => date('Y-m-d'),
    'notes' => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['id' => $id, 'user_id' => $userId]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        flash('error', 'Transaction not found.');
        redirect('transactions.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = $_POST['type'] ?? 'expense';
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $amountInput = trim((string) ($_POST['amount'] ?? ''));
    $amount = $amountInput === '' ? null : validate_amount($amountInput);
    $date = $_POST['transaction_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $transaction = [
        'type' => $type,
        'category_id' => $categoryId,
        'amount' => $amountInput,
        'transaction_date' => $date,
        'notes' => $notes,
    ];

    if (!in_array($type, ['income', 'expense'], true)) {
        $errors[] = 'Please choose income or expense.';
    }

    if (!$categoryId || !category_belongs_to_type($pdo, $categoryId, $type)) {
        $errors[] = 'Please choose a category that matches the transaction type.';
    }

    if ($amountInput === '') {
        $errors[] = 'Amount is required.';
    } elseif ($amount === null) {
        $errors[] = 'Amount must be greater than zero.';
    }

    if (!validate_date($date)) {
        $errors[] = 'Please enter a valid transaction date.';
    }

    if (strlen($notes) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    if (!$errors) {
        if ($isEdit) {
            $stmt = $pdo->prepare('
                UPDATE transactions
                SET category_id = :category_id,
                    type = :type,
                    amount = :amount,
                    transaction_date = :transaction_date,
                    notes = :notes
                WHERE id = :id AND user_id = :user_id
            ');
            $stmt->execute([
                'category_id' => $categoryId,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $date,
                'notes' => $notes ?: null,
                'id' => $id,
                'user_id' => $userId,
            ]);
            award_xp($pdo, $userId, 'transaction_update', 'Refined a transaction record', 5);
            flash('success', 'Transaction updated.');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO transactions (user_id, category_id, type, amount, transaction_date, notes)
                VALUES (:user_id, :category_id, :type, :amount, :transaction_date, :notes)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'category_id' => $categoryId,
                'type' => $type,
                'amount' => $amount,
                'transaction_date' => $date,
                'notes' => $notes ?: null,
            ]);
            $xpReward = $type === 'income' ? 10 : 5;
            award_xp($pdo, $userId, 'transaction_add', 'Logged a ' . $type . ' transaction', $xpReward);
            evaluate_achievements($pdo, $userId);
            flash('success', ucfirst($type) . ' record added. +' . $xpReward . ' XP');
        }

        redirect('transactions.php');
    }
}

$incomeCategories = get_categories($pdo, 'income');
$expenseCategories = get_categories($pdo, 'expense');

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1 class="h4 fw-bold mb-0"><?= e($pageTitle) ?></h1>
                    <a class="btn btn-sm btn-outline-secondary" href="transactions.php">Back</a>
                </div>

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?= e($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="transactionForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="type">Type</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="expense" <?= $transaction['type'] === 'expense' ? 'selected' : '' ?>>Expense</option>
                            <option value="income" <?= $transaction['type'] === 'income' ? 'selected' : '' ?>>Income</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-select" id="category_id" name="category_id" data-selected="<?= e((string) $transaction['category_id']) ?>" required>
                            <optgroup label="Expense categories" data-type="expense">
                                <?php foreach ($expenseCategories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" data-type="expense" <?= (int) $transaction['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                        <?= e($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Income categories" data-type="income">
                                <?php foreach ($incomeCategories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" data-type="income" <?= (int) $transaction['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                        <?= e($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="amount">Amount</label>
                            <input class="form-control" id="amount" type="number" step="0.01" min="0.01" name="amount" value="<?= e((string) $transaction['amount']) ?>" required>
                            <div class="invalid-feedback" id="amountFeedback">Amount is required.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="transaction_date">Date</label>
                            <input class="form-control" id="transaction_date" type="date" name="transaction_date" value="<?= e($transaction['transaction_date']) ?>" required>
                        </div>
                    </div>
                    <div class="my-3">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="255"><?= e($transaction['notes']) ?></textarea>
                    </div>
                    <button class="btn btn-success" type="submit">Save Transaction</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category_id');
    const form = document.getElementById('transactionForm');
    const amountInput = document.getElementById('amount');
    const amountFeedback = document.getElementById('amountFeedback');

    function filterCategories() {
        const selectedType = typeSelect.value;
        let firstVisible = null;

        categorySelect.querySelectorAll('option').forEach(function (option) {
            const show = option.dataset.type === selectedType;
            option.hidden = !show;
            option.disabled = !show;
            if (show && firstVisible === null) firstVisible = option;
        });

        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
        if (!selectedOption || selectedOption.disabled) {
            categorySelect.value = firstVisible ? firstVisible.value : '';
        }
    }

    typeSelect.addEventListener('change', filterCategories);
    filterCategories();

    form.addEventListener('submit', function (event) {
        const amountValue = amountInput.value.trim();
        amountInput.classList.remove('is-invalid');

        if (amountValue === '') {
            event.preventDefault();
            amountFeedback.textContent = 'Amount is required.';
            amountInput.classList.add('is-invalid');
            amountInput.focus();
            return;
        }

        if (Number(amountValue) <= 0) {
            event.preventDefault();
            amountFeedback.textContent = 'Amount must be greater than zero.';
            amountInput.classList.add('is-invalid');
            amountInput.focus();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
