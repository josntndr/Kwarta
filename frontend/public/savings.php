<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Savings Cart';
$userId = current_user_id();
$errors = [];

if (function_exists('kwarta_repair_savings_tables')) {
    try {
        kwarta_repair_savings_tables($pdo);
    } catch (Throwable $error) {
        error_log('[Kwarta savings schema] ' . $error->getMessage());
        $errors[] = 'Savings Cart is preparing your online database. Please refresh and try again.';
    }
}

function savings_goal_for_user(PDO $pdo, int $goalId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM savings_goals WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['id' => $goalId, 'user_id' => $userId]);
    $goal = $stmt->fetch();
    return $goal ?: null;
}

function log_savings_history(PDO $pdo, int $goalId, string $actionType, ?float $amountChanged, ?float $previousAmount, ?float $newAmount, string $notes = ''): void
{
    $stmt = $pdo->prepare('
        INSERT INTO savings_goal_histories (savings_goal_id, action_type, amount_changed, previous_amount, new_amount, notes)
        VALUES (:savings_goal_id, :action_type, :amount_changed, :previous_amount, :new_amount, :notes)
    ');
    $stmt->execute([
        'savings_goal_id' => $goalId,
        'action_type' => $actionType,
        'amount_changed' => $amountChanged,
        'previous_amount' => $previousAmount,
        'new_amount' => $newAmount,
        'notes' => $notes ?: null,
    ]);
}

function savings_action_label(string $actionType): string
{
    return match ($actionType) {
        'increase' => 'Increase',
        'decrease' => 'Decrease',
        'target_updated' => 'Price Updated',
        'saved_updated' => 'Saved Updated',
        'goal_edited' => 'Item Edited',
        'goal_created' => 'Added to Cart',
        'item_bought' => 'Already Bought',
        'item_unbought' => 'Marked Not Bought',
        default => ucwords(str_replace('_', ' ', $actionType)),
    };
}

function savings_history_time(string $timestamp): string
{
    return date('F j, Y, g:i A', strtotime($timestamp));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? 'create';
        $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $goal = savings_goal_for_user($pdo, $id, $userId);
        if ($goal) {
            $stmt = $pdo->prepare('DELETE FROM savings_goals WHERE id = :id AND user_id = :user_id');
            $stmt->execute(['id' => $id, 'user_id' => $userId]);
            flash('success', 'Cart item removed.');
        }
        redirect('savings.php');
    }

    if ($action === 'toggle_bought') {
        $goal = savings_goal_for_user($pdo, $id, $userId);
        if ($goal) {
            $isBought = (int) ($goal['is_bought'] ?? 0) === 1;
            $newStatus = $isBought ? 0 : 1;
            $stmt = $pdo->prepare('
                UPDATE savings_goals
                SET is_bought = :is_bought,
                    bought_at = ' . ($newStatus ? 'CURRENT_TIMESTAMP' : 'NULL') . '
                WHERE id = :id AND user_id = :user_id
            ');
            $stmt->execute([
                'is_bought' => $newStatus,
                'id' => $id,
                'user_id' => $userId,
            ]);

            log_savings_history(
                $pdo,
                $id,
                $newStatus ? 'item_bought' : 'item_unbought',
                null,
                (float) $goal['saved_amount'],
                (float) $goal['saved_amount'],
                $newStatus ? 'Marked item as already bought' : 'Removed already bought status'
            );

            if ($newStatus) {
                award_xp($pdo, $userId, 'savings_bought', 'Marked a cart item as bought', 25);
                evaluate_achievements($pdo, $userId);
                flash('success', 'Nice! Item marked as already bought. +25 XP');
            } else {
                flash('success', 'Already bought status removed.');
            }
        }
        redirect('savings.php#goal-' . $id);
    }

    if ($action === 'delete_history') {
        $historyId = (int) ($_POST['history_id'] ?? 0);
        $goalId = (int) ($_POST['goal_id'] ?? 0);

        $stmt = $pdo->prepare('
            DELETE h
            FROM savings_goal_histories h
            INNER JOIN savings_goals g ON g.id = h.savings_goal_id
            WHERE h.id = :history_id
              AND g.id = :goal_id
              AND g.user_id = :user_id
        ');
        $stmt->execute([
            'history_id' => $historyId,
            'goal_id' => $goalId,
            'user_id' => $userId,
        ]);

        flash('success', 'Savings history entry deleted.');
        redirect('savings.php#goal-' . $goalId);
    }

    if ($action === 'increase' || $action === 'decrease') {
        $goal = savings_goal_for_user($pdo, $id, $userId);
        $amount = validate_amount($_POST['amount_change'] ?? '');

        if (!$goal) {
            $errors[] = 'Cart item was not found.';
        }

        if ($amount === null) {
            $errors[] = 'Saved money adjustment must be greater than zero.';
        }

        if (!$errors && $goal) {
            $previousSaved = (float) $goal['saved_amount'];
            $newSaved = $action === 'increase' ? $previousSaved + $amount : $previousSaved - $amount;

            if ($newSaved < 0) {
                $errors[] = 'Saved amount cannot go below zero.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE savings_goals
                    SET saved_amount = :saved_amount
                    WHERE id = :id AND user_id = :user_id
                ');
                $stmt->execute([
                    'saved_amount' => $newSaved,
                    'id' => $id,
                    'user_id' => $userId,
                ]);

                $label = $action === 'increase' ? 'Increased' : 'Decreased';
                log_savings_history(
                    $pdo,
                    $id,
                    $action,
                    $amount,
                    $previousSaved,
                    $newSaved,
                    $label . ' saved amount by ' . peso($amount)
                );

                $xpReward = $action === 'increase' ? 15 : 5;
                award_xp($pdo, $userId, 'savings_' . $action, $label . ' cart item savings', $xpReward);
                evaluate_achievements($pdo, $userId);
                flash('success', $label . ' savings by ' . peso($amount) . '. +' . $xpReward . ' XP');
                redirect('savings.php#goal-' . $id);
            }
        }
    }

    if ($action === 'update' || $action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $targetAmount = validate_amount($_POST['target_amount'] ?? '');
        $savedInput = $_POST['saved_amount'] ?? '';
        $savedAmount = $savedInput !== '' ? validate_non_negative_amount($savedInput) : 0.0;
        $targetMonth = trim($_POST['target_month'] ?? '');
        $targetDate = $targetMonth !== '' ? $targetMonth . '-01' : '';

        if ($name === '' || strlen($name) > 120) {
            $errors[] = 'Item name is required and must be 120 characters or fewer.';
        }

        if (strlen($description) > 255) {
            $errors[] = 'Item description must be 255 characters or fewer.';
        }

        if ($targetAmount === null) {
            $errors[] = 'Item price must be greater than zero.';
        }

        if ($savedAmount === null) {
            $errors[] = 'Saved amount must be zero or greater.';
        }

        if ($targetMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $targetMonth)) {
            $errors[] = 'Target month must be valid.';
        }

        if ($targetDate !== '' && !validate_date($targetDate)) {
            $errors[] = 'Target month must be valid.';
        }

        if (!$errors) {
            if ($action === 'update') {
                $goal = savings_goal_for_user($pdo, $id, $userId);
                if (!$goal) {
                    $errors[] = 'Cart item was not found.';
                } else {
                    $previousSaved = (float) $goal['saved_amount'];
                    $previousTarget = (float) $goal['target_amount'];
                    $nameChanged = $name !== $goal['name'];
                    $descriptionChanged = $description !== ($goal['description'] ?? '');
                    $dateChanged = ($targetDate ?: null) !== ($goal['target_date'] ?: null);

                    $stmt = $pdo->prepare('
                        UPDATE savings_goals
                        SET name = :name,
                            description = :description,
                            target_amount = :target_amount,
                            saved_amount = :saved_amount,
                            target_date = :target_date
                        WHERE id = :id AND user_id = :user_id
                    ');
                    $stmt->execute([
                        'name' => $name,
                        'description' => $description ?: null,
                        'target_amount' => $targetAmount,
                        'saved_amount' => $savedAmount,
                        'target_date' => $targetDate ?: null,
                        'id' => $id,
                        'user_id' => $userId,
                    ]);

                    $targetChanged = abs($targetAmount - $previousTarget) > 0.009;
                    $savedChanged = abs($savedAmount - $previousSaved) > 0.009;
                    $changeNotes = [];

                    if ($nameChanged) {
                        $changeNotes[] = 'name';
                    }

                    if ($descriptionChanged) {
                        $changeNotes[] = 'description';
                    }

                    if ($dateChanged) {
                        $changeNotes[] = 'target month';
                    }

                    if ($targetChanged) {
                        $changeNotes[] = 'price from ' . peso($previousTarget) . ' to ' . peso($targetAmount);
                    }

                    if ($savedChanged) {
                        $changeNotes[] = 'saved amount from ' . peso($previousSaved) . ' to ' . peso($savedAmount);
                    }

                    if ($changeNotes) {
                        log_savings_history(
                            $pdo,
                            $id,
                            'goal_edited',
                            $savedChanged ? abs($savedAmount - $previousSaved) : null,
                            $savedChanged ? $previousSaved : null,
                            $savedChanged ? $savedAmount : null,
                            'Updated ' . implode(', ', $changeNotes)
                        );

                        $xpReward = $savedAmount >= $targetAmount ? 50 : 15;
                        award_xp($pdo, $userId, 'savings_update', 'Updated a cart item', $xpReward);
                        evaluate_achievements($pdo, $userId);
                        flash('success', 'Cart item updated. +' . $xpReward . ' XP');
                    } else {
                        flash('success', 'No savings cart changes were made.');
                    }

                    redirect('savings.php#goal-' . $id);
                }
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO savings_goals (user_id, name, description, target_amount, saved_amount, target_date)
                    VALUES (:user_id, :name, :description, :target_amount, :saved_amount, :target_date)
                ');
                $stmt->execute([
                    'user_id' => $userId,
                    'name' => $name,
                    'description' => $description ?: null,
                    'target_amount' => $targetAmount,
                    'saved_amount' => $savedAmount,
                    'target_date' => $targetDate ?: null,
                ]);

                $newGoalId = (int) $pdo->lastInsertId();
                log_savings_history($pdo, $newGoalId, 'goal_created', $savedAmount, 0.0, $savedAmount, 'Added item to cart');
                award_xp($pdo, $userId, 'savings_create', 'Added an item to the savings cart', 20);
                evaluate_achievements($pdo, $userId);
                flash('success', 'Item added to cart. +20 XP');
                redirect('savings.php#goal-' . $newGoalId);
            }
        }
    }
    } catch (Throwable $error) {
        error_log('[Kwarta savings action] ' . $error->getMessage());
        $errors[] = 'Savings Cart could not save that change yet. Please refresh and try again.';
    }
}

$stmt = $pdo->prepare('SELECT * FROM savings_goals WHERE user_id = :user_id ORDER BY created_at DESC');
$stmt->execute(['user_id' => $userId]);
$goals = $stmt->fetchAll();

$totalBudgetNeed = array_reduce($goals, static fn ($sum, $goal) => $sum + (float) $goal['target_amount'], 0.0);
$currentBudget = array_reduce($goals, static fn ($sum, $goal) => $sum + (float) $goal['saved_amount'], 0.0);
$remainingBudget = max(0.0, $totalBudgetNeed - $currentBudget);
$cartProgress = $totalBudgetNeed > 0 ? min(100, (int) round(($currentBudget / $totalBudgetNeed) * 100)) : 0;

$historiesByGoal = [];
if ($goals) {
    $ids = array_map(static fn ($goal) => (int) $goal['id'], $goals);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT *
        FROM savings_goal_histories
        WHERE savings_goal_id IN ({$placeholders})
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $history) {
        $historiesByGoal[(int) $history['savings_goal_id']][] = $history;
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-piggy" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">Savings Cart</h1>
            <p class="page-header-subtitle">Add items you want to buy, set a target month, and save toward each one.</p>
        </div>
    </div>
    <div class="page-header-actions">
        <?= mascot_img('savings', 'mascot-section-img', 'Kwarta savings mascot') ?>
    </div>
</section>

<div class="card content-card savings-cart-summary mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-lg-4">
                <div class="text-muted-small text-uppercase fw-bold">Total Budget Need</div>
                <div class="h3 fw-bold mb-0"><?= e(peso($totalBudgetNeed)) ?></div>
                <div class="text-muted-small">Full price of all cart items.</div>
            </div>
            <div class="col-lg-4">
                <div class="text-muted-small text-uppercase fw-bold">Current Budget</div>
                <div class="h3 fw-bold mb-0 text-success"><?= e(peso($currentBudget)) ?></div>
                <div class="text-muted-small">Money already saved for your cart.</div>
            </div>
            <div class="col-lg-4">
                <div class="d-flex justify-content-between text-muted-small mb-1">
                    <span>Cart Progress</span>
                    <strong><?= (int) $cartProgress ?>%</strong>
                </div>
                <div class="progress mb-2">
                    <div class="progress-bar bg-success" style="width: <?= (int) $cartProgress ?>%"></div>
                </div>
                <div class="text-muted-small"><?= e(peso($remainingBudget)) ?> still needed.</div>
            </div>
        </div>
    </div>
</div>

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
                <h2 class="h5 fw-bold mb-3">Add Item to Cart</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="name">Item Name</label>
                        <input class="form-control" id="name" name="name" maxlength="120" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Item Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" maxlength="255" placeholder="Example: For online class, school project, or birthday gift"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="target_amount">Item Price</label>
                        <input class="form-control" id="target_amount" type="number" step="0.01" min="0.01" name="target_amount" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="saved_amount">Amount Saved</label>
                        <input class="form-control" id="saved_amount" type="number" step="0.01" min="0" name="saved_amount" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="target_month">Target Month to Avail</label>
                        <input class="form-control" id="target_month" type="month" name="target_month">
                        <div class="form-text">Choose the month when you plan to buy or avail this item.</div>
                    </div>
                    <button class="btn btn-success w-100" type="submit">Add to Cart</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="row g-3">
            <?php if (!$goals): ?>
                <div class="col-12">
                    <div class="card content-card">
                        <div class="card-body">
                            <div class="empty-state justify-content-center">
                                <?= mascot_img('savings', 'mascot-empty-img', 'Kwarta savings mascot') ?>
                                <div>
                                    <strong>No cart items yet.</strong>
                                    <div class="text-muted-small">Add an item, choose when you want to avail it, and start saving toward it.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($goals as $goal): ?>
                <?php
                $goalId = (int) $goal['id'];
                $target = (float) $goal['target_amount'];
                $saved = (float) $goal['saved_amount'];
                $description = trim($goal['description'] ?? '');
                $isBought = (int) ($goal['is_bought'] ?? 0) === 1;
                $percentage = $target > 0 ? min(100, round(($saved / $target) * 100)) : 0;
                $remaining = max(0, $target - $saved);
                $histories = $historiesByGoal[$goalId] ?? [];
                ?>
                <div class="col-md-6" id="goal-<?= $goalId ?>">
                    <button class="card content-card savings-goal-card h-100 text-start w-100" type="button" data-bs-toggle="modal" data-bs-target="#goalModal<?= $goalId ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 fw-bold mb-1"><?= e($goal['name']) ?></h2>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <div class="text-muted-small">Tap to manage this item</div>
                                        <?php if ($isBought): ?>
                                            <span class="savings-status-badge bought">Already Bought</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?= mascot_img('savings', 'mascot-mini-img', 'Kwarta savings mascot') ?>
                            </div>
                            <?php if ($description !== ''): ?>
                                <p class="savings-description-preview"><?= e($description) ?></p>
                            <?php endif; ?>
                            <div class="progress mb-2">
                                <div class="progress-bar bg-success" style="width: <?= (int) $percentage ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between gap-3 text-muted-small">
                                <span><?= (int) $percentage ?>% saved</span>
                                <span><?= e(peso($saved)) ?> / <?= e(peso($target)) ?></span>
                            </div>
                            <?php if (!empty($goal['target_date'])): ?>
                                <div class="text-muted-small mt-2">Target: <?= e(date('F Y', strtotime($goal['target_date']))) ?></div>
                            <?php endif; ?>
                            <div class="savings-card-footer mt-3">
                                <span>Remaining</span>
                                <strong><?= e(peso($remaining)) ?></strong>
                            </div>
                        </div>
                    </button>
                </div>

                <div class="modal fade" id="goalModal<?= $goalId ?>" tabindex="-1" aria-labelledby="goalModalLabel<?= $goalId ?>" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content pixel-modal">
                            <div class="modal-header">
                                <div>
                                    <h2 class="modal-title h4 fw-bold" id="goalModalLabel<?= $goalId ?>"><?= e($goal['name']) ?></h2>
                                    <div class="text-muted-small">Cart item details, target month, and activity history</div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-4">
                                    <div class="col-lg-5">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <?= mascot_img('savings', 'mascot-section-img', 'Kwarta savings mascot') ?>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between text-muted-small mb-1">
                                                    <span><?= (int) $percentage ?>% saved</span>
                                                    <span><?= e(peso($remaining)) ?> remaining</span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar bg-success" style="width: <?= (int) $percentage ?>%"></div>
                                                </div>
                                                <?php if ($isBought): ?>
                                                    <span class="savings-status-badge bought mt-2">Already Bought<?= $goal['bought_at'] ? ' on ' . e(date('M j, Y', strtotime($goal['bought_at']))) : '' ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <form method="post" class="mb-4">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= $goalId ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Item Name</label>
                                                <input class="form-control" name="name" value="<?= e($goal['name']) ?>" maxlength="120" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Item Description</label>
                                                <textarea class="form-control" name="description" rows="3" maxlength="255" placeholder="What is this item for?"><?= e($description) ?></textarea>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Item Price</label>
                                                    <input class="form-control" type="number" step="0.01" min="0.01" name="target_amount" value="<?= e((string) $goal['target_amount']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Amount Saved</label>
                                                    <input class="form-control" type="number" step="0.01" min="0" name="saved_amount" value="<?= e((string) $goal['saved_amount']) ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Target Month to Avail</label>
                                                    <input class="form-control" type="month" name="target_month" value="<?= e($goal['target_date'] ? date('Y-m', strtotime($goal['target_date'])) : '') ?>">
                                                    <div class="form-text">Choose the month when you plan to buy or avail this item.</div>
                                                </div>
                                            </div>
                                            <button class="btn btn-success w-100 mt-3" type="submit">Update Item</button>
                                        </form>

                                        <form method="post" class="mb-4">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_bought">
                                            <input type="hidden" name="id" value="<?= $goalId ?>">
                                            <button class="btn <?= $isBought ? 'btn-outline-secondary' : 'btn-warning' ?> w-100" type="submit">
                                                <i class="bi <?= $isBought ? 'bi-arrow-counterclockwise' : 'bi-bag-check' ?>"></i>
                                                <?= $isBought ? 'Undo Already Bought' : 'Mark as Already Bought' ?>
                                            </button>
                                        </form>

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <form method="post" class="savings-adjust-box">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="increase">
                                                    <input type="hidden" name="id" value="<?= $goalId ?>">
                                                    <label class="form-label">Add Savings</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">PHP</span>
                                                        <input class="form-control" type="number" step="0.01" min="0.01" name="amount_change" placeholder="500" required>
                                                    </div>
                                                    <button class="btn btn-outline-success w-100 mt-2" type="submit">Add Savings</button>
                                                </form>
                                            </div>
                                            <div class="col-md-6">
                                                <form method="post" class="savings-adjust-box">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="decrease">
                                                    <input type="hidden" name="id" value="<?= $goalId ?>">
                                                    <label class="form-label">Reduce Savings</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">PHP</span>
                                                        <input class="form-control" type="number" step="0.01" min="0.01" max="<?= e((string) $goal['saved_amount']) ?>" name="amount_change" placeholder="200" required>
                                                    </div>
                                                    <button class="btn btn-outline-danger w-100 mt-2" type="submit">Reduce Savings</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-7">
                                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                            <h3 class="h5 fw-bold mb-0">Cart Activity</h3>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $goalId ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Remove this cart item and its history?');">
                                                    <i class="bi bi-trash"></i> Remove Item
                                                </button>
                                            </form>
                                        </div>

                                        <?php if (!$histories): ?>
                                            <div class="empty-state">
                                                <?= mascot_img('guide', 'mascot-empty-img', 'Kwarta guide mascot') ?>
                                                <div>
                                                    <strong>No activity yet.</strong>
                                                    <div class="text-muted-small">Add or reduce savings to start the history log.</div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="savings-history-list">
                                                <?php foreach ($histories as $history): ?>
                                                    <div class="savings-history-item">
                                                        <div class="d-flex justify-content-between gap-3">
                                                            <div>
                                                                <strong><?= e(savings_action_label($history['action_type'])) ?></strong>
                                                                <div class="text-muted-small"><?= e(savings_history_time($history['created_at'])) ?></div>
                                                            </div>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                                <input type="hidden" name="action" value="delete_history">
                                                                <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                                                                <input type="hidden" name="history_id" value="<?= (int) $history['id'] ?>">
                                                                <button class="btn btn-sm btn-outline-danger savings-history-delete" type="submit" onclick="return confirm('Delete this history entry? This will not change the saved amount.');">
                                                                    <i class="bi bi-trash"></i>
                                                                    Delete
                                                                </button>
                                                            </form>
                                                        </div>
                                                        <div class="text-muted-small mt-1"><?= e($history['notes'] ?? '') ?></div>
                                                        <div class="savings-history-meta mt-2">
                                                            <?php if ($history['amount_changed'] !== null): ?>
                                                                <span>Changed: <?= e(peso(abs((float) $history['amount_changed']))) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($history['previous_amount'] !== null && $history['new_amount'] !== null): ?>
                                                                <span><?= e(peso((float) $history['previous_amount'])) ?> to <?= e(peso((float) $history['new_amount'])) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
