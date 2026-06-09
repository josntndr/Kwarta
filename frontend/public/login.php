<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Login';
$errors = [];
$email = '';
$keepLogin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $keepLogin = isset($_POST['keep_login']);

    if (!$pdo instanceof PDO) {
        $errors[] = 'Online accounts are temporarily unavailable while Kwarta connects to its production database. Please try again later.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && ($user['status'] ?? 'active') !== 'active') {
            $errors[] = 'This account is inactive. Please contact the system owner.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            login_user($user, $keepLogin);
            $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) $user['id']]);

            if (($user['role'] ?? 'user') === 'admin') {
                log_admin_activity($pdo, (int) $user['id'], 'Admin login', 'Admin signed in through the shared login page.');
                redirect('admin/dashboard.php?fresh=1');
            }

            redirect($keepLogin ? 'dashboard.php' : 'dashboard.php?fresh=1');
        } elseif (!$errors) {
            $errors[] = 'Invalid email or password.';
        }
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<div class="card auth-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <?= mascot_img('logo', 'auth-logo mx-auto mb-3', 'Kwarta mascot logo') ?>
            <h1 class="h3 fw-bold mb-1">Welcome back to Kwarta</h1>
            <p class="text-muted mb-0">Sign in to view your dashboard.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" type="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" type="password" name="password" required>
            </div>
            <div class="form-check mb-4">
                <input class="form-check-input" id="keep_login" type="checkbox" name="keep_login" value="1" <?= $keepLogin ? 'checked' : '' ?>>
                <label class="form-check-label" for="keep_login">Keep me logged in</label>
            </div>
            <button class="btn btn-success w-100" type="submit">Login</button>
        </form>
        <p class="text-center mt-4 mb-0">New to Kwarta? <a href="register.php">Create an account</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
