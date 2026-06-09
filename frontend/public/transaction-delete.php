<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('transactions.php');
}

verify_csrf();

$stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
$stmt->execute([
    'id' => (int) ($_POST['id'] ?? 0),
    'user_id' => current_user_id(),
]);

flash('success', 'Transaction deleted.');
redirect('transactions.php');
