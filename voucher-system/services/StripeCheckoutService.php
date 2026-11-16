<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/BuyerOrderService.php';

final class StripeCheckoutService
{
    public static function createSession(array $order): array
    {
        $amountCents = (int) $order['voucher_amount_cents'];
        $amountLabel = BuyerOrderService::amountLabel($amountCents);
        $parameters = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'success_url' => app_absolute_url('shop/success.php?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => app_absolute_url('shop/cancel.php?token=' . rawurlencode($order['public_token'])),
            'client_reference_id' => (string) $order['id'],
            'customer_email' => $order['customer_email'],
            'line_items' => [[
                'price_data' => [
                    'currency' => BuyerOrderService::CURRENCY,
                    'product_data' => ['name' => 'Voucher ' . $amountLabel],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'metadata' => ['order_id' => (string) $order['id']],
            'payment_intent_data' => [
                'metadata' => ['order_id' => (string) $order['id']],
            ],
        ];

        $session = self::request(
            'POST',
            '/v1/checkout/sessions',
            $parameters,
            'buyer-order-' . (int) $order['id']
        );
        $checkoutUrl = $session['url'] ?? null;
        $sessionId = $session['id'] ?? null;
        $urlParts = is_string($checkoutUrl) ? parse_url($checkoutUrl) : false;
        if (!is_string($sessionId)
            || !is_array($urlParts)
            || ($urlParts['scheme'] ?? '') !== 'https'
            || ($urlParts['host'] ?? '') !== 'checkout.stripe.com') {
            throw new RuntimeException('Stripe returned an invalid checkout session.');
        }

        return ['id' => $sessionId, 'url' => $checkoutUrl];
    }

    public static function retrieveSession(string $sessionId): array
    {
        if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/D', $sessionId)) {
            throw new InvalidArgumentException('The checkout session ID is invalid.');
        }

        return self::request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));
    }

    public static function verifyWebhook(string $payload, string $signatureHeader): array
    {
        if (strlen($payload) > 1048576) {
            throw new InvalidArgumentException('The webhook payload is too large.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $component) {
            $parts = explode('=', trim($component), 2);
            if (count($parts) !== 2) {
                continue;
            }
            if ($parts[0] === 't' && $timestamp === null) {
                $timestamp = ctype_digit($parts[1]) ? (int) $parts[1] : null;
            } elseif ($parts[0] === 'v1') {
                $signatures[] = $parts[1];
            }
        }

        if ($timestamp === null || !$signatures || abs(time() - $timestamp) > 300) {
            throw new InvalidArgumentException('The webhook signature is invalid or expired.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, self::webhookSecret());
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new InvalidArgumentException('The webhook signature is invalid or expired.');
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The webhook payload is not valid JSON.', 0, $error);
        }
        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            throw new InvalidArgumentException('The webhook event is invalid.');
        }

        return $event;
    }

    private static function request(
        string $method,
        string $path,
        array $parameters = [],
        ?string $idempotencyKey = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Stripe Checkout.');
        }

        $secretKey = getenv('STRIPE_SECRET_KEY');
        if (!is_string($secretKey) || !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/D', $secretKey)) {
            throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET_KEY in the server environment.');
        }

        $headers = ['Authorization: Bearer ' . $secretKey];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init('https://api.stripe.com' . $path);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize the Stripe request.');
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $curlError = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($responseBody === false) {
            throw new RuntimeException('Stripe request failed: ' . $curlError);
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stripe returned an invalid response.', 0, $error);
        }
        if (!is_array($response)) {
            throw new RuntimeException('Stripe returned an invalid response.');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $response['error']['message'] ?? 'The payment provider rejected the request.';
            throw new RuntimeException('Stripe API error: ' . (is_string($message) ? $message : 'Request rejected.'));
        }

        return $response;
    }

    private static function webhookSecret(): string
    {
        $secret = getenv('STRIPE_WEBHOOK_SECRET');
        if (!is_string($secret) || !preg_match('/^whsec_[A-Za-z0-9_=-]+$/D', $secret)) {
            throw new RuntimeException('Stripe webhooks are not configured. Set STRIPE_WEBHOOK_SECRET in the server environment.');
        }

        return $secret;
    }
}
