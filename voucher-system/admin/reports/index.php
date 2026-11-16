<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('reports.view');

$connection = db();
$currentYear = $connection->query(
    'SELECT start_date, end_date FROM fiscal_years WHERE is_current = 1 LIMIT 1'
)->fetch() ?: [];
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : ($currentYear['start_date'] ?? date('Y-01-01'));
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : ($currentYear['end_date'] ?? date('Y-m-d'));
$errors = [];
$reportRows = [];
$revenueTotal = 0;
$expenseTotal = 0;

if (!is_iso_date($from) || !is_iso_date($to) || $from > $to) {
    $errors[] = 'Choose a valid date range with the start on or before the end.';
} else {
    $statement = $connection->prepare(
        'SELECT a.code, a.name, a.type,
                COALESCE(SUM(jl.debit), 0) AS debit_total,
                COALESCE(SUM(jl.credit), 0) AS credit_total
         FROM accounts a
         JOIN journal_lines jl ON jl.account_id = a.id
         JOIN journal_entries je ON je.id = jl.journal_id
         WHERE a.type IN (\'revenue\', \'expense\')
           AND je.entry_date >= :from_date AND je.entry_date <= :to_date
         GROUP BY a.id, a.code, a.name, a.type
         ORDER BY a.type, a.code'
    );
    $statement->execute(['from_date' => $from, 'to_date' => $to]);
    $reportRows = $statement->fetchAll();
    foreach ($reportRows as &$row) {
        $debit = amount_to_cents((string) $row['debit_total']);
        $credit = amount_to_cents((string) $row['credit_total']);
        $row['amount'] = $row['type'] === 'revenue' ? $credit - $debit : $debit - $credit;
        if ($row['type'] === 'revenue') {
            $revenueTotal += $row['amount'];
        } else {
            $expenseTotal += $row['amount'];
        }
    }
    unset($row);
}

$netIncome = $revenueTotal - $expenseTotal;
$pageTitle = 'Financial reports';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="from">Period start</label><input id="from" type="date" name="from" value="<?= e($from) ?>" required></div>
        <div><label for="to">Period end</label><input id="to" type="date" name="to" value="<?= e($to) ?>" required></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Run report</button></div>
    </form>
</section>
<?php if (!$errors): ?>
    <section class="app-grid" aria-label="Profit and loss totals">
        <div class="app-metric"><span class="app-metric-label">Revenue</span><span class="app-metric-value"><?= format_currency(signed_cents_to_decimal($revenueTotal)) ?></span></div>
        <div class="app-metric"><span class="app-metric-label">Expenses</span><span class="app-metric-value"><?= format_currency(signed_cents_to_decimal($expenseTotal)) ?></span></div>
        <div class="app-metric"><span class="app-metric-label">Net income</span><span class="app-metric-value"><?= format_currency(signed_cents_to_decimal($netIncome)) ?></span></div>
    </section>
    <div class="app-actions">
        <a class="app-button" href="<?= e(app_url('admin/reports/export.php?report=profit_loss&from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>">Export profit and loss CSV</a>
        <a class="app-button" href="<?= e(app_url('admin/reports/export.php?report=trial_balance&from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>">Export trial balance CSV</a>
        <a class="app-button" href="<?= e(app_url('admin/reports/export.php?report=journals&from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>">Export journal CSV</a>
    </div>
    <section class="app-card">
        <div class="app-card-header"><h2>Profit and loss · <?= e($from) ?> to <?= e($to) ?></h2></div>
        <div class="app-table-wrap">
            <table class="app-table">
                <thead><tr><th>Account</th><th>Type</th><th class="numeric">Activity</th></tr></thead>
                <tbody>
                    <?php if ($reportRows): foreach ($reportRows as $row): ?>
                        <tr><td><?= e($row['code'] . ' - ' . $row['name']) ?></td><td><?= e(ucfirst($row['type'])) ?></td><td class="numeric"><?= format_currency(signed_cents_to_decimal((int) $row['amount'])) ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td class="app-empty" colspan="3">No revenue or expense activity in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot><tr><th colspan="2">Net income</th><th class="numeric"><?= format_currency(signed_cents_to_decimal($netIncome)) ?></th></tr></tfoot>
            </table>
        </div>
    </section>
    <section class="app-grid">
        <a class="app-metric" href="<?= e(app_url('admin/accounting/trial_balance.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>"><span class="app-metric-label">Trial balance</span><span class="app-metric-value">Open report</span></a>
        <a class="app-metric" href="<?= e(app_url('admin/accounting/journals.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>"><span class="app-metric-label">Journal register</span><span class="app-metric-value">Open report</span></a>
        <a class="app-metric" href="<?= e(app_url('admin/vouchers/index.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to))) ?>"><span class="app-metric-label">Voucher register</span><span class="app-metric-value">Open report</span></a>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
