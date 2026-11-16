<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/VoucherService.php';

require_permission('voucher.view');

$connection = db();
$errors = [];
$statusOptions = ['all', 'draft', 'submitted', 'approved', 'rejected', 'posted', 'cancelled'];
$typeOptions = ['all', 'payment', 'receipt', 'journal', 'contra'];
$rawStatus = $_GET['status'] ?? 'all';
$rawType = $_GET['type'] ?? 'all';
$status = is_string($rawStatus) && in_array($rawStatus, $statusOptions, true) ? $rawStatus : 'all';
$type = is_string($rawType) && in_array($rawType, $typeOptions, true) ? $rawType : 'all';
if (!is_string($rawStatus) || !in_array($rawStatus, $statusOptions, true)) {
    $errors[] = 'The status filter is invalid.';
}
if (!is_string($rawType) || !in_array($rawType, $typeOptions, true)) {
    $errors[] = 'The voucher type filter is invalid.';
}

$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filters = [];
$parameters = [];
if ($from !== '') {
    if (is_iso_date($from)) {
        $filters['from'] = $from;
        $parameters['from_date'] = $from;
    } else {
        $errors[] = 'The start date is invalid.';
    }
}
if ($to !== '') {
    if (is_iso_date($to)) {
        $filters['to'] = $to;
        $parameters['to_date'] = $to;
    } else {
        $errors[] = 'The end date is invalid.';
    }
}
if ($from !== '' && $to !== '' && is_iso_date($from) && is_iso_date($to) && $from > $to) {
    $errors[] = 'The start date must be on or before the end date.';
}
if (app_text_length($search) > 100 || !is_string($_GET['q'] ?? '')) {
    $errors[] = 'Search text must be 100 characters or fewer.';
    $search = '';
}
if ($status !== 'all') {
    $filters['status'] = $status;
    $parameters['status'] = $status;
}
if ($type !== 'all') {
    $filters['type'] = $type;
    $parameters['voucher_type'] = $type;
}
if (isset($filters['from'])) {
    $parameters['from_date'] = $filters['from'];
}
if (isset($filters['to'])) {
    $parameters['to_date'] = $filters['to'];
}
if ($search !== '') {
    $filters['q'] = $search;
    $like = '%' . $search . '%';
    $parameters['search_number'] = $like;
    $parameters['search_payee'] = $like;
    $parameters['search_description'] = $like;
}

$where = [];
if (isset($filters['from'])) $where[] = 'voucher_date >= :from_date';
if (isset($filters['to'])) $where[] = 'voucher_date <= :to_date';
if (isset($filters['status'])) $where[] = 'status = :status';
if (isset($filters['type'])) $where[] = 'voucher_type = :voucher_type';
if (isset($filters['q'])) $where[] = '(voucher_no LIKE :search_number OR payee LIKE :search_payee OR description LIKE :search_description)';
if ($errors) $where[] = '1 = 0';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStatement = $connection->prepare('SELECT COUNT(*) FROM vouchers' . $whereSql);
$countStatement->execute($parameters);
$totalCount = (int) $countStatement->fetchColumn();

$pageSize = 30;
$pageCount = max(1, (int) ceil($totalCount / $pageSize));
$rawPage = $_GET['page'] ?? '1';
$page = is_string($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($page === false) {
    $errors[] = 'The page number is invalid.';
    $page = 1;
}
$page = min((int) $page, $pageCount);
$vouchers = VoucherService::list($connection, $filters, $pageSize, ($page - 1) * $pageSize);
if ($errors) {
    $vouchers = [];
    $totalCount = 0;
}

$query = array_filter([
    'status' => $status,
    'type' => $type,
    'from' => $from,
    'to' => $to,
    'q' => $search,
], static function ($value): bool { return $value !== '' && $value !== 'all'; });
$previousUrl = app_url('admin/vouchers/index.php?' . http_build_query($query + ['page' => max(1, $page - 1)]));
$nextUrl = app_url('admin/vouchers/index.php?' . http_build_query($query + ['page' => min($pageCount, $page + 1)]));

$pageTitle = 'Vouchers';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<div class="app-actions"><a class="app-button app-button-primary" href="<?= e(app_url('admin/vouchers/create.php')) ?>">Create voucher</a></div>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="status">Status</label><select id="status" name="status"><?php foreach ($statusOptions as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
        <div><label for="type">Type</label><select id="type" name="type"><?php foreach ($typeOptions as $option): ?><option value="<?= e($option) ?>" <?= $type === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
        <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
        <div><label for="q">Search</label><input id="q" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Voucher number or payee"></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Filter</button><a class="app-button" href="<?= e(app_url('admin/vouchers/index.php')) ?>">Clear</a></div>
    </form>
</section>
<section class="app-card">
    <div class="app-card-header"><h2><?= number_format($totalCount) ?> vouchers</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Voucher</th><th>Date</th><th>Type</th><th>Payee</th><th>Created by</th><th>Status</th><th class="numeric">Amount</th></tr></thead>
            <tbody>
                <?php if ($vouchers): foreach ($vouchers as $voucher): ?>
                    <tr>
                        <td><a href="<?= e(app_url('admin/vouchers/view.php?id=' . (int) $voucher['id'])) ?>"><?= e($voucher['voucher_no']) ?></a></td>
                        <td><?= e($voucher['voucher_date']) ?></td>
                        <td><?= e(ucfirst($voucher['voucher_type'])) ?></td>
                        <td><?= e($voucher['payee'] ?: '—') ?></td>
                        <td><?= e($voucher['created_by_name']) ?></td>
                        <td><span class="app-badge"><?= e(ucfirst($voucher['status'])) ?></span></td>
                        <td class="numeric"><?= format_currency($voucher['total_amount']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="7">No vouchers match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="app-card-body app-actions">
        <span class="app-muted">Page <?= $page ?> of <?= $pageCount ?></span>
        <a class="app-button" href="<?= e($previousUrl) ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
        <a class="app-button" href="<?= e($nextUrl) ?>" aria-disabled="<?= $page >= $pageCount ? 'true' : 'false' ?>">Next</a>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
