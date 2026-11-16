<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';

$connection = db();
if (BuyerAccountService::current($connection)) {
    header('Location: ' . app_url('shop/account.php'));
    exit;
}

$errors = [];
$fullName = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

        try {
            if (!hash_equals($password, $confirmPassword)) {
                throw new InvalidArgumentException('The passwords do not match.');
            }
            $account = BuyerAccountService::register($connection, $fullName, $email, $password);
            BuyerAccountService::signIn($account);
            header('Location: ' . app_url('shop/account.php'));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$pageTitle = 'Create buyer account';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<section class="shop-card shop-auth-card">
    <span class="shop-eyebrow">Join the shop</span>
    <h1>Create a buyer account</h1>
    <p class="shop-auth-intro">Save your purchases and quickly find your voucher receipts.</p>
    <?php foreach ($errors as $error): ?><p class="shop-error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
    <form method="post" class="shop-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="shop-fields shop-fields-single">
            <div><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="120" autocomplete="name" value="<?= e($fullName) ?>" required></div>
            <div><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= e($email) ?>" required></div>
            <div><label for="password">Password</label><input id="password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required><span class="shop-input-hint">Use at least 12 characters.</span></div>
            <div><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></div>
        </div>
        <button class="shop-button" type="submit">Create account</button>
    </form>
    <p class="shop-auth-footer">Already have an account? <a href="<?= e(app_url('shop/login.php')) ?>">Sign in</a>.</p>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
