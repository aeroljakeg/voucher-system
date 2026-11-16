<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../services/BuyerAccountService.php';

$csrfToken = $_POST['csrf_token'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(400);
    exit('Invalid sign-out request.');
}

BuyerAccountService::signOut();
header('Location: ' . app_url('shop/index.php'));
exit;
