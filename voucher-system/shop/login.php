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
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $account = BuyerAccountService::authenticate($connection, $email, $password);
        if ($account) {
            BuyerAccountService::signIn($account);
            header('Location: ' . app_url('shop/account.php'));
            exit;
        }

        $errors[] = 'The email or password is incorrect.';
    }
}

$pageTitle = 'Buyer sign in';
$companyName = app_setting('company_name', 'Voucher System') ?? 'Voucher System';
require __DIR__ . '/_header.php';
?>
<section class="shop-card shop-auth-card">
    <span class="shop-eyebrow">Welcome back</span>
    <h1>Sign in to your buyer account</h1>
    <?php foreach ($errors as $error): ?><p class="shop-error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
    <form method="post" class="shop-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="shop-fields shop-fields-single">
            <div><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= e($email) ?>" required></div>
            <div><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        </div>
        <button class="shop-button" type="submit">Sign in</button>
    </form>
    <p class="shop-auth-footer">New here? <a href="<?= e(app_url('shop/register.php')) ?>">Create a buyer account</a>.</p>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
