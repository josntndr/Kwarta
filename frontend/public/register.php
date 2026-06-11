<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Create Account';
$errors = [];
$name = '';
$email = '';

if (isset($_GET['security'])) {
    $errors[] = 'Your registration form expired. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$pdo instanceof PDO) {
        $errors[] = 'Account creation is unavailable because the production database is not connected yet. Please contact the app owner.';
    }

    if ($name === '' || strlen($name) > 120) {
        $errors[] = 'Please enter your name using 120 characters or fewer.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!is_strong_password($password)) {
        $errors[] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO users (name, email, password_hash, role, status)
                VALUES (:name, :email, :password_hash, "user", "active")
            ');
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $newUserId = (int) $pdo->lastInsertId();
            ensure_game_stats($pdo, $newUserId);
            ensure_wallet($pdo, $newUserId, 0);

            flash('success', 'Account created. You can now log in.');
            redirect('login.php');
        }
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<div class="card auth-card login-card register-card">
    <div class="card-body login-card-body">
        <div class="text-center login-header">
            <?= mascot_img('logo', 'auth-logo login-logo mx-auto', 'Kwarta mascot logo') ?>
            <span class="login-kicker">Kwarta Player Signup</span>
            <h1>Create your account</h1>
            <p class="text-muted mb-0">Start tracking your money quests with Kwarta.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger pixel-alert" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="login-form" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="login-field">
                <label class="form-label" for="name">Name</label>
                <input class="form-control login-input" id="name" name="name" value="<?= e($name) ?>" placeholder="Enter your name" autocomplete="name" required maxlength="120">
            </div>
            <div class="login-field">
                <label class="form-label" for="email">Email</label>
                <input class="form-control login-input" id="email" type="email" name="email" value="<?= e($email) ?>" placeholder="Enter your email" autocomplete="email" required maxlength="180">
            </div>
            <div class="login-field">
                <label class="form-label" for="password">Password</label>
                <div class="password-toggle-wrap">
                    <input class="form-control login-input password-toggle-input" id="password" type="password" name="password" placeholder="Create a password" autocomplete="new-password" required minlength="8">
                    <button class="password-toggle-btn" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle="password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="login-field">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="password-toggle-wrap">
                    <input class="form-control login-input password-toggle-input" id="confirm_password" type="password" name="confirm_password" placeholder="Confirm your password" autocomplete="new-password" required minlength="8">
                    <button class="password-toggle-btn" type="button" aria-label="Show confirm password" aria-pressed="false" data-password-toggle="confirm_password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <button class="btn btn-success login-submit" type="submit">Register</button>
        </form>
        <div class="login-register">
            Already have an account? <a href="login.php">Log in</a>
        </div>
    </div>
</div>

<script>
    (() => {
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            const input = document.getElementById(button.dataset.passwordToggle);
            const icon = button.querySelector('i');
            if (!input || !icon) return;

            button.addEventListener('click', () => {
                const showPassword = input.type === 'password';
                input.type = showPassword ? 'text' : 'password';
                const fieldName = input.id === 'confirm_password' ? 'confirm password' : 'password';
                button.setAttribute('aria-label', showPassword ? `Hide ${fieldName}` : `Show ${fieldName}`);
                button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
                icon.classList.toggle('bi-eye', !showPassword);
                icon.classList.toggle('bi-eye-slash', showPassword);
                input.focus();
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
