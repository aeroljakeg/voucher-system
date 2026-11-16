<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';

$pageTitle = 'Buy a gift voucher';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
$csrfToken = csrf_token();
$buyerAccount = BuyerAccountService::current(db());
$amountOptions = BuyerOrderService::amountOptions();
$searchQuery = $_GET['search'] ?? '';
$searchQuery = is_string($searchQuery) ? trim(substr($searchQuery, 0, 80)) : '';
$visibleAmountOptions = array_values(array_filter(
    $amountOptions,
    static function (array $option) use ($searchQuery): bool {
        return $searchQuery === '' || stripos($option['label'], $searchQuery) !== false;
    }
));
require __DIR__ . '/_header.php';
?>
<section class="shop-hero">
    <span class="shop-eyebrow">A thoughtful gift, delivered instantly</span>
    <h1>Give them a voucher they'll love.</h1>
    <p>Choose an amount, pay securely, and get a printable voucher code as soon as your payment is confirmed.</p>
</section>
<section class="shop-card" aria-labelledby="purchase-heading">
    <div class="shop-section-heading">
        <div>
            <h2 id="purchase-heading">Choose your voucher</h2>
            <p>Select an amount and enter where to send the receipt.</p>
        </div>
    </div>
    <form method="get" action="<?= e(app_url('shop/index.php')) ?>" class="shop-search" role="search">
        <label for="voucher-search">Search vouchers</label>
        <div class="shop-search-row">
            <input id="voucher-search" name="search" type="search" maxlength="80" placeholder="Search by amount, like $25" value="<?= e($searchQuery) ?>">
            <button class="shop-secondary-button" type="submit">Search</button>
            <?php if ($searchQuery !== ''): ?><a class="shop-search-clear" href="<?= e(app_url('shop/index.php')) ?>">Clear</a><?php endif; ?>
        </div>
        <p class="shop-search-status" aria-live="polite">
            <?= count($visibleAmountOptions) ?> voucher option<?= count($visibleAmountOptions) === 1 ? '' : 's' ?>
            <?= $searchQuery !== '' ? ' found.' : ' available.' ?>
        </p>
    </form>
    <?php if ($visibleAmountOptions): ?>
    <form method="post" action="<?= e(app_url('shop/checkout.php')) ?>" class="shop-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <fieldset class="shop-amounts">
            <legend>Voucher amount</legend>
            <?php foreach ($visibleAmountOptions as $option): ?>
                <label class="shop-amount-option">
                    <input type="radio" name="amount_cents" value="<?= (int) $option['cents'] ?>" required>
                    <span><?= e($option['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php if ($buyerAccount): ?>
            <div class="shop-buyer-note">
                Buying as <strong><?= e($buyerAccount['full_name']) ?></strong>
                (<?= e($buyerAccount['email']) ?>). This purchase will appear in <a href="<?= e(app_url('shop/account.php')) ?>">My purchases</a>.
            </div>
        <?php else: ?>
            <div class="shop-fields">
                <div>
                    <label for="customer_name">Your name</label>
                    <input id="customer_name" name="customer_name" maxlength="120" autocomplete="name" required>
                </div>
                <div>
                    <label for="customer_email">Email address</label>
                    <input id="customer_email" name="customer_email" type="email" maxlength="254" autocomplete="email" required>
                </div>
            </div>
            <p class="shop-privacy">Have an account? <a href="<?= e(app_url('shop/login.php')) ?>">Sign in</a> to save this purchase to your history, or <a href="<?= e(app_url('shop/register.php')) ?>">create an account</a>.</p>
        <?php endif; ?>
        <p class="shop-privacy">Your email is used for the payment and receipt. Card details are entered securely on Stripe and are not stored by this site.</p>
        <button class="shop-button" type="submit">Continue to secure payment</button>
    </form>
    <?php else: ?>
        <p class="shop-empty-results" role="status">No voucher amounts match &quot;<?= e($searchQuery) ?>&quot;. Try another search or <a href="<?= e(app_url('shop/index.php')) ?>">view all vouchers</a>.</p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
