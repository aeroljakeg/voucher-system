<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

start_app_session();
$defaultRedirect = app_url('admin/dashboard.php');

if (!empty($_SESSION['user_id'])) {
    $redirect = $_SESSION['after_login'] ?? $defaultRedirect;
    unset($_SESSION['after_login']);
    if (!is_string($redirect) || strpos($redirect, app_url('admin/')) !== 0) {
        $redirect = $defaultRedirect;
    }
    header('Location: ' . $redirect);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $statement = db()->prepare(
            'SELECT id, password_hash
             FROM users
             WHERE username = :username AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['buyer_account_id']);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $update = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
            $update->execute(['id' => (int) $user['id']]);

            $redirect = $_SESSION['after_login'] ?? $defaultRedirect;
            unset($_SESSION['after_login']);
            if (!is_string($redirect) || strpos($redirect, app_url('admin/')) !== 0) {
                $redirect = $defaultRedirect;
            }
            header('Location: ' . $redirect);
            exit;
        }

        $error = 'The username or password is incorrect.';
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Voucher System</title>
    <style>
        :root { color-scheme: light; --bg: #f3f6fb; --surface: #fff; --text: #192536; --muted: #65748b; --border: #dce4ef; --accent: #315ee8; --danger-bg: #fff1f0; --danger: #a52b24; }
        @media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #111827; --surface: #1c2738; --text: #e8eef8; --muted: #a0aec0; --border: #354257; --accent: #7d9bff; --danger-bg: #472b30; --danger: #ffb4ad; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, sans-serif; }
        main { width: min(100%, 420px); padding: 32px; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); box-shadow: 0 18px 50px #12213a12; }
        h1 { margin: 0 0 6px; font-size: 1.65rem; }
        p { margin: 0 0 24px; color: var(--muted); }
        label { display: block; margin: 16px 0 6px; font-weight: 650; }
        input { width: 100%; padding: 11px 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text); font: inherit; }
        button { width: 100%; margin-top: 22px; padding: 12px; border: 0; border-radius: 9px; background: var(--accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .error { margin: 0 0 16px; padding: 11px 12px; border-radius: 9px; background: var(--danger-bg); color: var(--danger); }
    </style>
</head>
<body>
<main>
    <h1>Welcome back</h1>
    <p>Sign in to manage your vouchers.</p>
    <?php if ($error !== ''): ?>
        <div class="error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
    <p style="margin: 20px 0 0; text-align: center;">Need an account? <a href="<?= h(app_url('auth/register.php')) ?>">Create one</a></p>
    <p style="margin: 12px 0 0; text-align: center;">Administrators sign in here too.</p>
    <p style="margin: 12px 0 0; text-align: center;"><a href="<?= h(app_url('shop/index.php')) ?>">Browse vouchers for sale</a></p>
</main>
</body>
</html>
