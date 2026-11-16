<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$order = BuyerOrderService::getByPublicToken(db(), $token);
if (!$order || $order['status'] !== 'paid' || !is_string($order['voucher_code'])) {
    http_response_code(404);
    exit('Receipt not found or payment has not been confirmed.');
}

$pageTitle = 'Your voucher receipt';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<div class="shop-receipt-actions no-print">
    <a class="shop-secondary-button" href="<?= e(app_url('shop/index.php')) ?>">Buy another voucher</a>
    <button class="shop-button" type="button" onclick="window.print()">Print receipt</button>
</div>
<article class="shop-card shop-receipt">
    <header class="shop-receipt-header">
        <div>
            <span class="shop-eyebrow">Payment receipt</span>
            <h1><?= e($companyName) ?></h1>
        </div>
        <span class="shop-paid-badge"><?= $order['redeemed_at'] ? 'Redeemed' : 'Paid' ?></span>
    </header>
    <div class="shop-receipt-meta">
        <div><span>Receipt number</span><strong><?= e($order['voucher_code']) ?></strong></div>
        <div><span>Purchase date</span><strong><?= e($order['paid_at']) ?></strong></div>
        <div><span>Buyer</span><strong><?= e($order['customer_name']) ?></strong></div>
        <div><span>Email</span><strong><?= e($order['customer_email']) ?></strong></div>
    </div>
    <div class="shop-receipt-line">
        <span>Gift voucher</span>
        <strong>$<?= number_format((int) $order['voucher_amount_cents'] / 100, 2) ?> USD</strong>
    </div>
    <div class="shop-receipt-total">
        <span>Total paid</span>
        <strong>$<?= number_format((int) $order['voucher_amount_cents'] / 100, 2) ?> USD</strong>
    </div>
    <section class="shop-voucher-code" aria-labelledby="voucher-code-heading">
        <span class="shop-eyebrow" id="voucher-code-heading">Your voucher code</span>
        <strong><?= e($order['voucher_code']) ?></strong>
        <p>Keep this code and present it when you redeem your voucher.</p>
    </section>
    <p class="shop-receipt-note">Paid securely through Stripe. Your card details are not stored by this site.</p>
</article>
<?php require __DIR__ . '/_footer.php'; ?>
