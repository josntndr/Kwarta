<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Reset Password';
$errors = [];
$success = '';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;

if (isset($_GET['security'])) {
    $errors[] = 'Your password reset form expired. Please try again.';
}

if ($token !== '') {
    try {
        $reset = find_password_reset($pdo, $token);
    } catch (Throwable $error) {
        error_log('[Kwarta reset password lookup] ' . $error->getMessage());
        $errors[] = 'Password reset is temporarily unavailable. Please try again in a moment.';
    }
}

if ($token === '' || (!$reset && !$errors)) {
    $errors[] = 'This reset link is invalid or expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    verify_csrf();

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!is_strong_password($password)) {
        $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE email = :email');
            $stmt->execute([
                'password_hash' => $hash,
                'email' => $reset['email'],
            ]);

            if (function_exists('kwarta_column_exists') && kwarta_column_exists($pdo, 'users', 'password')) {
                $stmt = $pdo->prepare('UPDATE users SET password = :password_hash WHERE email = :email');
                $stmt->execute([
                    'password_hash' => $hash,
                    'email' => $reset['email'],
                ]);
            }

            $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) $reset['id']]);
            clear_login_attempts($pdo, (string) $reset['email']);

            redirect('login.php?reset=1');
        } catch (Throwable $error) {
            error_log('[Kwarta reset password] ' . $error->getMessage());
            $errors[] = 'Password reset is temporarily unavailable. Please try again in a moment.';
        }
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<div class="card auth-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <?= mascot_img('rewards', 'auth-logo mx-auto mb-3', 'Kwarta reward mascot') ?>
            <h1 class="h3 fw-bold mb-1">Create a new password</h1>
            <p class="text-muted mb-0">Choose a strong password for your Kwarta account.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger pixel-alert" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($reset): ?>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="mb-3">
                    <label class="form-label" for="password">New Password</label>
                    <input class="form-control" id="password" type="password" name="password" required minlength="8">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <input class="form-control" id="confirm_password" type="password" name="confirm_password" required minlength="8">
                </div>
                <button class="btn btn-success w-100" type="submit">Update Password</button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-4 mb-0"><a href="login.php">Back to login</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
