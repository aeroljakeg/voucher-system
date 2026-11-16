<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('users.manage');

$connection = db();
$errors = [];
$success = '';
$permissions = $connection->query('SELECT id, code, description FROM permissions ORDER BY code')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (!is_string($action)) {
        $errors[] = 'The requested action is invalid.';
    } else {
        try {
            $name = $_POST['name'] ?? null;
            $description = $_POST['description'] ?? '';
            $permissionIds = $_POST['permission_ids'] ?? [];
            if ($action !== 'create' && $action !== 'save') {
                throw new InvalidArgumentException('Unknown role action.');
            }
            if (!is_string($name) || trim($name) === '' || app_text_length(trim($name)) < 0 || app_text_length(trim($name)) > 50) {
                throw new InvalidArgumentException('Role name is required and must be 50 characters or fewer.');
            }
            if (!is_string($description) || app_text_length($description) < 0 || app_text_length($description) > 255) {
                throw new InvalidArgumentException('Description must be 255 characters or fewer.');
            }
            if (!is_array($permissionIds)) {
                throw new InvalidArgumentException('Choose valid role permissions.');
            }
            $permissionIds = array_values(array_unique(array_map(static function ($id) {
                if (!is_string($id) && !is_int($id)) {
                    throw new InvalidArgumentException('Choose valid role permissions.');
                }
                $validId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($validId === false) {
                    throw new InvalidArgumentException('Choose valid role permissions.');
                }
                return (int) $validId;
            }, $permissionIds)));
            if (count(array_diff($permissionIds, array_map('intval', array_column($permissions, 'id')))) > 0) {
                throw new InvalidArgumentException('A selected permission does not exist.');
            }

            $connection->beginTransaction();
            $roleId = null;
            if ($action === 'save') {
                $rawRoleId = $_POST['role_id'] ?? null;
                if (!is_string($rawRoleId) && !is_int($rawRoleId)) {
                    throw new InvalidArgumentException('Choose a valid role.');
                }
                $roleId = filter_var($rawRoleId, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($roleId === false) {
                    throw new InvalidArgumentException('Choose a valid role.');
                }
            }
            $duplicateStatement = $connection->prepare(
                'SELECT id FROM roles WHERE name = :name' . ($roleId === null ? '' : ' AND id <> :id') . ' LIMIT 1'
            );
            $duplicateParameters = ['name' => trim($name)];
            if ($roleId !== null) {
                $duplicateParameters['id'] = (int) $roleId;
            }
            $duplicateStatement->execute($duplicateParameters);
            if ($duplicateStatement->fetchColumn()) {
                throw new DomainException('That role name is already in use.');
            }

            if ($action === 'create') {
                $insert = $connection->prepare(
                    'INSERT INTO roles (name, description) VALUES (:name, :description)'
                );
                $insert->execute(['name' => trim($name), 'description' => trim($description) ?: null]);
                $roleId = (int) $connection->lastInsertId();
                $roleName = trim($name);
            } else {
                $roleStatement = $connection->prepare('SELECT name FROM roles WHERE id = :id FOR UPDATE');
                $roleStatement->execute(['id' => (int) $roleId]);
                $roleName = $roleStatement->fetchColumn();
                if ($roleName === false) {
                    throw new DomainException('Role not found.');
                }
                if ($roleName === 'Admin' && (trim($name) !== 'Admin' || count($permissionIds) !== count($permissions))) {
                    throw new DomainException('The Admin role name and full permissions cannot be changed.');
                }
                $update = $connection->prepare(
                    'UPDATE roles SET name = :name, description = :description WHERE id = :id'
                );
                $update->execute([
                    'name' => trim($name),
                    'description' => trim($description) ?: null,
                    'id' => (int) $roleId,
                ]);
                $delete = $connection->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
                $delete->execute(['role_id' => (int) $roleId]);
                $roleName = trim($name);
            }

            $grant = $connection->prepare(
                'INSERT INTO role_permissions (role_id, permission_id)
                 VALUES (:role_id, :permission_id)'
            );
            foreach ($permissionIds as $permissionId) {
                $grant->execute(['role_id' => (int) $roleId, 'permission_id' => $permissionId]);
            }
            log_audit($action, 'role', (int) $roleId, null, [
                'name' => $roleName,
                'permission_ids' => $permissionIds,
            ]);
            $connection->commit();
            $success = $action === 'create' ? 'Role created.' : 'Role updated.';
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

$roles = $connection->query(
    'SELECT r.id, r.name, r.description, COUNT(DISTINCT u.id) AS user_count
     FROM roles r
     LEFT JOIN users u ON u.role_id = r.id
     GROUP BY r.id, r.name, r.description
     ORDER BY r.name'
)->fetchAll();
$rolePermissions = [];
$rolePermissionStatement = $connection->query(
    'SELECT rp.role_id, rp.permission_id
     FROM role_permissions rp
     ORDER BY rp.role_id, rp.permission_id'
);
foreach ($rolePermissionStatement->fetchAll() as $row) {
    $rolePermissions[(int) $row['role_id']][] = (int) $row['permission_id'];
}

$pageTitle = 'Roles and permissions';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Create role</h2></div>
    <div class="app-card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="app-form">
                <div><label for="new-name">Name</label><input id="new-name" name="name" maxlength="50" required></div>
                <div><label for="new-description">Description</label><input id="new-description" name="description" maxlength="255"></div>
            </div>
            <h3>Permissions</h3>
            <div class="app-grid"><?php foreach ($permissions as $permission): ?><label><input type="checkbox" name="permission_ids[]" value="<?= (int) $permission['id'] ?>"> <?= e($permission['code']) ?></label><?php endforeach; ?></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Create role</button></div>
        </form>
    </div>
</section>
<?php foreach ($roles as $role): ?>
    <section class="app-card">
        <div class="app-card-header"><h2><?= e($role['name']) ?></h2><span class="app-badge"><?= (int) $role['user_count'] ?> users</span></div>
        <div class="app-card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                <div class="app-form">
                    <div><label for="role-name-<?= (int) $role['id'] ?>">Name</label><input id="role-name-<?= (int) $role['id'] ?>" name="name" maxlength="50" value="<?= e($role['name']) ?>" required></div>
                    <div><label for="role-description-<?= (int) $role['id'] ?>">Description</label><input id="role-description-<?= (int) $role['id'] ?>" name="description" maxlength="255" value="<?= e($role['description'] ?? '') ?>"></div>
                </div>
                <h3>Permissions</h3>
                <div class="app-grid">
                    <?php foreach ($permissions as $permission): ?>
                        <?php $checked = in_array((int) $permission['id'], $rolePermissions[(int) $role['id']] ?? [], true); ?>
                        <label title="<?= e($permission['description'] ?? '') ?>">
                            <input type="checkbox" name="permission_ids[]" value="<?= (int) $permission['id'] ?>" <?= $checked ? 'checked' : '' ?>
                                <?= $role['name'] === 'Admin' ? 'disabled' : '' ?>>
                            <?= e($permission['code']) ?>
                        </label>
                        <?php if ($role['name'] === 'Admin' && $checked): ?><input type="hidden" name="permission_ids[]" value="<?= (int) $permission['id'] ?>"><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="app-actions"><button class="app-button app-button-primary" type="submit">Save role</button></div>
            </form>
        </div>
    </section>
<?php endforeach; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
