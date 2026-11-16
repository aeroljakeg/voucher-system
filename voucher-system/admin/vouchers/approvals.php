<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/ApprovalService.php';

require_permission('voucher.approve');

$connection = db();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $voucherId = app_positive_int_id($_POST['voucher_id'] ?? null);
    $decision = $_POST['decision'] ?? '';
    $remarks = $_POST['remarks'] ?? '';

    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($voucherId === false || !is_string($decision) || !is_string($remarks)) {
        $errors[] = 'The approval request is invalid.';
    } else {
        try {
            ApprovalService::decide(
                $connection,
                (int) $voucherId,
                (int) $_SESSION['user_id'],
                $decision,
                $remarks
            );
            $success = $decision === 'approved' ? 'Voucher approved.' : 'Voucher rejected.';
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$queue = ApprovalService::queue($connection);
$pageTitle = 'Voucher approvals';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<p class="app-muted">Review submitted vouchers. You cannot approve or reject a voucher you created.</p>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Awaiting approval</h2><span class="app-badge"><?= count($queue) ?> pending</span></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Voucher</th><th>Date</th><th>Payee</th><th>Created by</th><th class="numeric">Amount</th><th>Decision</th></tr></thead>
            <tbody>
                <?php if ($queue): foreach ($queue as $voucher): ?>
                    <tr>
                        <td><a href="<?= e(app_url('admin/vouchers/view.php?id=' . (int) $voucher['id'])) ?>"><?= e($voucher['voucher_no']) ?></a><span class="app-muted"> · <?= e(ucfirst($voucher['voucher_type'])) ?></span></td>
                        <td><?= e($voucher['voucher_date']) ?></td>
                        <td><?= e($voucher['payee'] ?: '—') ?></td>
                        <td><?= e($voucher['created_by_name']) ?></td>
                        <td class="numeric"><?= format_currency($voucher['total_amount']) ?></td>
                        <td>
                            <form method="post" class="app-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="voucher_id" value="<?= (int) $voucher['id'] ?>">
                                <label class="sr-only" for="remarks-<?= (int) $voucher['id'] ?>">Remarks or rejection reason</label>
                                <input id="remarks-<?= (int) $voucher['id'] ?>" name="remarks" maxlength="255" placeholder="Rejection reason required">
                                <div class="app-actions">
                                    <button class="app-button app-button-primary" type="submit" name="decision" value="approved" data-confirm="Approve this voucher?">Approve</button>
                                    <button class="app-button" type="submit" name="decision" value="rejected">Reject</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">There are no vouchers awaiting approval.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
