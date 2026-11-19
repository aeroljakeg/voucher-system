<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Gift vouchers';
$companyName = isset($companyName) && is_string($companyName) && $companyName !== ''
    ? $companyName
    : 'Voucher System';
$shopBuyer = BuyerAccountService::current(db());
?>
<!doctype html>
<html lang="en" data-theme="light" data-theme-key="voucher-system-theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e($companyName) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="shop-page">
<header class="shop-topbar">
    <a class="shop-brand" href="<?= e(app_url('shop/index.php')) ?>"><?= e($companyName) ?></a>
    <nav class="shop-nav" aria-label="Account navigation">
        <a href="<?= e(app_url('shop/index.php')) ?>">Shop</a>
        <?php if ($shopBuyer): ?>
            <a href="<?= e(app_url('shop/account.php')) ?>">My purchases</a>
            <form method="post" action="<?= e(app_url('shop/logout.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="shop-nav-button" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a href="<?= e(app_url('shop/login.php')) ?>">Buyer sign in</a>
            <a href="<?= e(app_url('shop/register.php')) ?>">Create account</a>
        <?php endif; ?>
        <button class="shop-theme-button" type="button" data-theme-toggle data-theme-icon="true" aria-label="Switch to dark mode">🌙</button>
    </nav>
</header>
<main class="shop-content">
