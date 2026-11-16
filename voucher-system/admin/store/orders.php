<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('users.manage');

$connection = db();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $rawOrderId = $_POST['order_id'] ?? null;
    $orderId = is_string($rawOrderId)
        ? filter_var($rawOrderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($orderId === false) {
        $errors[] = 'The purchase ID is invalid.';
    } else {
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'SELECT status, redeemed_at FROM buyer_orders WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => (int) $orderId]);
            $order = $statement->fetch();
            if (!$order) {
                throw new DomainException('Purchase not found.');
            }
            if ($order['status'] !== 'paid' || $order['redeemed_at'] !== null) {
                throw new DomainException('Only paid, unused vouchers can be marked as redeemed.');
            }

            $update = $connection->prepare(
                'UPDATE buyer_orders SET redeemed_at = NOW(), redeemed_by = :user_id
                 WHERE id = :id AND status = \'paid\' AND redeemed_at IS NULL'
            );
            $update->execute([
                'user_id' => (int) $_SESSION['user_id'],
                'id' => (int) $orderId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('This voucher has already been redeemed.');
            }
            log_audit('redeem', 'buyer_order', (int) $orderId, ['redeemed_at' => null], [
                'redeemed_at' => date('Y-m-d H:i:s'),
            ]);
            $connection->commit();
            $success = 'Voucher marked as redeemed.';
        } catch (DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}

$orders = $connection->query(
    'SELECT bo.id, bo.customer_name, bo.customer_email, bo.voucher_amount_cents,
            bo.voucher_code, bo.status, bo.created_at, bo.paid_at, bo.redeemed_at,
            redeemer.full_name AS redeemed_by_name
     FROM buyer_orders bo
     LEFT JOIN users redeemer ON redeemer.id = bo.redeemed_by
     ORDER BY bo.created_at DESC, bo.id DESC
     LIMIT 100'
)->fetchAll();

$pageTitle = 'Buyer purchases';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<p class="app-muted">Review buyer payments and redeem a paid voucher once it has been used.</p>
<section class="app-card">
    <div class="app-card-header"><h2>Recent purchases</h2><span class="app-badge"><?= count($orders) ?> shown</span></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead>
                <tr><th>Buyer</th><th>Voucher code</th><th>Purchased</th><th>Paid</th><th>Redemption</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if ($orders): foreach ($orders as $order): ?>
                    <tr>
                        <td><strong><?= e($order['customer_name']) ?></strong><span class="app-muted" style="display:block"><?= e($order['customer_email']) ?></span></td>
                        <td><?= $order['voucher_code'] ? e($order['voucher_code']) : '—' ?></td>
                        <td><?= '$' . number_format((int) $order['voucher_amount_cents'] / 100, 2) . ' USD' ?><span class="app-muted" style="display:block"><?= e($order['created_at']) ?></span></td>
                        <td><span class="app-badge"><?= e(ucfirst($order['status'])) ?></span></td>
                        <td>
                            <?php if ($order['redeemed_at']): ?>
                                Redeemed <?= e($order['redeemed_at']) ?>
                                <?php if ($order['redeemed_by_name']): ?><span class="app-muted" style="display:block">by <?= e($order['redeemed_by_name']) ?></span><?php endif; ?>
                            <?php else: ?>
                                Not redeemed
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($order['status'] === 'paid' && !$order['redeemed_at']): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button class="app-button" type="submit" data-confirm="Mark this voucher as redeemed?">Redeem</button>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">No buyer purchases yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
