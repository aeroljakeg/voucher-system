<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/VoucherService.php';
require_once __DIR__ . '/../../services/ApprovalService.php';
require_once __DIR__ . '/../../services/PostingService.php';

require_permission('voucher.view');

$voucherId = app_positive_int_id($_GET['id'] ?? null);
if ($voucherId === false) {
    http_response_code(400);
    exit('A valid voucher ID is required.');
}

$connection = db();
$errors = [];
$successMessages = [
    'submitted' => 'Voucher submitted for approval.',
    'cancelled' => 'Voucher cancelled.',
    'posted' => 'Voucher posted to the journal.',
    'updated' => 'Voucher updated.',
];
$success = is_string($_GET['updated'] ?? null) ? ($successMessages[$_GET['updated']] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $action = $_POST['action'] ?? '';
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (!is_string($action)) {
        $errors[] = 'The requested action is invalid.';
    } else {
        try {
            $userId = (int) $_SESSION['user_id'];
            if ($action === 'submit') {
                if (!user_has_permission('voucher.create')) {
                    throw new DomainException('You do not have permission to submit vouchers.');
                }
                ApprovalService::submit($connection, (int) $voucherId, $userId);
                header('Location: ' . app_url('admin/vouchers/view.php?id=' . (int) $voucherId . '&updated=submitted'));
                exit;
            }
            if ($action === 'cancel') {
                if (!user_has_permission('voucher.create')) {
                    throw new DomainException('You do not have permission to cancel vouchers.');
                }
                ApprovalService::cancel($connection, (int) $voucherId, $userId);
                header('Location: ' . app_url('admin/vouchers/view.php?id=' . (int) $voucherId . '&updated=cancelled'));
                exit;
            }
            if ($action === 'post') {
                if (!user_has_permission('voucher.post')) {
                    throw new DomainException('You do not have permission to post vouchers.');
                }
                $journalId = PostingService::post($connection, (int) $voucherId, $userId);
                header('Location: ' . app_url('admin/vouchers/view.php?id=' . (int) $voucherId . '&updated=posted'));
                exit;
            }

            throw new InvalidArgumentException('Unknown voucher action.');
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$voucher = VoucherService::get($connection, (int) $voucherId);
if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}
$approvalStatement = $connection->prepare(
    'SELECT a.action, a.remarks, a.created_at, u.full_name
     FROM approvals a
     JOIN users u ON u.id = a.acted_by
     WHERE a.voucher_id = :voucher_id
     ORDER BY a.created_at, a.id'
);
$approvalStatement->execute(['voucher_id' => (int) $voucherId]);
$approvalHistory = $approvalStatement->fetchAll();
$currentUserId = (int) $_SESSION['user_id'];
$isCreator = (int) $voucher['created_by'] === $currentUserId;
$canEdit = $isCreator && $voucher['status'] === 'draft' && user_has_permission('voucher.create');
$canSubmit = $isCreator && $voucher['status'] === 'draft' && user_has_permission('voucher.create');
$canCancel = $isCreator
    && in_array($voucher['status'], ['draft', 'submitted'], true)
    && user_has_permission('voucher.create');
$canPost = $voucher['status'] === 'approved' && user_has_permission('voucher.post');

$pageTitle = 'Voucher ' . $voucher['voucher_no'];
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<div class="app-actions">
    <a class="app-button" href="<?= e(app_url('admin/vouchers/print.php?id=' . (int) $voucherId)) ?>"><?= $voucher['voucher_type'] === 'receipt' ? 'Print receipt' : 'Print voucher' ?></a>
    <?php if ($canEdit): ?><a class="app-button" href="<?= e(app_url('admin/vouchers/edit.php?id=' . (int) $voucherId)) ?>">Edit draft</a><?php endif; ?>
    <?php if ($canSubmit || $canCancel || $canPost): ?>
        <form method="post" class="app-actions">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <?php if ($canSubmit): ?><button class="app-button app-button-primary" name="action" value="submit" data-confirm="Submit this voucher for approval?">Submit for approval</button><?php endif; ?>
            <?php if ($canPost): ?><button class="app-button app-button-primary" name="action" value="post" data-confirm="Post this voucher to the general journal?">Post to journal</button><?php endif; ?>
            <?php if ($canCancel): ?><button class="app-button" name="action" value="cancel" data-confirm="Cancel this voucher?">Cancel voucher</button><?php endif; ?>
        </form>
    <?php endif; ?>
</div>
<section class="app-card">
    <div class="app-card-header"><h2>Voucher details</h2><span class="app-badge"><?= e(ucfirst($voucher['status'])) ?></span></div>
    <div class="app-card-body app-grid">
        <div><strong>Voucher number</strong><div><?= e($voucher['voucher_no']) ?></div></div>
        <div><strong>Type</strong><div><?= e(ucfirst($voucher['voucher_type'])) ?></div></div>
        <div><strong>Date</strong><div><?= e($voucher['voucher_date']) ?></div></div>
        <div><strong>Fiscal year</strong><div><?= e($voucher['fiscal_year_name']) ?></div></div>
        <div><strong>Payee</strong><div><?= e($voucher['payee'] ?: '—') ?></div></div>
        <div><strong>Created by</strong><div><?= e($voucher['created_by_name']) ?></div></div>
        <div><strong>Description</strong><div><?= nl2br(e($voucher['description'] ?? '—')) ?></div></div>
        <?php if ($voucher['rejection_reason']): ?><div><strong>Rejection reason</strong><div><?= e($voucher['rejection_reason']) ?></div></div><?php endif; ?>
        <?php if ($voucher['journal_id']): ?><div><strong>Journal entry</strong><div><a href="<?= e(app_url('admin/accounting/journal_view.php?id=' . (int) $voucher['journal_id'])) ?>">View posted journal</a></div></div><?php endif; ?>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Voucher lines</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Account</th><th>Description</th><th class="numeric">Debit</th><th class="numeric">Credit</th></tr></thead>
            <tbody>
                <?php foreach ($voucher['lines'] as $line): ?>
                    <tr>
                        <td><?= e($line['account_code'] . ' - ' . $line['account_name']) ?></td>
                        <td><?= e($line['description'] ?: '—') ?></td>
                        <td class="numeric"><?= (float) $line['debit'] > 0 ? format_currency($line['debit']) : '—' ?></td>
                        <td class="numeric"><?= (float) $line['credit'] > 0 ? format_currency($line['credit']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$voucher['lines']): ?><tr><td class="app-empty" colspan="4">No lines recorded.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th colspan="2">Total</th><th class="numeric"><?= format_currency($voucher['total_amount']) ?></th><th class="numeric"><?= format_currency($voucher['total_amount']) ?></th></tr></tfoot>
        </table>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Approval history</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Action</th><th>By</th><th>Date</th><th>Remarks</th></tr></thead>
            <tbody>
                <?php foreach ($approvalHistory as $history): ?>
                    <tr><td><?= e(ucfirst($history['action'])) ?></td><td><?= e($history['full_name']) ?></td><td><?= e($history['created_at']) ?></td><td><?= e($history['remarks'] ?: '—') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$approvalHistory): ?><tr><td class="app-empty" colspan="4">No approval actions recorded.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
