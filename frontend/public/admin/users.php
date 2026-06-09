<?php

require_once __DIR__ . '/../../../backend/includes/auth.php';

require_admin();

$pageTitle = 'User Management';
$adminId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (!in_array($status, ['active', 'inactive'], true)) {
        flash('error', 'Invalid account status.');
        redirect('users.php');
    }

    if ($userId === $adminId && $status === 'inactive') {
        flash('error', 'You cannot deactivate your own admin account.');
        redirect('users.php');
    }

    $stmt = $pdo->prepare('
        UPDATE users
        SET status = :status
        WHERE id = :id AND role <> "admin"
    ');
    $stmt->execute(['status' => $status, 'id' => $userId]);

    if ($stmt->rowCount() > 0) {
        log_admin_activity($pdo, $adminId, 'User status updated', 'User ID ' . $userId . ' set to ' . $status . '.');
        flash('success', 'User account updated.');
    } else {
        flash('error', 'Admin roles cannot be changed or deactivated from the website.');
    }

    redirect('users.php');
}

$stmt = $pdo->query('
    SELECT id, name, email, role, status, created_at, last_login_at
    FROM users
    ORDER BY created_at DESC, id DESC
');
$users = $stmt->fetchAll();

require_once __DIR__ . '/../../../backend/includes/header.php';
?>

<section class="page-header-panel">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-avatar" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <h1 class="page-header-title">User Management</h1>
            <p class="page-header-subtitle">Activate or deactivate user accounts without accessing private financial records.</p>
        </div>
    </div>
</section>

<div class="admin-privacy-note mb-4">
    <strong>Privacy note:</strong> Admins can see basic account metadata only. Transaction records, budgets, cart items, and receipt details are not shown here.
</div>

<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Created</th>
                        <th>Last Login</th>
                        <th>Status</th>
                        <th>Role</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int) $user['id'] ?></td>
                            <td><?= e($user['name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e(date('M d, Y', strtotime($user['created_at']))) ?></td>
                            <td><?= $user['last_login_at'] ? e(date('M d, Y g:i A', strtotime($user['last_login_at']))) : 'Never' ?></td>
                            <td><span class="badge <?= $user['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= e(ucfirst($user['status'])) ?></span></td>
                            <td><span class="badge <?= $user['role'] === 'admin' ? 'text-bg-warning' : 'text-bg-light' ?>"><?= e(ucfirst($user['role'])) ?></span></td>
                            <td class="text-end">
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="text-muted-small">Database only</span>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $user['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <button class="btn btn-sm <?= $user['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                            <?= $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../../backend/includes/footer.php'; ?>
