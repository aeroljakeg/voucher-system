<?php

require_once __DIR__ . '/functions.php';

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function require_login(): void
{
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        $requestedPath = $_SERVER['REQUEST_URI'] ?? '';
        $adminPath = app_url('admin/');
        if (is_string($requestedPath) && strpos($requestedPath, $adminPath) === 0) {
            $_SESSION['after_login'] = $requestedPath;
        }
        header('Location: ' . app_url('auth/login.php'));
        exit;
    }

    $userStatement = db()->prepare(
        'SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $userStatement->execute(['id' => (int) $_SESSION['user_id']]);
    if (!$userStatement->fetchColumn()) {
        unset($_SESSION['user_id']);
        header('Location: ' . app_url('auth/login.php'));
        exit;
    }
}

function require_permission(string $permission): void
{
    require_login();

    if (!user_has_permission($permission)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
}

function user_has_permission(string $permission): bool
{
    start_app_session();
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT 1
         FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE u.id = :user_id AND u.is_active = 1 AND p.code = :permission
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'permission' => $permission,
    ]);

    return (bool) $statement->fetchColumn();
}
