<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Login';
$errors = [];
$email = '';
$keepLogin = false;
$cooldownSeconds = 0;
$successMessage = '';

if (isset($_GET['security'])) {
    $errors[] = 'Your login form expired. Please try again.';
}

if (isset($_GET['reset'])) {
    $successMessage = 'Password updated. You can now log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $keepLogin = isset($_POST['keep_login']);

    if (!$pdo instanceof PDO) {
        $errors[] = 'Login is unavailable because the production database is not connected yet. Please contact the app owner.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        try {
            $attemptStatus = login_attempt_status($pdo, $email);
            if ($attemptStatus['locked']) {
                $cooldownSeconds = (int) $attemptStatus['seconds'];
                $errors[] = 'Too many failed login attempts. Please wait 1 minute before trying again.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();
                $storedHash = (string) ($user['password_hash'] ?? '');

                if ($user && ($user['status'] ?? 'active') !== 'active') {
                    $errors[] = 'This account is inactive. Please contact the system owner.';
                } elseif ($user && $storedHash !== '' && password_verify($password, $storedHash)) {
                    clear_login_attempts($pdo, $email);
                    login_user($user, $keepLogin);
                    $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                    $stmt->execute(['id' => (int) $user['id']]);

                    if (($user['role'] ?? 'user') === 'admin') {
                        log_admin_activity($pdo, (int) $user['id'], 'Admin login', 'Admin signed in through the shared login page.');
                        redirect('admin/dashboard.php?fresh=1');
                    }

                    redirect($keepLogin ? 'dashboard.php' : 'dashboard.php?fresh=1');
                } else {
                    $remaining = record_failed_login($pdo, $email);
                    if ($remaining <= 0) {
                        $cooldownSeconds = 60;
                        $errors[] = 'Too many failed login attempts. Please wait 1 minute before trying again.';
                    } else {
                        $errors[] = 'Incorrect email or password. Please try again.';
                    }
                }
            }
        } catch (Throwable $error) {
            error_log('[Kwarta login] ' . $error->getMessage());
            $errors[] = 'Login is temporarily unavailable. Please try again in a moment.';
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
            <div class="alert alert-danger pixel-alert" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
                <?php if ($cooldownSeconds > 0): ?>
                    <div class="small mt-2">Please try again in <strong id="cooldownTimer"><?= e((string) $cooldownSeconds) ?></strong> seconds.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success pixel-alert" role="alert">
                <?= e($successMessage) ?>
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
            <button class="btn btn-success w-100" type="submit" <?= $cooldownSeconds > 0 ? 'disabled' : '' ?>>Login</button>
        </form>
        <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mt-4">
            <a href="forgot-password.php">Forgot password?</a>
            <span>New to Kwarta? <a href="register.php">Create an account</a></span>
        </div>
    </div>
</div>

<?php if ($cooldownSeconds > 0): ?>
    <script>
        (() => {
            const timer = document.getElementById('cooldownTimer');
            let seconds = Number(timer?.textContent || 0);
            const tick = () => {
                seconds -= 1;
                if (timer) timer.textContent = String(Math.max(0, seconds));
                if (seconds <= 0) window.location.href = 'login.php';
            };
            setInterval(tick, 1000);
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
