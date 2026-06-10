<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_guest();

$pageTitle = 'Forgot Password';
$errors = [];
$notice = '';
$resetLink = null;
$email = '';

if (isset($_GET['security'])) {
    $errors[] = 'Your password reset form expired. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        try {
            $token = create_password_reset($pdo, $email);
            $notice = 'If this email is registered, a password reset link will be provided.';

            if ($token !== null) {
                $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
                $scheme = ($forwardedProto === 'https' || getenv('VERCEL') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetLink = $scheme . '://' . $host . '/reset-password.php?token=' . urlencode($token);
            }
        } catch (Throwable $error) {
            error_log('[Kwarta forgot password] ' . $error->getMessage());
            $errors[] = 'Password reset is temporarily unavailable. Please try again in a moment.';
        }
    }
}

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<div class="card auth-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <?= mascot_img('guide', 'auth-logo mx-auto mb-3', 'Kwarta guide mascot') ?>
            <h1 class="h3 fw-bold mb-1">Reset your password</h1>
            <p class="text-muted mb-0">Enter your email and get a secure reset link.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger pixel-alert" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="alert alert-success pixel-alert" role="alert">
                <?= e($notice) ?>
                <?php if ($resetLink): ?>
                    <div class="mt-3">
                        <div class="small text-muted mb-2">Demo reset link:</div>
                        <a class="text-break" href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="mb-4">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" type="email" name="email" value="<?= e($email) ?>" required maxlength="180">
            </div>
            <button class="btn btn-success w-100" type="submit">Send Reset Link</button>
        </form>

        <p class="text-center mt-4 mb-0"><a href="login.php">Back to login</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
