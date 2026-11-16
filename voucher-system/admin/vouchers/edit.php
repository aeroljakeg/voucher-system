<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/VoucherService.php';

require_permission('voucher.create');

$voucherId = app_positive_int_id($_GET['id'] ?? null);
if ($voucherId === false) {
    http_response_code(400);
    exit('A valid voucher ID is required.');
}

$connection = db();
$voucher = VoucherService::get($connection, (int) $voucherId);
if (!$voucher) {
    http_response_code(404);
    exit('Voucher not found.');
}
if ((int) $voucher['created_by'] !== (int) $_SESSION['user_id']) {
    http_response_code(403);
    exit('Only the voucher creator can edit this voucher.');
}
if ($voucher['status'] !== 'draft') {
    http_response_code(409);
    exit('Only draft vouchers can be edited.');
}

$accounts = $connection->query(
    'SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code'
)->fetchAll();
$errors = [];
$formData = [
    'voucher_type' => $voucher['voucher_type'],
    'voucher_date' => $voucher['voucher_date'],
    'payee' => $voucher['payee'] ?? '',
    'description' => $voucher['description'] ?? '',
];
$formLines = $voucher['lines'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['voucher_type', 'voucher_date', 'payee', 'description'] as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $formData[$field] = trim($_POST[$field]);
        }
    }

    $rawLines = $_POST['lines'] ?? [];
    $formLines = VoucherService::linesForForm($rawLines);
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            $lines = VoucherService::linesFromForm($rawLines);
            VoucherService::updateDraft($connection, (int) $voucherId, $formData, $lines);
            header('Location: ' . app_url('admin/vouchers/view.php?id=' . (int) $voucherId . '&updated=1'));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$pageTitle = 'Edit voucher';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<p class="app-muted">Update draft <?= e($voucher['voucher_no']) ?>. Submitted or posted vouchers cannot be edited.</p>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php
$formAction = app_url('admin/vouchers/edit.php?id=' . (int) $voucherId);
$submitLabel = 'Save changes';
require __DIR__ . '/_form.php';
?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
