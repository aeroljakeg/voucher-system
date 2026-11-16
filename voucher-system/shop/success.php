<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';
require_once __DIR__ . '/../services/StripeCheckoutService.php';

$sessionId = is_string($_GET['session_id'] ?? null) ? $_GET['session_id'] : '';
$order = null;
$error = '';
$pending = false;

if ($sessionId === '') {
    http_response_code(400);
    $error = 'The checkout session is missing.';
} else {
    try {
        $session = StripeCheckoutService::retrieveSession($sessionId);
        if (($session['status'] ?? null) === 'complete' && ($session['payment_status'] ?? null) === 'paid') {
            $order = BuyerOrderService::fulfillPaidSession(db(), $session);
        } else {
            $pending = true;
        }
    } catch (DomainException $exception) {
        http_response_code(400);
        $error = 'We could not match the confirmed payment to a purchase.';
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $error = 'The checkout session is invalid.';
    } catch (RuntimeException $exception) {
        error_log('Could not confirm buyer checkout: ' . $exception->getMessage());
        $error = 'We are confirming your payment. Please refresh this page shortly.';
    }
}

$pageTitle = $order ? 'Voucher ready' : 'Payment confirmation';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<?php if ($order): ?>
    <section class="shop-card shop-message-card">
        <span class="shop-success-mark" aria-hidden="true">✓</span>
        <span class="shop-eyebrow">Payment confirmed</span>
        <h1>Your voucher is ready.</h1>
        <p>We’ve created your voucher code and printable receipt.</p>
        <a class="shop-button shop-button-link" href="<?= e(app_url('shop/receipt.php?token=' . rawurlencode($order['public_token']))) ?>">View voucher and receipt</a>
    </section>
<?php elseif ($pending): ?>
    <section class="shop-card shop-message-card">
        <span class="shop-eyebrow">Payment processing</span>
        <h1>We’re confirming your payment.</h1>
        <p>Your voucher will appear here as soon as Stripe confirms the payment. Refresh this page in a moment.</p>
        <a class="shop-button shop-button-link" href="<?= e(app_url('shop/success.php?session_id=' . rawurlencode($sessionId))) ?>">Check again</a>
    </section>
<?php else: ?>
    <section class="shop-card shop-message-card">
        <span class="shop-eyebrow">Unable to confirm</span>
        <h1>We couldn't display your purchase.</h1>
        <p class="shop-error" role="alert"><?= e($error) ?></p>
        <p>If you completed payment, contact the shop and include your Stripe receipt email.</p>
        <a class="shop-button shop-button-link" href="<?= e(app_url('shop/index.php')) ?>">Back to vouchers</a>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
