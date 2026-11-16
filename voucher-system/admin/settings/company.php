<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('settings.manage');

$connection = db();
$errors = [];
$success = false;
$dateFormats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y'];
$fields = [
    'company_name' => ['label' => 'Company name', 'limit' => 150],
    'company_address' => ['label' => 'Company address', 'limit' => 1000],
    'currency_symbol' => ['label' => 'Currency symbol', 'limit' => 10],
    'date_format' => ['label' => 'Date format', 'limit' => 20],
];
$settingsStatement = $connection->query(
    'SELECT setting_key, setting_value
     FROM settings
     WHERE setting_key IN (\'company_name\', \'company_address\', \'currency_symbol\', \'date_format\')'
);
$settings = [];
foreach ($settingsStatement->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'] ?? '';
}
$settings += [
    'company_name' => '',
    'company_address' => '',
    'currency_symbol' => '$',
    'date_format' => 'Y-m-d',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $newValues = [];
        foreach ($fields as $key => $field) {
            $value = $_POST[$key] ?? '';
            if (!is_string($value)) {
                $errors[] = $field['label'] . ' must be plain text.';
                continue;
            }
            $value = trim($value);
            $length = app_text_length($value);
            if ($length < 0 || $length > $field['limit']) {
                $errors[] = $field['label'] . ' must be ' . $field['limit'] . ' characters or fewer.';
            } elseif ($key === 'company_name' && $value === '') {
                $errors[] = 'Company name is required.';
            } elseif ($key === 'currency_symbol' && $value === '') {
                $errors[] = 'Currency symbol is required.';
            } elseif ($key === 'date_format' && !in_array($value, $dateFormats, true)) {
                $errors[] = 'Choose a supported date format.';
            } else {
                $newValues[$key] = $value;
            }
        }

        if (!$errors) {
            $connection->beginTransaction();
            try {
                $save = $connection->prepare(
                    'INSERT INTO settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                foreach ($newValues as $key => $value) {
                    $save->execute(['setting_key' => $key, 'setting_value' => $value]);
                }
                log_audit('update', 'settings', null, $settings, $newValues);
                $connection->commit();
                $settings = $newValues;
                $success = true;
            } catch (Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $error;
            }
        } else {
            foreach ($fields as $key => $field) {
                if (isset($_POST[$key]) && is_string($_POST[$key])) {
                    $settings[$key] = trim($_POST[$key]);
                }
            }
        }
    }
}

$pageTitle = 'Company settings';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success): ?><div class="app-alert" role="status">Company settings saved.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Organization and display</h2></div>
    <div class="app-card-body">
        <form class="app-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div><label for="company_name">Company name</label><input id="company_name" name="company_name" maxlength="150" required value="<?= e((string) $settings['company_name']) ?>"></div>
            <div><label for="currency_symbol">Currency symbol</label><input id="currency_symbol" name="currency_symbol" maxlength="10" required value="<?= e((string) $settings['currency_symbol']) ?>"></div>
            <div><label for="date_format">Date format</label><select id="date_format" name="date_format"><?php foreach ($dateFormats as $format): ?><option value="<?= e($format) ?>" <?= $settings['date_format'] === $format ? 'selected' : '' ?>><?= e(date($format)) ?> (<?= e($format) ?>)</option><?php endforeach; ?></select></div>
            <div><label for="company_address">Company address</label><textarea id="company_address" name="company_address" maxlength="1000"><?= e((string) $settings['company_address']) ?></textarea></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Save settings</button></div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
