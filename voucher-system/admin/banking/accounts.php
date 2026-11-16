<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../services/BankService.php';

require_permission('bank.manage');

$connection = db();
$errors = [];
$success = '';
$formData = [
    'account_id' => '',
    'bank_name' => '',
    'account_name' => '',
    'account_number' => '',
    'opening_balance' => '0.00',
];

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
                foreach (array_keys($formData) as $field) {
                    if (isset($_POST[$field]) && (is_string($_POST[$field]) || is_int($_POST[$field]))) {
                        $formData[$field] = trim((string) $_POST[$field]);
                    }
                }
                BankService::createAccount($connection, $formData);
                header('Location: ' . app_url('admin/banking/accounts.php?created=1'));
                exit;
            }
            if ($action === 'toggle') {
                $id = app_positive_int_id($_POST['bank_account_id'] ?? null);
                $active = ($_POST['active'] ?? null) === '1';
                if ($id === false) {
                    throw new InvalidArgumentException('Choose a valid bank account.');
                }
                BankService::setAccountActive($connection, (int) $id, $active);
                header('Location: ' . app_url('admin/banking/accounts.php?updated=1'));
                exit;
            }
            throw new InvalidArgumentException('Unknown bank account action.');
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$ledgerAccounts = $connection->query(
    'SELECT a.id, a.code, a.name
     FROM accounts a
     WHERE a.is_active = 1 AND a.is_bank = 1
       AND NOT EXISTS (SELECT 1 FROM bank_accounts ba WHERE ba.account_id = a.id)
     ORDER BY a.code'
)->fetchAll();
$bankAccounts = BankService::accounts($connection);
$currencySymbol = app_setting('currency_symbol', '$') ?? '$';

$pageTitle = 'Bank accounts';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if (isset($_GET['created'])): ?><div class="app-alert" role="status">Bank account created.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="app-alert" role="status">Bank account status updated.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Add bank account</h2></div>
    <div class="app-card-body">
        <?php if (!$ledgerAccounts): ?>
            <p class="app-muted">Create an active bank-type account in the chart of accounts before adding a bank account.</p>
        <?php else: ?>
            <form class="app-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <div><label for="account_id">Ledger account</label><select id="account_id" name="account_id" required><option value="">Select account</option><?php foreach ($ledgerAccounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= $formData['account_id'] === (string) $account['id'] ? 'selected' : '' ?>><?= e($account['code'] . ' - ' . $account['name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="bank_name">Bank name</label><input id="bank_name" name="bank_name" maxlength="100" required value="<?= e($formData['bank_name']) ?>"></div>
                <div><label for="account_name">Account name</label><input id="account_name" name="account_name" maxlength="120" required value="<?= e($formData['account_name']) ?>"></div>
                <div><label for="account_number">Account number</label><input id="account_number" name="account_number" maxlength="50" required value="<?= e($formData['account_number']) ?>"></div>
                <div><label for="opening_balance">Opening balance (<?= e($currencySymbol) ?>)</label><input id="opening_balance" name="opening_balance" type="number" step="0.01" value="<?= e($formData['opening_balance']) ?>"></div>
                <div class="app-actions"><button class="app-button app-button-primary" type="submit">Add bank account</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Bank accounts</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Bank account</th><th>Ledger account</th><th>Account number</th><th class="numeric">Opening balance</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($bankAccounts): foreach ($bankAccounts as $account): ?>
                    <tr>
                        <td><strong><?= e($account['bank_name']) ?></strong><span class="app-muted" style="display:block"><?= e($account['account_name']) ?></span></td>
                        <td><?= e($account['account_code'] . ' - ' . $account['ledger_account_name']) ?></td>
                        <td><?= e(mask_account_number($account['account_number'])) ?></td>
                        <td class="numeric"><?= format_currency($account['opening_balance']) ?></td>
                        <td><span class="app-badge"><?= (int) $account['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="bank_account_id" value="<?= (int) $account['id'] ?>">
                                <input type="hidden" name="active" value="<?= (int) $account['is_active'] === 1 ? '0' : '1' ?>">
                                <button class="app-button" type="submit" data-confirm="<?= (int) $account['is_active'] === 1 ? 'Deactivate this bank account?' : 'Reactivate this bank account?' ?>">
                                    <?= (int) $account['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">No bank accounts have been configured.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
