<?php

require_once __DIR__ . '/../../config/auth.php';

require_permission('users.manage');

$connection = db();
$errors = [];
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$userId = is_string($_GET['user_id'] ?? null) ? trim($_GET['user_id']) : '';
$where = [];
$parameters = [];

if ($from !== '') {
    if (is_iso_date($from)) {
        $where[] = 'al.created_at >= :from_date';
        $parameters['from_date'] = $from . ' 00:00:00';
    } else {
        $errors[] = 'The start date is invalid.';
    }
}
if ($to !== '') {
    if (is_iso_date($to)) {
        $where[] = 'al.created_at < :to_date';
        $parameters['to_date'] = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
    } else {
        $errors[] = 'The end date is invalid.';
    }
}
if ($search !== '') {
    if (app_text_length($search) > 100) {
        $errors[] = 'Search text must be 100 characters or fewer.';
    } else {
        $where[] = '(al.action LIKE :action OR al.entity LIKE :entity OR CAST(al.entity_id AS CHAR) LIKE :entity_id)';
        $like = '%' . $search . '%';
        $parameters['action'] = $like;
        $parameters['entity'] = $like;
        $parameters['entity_id'] = $like;
    }
}
if ($userId !== '') {
    $validUserId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validUserId === false) {
        $errors[] = 'The user filter is invalid.';
    } else {
        $where[] = 'al.user_id = :user_id';
        $parameters['user_id'] = (int) $validUserId;
    }
}
if (
    !is_string($_GET['from'] ?? '')
    || !is_string($_GET['to'] ?? '')
    || !is_string($_GET['q'] ?? '')
    || !is_string($_GET['user_id'] ?? '')
) {
    $errors[] = 'Filter values must be plain text.';
}
if ($errors) {
    $where[] = '1 = 0';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = $connection->prepare('SELECT COUNT(*) FROM audit_log al' . $whereSql);
$countStatement->execute($parameters);
$totalCount = (int) $countStatement->fetchColumn();
$pageSize = 50;
$pageCount = max(1, (int) ceil($totalCount / $pageSize));
$rawPage = $_GET['page'] ?? '1';
$page = is_string($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($page === false) {
    $errors[] = 'The page number is invalid.';
    $page = 1;
}
$page = min((int) $page, $pageCount);
$statement = $connection->prepare(
    'SELECT al.id, al.action, al.entity, al.entity_id, al.old_values, al.new_values,
            al.ip_address, al.created_at, u.username, u.full_name
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id'
    . $whereSql . '
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
);
$statement->execute($parameters);
$entries = $statement->fetchAll();
if ($errors) {
    $entries = [];
    $totalCount = 0;
}
$users = $connection->query('SELECT id, username FROM users ORDER BY username')->fetchAll();

$query = array_filter([
    'from' => $from,
    'to' => $to,
    'q' => $search,
    'user_id' => $userId,
], static function ($value): bool { return $value !== ''; });
$previousUrl = app_url('admin/users/activity_log.php?' . http_build_query($query + ['page' => max(1, $page - 1)]));
$nextUrl = app_url('admin/users/activity_log.php?' . http_build_query($query + ['page' => min($pageCount, $page + 1)]));

$pageTitle = 'Activity log';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">Through</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
        <div><label for="user_id">User</label><select id="user_id" name="user_id"><option value="">All users</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= $userId === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['username']) ?></option><?php endforeach; ?></select></div>
        <div><label for="q">Action or entity</label><input id="q" name="q" maxlength="100" value="<?= e($search) ?>"></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Filter</button><a class="app-button" href="<?= e(app_url('admin/users/activity_log.php')) ?>">Clear</a></div>
    </form>
</section>
<section class="app-card">
    <div class="app-card-header"><h2><?= number_format($totalCount) ?> audit events</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>IP address</th><th>Details</th></tr></thead>
            <tbody>
                <?php if ($entries): foreach ($entries as $entry): ?>
                    <?php
                    $oldValues = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
                    $newValues = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;
                    $details = json_encode(['old' => $oldValues, 'new' => $newValues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    ?>
                    <tr>
                        <td><?= e($entry['created_at']) ?></td>
                        <td><?= e($entry['full_name'] ?: 'System') ?><span class="app-muted" style="display:block"><?= e($entry['username'] ?? '') ?></span></td>
                        <td><span class="app-badge"><?= e($entry['action']) ?></span></td>
                        <td><?= e($entry['entity'] ?: '—') ?><?= $entry['entity_id'] ? ' #' . (int) $entry['entity_id'] : '' ?></td>
                        <td><?= e($entry['ip_address'] ?: '—') ?></td>
                        <td><details><summary>View</summary><pre><?= e($details === false ? 'Unable to display audit details.' : $details) ?></pre></details></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">No audit entries match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="app-card-body app-actions"><span class="app-muted">Page <?= $page ?> of <?= $pageCount ?></span><a class="app-button" href="<?= e($previousUrl) ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a><a class="app-button" href="<?= e($nextUrl) ?>" aria-disabled="<?= $page >= $pageCount ? 'true' : 'false' ?>">Next</a></div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
