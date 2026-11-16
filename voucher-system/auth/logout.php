<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

start_app_session();

$csrfToken = $_POST['csrf_token'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(400);
    exit('Invalid logout request.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
