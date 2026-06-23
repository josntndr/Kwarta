<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Profile';
$userId = current_user_id();
$errors = [];
$game = gamification_profile($pdo, $userId);
$stats = $game['stats'];
$currentMoney = get_current_money($pdo, $userId);

$stmt = $pdo->prepare('
    SELECT name, email, created_at, password_hash
    FROM users
    WHERE id = :user_id
    LIMIT 1
');
$stmt->execute(['user_id' => $userId]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Display name is required.';
        } elseif (strlen($name) > 120) {
            $errors[] = 'Display name must be 120 characters or fewer.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET name = :name WHERE id = :user_id');
            $stmt->execute(['name' => $name, 'user_id' => $userId]);
            $_SESSION['user_name'] = $name;
            flash('success', 'Profile updated.');
            redirect('profile.php');
        }
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPassword, $user['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        }
        if (!is_strong_password($newPassword)) {
            $errors[] = 'New password must be at least 8 characters and include uppercase, lowercase, and a number.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
            $stmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'user_id' => $userId,
            ]);
            flash('success', 'Password changed successfully.');
            redirect('profile.php');
        }
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="profile-header-main">
        <?= mascot_img('dashboard', 'profile-header-avatar', 'Kwarta profile avatar') ?>
        <div class="page-header-copy">
            <span class="level-pill mb-2"><i class="bi bi-person-badge"></i> Kwarta Player Profile</span>
            <h1 class="page-header-title"><?= e(current_user_name()) ?></h1>
            <p class="page-header-subtitle"><?= e($user['email'] ?? '') ?></p>
            <div class="page-header-meta">
                <span class="page-header-chip">Level <?= (int) $stats['level'] ?>: <?= e(level_title((int) $stats['level'])) ?></span>
                <span class="page-header-chip"><?= (int) $stats['xp'] ?> XP</span>
                <span class="page-header-chip"><?= (int) $stats['current_streak'] ?> day streak</span>
            </div>
        </div>
    </div>
    <div class="page-header-actions">
        <div class="page-header-progress">
            <div class="d-flex justify-content-between text-muted-small mb-1">
                <span>Player Progress</span>
                <strong><?= (int) $stats['level_progress'] ?>%</strong>
            </div>
            <div class="progress">
                <div class="progress-bar bg-success" style="width: <?= (int) $stats['level_progress'] ?>%"></div>
            </div>
        </div>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-danger pixel-card">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Player Card</h2>
                <div class="text-center mb-3">
                    <?= mascot_img('dashboard', 'mascot-section-img', 'Kwarta profile avatar') ?>
                    <div class="h4 fw-bold mt-2 mb-0"><?= e(current_user_name()) ?></div>
                    <div class="text-muted-small"><?= e($user['email'] ?? '') ?></div>
                </div>
                <div class="d-grid gap-3">
                    <div class="profile-info-row"><span>Joined</span><strong><?= e(isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'Unknown') ?></strong></div>
                    <div class="profile-info-row"><span>Current Money</span><strong><?= e(peso($currentMoney)) ?></strong></div>
                    <div class="profile-info-row"><span>Security</span><strong>Session protected</strong></div>
                    <a class="btn btn-outline-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Player Stats</h2>
                <div class="row g-3">
                    <div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Level</div><div class="h3 fw-bold"><?= (int) $stats['level'] ?></div></div></div>
                    <div class="col-md-3"><div class="quest-card"><div class="text-muted-small">XP</div><div class="h3 fw-bold"><?= (int) $stats['xp'] ?></div></div></div>
                    <div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Coins</div><div class="h3 fw-bold"><?= (int) $stats['coins'] ?></div></div></div>
                    <div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Best Streak</div><div class="h3 fw-bold"><?= (int) $stats['longest_streak'] ?></div></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Account Settings</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label" for="name">Display Name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($user['name'] ?? current_user_name()) ?>" required maxlength="120">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email Address</label>
                        <input class="form-control" id="email" value="<?= e($user['email'] ?? '') ?>" disabled>
                        <div class="form-text">Email editing is locked for account safety.</div>
                    </div>
                    <button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save Profile</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Change Password</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input class="form-control" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New Password</label>
                        <input class="form-control" id="new_password" type="password" name="new_password" autocomplete="new-password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input class="form-control" id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required minlength="8">
                    </div>
                    <button class="btn btn-success" type="submit"><i class="bi bi-shield-lock"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Financial Preferences</h2>
                <div class="settings-list">
                    <div class="settings-row"><span>Default currency</span><strong>PHP (Philippine Peso)</strong></div>
                    <div class="settings-row"><span>Budget warnings</span><a class="fw-bold text-decoration-none" href="budgets.php">On Budget page</a></div>
                    <div class="settings-row"><span>Monthly receipt</span><a class="fw-bold text-decoration-none" href="receipt.php">Generate anytime</a></div>
                    <div class="settings-row"><span>Pixel theme</span><strong>Always on</strong></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Account Security</h2>
                <div class="settings-list">
                    <div class="settings-row"><span>Password hashing</span><strong>Enabled</strong></div>
                    <div class="settings-row"><span>Session protection</span><strong>Enabled</strong></div>
                    <div class="settings-row"><span>User data isolation</span><strong>Enabled</strong></div>
                    <div class="settings-row"><span>Keep me logged in</span><strong><?= keep_logged_in() ? 'On' : 'Off' ?></strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
