<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/VoucherService.php';

require_permission('voucher.view');

$voucherId = app_positive_int_id($_GET['id'] ?? null);
if ($voucherId === false) {
    http_response_code(400);
    exit('A valid voucher ID is required.');
}

$voucher = VoucherService::get(db(), (int) $voucherId);
if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}

$isReceipt = $voucher['voucher_type'] === 'receipt';
$bodyClass = 'print-page';
$pageTitle = 'Print ' . $voucher['voucher_no'];
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<div class="app-actions no-print">
    <a class="app-button" href="<?= e(app_url('admin/vouchers/view.php?id=' . (int) $voucherId)) ?>">Back to voucher</a>
    <button class="app-button app-button-primary" type="button" onclick="window.print()"><?= $isReceipt ? 'Print receipt' : 'Print voucher' ?></button>
</div>
<?php if ($isReceipt): ?>
    <section class="app-card receipt-printout">
        <header class="receipt-header">
            <div>
                <h2><?= e(app_setting('company_name', 'Voucher System') ?? 'Voucher System') ?></h2>
                <?php $companyAddress = app_setting('company_address', '') ?? ''; ?>
                <?php if ($companyAddress !== ''): ?><p><?= nl2br(e($companyAddress)) ?></p><?php endif; ?>
            </div>
            <span class="app-badge">Receipt · <?= e(ucfirst($voucher['status'])) ?></span>
        </header>
        <h1>Payment receipt</h1>
        <div class="receipt-details">
            <div><span>Receipt number</span><strong><?= e($voucher['voucher_no']) ?></strong></div>
            <div><span>Date</span><strong><?= e($voucher['voucher_date']) ?></strong></div>
            <div><span>Received from</span><strong><?= e($voucher['payee'] ?: '—') ?></strong></div>
        </div>
        <div class="receipt-description">
            <strong>Description</strong>
            <p><?= nl2br(e($voucher['description'] ?: '—')) ?></p>
        </div>
        <div class="receipt-amount">
            <span>Amount received</span>
            <strong><?= format_currency($voucher['total_amount']) ?></strong>
        </div>
        <div class="receipt-signature">Recorded by: <?= e($voucher['created_by_name']) ?></div>
    </section>
<?php else: ?>
<section class="app-card">
    <div class="app-card-header">
        <div>
            <h2><?= e(app_setting('company_name', 'Voucher System') ?? 'Voucher System') ?></h2>
            <p class="app-muted"><?= e(app_setting('company_address', '') ?? '') ?></p>
        </div>
        <span class="app-badge"><?= e(strtoupper($voucher['voucher_type'])) ?> · <?= e(strtoupper($voucher['status'])) ?></span>
    </div>
    <div class="app-card-body app-grid">
        <div><strong>Voucher number</strong><div><?= e($voucher['voucher_no']) ?></div></div>
        <div><strong>Date</strong><div><?= e($voucher['voucher_date']) ?></div></div>
        <div><strong>Payee</strong><div><?= e($voucher['payee'] ?: '—') ?></div></div>
        <div><strong>Fiscal year</strong><div><?= e($voucher['fiscal_year_name']) ?></div></div>
        <div><strong>Description</strong><div><?= nl2br(e($voucher['description'] ?? '—')) ?></div></div>
    </div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Account code</th><th>Account name</th><th>Description</th><th class="numeric">Debit</th><th class="numeric">Credit</th></tr></thead>
            <tbody>
                <?php foreach ($voucher['lines'] as $line): ?>
                    <tr>
                        <td><?= e($line['account_code']) ?></td>
                        <td><?= e($line['account_name']) ?></td>
                        <td><?= e($line['description'] ?: '—') ?></td>
                        <td class="numeric"><?= (float) $line['debit'] > 0 ? format_currency($line['debit']) : '—' ?></td>
                        <td class="numeric"><?= (float) $line['credit'] > 0 ? format_currency($line['credit']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="3">Total</th><th class="numeric"><?= format_currency($voucher['total_amount']) ?></th><th class="numeric"><?= format_currency($voucher['total_amount']) ?></th></tr></tfoot>
        </table>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
