<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('accounts.manage');

function account_form_data(array $input, ?int $accountId): array
{
    $code = $input['code'] ?? null;
    $name = $input['name'] ?? null;
    $type = $input['type'] ?? null;
    $normalBalance = $input['normal_balance'] ?? null;
    $rawParent = $input['parent_id'] ?? '';

    foreach (['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance] as $label => $value) {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $label)) . ' is required.');
        }
    }

    $code = trim($code);
    $name = trim($name);
    if (app_text_length($code) < 0 || app_text_length($name) < 0
        || app_text_length($code) > 20 || app_text_length($name) > 120) {
        throw new InvalidArgumentException('Account code must be 20 characters or fewer and name 120 characters or fewer.');
    }
    if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        throw new InvalidArgumentException('Choose a valid account type.');
    }
    if (!in_array($normalBalance, ['debit', 'credit'], true)) {
        throw new InvalidArgumentException('Choose a valid normal balance.');
    }
    foreach (['is_bank', 'is_active'] as $checkbox) {
        if (isset($input[$checkbox]) && $input[$checkbox] !== '1') {
            throw new InvalidArgumentException('Account options are invalid.');
        }
    }

    $parentId = null;
    if ($rawParent !== '') {
        if (!is_string($rawParent) && !is_int($rawParent)) {
            throw new InvalidArgumentException('Choose a valid parent account.');
        }
        $parentId = filter_var($rawParent, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId === false || ($accountId !== null && (int) $parentId === $accountId)) {
            throw new InvalidArgumentException('Choose a valid parent account.');
        }
        $parentId = (int) $parentId;
    }

    return [
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'normal_balance' => $normalBalance,
        'parent_id' => $parentId,
        'is_bank' => isset($input['is_bank']) && $input['is_bank'] === '1' ? 1 : 0,
        'is_active' => isset($input['is_active']) && $input['is_active'] === '1' ? 1 : 0,
    ];
}

$connection = db();
$errors = [];
$success = '';
$editId = app_positive_int_id($_GET['id'] ?? null);
if ($editId === false) {
    $editId = null;
}
$account = null;
if ($editId !== null) {
    $editStatement = $connection->prepare('SELECT * FROM accounts WHERE id = :id LIMIT 1');
    $editStatement->execute(['id' => $editId]);
    $account = $editStatement->fetch();
    if (!$account) {
        http_response_code(404);
        exit('Account not found.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawId = $_POST['account_id'] ?? '';
    $postedId = $rawId === ''
        ? null
        : ((!is_string($rawId) && !is_int($rawId)) ? false : filter_var($rawId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]));
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($postedId === false) {
        $errors[] = 'The account ID is invalid.';
    } else {
        try {
            $data = account_form_data($_POST, $postedId === null ? null : (int) $postedId);
            $connection->beginTransaction();

            if ($postedId === null) {
                $parentCheck = null;
                if ($data['parent_id'] !== null) {
                    $parentCheck = $connection->prepare('SELECT id FROM accounts WHERE id = :id AND is_active = 1');
                    $parentCheck->execute(['id' => $data['parent_id']]);
                    if (!$parentCheck->fetchColumn()) {
                        throw new InvalidArgumentException('Parent account is inactive or unavailable.');
                    }
                }
                if ($data['is_bank'] && $data['type'] !== 'asset') {
                    throw new InvalidArgumentException('Only asset accounts can be marked as bank accounts.');
                }
                $duplicate = $connection->prepare('SELECT id FROM accounts WHERE code = :code LIMIT 1');
                $duplicate->execute(['code' => $data['code']]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('Account code is already in use.');
                }
                $insert = $connection->prepare(
                    'INSERT INTO accounts (code, name, type, normal_balance, parent_id, is_bank, is_active)
                     VALUES (:code, :name, :type, :normal_balance, :parent_id, :is_bank, :is_active)'
                );
                $insert->execute($data);
                $savedId = (int) $connection->lastInsertId();
                log_audit('create', 'account', $savedId, null, $data);
                $connection->commit();
                header('Location: ' . app_url('admin/accounts/index.php?id=' . $savedId . '&saved=1'));
                exit;
            }

            $currentStatement = $connection->prepare('SELECT * FROM accounts WHERE id = :id FOR UPDATE');
            $currentStatement->execute(['id' => (int) $postedId]);
            $current = $currentStatement->fetch();
            if (!$current) {
                throw new DomainException('Account not found.');
            }
            if ($data['parent_id'] !== null) {
                $parentStatement = $connection->prepare('SELECT id, parent_id FROM accounts WHERE id = :id AND is_active = 1');
                $parentId = $data['parent_id'];
                $visited = [];
                while ($parentId !== null) {
                    if ($parentId === (int) $postedId || isset($visited[$parentId])) {
                        throw new DomainException('Parent selection would create an account hierarchy cycle.');
                    }
                    $visited[$parentId] = true;
                    $parentStatement->execute(['id' => $parentId]);
                    $parent = $parentStatement->fetch();
                    if (!$parent) {
                        throw new InvalidArgumentException('Parent account is inactive or unavailable.');
                    }
                    $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
                    if (count($visited) > 100) {
                        throw new DomainException('Account hierarchy is too deep to update safely.');
                    }
                }
            }
            if ($data['is_bank'] && $data['type'] !== 'asset') {
                throw new InvalidArgumentException('Only asset accounts can be marked as bank accounts.');
            }
            if (!$data['is_active']) {
                $children = $connection->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id = :id AND is_active = 1');
                $children->execute(['id' => (int) $postedId]);
                if ((int) $children->fetchColumn() > 0) {
                    throw new DomainException('Deactivate child accounts before deactivating this account.');
                }
                $bankLink = $connection->prepare(
                    'SELECT id FROM bank_accounts WHERE account_id = :id AND is_active = 1 LIMIT 1'
                );
                $bankLink->execute(['id' => (int) $postedId]);
                if ($bankLink->fetchColumn()) {
                    throw new DomainException('Deactivate the linked bank account before deactivating this ledger account.');
                }
            }
            if ((int) $current['is_bank'] === 1 && !$data['is_bank']) {
                $bankLink = $connection->prepare('SELECT id FROM bank_accounts WHERE account_id = :id LIMIT 1');
                $bankLink->execute(['id' => (int) $postedId]);
                if ($bankLink->fetchColumn()) {
                    throw new DomainException('Remove the bank account link before clearing the bank-account flag.');
                }
            }
            $usage = $connection->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM journal_lines WHERE account_id = :journal_account) +
                    (SELECT COUNT(*) FROM voucher_lines WHERE account_id = :voucher_account) AS total'
            );
            $usage->execute([
                'journal_account' => (int) $postedId,
                'voucher_account' => (int) $postedId,
            ]);
            if ((int) $usage->fetchColumn() > 0
                && ($current['type'] !== $data['type'] || $current['normal_balance'] !== $data['normal_balance'])) {
                throw new DomainException('Account type and normal balance cannot change after the account has been used.');
            }

            $duplicate = $connection->prepare('SELECT id FROM accounts WHERE code = :code AND id <> :id LIMIT 1');
            $duplicate->execute(['code' => $data['code'], 'id' => (int) $postedId]);
            if ($duplicate->fetchColumn()) {
                throw new DomainException('Account code is already in use.');
            }
            $update = $connection->prepare(
                'UPDATE accounts
                 SET code = :code, name = :name, type = :type, normal_balance = :normal_balance,
                     parent_id = :parent_id, is_bank = :is_bank, is_active = :is_active
                 WHERE id = :id'
            );
            $update->execute($data + ['id' => (int) $postedId]);
            log_audit('update', 'account', (int) $postedId, [
                'code' => $current['code'],
                'name' => $current['name'],
                'type' => $current['type'],
                'normal_balance' => $current['normal_balance'],
                'is_active' => (int) $current['is_active'],
            ], $data);
            $connection->commit();
            header('Location: ' . app_url('admin/accounts/index.php?id=' . (int) $postedId . '&saved=1'));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        }
    }
}

$accounts = $connection->query(
    'SELECT a.*, parent.code AS parent_code, parent.name AS parent_name
     FROM accounts a
     LEFT JOIN accounts parent ON parent.id = a.parent_id
     ORDER BY a.code'
)->fetchAll();
$parentOptions = array_values(array_filter($accounts, static function (array $candidate) use ($editId): bool {
    return (int) $candidate['is_active'] === 1 && ($editId === null || (int) $candidate['id'] !== $editId);
}));

$form = $account ?: [
    'id' => '',
    'code' => '',
    'name' => '',
    'type' => 'asset',
    'normal_balance' => 'debit',
    'parent_id' => '',
    'is_bank' => 0,
    'is_active' => 1,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    foreach (['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_bank', 'is_active'] as $field) {
        if (isset($_POST[$field]) && (is_string($_POST[$field]) || is_int($_POST[$field]))) {
            $form[$field] = $_POST[$field];
        }
    }
}

$pageTitle = 'Chart of accounts';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?><div class="app-alert" role="status">Account saved.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2><?= $editId !== null ? 'Edit account' : 'Add account' ?></h2><?php if ($editId !== null): ?><a href="<?= e(app_url('admin/accounts/index.php')) ?>">New account</a><?php endif; ?></div>
    <div class="app-card-body">
        <form class="app-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="account_id" value="<?= e((string) ($form['id'] ?? $editId ?? '')) ?>">
            <div><label for="code">Code</label><input id="code" name="code" maxlength="20" required value="<?= e((string) ($form['code'] ?? '')) ?>"></div>
            <div><label for="name">Name</label><input id="name" name="name" maxlength="120" required value="<?= e((string) ($form['name'] ?? '')) ?>"></div>
            <div><label for="type">Type</label><select id="type" name="type"><?php foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type): ?><option value="<?= e($type) ?>" <?= ($form['type'] ?? '') === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></div>
            <div><label for="normal_balance">Normal balance</label><select id="normal_balance" name="normal_balance"><option value="debit" <?= ($form['normal_balance'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option><option value="credit" <?= ($form['normal_balance'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option></select></div>
            <div><label for="parent_id">Parent account</label><select id="parent_id" name="parent_id"><option value="">None</option><?php foreach ($parentOptions as $parent): ?><option value="<?= (int) $parent['id'] ?>" <?= (string) ($form['parent_id'] ?? '') === (string) $parent['id'] ? 'selected' : '' ?>><?= e($parent['code'] . ' - ' . $parent['name']) ?></option><?php endforeach; ?></select></div>
            <div class="app-actions"><label><input type="checkbox" name="is_bank" value="1" <?= (int) ($form['is_bank'] ?? 0) === 1 || ($form['is_bank'] ?? '') === '1' ? 'checked' : '' ?>> Bank account ledger</label><label><input type="checkbox" name="is_active" value="1" <?= (int) ($form['is_active'] ?? 0) === 1 || ($form['is_active'] ?? '') === '1' ? 'checked' : '' ?>> Active</label></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Save account</button></div>
        </form>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Accounts</h2><span class="app-badge"><?= count($accounts) ?> total</span></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Normal balance</th><th>Parent</th><th>Bank</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($accounts as $row): ?>
                    <tr><td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e(ucfirst($row['type'])) ?></td><td><?= e(ucfirst($row['normal_balance'])) ?></td><td><?= e($row['parent_code'] ? $row['parent_code'] . ' - ' . $row['parent_name'] : '—') ?></td><td><?= (int) $row['is_bank'] === 1 ? 'Yes' : 'No' ?></td><td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td><td><a href="<?= e(app_url('admin/accounts/index.php?id=' . (int) $row['id'])) ?>">Edit</a></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
