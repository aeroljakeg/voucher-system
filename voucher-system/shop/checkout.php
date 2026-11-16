<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';
require_once __DIR__ . '/../services/StripeCheckoutService.php';

start_app_session();
$errors = [];
$name = '';
$email = '';
$amountCents = 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the voucher form to start checkout.');
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
    $errors[] = 'Your session expired. Please return to the shop and try again.';
} else {
    $name = is_string($_POST['customer_name'] ?? null) ? trim($_POST['customer_name']) : '';
    $email = is_string($_POST['customer_email'] ?? null) ? trim($_POST['customer_email']) : '';
    $rawAmount = $_POST['amount_cents'] ?? null;
    $amountCents = is_string($rawAmount)
        ? filter_var($rawAmount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    try {
        if ($amountCents === false) {
            throw new InvalidArgumentException('Choose one of the available voucher amounts.');
        }

        $connection = db();
        $buyerAccount = BuyerAccountService::current($connection);
        if ($buyerAccount) {
            $name = $buyerAccount['full_name'];
            $email = $buyerAccount['email'];
        }
        $order = BuyerOrderService::createPending(
            $connection,
            $name,
            $email,
            (int) $amountCents,
            $buyerAccount ? (int) $buyerAccount['id'] : null
        );
        try {
            $checkout = StripeCheckoutService::createSession($order);
            BuyerOrderService::attachCheckoutSession($connection, (int) $order['id'], $checkout['id']);
            header('Location: ' . $checkout['url'], true, 303);
            exit;
        } catch (RuntimeException | DomainException $error) {
            BuyerOrderService::markCheckoutFailed($connection, (int) $order['id']);
            error_log('Buyer checkout could not be started: ' . $error->getMessage());
            $errors[] = 'Secure checkout is not available right now. Please try again later.';
        }
    } catch (InvalidArgumentException $error) {
        $errors[] = $error->getMessage();
    }
}

$pageTitle = 'Secure checkout';
$companyName = 'Voucher System';
require __DIR__ . '/_header.php';
?>
<section class="shop-card shop-message-card">
    <span class="shop-eyebrow">Checkout unavailable</span>
    <h1>We couldn't start your payment.</h1>
    <?php foreach ($errors as $error): ?><p class="shop-error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
    <p>Your payment has not been taken. Please return to the shop and try again.</p>
    <a class="shop-button shop-button-link" href="<?= e(app_url('shop/index.php')) ?>">Back to vouchers</a>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
