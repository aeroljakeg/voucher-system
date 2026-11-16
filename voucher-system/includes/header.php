<?php

require_once __DIR__ . '/../config/functions.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Voucher System';
$themeStorageKey = isset($themeStorageKey) && is_string($themeStorageKey)
    ? $themeStorageKey
    : 'voucher-system-theme';
$bodyClass = isset($bodyClass) && is_string($bodyClass) ? $bodyClass : '';
?>
<!doctype html>
<html lang="en" data-theme="light" data-theme-key="<?= e($themeStorageKey) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Voucher System</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="app-shell">
