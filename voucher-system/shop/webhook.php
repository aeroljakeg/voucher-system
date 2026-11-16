<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/BuyerOrderService.php';
require_once __DIR__ . '/../services/StripeCheckoutService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (!is_string($payload) || !is_string($signature)) {
    http_response_code(400);
    exit('Invalid webhook request.');
}

try {
    $event = StripeCheckoutService::verifyWebhook($payload, $signature);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit('Invalid webhook signature or payload.');
}

$session = $event['data']['object'] ?? null;
if (is_array($session)) {
    if (($event['type'] ?? '') === 'checkout.session.completed'
        && ($session['payment_status'] ?? '') === 'paid') {
        try {
            BuyerOrderService::fulfillPaidSession(db(), $session);
        } catch (DomainException $error) {
            error_log('Stripe webhook could not match a paid purchase: ' . $error->getMessage());
            http_response_code(400);
            exit('Purchase could not be matched.');
        }
    } elseif (($event['type'] ?? '') === 'checkout.session.expired'
        && is_string($session['id'] ?? null)) {
        BuyerOrderService::markExpired(db(), $session['id']);
    }
}

http_response_code(200);
echo 'ok';
