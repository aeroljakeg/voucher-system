<?php

require_once __DIR__ . '/../../config/auth.php';

require_permission('journal.view');

$connection = db();
$errors = [];
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$allowedStatuses = ['all', 'posted', 'reversed'];
$where = [];
$parameters = [];

if ($from !== '') {
    if (is_iso_date($from)) {
        $where[] = 'je.entry_date >= :from_date';
        $parameters['from_date'] = $from;
    } else {
        $errors[] = 'Enter a valid start date.';
    }
}
if ($to !== '') {
    if (is_iso_date($to)) {
        $where[] = 'je.entry_date <= :to_date';
        $parameters['to_date'] = $to;
    } else {
        $errors[] = 'Enter a valid end date.';
    }
}
if ($from !== '' && $to !== '' && is_iso_date($from) && is_iso_date($to) && $from > $to) {
    $errors[] = 'The start date must be on or before the end date.';
}
if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'The journal status filter is invalid.';
    $status = 'all';
} elseif ($status !== 'all') {
    $where[] = 'je.status = :status';
    $parameters['status'] = $status;
}
if (app_text_length($search) > 100) {
    $errors[] = 'Search text must be 100 characters or fewer.';
    $search = '';
} elseif ($search !== '') {
    $where[] = '(je.entry_no LIKE :search_entry OR je.description LIKE :search_description OR v.voucher_no LIKE :search_voucher)';
    $like = '%' . $search . '%';
    $parameters['search_entry'] = $like;
    $parameters['search_description'] = $like;
    $parameters['search_voucher'] = $like;
}
if (!is_string($_GET['q'] ?? '')) {
    $errors[] = 'Search text must be plain text.';
}
if ($errors) {
    $where[] = '1 = 0';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql = '
    FROM journal_entries je
    JOIN fiscal_years fy ON fy.id = je.fiscal_year_id
    JOIN users u ON u.id = je.posted_by
    LEFT JOIN vouchers v ON v.id = je.voucher_id
    LEFT JOIN journal_entries reversal ON reversal.reversal_of = je.id';
$countStatement = $connection->prepare('SELECT COUNT(DISTINCT je.id)' . $fromSql . $whereSql);
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
$statement = $connection->prepare(
    'SELECT je.id, je.entry_no, je.entry_date, je.description, je.total_debit, je.total_credit,
            je.status, je.reversal_of, fy.name AS fiscal_year_name, u.full_name AS posted_by_name,
            v.voucher_no, reversal.entry_no AS reversal_entry_no
    ' . $fromSql . $whereSql . '
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
);
$statement->execute($parameters);
$entries = $statement->fetchAll();
if ($errors) {
    $entries = [];
    $totalCount = 0;
}
$query = array_filter([
    'from' => $from,
    'to' => $to,
    'status' => $status === 'all' ? '' : $status,
    'q' => $search,
]);
$previousUrl = app_url('admin/accounting/journals.php?' . http_build_query($query + ['page' => max(1, $page - 1)]));
$nextUrl = app_url('admin/accounting/journals.php?' . http_build_query($query + ['page' => min($pageCount, $page + 1)]));

$pageTitle = 'Journal entries';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
        <div><label for="status">Status</label><select id="status" name="status"><?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
        <div><label for="q">Search</label><input id="q" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Entry number or voucher"></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Filter</button><a class="app-button" href="<?= e(app_url('admin/accounting/journals.php')) ?>">Clear</a></div>
    </form>
</section>
<section class="app-card">
    <div class="app-card-header"><h2><?= number_format($totalCount) ?> journal entries</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Entry</th><th>Date</th><th>Fiscal year</th><th>Description / source</th><th>Posted by</th><th>Status</th><th class="numeric">Debit</th><th class="numeric">Credit</th></tr></thead>
            <tbody>
                <?php if ($entries): foreach ($entries as $entry): ?>
                    <tr>
                        <td><a href="<?= e(app_url('admin/accounting/journal_view.php?id=' . (int) $entry['id'])) ?>"><?= e($entry['entry_no']) ?></a></td>
                        <td><?= e($entry['entry_date']) ?></td>
                        <td><?= e($entry['fiscal_year_name']) ?></td>
                        <td><?= e($entry['description'] ?: '—') ?><?php if ($entry['voucher_no']): ?><span class="app-muted"> · Voucher <?= e($entry['voucher_no']) ?></span><?php endif; ?><?php if ($entry['reversal_of']): ?><span class="app-muted"> · Reversal</span><?php endif; ?></td>
                        <td><?= e($entry['posted_by_name']) ?></td>
                        <td><span class="app-badge"><?= e(ucfirst($entry['status'])) ?></span><?php if ($entry['reversal_entry_no']): ?><span class="app-muted"> · Reversed by <?= e($entry['reversal_entry_no']) ?></span><?php endif; ?></td>
                        <td class="numeric"><?= format_currency($entry['total_debit']) ?></td>
                        <td class="numeric"><?= format_currency($entry['total_credit']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="8">No journal entries match these filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="app-card-body app-actions"><span class="app-muted">Page <?= $page ?> of <?= $pageCount ?></span><a class="app-button" href="<?= e($previousUrl) ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a><a class="app-button" href="<?= e($nextUrl) ?>" aria-disabled="<?= $page >= $pageCount ? 'true' : 'false' ?>">Next</a></div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
