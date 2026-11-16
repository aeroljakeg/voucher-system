<?php

require_once __DIR__ . '/config/auth.php';

start_app_session();
if (!empty($_SESSION['user_id'])) {
    $statement = db()->prepare(
        'SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    if ($statement->fetchColumn()) {
        header('Location: ' . app_url('admin/dashboard.php'));
        exit;
    }

    unset($_SESSION['user_id']);
}

header('Location: ' . app_url('shop/index.php'));
exit;
