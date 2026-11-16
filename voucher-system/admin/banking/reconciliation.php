<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../services/BankService.php';

require_permission('bank.manage');

$connection = db();
$accounts = BankService::accounts($connection, false);
$errors = [];
$selectedId = app_positive_int_id($_GET['account_id'] ?? null);
if ($selectedId === false) {
    $selectedId = $accounts ? (int) $accounts[0]['id'] : null;
}
$statementDate = is_string($_GET['statement_date'] ?? null)
    ? trim($_GET['statement_date'])
    : date('Y-m-d');
if ($statementDate === '') {
    $statementDate = date('Y-m-d');
}
$reconciliationId = app_positive_int_id($_GET['reconciliation_id'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $postedAccountId = app_positive_int_id($_POST['bank_account_id'] ?? null);
    $rawDate = $_POST['statement_date'] ?? '';
    $rawBalance = $_POST['statement_balance'] ?? '';
    $rawTransactionIds = $_POST['transaction_ids'] ?? [];
    $statementDate = is_string($rawDate) ? trim($rawDate) : '';

    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($postedAccountId === false || !is_string($rawDate) || !is_string($rawBalance) || !is_array($rawTransactionIds)) {
        $errors[] = 'The reconciliation request is invalid.';
    } else {
        try {
            $result = BankService::reconcile(
                $connection,
                (int) $postedAccountId,
                $statementDate,
                trim($rawBalance),
                $rawTransactionIds,
                (int) $_SESSION['user_id']
            );
            header('Location: ' . app_url(
                'admin/banking/reconciliation.php?account_id=' . (int) $postedAccountId
                . '&statement_date=' . rawurlencode($statementDate)
                . '&reconciliation_id=' . $result['id']
            ));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$selectedAccount = null;
foreach ($accounts as $account) {
    if ((int) $account['id'] === (int) $selectedId) {
        $selectedAccount = $account;
        break;
    }
}

$transactions = [];
if ($selectedAccount && is_iso_date($statementDate)) {
    $transactionStatement = $connection->prepare(
        'SELECT id, txn_date, reference, description, deposit, withdrawal
         FROM bank_transactions
         WHERE bank_account_id = :bank_account_id
           AND is_reconciled = 0 AND txn_date <= :statement_date
         ORDER BY txn_date, id'
    );
    $transactionStatement->execute([
        'bank_account_id' => (int) $selectedAccount['id'],
        'statement_date' => $statementDate,
    ]);
    $transactions = $transactionStatement->fetchAll();
} elseif (!is_iso_date($statementDate)) {
    $errors[] = 'Enter a valid statement date.';
}

$savedReconciliation = null;
if ($reconciliationId !== false && $selectedAccount) {
    $savedStatement = $connection->prepare(
        'SELECT statement_date, statement_balance, book_balance, created_at
         FROM bank_reconciliations
         WHERE id = :id AND bank_account_id = :bank_account_id
         LIMIT 1'
    );
    $savedStatement->execute([
        'id' => (int) $reconciliationId,
        'bank_account_id' => (int) $selectedAccount['id'],
    ]);
    $savedReconciliation = $savedStatement->fetch() ?: null;
}
$history = [];
if ($selectedAccount) {
    $historyStatement = $connection->prepare(
        'SELECT br.id, br.statement_date, br.statement_balance, br.book_balance,
                br.created_at, u.full_name
         FROM bank_reconciliations br
         JOIN users u ON u.id = br.reconciled_by
         WHERE br.bank_account_id = :bank_account_id
         ORDER BY br.statement_date DESC, br.id DESC
         LIMIT 20'
    );
    $historyStatement->execute(['bank_account_id' => (int) $selectedAccount['id']]);
    $history = $historyStatement->fetchAll();
}

$pageTitle = 'Bank reconciliation';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if ($savedReconciliation): ?>
    <?php $variance = signed_amount_to_cents((string) $savedReconciliation['statement_balance']) - signed_amount_to_cents((string) $savedReconciliation['book_balance']); ?>
    <div class="app-alert" role="status">Reconciliation saved. Statement-to-book difference: <?= format_currency(signed_cents_to_decimal($variance)) ?>.</div>
<?php endif; ?>
<?php if (!$accounts): ?>
    <section class="app-card app-card-body"><p class="app-empty">Add an active bank account before reconciling transactions.</p></section>
<?php else: ?>
    <section class="app-card app-card-body">
        <form class="app-form" method="get">
            <div><label for="account_id">Bank account</label><select id="account_id" name="account_id"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $selectedId === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['bank_name'] . ' - ' . $account['account_name'] . ' (' . mask_account_number($account['account_number']) . ')') ?></option><?php endforeach; ?></select></div>
            <div><label for="statement_date">Statement date</label><input id="statement_date" type="date" name="statement_date" value="<?= e($statementDate) ?>" required></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Load transactions</button></div>
        </form>
    </section>
    <?php if ($selectedAccount): ?>
        <section class="app-card">
            <div class="app-card-header"><h2>Unreconciled transactions through <?= e($statementDate) ?></h2><span class="app-badge"><?= count($transactions) ?> available</span></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="bank_account_id" value="<?= (int) $selectedAccount['id'] ?>">
                <input type="hidden" name="statement_date" value="<?= e($statementDate) ?>">
                <div class="app-card-body app-form">
                    <div><label for="statement_balance">Statement ending balance</label><input id="statement_balance" name="statement_balance" type="number" step="0.01" required></div>
                    <p class="app-muted">Choose the bank statement items that have cleared. The book balance is calculated from the linked ledger account through the statement date.</p>
                </div>
                <div class="app-table-wrap">
                    <table class="app-table">
                        <thead><tr><th><input type="checkbox" data-select-all aria-label="Select all transactions"></th><th>Date</th><th>Reference / description</th><th class="numeric">Deposit</th><th class="numeric">Withdrawal</th></tr></thead>
                        <tbody>
                            <?php if ($transactions): foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><input type="checkbox" name="transaction_ids[]" value="<?= (int) $transaction['id'] ?>" aria-label="Select transaction <?= (int) $transaction['id'] ?>"></td>
                                    <td><?= e($transaction['txn_date']) ?></td>
                                    <td><?= e($transaction['reference'] ?: '—') ?><?php if ($transaction['description']): ?><span class="app-muted" style="display:block"><?= e($transaction['description']) ?></span><?php endif; ?></td>
                                    <td class="numeric"><?= (float) $transaction['deposit'] > 0 ? format_currency($transaction['deposit']) : '—' ?></td>
                                    <td class="numeric"><?= (float) $transaction['withdrawal'] > 0 ? format_currency($transaction['withdrawal']) : '—' ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td class="app-empty" colspan="5">No unreconciled transactions are available through this date.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="app-card-body app-actions"><button class="app-button app-button-primary" type="submit" data-confirm="Save this reconciliation and mark selected statement items as reconciled?">Save reconciliation</button></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="app-card">
        <div class="app-card-header"><h2>Recent reconciliations</h2></div>
        <div class="app-table-wrap">
            <table class="app-table">
                <thead><tr><th>Date</th><th>Recorded</th><th>By</th><th class="numeric">Statement balance</th><th class="numeric">Book balance</th><th class="numeric">Difference</th></tr></thead>
                <tbody>
                    <?php if ($history): foreach ($history as $row): ?>
                        <?php $rowVariance = signed_amount_to_cents((string) $row['statement_balance']) - signed_amount_to_cents((string) $row['book_balance']); ?>
                        <tr><td><?= e($row['statement_date']) ?></td><td><?= e($row['created_at']) ?></td><td><?= e($row['full_name']) ?></td><td class="numeric"><?= format_currency($row['statement_balance']) ?></td><td class="numeric"><?= format_currency($row['book_balance']) ?></td><td class="numeric"><?= format_currency(signed_cents_to_decimal($rowVariance)) ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td class="app-empty" colspan="6">No reconciliations recorded for this account.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
