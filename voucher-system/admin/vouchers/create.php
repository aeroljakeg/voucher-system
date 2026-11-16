<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/VoucherService.php';

require_permission('voucher.create');

$connection = db();
$accounts = $connection->query(
    'SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code'
)->fetchAll();
$errors = [];
$formData = [
    'voucher_type' => 'payment',
    'voucher_date' => date('Y-m-d'),
    'payee' => '',
    'description' => '',
];
$formLines = [
    ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
    ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
];

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
            $voucherId = VoucherService::createDraft(
                $connection,
                $formData,
                $lines,
                (int) $_SESSION['user_id']
            );
            header('Location: ' . app_url('admin/vouchers/view.php?id=' . $voucherId));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$pageTitle = 'Create voucher';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<p class="app-muted">Create a balanced voucher draft. It can be submitted for approval after it is saved.</p>
<?php foreach ($errors as $error): ?>
    <div class="app-alert" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<?php
$formAction = app_url('admin/vouchers/create.php');
$submitLabel = 'Save draft';
require __DIR__ . '/_form.php';
?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
