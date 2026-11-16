<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('journal.view');

$connection = db();
$accounts = $connection->query(
    'SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code'
)->fetchAll();
$accountId = app_positive_int_id($_GET['account_id'] ?? null);
if ($accountId === false) {
    $accountId = $accounts ? (int) $accounts[0]['id'] : null;
}
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$ledger = null;
$errors = [];

if ($accountId !== null) {
    try {
        $ledger = LedgerService::accountLedger(
            $connection,
            (int) $accountId,
            $from === '' ? null : $from,
            $to === '' ? null : $to
        );
    } catch (InvalidArgumentException | DomainException $error) {
        $errors[] = $error->getMessage();
    }
}

$pageTitle = 'General ledger';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if (!$accounts): ?>
    <div class="app-card app-empty">No accounts are available.</div>
<?php else: ?>
    <section class="app-card app-card-body">
        <form class="app-form" method="get">
            <div><label for="account_id">Account</label><select id="account_id" name="account_id"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $accountId === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['code'] . ' - ' . $account['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
            <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Show ledger</button></div>
        </form>
    </section>
    <?php if ($ledger): ?>
        <?php $opening = (int) $ledger['opening_balance']; $closing = (int) $ledger['closing_balance']; ?>
        <section class="app-card">
            <div class="app-card-header"><h2><?= e($ledger['account']['code'] . ' - ' . $ledger['account']['name']) ?></h2></div>
            <div class="app-table-wrap">
                <table class="app-table">
                    <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="numeric">Debit</th><th class="numeric">Credit</th><th class="numeric">Running balance</th></tr></thead>
                    <tbody>
                        <?php if ($from !== ''): ?>
                            <tr><td colspan="5"><strong>Opening balance</strong></td><td class="numeric"><strong><?= format_currency(signed_cents_to_decimal($opening)) ?></strong></td></tr>
                        <?php endif; ?>
                        <?php foreach ($ledger['lines'] as $line): ?>
                            <?php $balance = (int) $line['running_balance']; ?>
                            <tr>
                                <td><?= e($line['entry_date']) ?></td>
                                <td><a href="<?= e(app_url('admin/accounting/journal_view.php?id=' . (int) $line['journal_id'])) ?>"><?= e($line['entry_no']) ?></a></td>
                                <td><?= e($line['line_description'] ?: $line['entry_description'] ?: '—') ?></td>
                                <td class="numeric"><?= (float) $line['debit'] > 0 ? format_currency($line['debit']) : '—' ?></td>
                                <td class="numeric"><?= (float) $line['credit'] > 0 ? format_currency($line['credit']) : '—' ?></td>
                                <td class="numeric"><?= format_currency(signed_cents_to_decimal($balance)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$ledger['lines']): ?><tr><td class="app-empty" colspan="6">No journal activity for this account in the selected range.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="5">Closing balance</th><th class="numeric"><?= format_currency(signed_cents_to_decimal($closing)) ?></th></tr></tfoot>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
