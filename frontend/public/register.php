<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Create Account';
$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$pdo instanceof PDO) {
        $errors[] = 'Online account creation is temporarily unavailable while Kwarta connects to its production database. Please try again later.';
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

<div class="card auth-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <?= mascot_img('logo', 'auth-logo mx-auto mb-3', 'Kwarta mascot logo') ?>
            <h1 class="h3 fw-bold mb-1">Create your Kwarta account</h1>
            <p class="text-muted mb-0">Track your money with simple, student-friendly tools.</p>
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
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" value="<?= e($name) ?>" required maxlength="120">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" type="email" name="email" value="<?= e($email) ?>" required maxlength="180">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" type="password" name="password" required minlength="8">
            </div>
            <div class="mb-4">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <input class="form-control" id="confirm_password" type="password" name="confirm_password" required minlength="8">
            </div>
            <button class="btn btn-success w-100" type="submit">Register</button>
        </form>
        <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php">Log in</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
