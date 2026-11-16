<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('users.manage');

$connection = db();
$errors = [];
$success = '';
$roles = $connection->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (!is_string($action)) {
        $errors[] = 'The requested action is invalid.';
    } else {
        try {
            if ($action === 'create') {
                $username = $_POST['username'] ?? null;
                $fullName = $_POST['full_name'] ?? null;
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? null;
                $rawRoleId = $_POST['role_id'] ?? null;
                $roleId = is_string($rawRoleId) || is_int($rawRoleId)
                    ? filter_var($rawRoleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                if (!is_string($username) || !preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username)) {
                    throw new InvalidArgumentException('Username must be 3–50 letters, numbers, dots, underscores, or hyphens.');
                }
                if (!is_string($fullName) || trim($fullName) === '' || app_text_length($fullName) < 0 || app_text_length($fullName) > 100) {
                    throw new InvalidArgumentException('Full name is required and must be 100 characters or fewer.');
                }
                if (!is_string($email) || ($email !== '' && (app_text_length($email) < 0 || app_text_length($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
                    throw new InvalidArgumentException('Enter a valid email address of 120 characters or fewer.');
                }
                if (!is_string($password) || strlen($password) < 12 || strlen($password) > 200) {
                    throw new InvalidArgumentException('Password must be at least 12 characters.');
                }
                if ($roleId === false) {
                    throw new InvalidArgumentException('Choose a valid role.');
                }
                $roleCheck = $connection->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
                $roleCheck->execute(['id' => (int) $roleId]);
                if (!$roleCheck->fetchColumn()) {
                    throw new InvalidArgumentException('The selected role is unavailable.');
                }
                $insert = $connection->prepare(
                    'INSERT INTO users (role_id, username, full_name, email, password_hash)
                     VALUES (:role_id, :username, :full_name, :email, :password_hash)'
                );
                $insert->execute([
                    'role_id' => (int) $roleId,
                    'username' => $username,
                    'full_name' => trim($fullName),
                    'email' => $email === '' ? null : trim($email),
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $id = (int) $connection->lastInsertId();
                log_audit('create', 'user', $id, null, [
                    'username' => $username,
                    'role_id' => (int) $roleId,
                ]);
                $success = 'User created.';
            } elseif ($action === 'update') {
                $rawUserId = $_POST['user_id'] ?? null;
                $rawRoleId = $_POST['role_id'] ?? null;
                $userId = is_string($rawUserId) || is_int($rawUserId)
                    ? filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                $roleId = is_string($rawRoleId) || is_int($rawRoleId)
                    ? filter_var($rawRoleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                $activeValue = $_POST['is_active'] ?? null;
                if ($userId === false || $roleId === false || !in_array($activeValue, ['0', '1'], true)) {
                    throw new InvalidArgumentException('User update values are invalid.');
                }
                $roleCheck = $connection->prepare('SELECT name FROM roles WHERE id = :id LIMIT 1');
                $roleCheck->execute(['id' => (int) $roleId]);
                $newRoleName = $roleCheck->fetchColumn();
                if ($newRoleName === false) {
                    throw new InvalidArgumentException('The selected role is unavailable.');
                }

                $connection->beginTransaction();
                $activeAdmins = $connection->query(
                    'SELECT u.id FROM users u
                     JOIN roles r ON r.id = u.role_id
                     WHERE r.name = \'Admin\' AND u.is_active = 1
                     ORDER BY u.id FOR UPDATE'
                )->fetchAll();
                $userStatement = $connection->prepare(
                    'SELECT u.username, u.role_id, u.is_active, r.name AS role_name
                     FROM users u JOIN roles r ON r.id = u.role_id
                     WHERE u.id = :id FOR UPDATE'
                );
                $userStatement->execute(['id' => (int) $userId]);
                $current = $userStatement->fetch();
                if (!$current) {
                    throw new DomainException('User not found.');
                }
                if ((int) $userId === (int) $_SESSION['user_id'] && $activeValue === '0') {
                    throw new DomainException('You cannot deactivate your own account.');
                }
                if ($current['role_name'] === 'Admin'
                    && ($newRoleName !== 'Admin' || $activeValue === '0')) {
                    if (count($activeAdmins) <= 1) {
                        throw new DomainException('The last active administrator cannot be removed or deactivated.');
                    }
                }
                $update = $connection->prepare(
                    'UPDATE users SET role_id = :role_id, is_active = :is_active WHERE id = :id'
                );
                $update->execute([
                    'role_id' => (int) $roleId,
                    'is_active' => (int) $activeValue,
                    'id' => (int) $userId,
                ]);
                log_audit('update', 'user', (int) $userId, [
                    'role_id' => (int) $current['role_id'],
                    'is_active' => (int) $current['is_active'],
                ], [
                    'role_id' => (int) $roleId,
                    'is_active' => (int) $activeValue,
                ]);
                $connection->commit();
                $success = 'User updated.';
            } elseif ($action === 'reset_password') {
                $rawUserId = $_POST['user_id'] ?? null;
                $userId = is_string($rawUserId) || is_int($rawUserId)
                    ? filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                $password = $_POST['password'] ?? null;
                if ($userId === false || !is_string($password) || strlen($password) < 12 || strlen($password) > 200) {
                    throw new InvalidArgumentException('Choose a user and enter a password of at least 12 characters.');
                }
                $update = $connection->prepare(
                    'UPDATE users SET password_hash = :password_hash WHERE id = :id'
                );
                $update->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => (int) $userId,
                ]);
                if ($update->rowCount() === 0) {
                    $check = $connection->prepare('SELECT id FROM users WHERE id = :id');
                    $check->execute(['id' => (int) $userId]);
                    if (!$check->fetchColumn()) {
                        throw new DomainException('User not found.');
                    }
                }
                log_audit('password_reset', 'user', (int) $userId);
                $success = 'Password reset.';
            } else {
                throw new InvalidArgumentException('Unknown user action.');
            }
        } catch (InvalidArgumentException | DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}

$users = $connection->query(
    'SELECT u.id, u.username, u.full_name, u.email, u.is_active, u.last_login, u.created_at,
            u.role_id, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     ORDER BY u.username'
)->fetchAll();

$pageTitle = 'User management';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Create user</h2></div>
    <div class="app-card-body">
        <form class="app-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div><label for="username">Username</label><input id="username" name="username" maxlength="50" autocomplete="off" required></div>
            <div><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" required></div>
            <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120"></div>
            <div><label for="role_id">Role</label><select id="role_id" name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="password">Temporary password</label><input id="password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Create user</button></div>
        </form>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Users</h2><span class="app-badge"><?= count($users) ?> total</span></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>User</th><th>Role and status</th><th>Last login</th><th>Update access</th><th>Reset password</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= e($user['full_name']) ?></strong><span class="app-muted" style="display:block"><?= e($user['username']) ?><?= $user['email'] ? ' · ' . e($user['email']) : '' ?></span></td>
                        <td><?= e($user['role_name']) ?> · <?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td><?= e($user['last_login'] ?: 'Never') ?></td>
                        <td>
                            <form method="post" class="app-actions">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <select name="role_id" aria-label="Role for <?= e($user['username']) ?>"><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option><?php endforeach; ?></select>
                                <select name="is_active" aria-label="Status for <?= e($user['username']) ?>"><option value="1" <?= (int) $user['is_active'] === 1 ? 'selected' : '' ?>>Active</option><option value="0" <?= (int) $user['is_active'] !== 1 ? 'selected' : '' ?>>Inactive</option></select>
                                <button class="app-button" type="submit">Save</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="app-actions">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input type="password" name="password" minlength="12" maxlength="200" placeholder="New password" autocomplete="new-password" required aria-label="New password for <?= e($user['username']) ?>">
                                <button class="app-button" type="submit" data-confirm="Reset this user's password?">Reset</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
