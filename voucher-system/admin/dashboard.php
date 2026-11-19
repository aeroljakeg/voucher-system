<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';

require_login();

$connection = db();
$voucherCounts = $connection->query(
    'SELECT status, COUNT(*) AS total FROM vouchers GROUP BY status'
)->fetchAll();
$counts = array_fill_keys(['draft', 'submitted', 'approved', 'rejected', 'posted', 'cancelled'], 0);
foreach ($voucherCounts as $row) {
    $counts[$row['status']] = (int) $row['total'];
}

$journalSummary = $connection->query(
    'SELECT COUNT(*) AS entries, COALESCE(SUM(total_debit), 0) AS debits
     FROM journal_entries'
)->fetch();
$unreconciled = (int) $connection->query(
    'SELECT COUNT(*) FROM bank_transactions WHERE is_reconciled = 0'
)->fetchColumn();
$latestStatement = $connection->query(
    'SELECT v.id, v.voucher_no, v.voucher_type, v.voucher_date, v.total_amount, v.status,
            v.payee, u.full_name AS created_by_name
     FROM vouchers v
     JOIN users u ON u.id = v.created_by
     ORDER BY v.created_at DESC, v.id DESC
     LIMIT 8'
);
$latestVouchers = $latestStatement->fetchAll();

$pageTitle = 'Dashboard';
$themeStorageKey = 'voucher-system-theme';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>
<p class="app-muted">A current overview of vouchers, journals, and bank statement activity.</p>
<section class="app-grid" aria-label="System totals">
    <a class="app-metric" href="<?= e(app_url('admin/vouchers/index.php?status=draft')) ?>">
        <span class="app-metric-label">Draft vouchers</span>
        <span class="app-metric-value"><?= number_format($counts['draft']) ?></span>
    </a>
    <a class="app-metric" href="<?= e(app_url('admin/vouchers/approvals.php')) ?>">
        <span class="app-metric-label">Awaiting approval</span>
        <span class="app-metric-value"><?= number_format($counts['submitted']) ?></span>
    </a>
    <a class="app-metric" href="<?= e(app_url('admin/vouchers/index.php?status=posted')) ?>">
        <span class="app-metric-label">Posted vouchers</span>
        <span class="app-metric-value"><?= number_format($counts['posted']) ?></span>
    </a>
    <a class="app-metric" href="<?= e(app_url('admin/accounting/journals.php')) ?>">
        <span class="app-metric-label">Journal entries</span>
        <span class="app-metric-value"><?= number_format((int) $journalSummary['entries']) ?></span>
    </a>
    <a class="app-metric" href="<?= e(app_url('admin/banking/transactions.php?status=unreconciled')) ?>">
        <span class="app-metric-label">Unreconciled transactions</span>
        <span class="app-metric-value"><?= number_format($unreconciled) ?></span>
    </a>
    <div class="app-metric">
        <span class="app-metric-label">Journal debits</span>
        <span class="app-metric-value"><?= format_currency($journalSummary['debits']) ?></span>
    </div>
</section>
<section class="app-card" aria-labelledby="latest-vouchers-heading">
    <div class="app-card-header"><h2 id="latest-vouchers-heading">Recent vouchers</h2><a href="<?= e(app_url('admin/vouchers/index.php')) ?>">View all</a></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead>
                <tr><th>Voucher</th><th>Date</th><th>Payee</th><th>Created by</th><th>Status</th><th class="numeric">Amount</th></tr>
            </thead>
            <tbody>
                <?php if ($latestVouchers): ?>
                    <?php foreach ($latestVouchers as $voucher): ?>
                        <tr>
                            <td><a href="<?= e(app_url('admin/vouchers/view.php?id=' . (int) $voucher['id'])) ?>"><?= e($voucher['voucher_no']) ?></a><span class="app-muted"> · <?= e(ucfirst($voucher['voucher_type'])) ?></span></td>
                            <td><?= e($voucher['voucher_date']) ?></td>
                            <td><?= e($voucher['payee'] ?: '—') ?></td>
                            <td><?= e($voucher['created_by_name']) ?></td>
                            <td><span class="app-badge"><?= e(ucfirst($voucher['status'])) ?></span></td>
                            <td class="numeric"><?= format_currency($voucher['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td class="app-empty" colspan="6">No vouchers have been created yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
