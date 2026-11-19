<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

start_app_session();
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('admin/dashboard.php'));
    exit;
}

$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $username = $_POST['username'] ?? null;
        $fullName = $_POST['full_name'] ?? null;
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? null;
        $confirmPassword = $_POST['confirm_password'] ?? null;

        try {
            if (!is_string($username) || !preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username)) {
                throw new InvalidArgumentException('Username must be 3–50 letters, numbers, dots, underscores, or hyphens.');
            }
            if (!is_string($fullName) || trim($fullName) === '' || app_text_length($fullName) < 0 || app_text_length($fullName) > 100) {
                throw new InvalidArgumentException('Full name is required and must be 100 characters or fewer.');
            }
            if (!is_string($email) || ($email !== '' && (app_text_length($email) < 0 || app_text_length($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
                throw new InvalidArgumentException('Enter a valid email address of 120 characters or fewer.');
            }
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 200) {
                throw new InvalidArgumentException('Password must be at least 12 characters.');
            }
            if (!is_string($confirmPassword) || !hash_equals($password, $confirmPassword)) {
                throw new InvalidArgumentException('The passwords do not match.');
            }

            $connection = db();
            $roleStatement = $connection->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $roleStatement->execute(['name' => 'Viewer']);
            $roleId = $roleStatement->fetchColumn();
            if ($roleId === false) {
                throw new RuntimeException('The Viewer role is missing. Restore the application database seed data.');
            }

            $duplicateStatement = $connection->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $duplicateStatement->execute(['username' => trim($username)]);
            if ($duplicateStatement->fetchColumn()) {
                throw new DomainException('That username is already in use.');
            }

            $insert = $connection->prepare(
                'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active)
                 VALUES (:role_id, :username, :full_name, :email, :password_hash, 0)'
            );
            try {
                $insert->execute([
                    'role_id' => (int) $roleId,
                    'username' => trim($username),
                    'full_name' => trim($fullName),
                    'email' => trim($email) === '' ? null : trim($email),
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '23000') {
                    throw $error;
                }

                $duplicateStatement->execute(['username' => trim($username)]);
                if ($duplicateStatement->fetchColumn()) {
                    throw new DomainException('That username is already in use.', 0, $error);
                }

                throw $error;
            }

            $submitted = true;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
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
    <title>Create account | Voucher System</title>
    <style>
        :root { color-scheme: light; --bg: #f3f6fb; --surface: #fff; --text: #192536; --muted: #65748b; --border: #dce4ef; --accent: #315ee8; --danger-bg: #fff1f0; --danger: #a52b24; --success-bg: #edf9f0; --success: #246b36; }
        @media (prefers-color-scheme: dark) { :root { color-scheme: dark; --bg: #111827; --surface: #1c2738; --text: #e8eef8; --muted: #a0aec0; --border: #354257; --accent: #7d9bff; --danger-bg: #472b30; --danger: #ffb4ad; --success-bg: #203b2b; --success: #a7e5b3; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, sans-serif; }
        main { width: min(100%, 420px); padding: 32px; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); box-shadow: 0 18px 50px #12213a12; }
        h1 { margin: 0 0 6px; font-size: 1.65rem; }
        p { margin: 0 0 24px; color: var(--muted); }
        label { display: block; margin: 16px 0 6px; font-weight: 650; }
        input { width: 100%; padding: 11px 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text); font: inherit; }
        button { width: 100%; margin-top: 22px; padding: 12px; border: 0; border-radius: 9px; background: var(--accent); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .message { margin: 0 0 16px; padding: 11px 12px; border-radius: 9px; }
        .error { background: var(--danger-bg); color: var(--danger); }
        .success { background: var(--success-bg); color: var(--success); }
        .footer { margin: 20px 0 0; text-align: center; }
        .password-field { position: relative; display: block; }
        .password-field input { padding-right: 66px; }
        .password-toggle { position: absolute; top: 50%; right: 8px; transform: translateY(-50%); min-height: 28px; padding: 3px 6px; border: 0; border-radius: 6px; background: transparent; color: var(--accent); font: 700 .78rem/1.2 system-ui, sans-serif; cursor: pointer; }
        .password-toggle:hover { background: color-mix(in srgb, var(--accent) 12%, transparent); }
    </style>
</head>
<body>
<main>
    <h1>Create an account</h1>
    <p>New accounts need administrator approval before they can sign in.</p>
    <?php foreach ($errors as $error): ?>
        <div class="message error" role="alert"><?= h($error) ?></div>
    <?php endforeach; ?>
    <?php if ($submitted): ?>
        <div class="message success" role="status">Your account request was submitted. An administrator must activate your account before you can sign in.</div>
        <p class="footer"><a href="<?= h(app_url('auth/login.php')) ?>">Back to sign in</a></p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" maxlength="100" autocomplete="name" required>
            <label for="username">Username</label>
            <input id="username" name="username" maxlength="50" autocomplete="username" required>
            <label for="email">Email (optional)</label>
            <input id="email" name="email" type="email" maxlength="120" autocomplete="email">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
            <label for="confirm_password">Confirm password</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
            <button type="submit">Request account</button>
        </form>
        <p class="footer">Already have an account? <a href="<?= h(app_url('auth/login.php')) ?>">Sign in</a></p>
    <?php endif; ?>
</main>
<script src="<?= h(app_url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
