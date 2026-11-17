<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

start_app_session();
if (empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$sidebarStatement = db()->prepare(
    'SELECT u.full_name, u.username, p.code AS permission_code
     FROM users u
     LEFT JOIN role_permissions rp ON rp.role_id = u.role_id
     LEFT JOIN permissions p ON p.id = rp.permission_id
     WHERE u.id = :user_id AND u.is_active = 1'
);
$sidebarStatement->execute(['user_id' => (int) $_SESSION['user_id']]);
$sidebarRows = $sidebarStatement->fetchAll();

if (!$sidebarRows) {
    http_response_code(403);
    exit('Your account is inactive or unavailable.');
}

$sidebarUser = $sidebarRows[0];
$sidebarPermissions = [];
foreach ($sidebarRows as $sidebarRow) {
    if ($sidebarRow['permission_code'] !== null) {
        $sidebarPermissions[$sidebarRow['permission_code']] = true;
    }
}

$sidebarCurrentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$sidebarSections = [
    [
        'title' => 'Main',
        'items' => [
            ['label' => 'Dashboard', 'path' => 'admin/dashboard.php', 'permission' => null],
            ['label' => 'Buy vouchers', 'path' => 'shop/index.php', 'permission' => null],
        ],
    ],
    [
        'title' => 'Vouchers',
        'items' => [
            ['label' => 'All vouchers', 'path' => 'admin/vouchers/index.php', 'permission' => 'voucher.view'],
            ['label' => 'Create voucher', 'path' => 'admin/vouchers/create.php', 'permission' => 'voucher.create'],
            ['label' => 'Approvals', 'path' => 'admin/vouchers/approvals.php', 'permission' => 'voucher.approve'],
        ],
    ],
    [
        'title' => 'Accounting',
        'items' => [
            ['label' => 'Journal entries', 'path' => 'admin/accounting/journals.php', 'permission' => 'journal.view'],
            ['label' => 'General ledger', 'path' => 'admin/accounting/ledger.php', 'permission' => 'journal.view'],
            ['label' => 'Trial balance', 'path' => 'admin/accounting/trial_balance.php', 'permission' => 'journal.view'],
            ['label' => 'T-accounts', 'path' => 'admin/accounting/t_accounts.php', 'permission' => 'journal.view'],
            ['label' => 'Reverse entry', 'path' => 'admin/accounting/reversal.php', 'permission' => 'journal.reverse'],
        ],
    ],
    [
        'title' => 'Banking',
        'items' => [
            ['label' => 'Bank accounts', 'path' => 'admin/banking/accounts.php', 'permission' => 'bank.manage'],
            ['label' => 'Transactions', 'path' => 'admin/banking/transactions.php', 'permission' => 'bank.manage'],
            ['label' => 'Reconciliation', 'path' => 'admin/banking/reconciliation.php', 'permission' => 'bank.manage'],
        ],
    ],
    [
        'title' => 'Administration',
        'items' => [
            ['label' => 'Chart of accounts', 'path' => 'admin/accounts/index.php', 'permission' => 'accounts.manage'],
            ['label' => 'Reports', 'path' => 'admin/reports/index.php', 'permission' => 'reports.view'],
            ['label' => 'Users', 'path' => 'admin/users/index.php', 'permission' => 'users.manage'],
            ['label' => 'Roles', 'path' => 'admin/users/roles.php', 'permission' => 'users.manage'],
            ['label' => 'Buyer purchases', 'path' => 'admin/store/orders.php', 'permission' => 'users.manage'],
            ['label' => 'Activity log', 'path' => 'admin/users/activity_log.php', 'permission' => 'users.manage'],
            ['label' => 'Company settings', 'path' => 'admin/settings/company.php', 'permission' => 'settings.manage'],
            ['label' => 'Fiscal years', 'path' => 'admin/settings/fiscal_year.php', 'permission' => 'settings.manage'],
            ['label' => 'Numbering', 'path' => 'admin/settings/numbering.php', 'permission' => 'settings.manage'],
        ],
    ],
];

?>
<aside class="app-sidebar" aria-label="Primary navigation">
    <a class="app-sidebar-brand" href="<?= e(app_url('admin/dashboard.php')) ?>">Voucher System</a>
    <nav class="app-sidebar-nav">
        <?php foreach ($sidebarSections as $section): ?>
            <?php
            $visibleItems = array_filter($section['items'], static function (array $item) use ($sidebarPermissions): bool {
                return $item['permission'] === null || isset($sidebarPermissions[$item['permission']]);
            });
            ?>
            <?php if ($visibleItems): ?>
                <section class="app-sidebar-section">
                    <h2><?= e($section['title']) ?></h2>
                    <?php foreach ($visibleItems as $item): ?>
                        <?php
                        $itemUrl = app_url($item['path']);
                        $itemPath = parse_url($itemUrl, PHP_URL_PATH);
                        $isCurrent = $sidebarCurrentPath === $itemPath;
                        ?>
                        <a class="app-sidebar-link<?= $isCurrent ? ' is-active' : '' ?>"
                           href="<?= e($itemUrl) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>
                           title="<?= e($item['label']) ?>">
                            <?= e($item['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="app-sidebar-user">
        <strong><?= e($sidebarUser['full_name']) ?></strong>
        <span><?= e($sidebarUser['username']) ?></span>
    </div>
</aside>
