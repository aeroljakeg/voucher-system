<?php

require_once __DIR__ . '/../config/functions.php';

final class BuyerOrderService
{
    public const CURRENCY = 'usd';
    private const AMOUNTS_CENTS = [1000, 2500, 5000, 10000];

    public static function amountOptions(): array
    {
        return array_map(static function (int $amountCents): array {
            return [
                'cents' => $amountCents,
                'label' => '$' . number_format($amountCents / 100, 2) . ' USD',
            ];
        }, self::AMOUNTS_CENTS);
    }

    public static function amountLabel(int $amountCents): string
    {
        if (!in_array($amountCents, self::AMOUNTS_CENTS, true)) {
            throw new InvalidArgumentException('Choose a valid voucher amount.');
        }

        return '$' . number_format($amountCents / 100, 2) . ' USD';
    }

    public static function createPending(
        PDO $connection,
        string $name,
        string $email,
        int $amountCents,
        ?int $buyerAccountId = null
    ): array
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || app_text_length($name) > 120) {
            throw new InvalidArgumentException('Enter your name (up to 120 characters).');
        }
        if (app_text_length($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (!in_array($amountCents, self::AMOUNTS_CENTS, true)) {
            throw new InvalidArgumentException('Choose one of the available voucher amounts.');
        }
        if ($buyerAccountId !== null && $buyerAccountId < 1) {
            throw new InvalidArgumentException('The buyer account is invalid.');
        }

        $publicToken = bin2hex(random_bytes(32));
        $statement = $connection->prepare(
            'INSERT INTO buyer_orders
                (buyer_account_id, public_token, customer_name, customer_email, voucher_amount_cents, currency, status)
             VALUES
                (:buyer_account_id, :public_token, :customer_name, :customer_email, :amount, :currency, \'pending\')'
        );
        $statement->execute([
            'buyer_account_id' => $buyerAccountId,
            'public_token' => $publicToken,
            'customer_name' => $name,
            'customer_email' => $email,
            'amount' => $amountCents,
            'currency' => strtoupper(self::CURRENCY),
        ]);

        return self::getById($connection, (int) $connection->lastInsertId());
    }

    public static function attachCheckoutSession(PDO $connection, int $orderId, string $sessionId): void
    {
        $statement = $connection->prepare(
            'UPDATE buyer_orders
             SET stripe_checkout_session_id = :session_id
             WHERE id = :id AND status = \'pending\' AND stripe_checkout_session_id IS NULL'
        );
        $statement->execute(['session_id' => $sessionId, 'id' => $orderId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('The checkout session could not be attached to the purchase.');
        }
    }

    public static function markCheckoutFailed(PDO $connection, int $orderId): void
    {
        $statement = $connection->prepare(
            'UPDATE buyer_orders
             SET status = \'checkout_failed\'
             WHERE id = :id AND status = \'pending\' AND stripe_checkout_session_id IS NULL'
        );
        $statement->execute(['id' => $orderId]);
    }

    public static function getByPublicToken(PDO $connection, string $publicToken): ?array
    {
        $statement = $connection->prepare(
            'SELECT * FROM buyer_orders WHERE public_token = :token LIMIT 1'
        );
        $statement->execute(['token' => $publicToken]);
        $order = $statement->fetch();

        return $order ?: null;
    }

    public static function fulfillPaidSession(PDO $connection, array $session): array
    {
        $sessionId = $session['id'] ?? null;
        $referenceId = $session['client_reference_id'] ?? null;
        $metadataOrderId = $session['metadata']['order_id'] ?? null;
        $orderId = is_string($referenceId)
            ? filter_var($referenceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (!is_string($sessionId)
            || $orderId === false
            || !is_string($metadataOrderId)
            || !hash_equals((string) $orderId, $metadataOrderId)
            || ($session['status'] ?? null) !== 'complete'
            || ($session['payment_status'] ?? null) !== 'paid') {
            throw new DomainException('The payment has not been completed.');
        }

        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT * FROM buyer_orders WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => (int) $orderId]);
            $order = $statement->fetch();
            if (!$order
                || !is_string($order['stripe_checkout_session_id'])
                || !hash_equals($order['stripe_checkout_session_id'], $sessionId)) {
                throw new DomainException('The payment could not be matched to a purchase.');
            }
            if ((int) ($session['amount_total'] ?? -1) !== (int) $order['voucher_amount_cents']
                || strtolower((string) ($session['currency'] ?? '')) !== strtolower($order['currency'])) {
                throw new DomainException('The payment amount does not match the purchase.');
            }

            if ($order['status'] !== 'paid') {
                if ($order['status'] !== 'pending') {
                    throw new DomainException('This purchase is no longer pending.');
                }

                $voucherCode = 'VCH-' . strtoupper(bin2hex(random_bytes(8)));
                $paymentIntent = $session['payment_intent'] ?? null;
                $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : null;
                $update = $connection->prepare(
                    'UPDATE buyer_orders
                     SET status = \'paid\', voucher_code = :voucher_code,
                         stripe_payment_intent_id = :payment_intent_id, paid_at = NOW()
                     WHERE id = :id AND status = \'pending\''
                );
                $update->execute([
                    'voucher_code' => $voucherCode,
                    'payment_intent_id' => $paymentIntentId,
                    'id' => (int) $orderId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('The purchase could not be marked as paid.');
                }
            }

            $connection->commit();

            return self::getById($connection, (int) $orderId);
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function markExpired(PDO $connection, string $sessionId): void
    {
        $statement = $connection->prepare(
            'UPDATE buyer_orders
             SET status = \'expired\'
             WHERE stripe_checkout_session_id = :session_id AND status = \'pending\''
        );
        $statement->execute(['session_id' => $sessionId]);
    }

    private static function getById(PDO $connection, int $orderId): array
    {
        $statement = $connection->prepare('SELECT * FROM buyer_orders WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        if (!$order) {
            throw new RuntimeException('The purchase could not be loaded after it was saved.');
        }

        return $order;
    }
}
