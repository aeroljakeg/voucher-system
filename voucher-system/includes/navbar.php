<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/csrf.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Voucher System';
?>
<div class="app-main">
    <header class="app-topbar">
        <div class="app-topbar-leading">
            <button class="app-button app-sidebar-toggle" type="button" data-sidebar-toggle aria-expanded="true" aria-label="Collapse sidebar">☰</button>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <div class="app-topbar-actions">
            <button class="app-button" type="button" data-theme-toggle aria-label="Switch to dark mode">Dark mode</button>
            <form action="<?= e(app_url('auth/logout.php')) ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="app-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>
    <main class="app-content">
