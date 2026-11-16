<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('journal.view');

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$errors = [];
$report = null;
try {
    $report = LedgerService::trialBalance(
        db(),
        $from === '' ? null : $from,
        $to === '' ? null : $to
    );
} catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
}

$balanced = $report
    && $report['total_debit_balance'] === $report['total_credit_balance'];
$pageTitle = 'Trial balance';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">Through</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Run report</button><a class="app-button" href="<?= e(app_url('admin/accounting/trial_balance.php')) ?>">Clear</a></div>
    </form>
</section>
<?php if ($report): ?>
    <section class="app-card">
        <div class="app-card-header">
            <h2>Trial balance<?= $from !== '' || $to !== '' ? ' · ' . e($from ?: 'Beginning') . ' through ' . e($to ?: 'Today') : '' ?></h2>
            <span class="app-badge"><?= $balanced ? 'Balanced' : 'Out of balance' ?></span>
        </div>
        <div class="app-table-wrap">
            <table class="app-table">
                <thead><tr><th>Account</th><th>Name</th><th>Type</th><th class="numeric">Debit balance</th><th class="numeric">Credit balance</th></tr></thead>
                <tbody>
                    <?php foreach ($report['accounts'] as $account): ?>
                        <?php if ((int) $account['debit_balance'] === 0 && (int) $account['credit_balance'] === 0) continue; ?>
                        <tr>
                            <td><?= e($account['code']) ?></td>
                            <td><?= e($account['name']) ?></td>
                            <td><?= e(ucfirst($account['type'])) ?></td>
                            <td class="numeric"><?= (int) $account['debit_balance'] > 0 ? format_currency(cents_to_decimal((int) $account['debit_balance'])) : '—' ?></td>
                            <td class="numeric"><?= (int) $account['credit_balance'] > 0 ? format_currency(cents_to_decimal((int) $account['credit_balance'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="3">Totals</th><th class="numeric"><?= format_currency(cents_to_decimal($report['total_debit_balance'])) ?></th><th class="numeric"><?= format_currency(cents_to_decimal($report['total_credit_balance'])) ?></th></tr>
                </tfoot>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
