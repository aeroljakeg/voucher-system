<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';

$connection = db();
$buyerAccount = BuyerAccountService::current($connection);
if (!$buyerAccount) {
    header('Location: ' . app_url('shop/login.php'));
    exit;
}

$statement = $connection->prepare(
    'SELECT id, public_token, voucher_amount_cents, voucher_code, status, created_at, paid_at, redeemed_at
     FROM buyer_orders
     WHERE buyer_account_id = :buyer_account_id
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$statement->execute(['buyer_account_id' => (int) $buyerAccount['id']]);
$orders = $statement->fetchAll();

$pageTitle = 'My purchases';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<section class="shop-account-welcome">
    <span class="shop-eyebrow">Your account</span>
    <h1>Welcome, <?= e($buyerAccount['full_name']) ?></h1>
    <p>View your vouchers and receipts here. Purchases made while signed in are saved to this account.</p>
    <a class="shop-button shop-button-link" href="<?= e(app_url('shop/index.php')) ?>">Buy a voucher</a>
</section>
<section class="shop-card">
    <div class="shop-section-heading">
        <div><h2>Purchase history</h2><p><?= count($orders) ?> recent purchases</p></div>
    </div>
    <?php if ($orders): ?>
        <div class="shop-order-list">
            <?php foreach ($orders as $order): ?>
                <article class="shop-order">
                    <div>
                        <strong>$<?= number_format((int) $order['voucher_amount_cents'] / 100, 2) ?> USD voucher</strong>
                        <span><?= e($order['created_at']) ?> · <?= e(ucfirst($order['status'])) ?></span>
                        <?php if ($order['voucher_code']): ?><code><?= e($order['voucher_code']) ?></code><?php endif; ?>
                    </div>
                    <?php if ($order['status'] === 'paid'): ?>
                        <a class="shop-secondary-button" href="<?= e(app_url('shop/receipt.php?token=' . rawurlencode($order['public_token']))) ?>">View receipt</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="shop-empty-state">
            <p>You don't have any purchases yet.</p>
            <a href="<?= e(app_url('shop/index.php')) ?>">Browse gift vouchers</a>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
