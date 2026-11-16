<?php

require_once __DIR__ . '/../config/functions.php';

$pageTitle = 'Checkout cancelled';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<section class="shop-card shop-message-card">
    <span class="shop-eyebrow">No payment was completed</span>
    <h1>Your checkout was cancelled.</h1>
    <p>You have not been charged. You can choose a voucher and try again whenever you’re ready.</p>
    <a class="shop-button shop-button-link" href="<?= e(app_url('shop/index.php')) ?>">Return to vouchers</a>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
