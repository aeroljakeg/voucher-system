<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/BankService.php';

require_permission('bank.manage');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function is_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function normalize_amount($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
        return null;
    }

    $parts = explode('.', $value, 2);
    $whole = ltrim($parts[0], '0');
    $fraction = str_pad($parts[1] ?? '', 2, '0');

    return ($whole === '' ? '0' : $whole) . '.' . $fraction;
}

function format_amount(string $currencySymbol, $amount): string
{
    return h($currencySymbol) . number_format((float) $amount, 2);
}

$connection = db();
$accountStatement = $connection->query(
    'SELECT ba.id, ba.bank_name, ba.account_name, ba.account_number, ba.is_active,
            a.code AS account_code, a.name AS ledger_account_name
     FROM bank_accounts ba
     JOIN accounts a ON a.id = ba.account_id
     ORDER BY ba.bank_name, ba.account_name'
);
$allBankAccounts = $accountStatement->fetchAll();
$activeBankAccounts = array_values(array_filter(
    $allBankAccounts,
    static function (array $account): bool {
        return (int) $account['is_active'] === 1;
    }
));
$activeAccountIds = array_fill_keys(
    array_map('intval', array_column($activeBankAccounts, 'id')),
    true
);

$currencyStatement = $connection->prepare(
    'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1'
);
$currencyStatement->execute(['setting_key' => 'currency_symbol']);
$currencySymbol = $currencyStatement->fetchColumn();
if (!is_string($currencySymbol) || $currencySymbol === '') {
    $currencySymbol = '$';
}

$errors = [];
$createdAccountId = '';
$createdDate = date('Y-m-d');
$createdReference = '';
$createdDescription = '';
$createdDeposit = '';
$createdWithdrawal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $rawAccountId = $_POST['bank_account_id'] ?? '';
    $accountId = filter_var(
        is_string($rawAccountId) ? $rawAccountId : '',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $createdAccountId = $accountId === false ? '' : (string) $accountId;

    $rawDate = $_POST['txn_date'] ?? '';
    $createdDate = is_string($rawDate) ? trim($rawDate) : '';
    if (!is_valid_date($createdDate)) {
        $errors[] = 'Enter a valid transaction date.';
    }

    $rawReference = $_POST['reference'] ?? '';
    $createdReference = is_string($rawReference) ? trim($rawReference) : '';
    $referenceLength = app_text_length($createdReference);
    if (!is_string($rawReference) || $referenceLength < 0 || $referenceLength > 60) {
        $errors[] = 'The reference must be 60 characters or fewer.';
    }

    $rawDescription = $_POST['description'] ?? '';
    $createdDescription = is_string($rawDescription) ? trim($rawDescription) : '';
    $descriptionLength = app_text_length($createdDescription);
    if (!is_string($rawDescription) || $descriptionLength < 0 || $descriptionLength > 255) {
        $errors[] = 'The description must be 255 characters or fewer.';
    }

    $rawDeposit = $_POST['deposit'] ?? '';
    $rawWithdrawal = $_POST['withdrawal'] ?? '';
    $createdDeposit = is_string($rawDeposit) ? trim($rawDeposit) : '';
    $createdWithdrawal = is_string($rawWithdrawal) ? trim($rawWithdrawal) : '';
    $deposit = is_string($rawDeposit)
        ? ($createdDeposit === '' ? '0.00' : normalize_amount($createdDeposit))
        : null;
    $withdrawal = is_string($rawWithdrawal)
        ? ($createdWithdrawal === '' ? '0.00' : normalize_amount($createdWithdrawal))
        : null;

    if ($deposit === null || $withdrawal === null) {
        $errors[] = 'Enter valid amounts with no more than two decimal places.';
    } elseif (($deposit === '0.00' && $withdrawal === '0.00')
        || ($deposit !== '0.00' && $withdrawal !== '0.00')) {
        $errors[] = 'Enter a positive deposit or a positive withdrawal, but not both.';
    }

    if ($accountId === false || !isset($activeAccountIds[(int) $accountId])) {
        $errors[] = 'Choose an active bank account.';
    }

    if (!$errors) {
        $connection->beginTransaction();
        try {
            $transactionId = BankService::recordStatementTransaction($connection, [
                'bank_account_id' => (int) $accountId,
                'txn_date' => $createdDate,
                'reference' => $createdReference,
                'description' => $createdDescription,
                'deposit' => $deposit,
                'withdrawal' => $withdrawal,
            ]);
            log_audit('create', 'bank_transaction', $transactionId, null, [
                'bank_account_id' => (int) $accountId,
                'txn_date' => $createdDate,
                'reference' => $createdReference,
                'deposit' => $deposit,
                'withdrawal' => $withdrawal,
            ]);
            $connection->commit();
            header('Location: transactions.php?created=1&account_id=' . (int) $accountId);
            exit;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($error instanceof InvalidArgumentException || $error instanceof DomainException) {
                $errors[] = $error->getMessage();
            } else {
                throw $error;
            }
        }
    }
}

$filters = [];
$parameters = [];
$filterErrors = [];
$filterAccountId = '';
$fromDate = '';
$toDate = '';
$status = 'all';
$search = '';

$rawFilterAccountId = $_GET['account_id'] ?? '';
if (!is_string($rawFilterAccountId)) {
    $filterErrors[] = 'The selected bank account filter is invalid.';
} elseif ($rawFilterAccountId !== '') {
    $validatedAccountId = filter_var(
        $rawFilterAccountId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($validatedAccountId === false) {
        $filterErrors[] = 'The selected bank account filter is invalid.';
    } else {
        $filterAccountId = (string) $validatedAccountId;
        $filters[] = 'bt.bank_account_id = :account_id';
        $parameters['account_id'] = (int) $validatedAccountId;
    }
}

$rawFromDate = $_GET['from'] ?? '';
$fromDate = is_string($rawFromDate) ? trim($rawFromDate) : '';
if (!is_string($rawFromDate)) {
    $filterErrors[] = 'The start date filter is invalid.';
}
if ($fromDate !== '') {
    if (is_valid_date($fromDate)) {
        $filters[] = 'bt.txn_date >= :from_date';
        $parameters['from_date'] = $fromDate;
    } else {
        $filterErrors[] = 'The start date filter is invalid.';
    }
}

$rawToDate = $_GET['to'] ?? '';
$toDate = is_string($rawToDate) ? trim($rawToDate) : '';
if (!is_string($rawToDate)) {
    $filterErrors[] = 'The end date filter is invalid.';
}
if ($toDate !== '') {
    if (is_valid_date($toDate)) {
        $filters[] = 'bt.txn_date <= :to_date';
        $parameters['to_date'] = $toDate;
    } else {
        $filterErrors[] = 'The end date filter is invalid.';
    }
}

if ($fromDate !== '' && $toDate !== '' && is_valid_date($fromDate) && is_valid_date($toDate) && $fromDate > $toDate) {
    $filterErrors[] = 'The start date must be on or before the end date.';
}

$rawStatus = $_GET['status'] ?? 'all';
if (is_string($rawStatus) && in_array($rawStatus, ['all', 'reconciled', 'unreconciled'], true)) {
    $status = $rawStatus;
} else {
    $filterErrors[] = 'The reconciliation status filter is invalid.';
}
if ($status === 'reconciled') {
    $filters[] = 'bt.is_reconciled = 1';
} elseif ($status === 'unreconciled') {
    $filters[] = 'bt.is_reconciled = 0';
}

$rawSearch = $_GET['q'] ?? '';
$search = is_string($rawSearch) ? trim($rawSearch) : '';
$searchLength = app_text_length($search);
if (!is_string($rawSearch) || $searchLength < 0 || $searchLength > 100) {
    $filterErrors[] = 'Search text must be 100 characters or fewer.';
    $search = '';
} elseif ($search !== '') {
    $filters[] = '(bt.reference LIKE :search_reference
        OR bt.description LIKE :search_description
        OR ba.bank_name LIKE :search_bank
        OR ba.account_name LIKE :search_account
        OR a.code LIKE :search_code
        OR a.name LIKE :search_ledger_account)';
    $like = '%' . $search . '%';
    $parameters['search_reference'] = $like;
    $parameters['search_description'] = $like;
    $parameters['search_bank'] = $like;
    $parameters['search_account'] = $like;
    $parameters['search_code'] = $like;
    $parameters['search_ledger_account'] = $like;
}

if ($filterErrors) {
    $filters[] = '1 = 0';
}

$whereSql = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
$fromSql = '
    FROM bank_transactions bt
    JOIN bank_accounts ba ON ba.id = bt.bank_account_id
    JOIN accounts a ON a.id = ba.account_id
    LEFT JOIN vouchers v ON v.id = bt.voucher_id
    LEFT JOIN journal_entries je ON je.id = bt.journal_id';
$summaryStatement = $connection->prepare(
    'SELECT COUNT(*) AS transaction_count,
            COALESCE(SUM(bt.deposit), 0) AS total_deposits,
            COALESCE(SUM(bt.withdrawal), 0) AS total_withdrawals,
            COALESCE(SUM(bt.deposit - bt.withdrawal), 0) AS net_change'
    . $fromSql . $whereSql
);
$summaryStatement->execute($parameters);
$summary = $summaryStatement->fetch();

$pageSize = 25;
$totalCount = (int) $summary['transaction_count'];
$pageCount = max(1, (int) ceil($totalCount / $pageSize));
$rawPage = $_GET['page'] ?? '1';
$page = is_string($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($page === false) {
    $filterErrors[] = 'The page number is invalid.';
    $page = 1;
} else {
    $page = min((int) $page, $pageCount);
}
$offset = ($page - 1) * $pageSize;

$transactionStatement = $connection->prepare(
    'SELECT bt.id, bt.txn_date, bt.reference, bt.description, bt.deposit, bt.withdrawal,
            bt.is_reconciled, bt.reconciled_at,
            ba.bank_name, ba.account_name, ba.account_number,
            a.code AS account_code, a.name AS ledger_account_name,
            v.voucher_no, je.entry_no
    ' . $fromSql . $whereSql . '
    ORDER BY bt.txn_date DESC, bt.id DESC
    LIMIT ' . $pageSize . ' OFFSET ' . $offset
);
$transactionStatement->execute($parameters);
$transactions = $transactionStatement->fetchAll();

$pageQuery = [
    'account_id' => $filterAccountId,
    'from' => $fromDate,
    'to' => $toDate,
    'status' => $status,
    'q' => $search,
];
$pageQuery = array_filter($pageQuery, static function ($value): bool {
    return $value !== '';
});
$previousUrl = 'transactions.php?' . http_build_query($pageQuery + ['page' => max(1, $page - 1)]);
$nextUrl = 'transactions.php?' . http_build_query($pageQuery + ['page' => min($pageCount, $page + 1)]);
?>
<!doctype html>
<html lang="en" data-theme="light" data-theme-key="voucher-system-theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bank transactions | Voucher System</title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
    <style>
        :root {
            color-scheme: light;
            --page: #f3f6fb; --surface: #fff; --surface-muted: #f7f9fc;
            --text: #192536; --muted: #64748b; --border: #dce4ef;
            --accent: #315ee8; --accent-soft: #edf2ff; --success: #087443;
            --success-soft: #e7f7ef; --warning: #965b00; --warning-soft: #fff3d9;
            --danger: #a52b24; --danger-soft: #fff1f0; --shadow: 0 16px 40px #12213a0d;
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #111827; --surface: #1c2738; --surface-muted: #222f43;
            --text: #e8eef8; --muted: #a0aec0; --border: #354257;
            --accent: #9ab0ff; --accent-soft: #29385e; --success: #78ddb0;
            --success-soft: #183c33; --warning: #ffd083; --warning-soft: #49391e;
            --danger: #ffaaa3; --danger-soft: #482b31; --shadow: 0 16px 40px #00000024;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px max(24px, calc((100% - 1180px) / 2)); background: var(--surface); border-bottom: 1px solid var(--border); }
        .brand { color: var(--text); font-weight: 750; }
        .top-actions { display: flex; align-items: center; gap: 9px; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface); color: var(--text); font: inherit; font-weight: 650; cursor: pointer; }
        .button:hover { background: var(--surface-muted); text-decoration: none; }
        .button-primary { border-color: var(--accent); background: var(--accent); color: #fff; }
        .button-primary:hover { opacity: .9; background: var(--accent); }
        main { width: min(1180px, calc(100% - 40px)); margin: 30px auto 56px; }
        .breadcrumbs { margin-bottom: 18px; color: var(--muted); font-size: .92rem; }
        .heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
        h1 { margin: 0; font-size: clamp(1.7rem, 3vw, 2.2rem); letter-spacing: -.025em; }
        h2 { margin: 0; font-size: 1rem; }
        .subtitle { margin: 5px 0 0; color: var(--muted); }
        .card { margin-bottom: 18px; overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); box-shadow: var(--shadow); }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 19px; border-bottom: 1px solid var(--border); }
        .card-body { padding: 18px 20px; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
        .metric { padding: 17px 18px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface); box-shadow: var(--shadow); }
        .metric-label { display: block; color: var(--muted); font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .metric-value { display: block; margin-top: 6px; font-size: 1.25rem; font-weight: 750; font-variant-numeric: tabular-nums; }
        .filters { display: grid; grid-template-columns: 1.3fr repeat(3, minmax(130px, .8fr)) 1.2fr auto; align-items: end; gap: 12px; }
        label { display: block; margin-bottom: 5px; color: var(--muted); font-size: .82rem; font-weight: 650; }
        input, select, textarea { width: 100%; min-height: 40px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; }
        textarea { min-height: 76px; resize: vertical; }
        input:focus, select:focus, textarea:focus, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .form-field-wide { grid-column: span 2; }
        .form-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
        .hint { margin: 12px 0 0; color: var(--muted); font-size: .87rem; }
        .alerts { display: grid; gap: 9px; margin-bottom: 16px; }
        .alert { padding: 11px 14px; border-radius: 9px; }
        .alert-error { background: var(--danger-soft); color: var(--danger); }
        .alert-success { background: var(--success-soft); color: var(--success); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 930px; border-collapse: collapse; }
        th, td { padding: 11px 13px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { background: var(--surface-muted); color: var(--muted); font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        th.numeric, td.numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tbody tr:last-child td { border-bottom: 0; }
        .secondary { display: block; color: var(--muted); font-size: .82rem; }
        .badge { display: inline-flex; padding: 3px 8px; border-radius: 999px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
        .badge-reconciled { background: var(--success-soft); color: var(--success); }
        .badge-open { background: var(--warning-soft); color: var(--warning); }
        .badge-inactive { margin-left: 5px; background: var(--surface-muted); color: var(--muted); }
        .empty { padding: 28px 18px; color: var(--muted); text-align: center; }
        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 18px; border-top: 1px solid var(--border); color: var(--muted); }
        .pagination-links { display: flex; gap: 8px; }
        .pagination .button[aria-disabled="true"] { opacity: .45; pointer-events: none; }
        details > summary { cursor: pointer; list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        @media (max-width: 1050px) { .filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 720px) {
            .topbar { padding: 12px 16px; }
            main { width: min(100% - 28px, 1180px); margin-top: 22px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .filters, .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .form-field-wide { grid-column: span 2; }
        }
        @media (max-width: 460px) {
            .topbar { align-items: flex-start; }
            .top-actions { gap: 5px; }
            .summary-grid, .filters, .form-grid { grid-template-columns: 1fr; }
            .form-field-wide { grid-column: auto; }
            .pagination { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="app-main">
<header class="topbar">
    <strong>Bank transactions</strong>
    <div class="top-actions">
        <button class="button" type="button" data-theme-toggle aria-label="Switch to dark mode">Dark mode</button>
        <form action="../../auth/logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <button class="button" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main>
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="../dashboard.php">Home</a> / Banking / Transactions</nav>
    <section class="heading">
        <div>
            <h1>Bank transactions</h1>
            <p class="subtitle">Review and record bank statement transactions.</p>
        </div>
    </section>

    <?php if ($errors || $filterErrors || (isset($_GET['created']) && $_GET['created'] === '1')): ?>
        <div class="alerts" aria-live="polite">
            <?php foreach (array_merge($errors, $filterErrors) as $error): ?>
                <div class="alert alert-error" role="alert"><?= h($error) ?></div>
            <?php endforeach; ?>
            <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
                <div class="alert alert-success" role="status">Bank statement transaction recorded.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="summary-grid" aria-label="Filtered transaction totals">
        <div class="metric"><span class="metric-label">Transactions</span><span class="metric-value"><?= number_format($totalCount) ?></span></div>
        <div class="metric"><span class="metric-label">Deposits</span><span class="metric-value"><?= format_amount($currencySymbol, $summary['total_deposits']) ?></span></div>
        <div class="metric"><span class="metric-label">Withdrawals</span><span class="metric-value"><?= format_amount($currencySymbol, $summary['total_withdrawals']) ?></span></div>
        <div class="metric"><span class="metric-label">Net change</span><span class="metric-value"><?= format_amount($currencySymbol, $summary['net_change']) ?></span></div>
    </section>

    <details class="card" <?= $errors ? 'open' : '' ?>>
        <summary class="card-head"><h2>Record a statement transaction</h2><span class="button button-primary">Add transaction</span></summary>
        <div class="card-body">
            <form method="post" action="transactions.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="form-grid">
                    <div>
                        <label for="bank_account_id">Bank account</label>
                        <select id="bank_account_id" name="bank_account_id" required>
                            <option value="">Select a bank account</option>
                            <?php foreach ($activeBankAccounts as $account): ?>
                                <?php $accountLabel = $account['bank_name'] . ' - ' . $account['account_name'] . ' (' . masked_account_number($account['account_number']) . ')'; ?>
                                <option value="<?= (int) $account['id'] ?>" <?= $createdAccountId === (string) $account['id'] ? 'selected' : '' ?>>
                                    <?= h($accountLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="txn_date">Transaction date</label>
                        <input id="txn_date" type="date" name="txn_date" value="<?= h($createdDate) ?>" required>
                    </div>
                    <div>
                        <label for="reference">Reference</label>
                        <input id="reference" name="reference" maxlength="60" value="<?= h($createdReference) ?>">
                    </div>
                    <div>
                        <label for="deposit">Deposit</label>
                        <input id="deposit" type="number" name="deposit" min="0" max="9999999999999.99" step="0.01" value="<?= h($createdDeposit) ?>" inputmode="decimal">
                    </div>
                    <div>
                        <label for="withdrawal">Withdrawal</label>
                        <input id="withdrawal" type="number" name="withdrawal" min="0" max="9999999999999.99" step="0.01" value="<?= h($createdWithdrawal) ?>" inputmode="decimal">
                    </div>
                    <div class="form-field-wide">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" maxlength="255"><?= h($createdDescription) ?></textarea>
                    </div>
                </div>
                <p class="hint">This records an unreconciled statement item; it does not create a voucher or journal entry. Enter either a deposit or a withdrawal.</p>
                <div class="form-actions"><button class="button button-primary" type="submit">Save transaction</button></div>
            </form>
        </div>
    </details>

    <section class="card" aria-labelledby="transaction-list-heading">
        <div class="card-head"><h2 id="transaction-list-heading">Transaction history</h2></div>
        <div class="card-body">
            <form class="filters" method="get" action="transactions.php">
                <div>
                    <label for="filter-account">Bank account</label>
                    <select id="filter-account" name="account_id">
                        <option value="">All accounts</option>
                        <?php foreach ($allBankAccounts as $account): ?>
                            <?php $accountLabel = $account['bank_name'] . ' - ' . $account['account_name']; ?>
                            <option value="<?= (int) $account['id'] ?>" <?= $filterAccountId === (string) $account['id'] ? 'selected' : '' ?>>
                                <?= h($accountLabel) ?><?= (int) $account['is_active'] !== 1 ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= h($fromDate) ?>"></div>
                <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= h($toDate) ?>"></div>
                <div>
                    <label for="status">Reconciliation</label>
                    <select id="status" name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="unreconciled" <?= $status === 'unreconciled' ? 'selected' : '' ?>>Unreconciled</option>
                        <option value="reconciled" <?= $status === 'reconciled' ? 'selected' : '' ?>>Reconciled</option>
                    </select>
                </div>
                <div><label for="q">Search</label><input id="q" name="q" value="<?= h($search) ?>" maxlength="100" placeholder="Reference or description"></div>
                <button class="button button-primary" type="submit">Filter</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Bank account</th>
                        <th scope="col">Reference / description</th>
                        <th scope="col">Linked record</th>
                        <th scope="col" class="numeric">Deposit</th>
                        <th scope="col" class="numeric">Withdrawal</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?= h($transaction['txn_date']) ?></td>
                                <td>
                                    <strong><?= h($transaction['bank_name']) ?></strong>
                                    <span class="secondary"><?= h($transaction['account_name']) ?> · <?= h(mask_account_number($transaction['account_number'])) ?></span>
                                    <span class="secondary"><?= h($transaction['account_code'] . ' - ' . $transaction['ledger_account_name']) ?></span>
                                </td>
                                <td>
                                    <?= $transaction['reference'] !== null && $transaction['reference'] !== '' ? h($transaction['reference']) : '—' ?>
                                    <?php if ($transaction['description'] !== null && $transaction['description'] !== ''): ?>
                                        <span class="secondary"><?= h($transaction['description']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($transaction['voucher_no'] || $transaction['entry_no']): ?>
                                        <?= $transaction['voucher_no'] ? 'Voucher ' . h($transaction['voucher_no']) : '' ?>
                                        <?= $transaction['entry_no'] ? 'Journal ' . h($transaction['entry_no']) : '' ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="numeric"><?= (float) $transaction['deposit'] > 0 ? format_amount($currencySymbol, $transaction['deposit']) : '—' ?></td>
                                <td class="numeric"><?= (float) $transaction['withdrawal'] > 0 ? format_amount($currencySymbol, $transaction['withdrawal']) : '—' ?></td>
                                <td>
                                    <?php if ((int) $transaction['is_reconciled'] === 1): ?>
                                        <span class="badge badge-reconciled">Reconciled</span>
                                        <?php if ($transaction['reconciled_at']): ?><span class="secondary"><?= h($transaction['reconciled_at']) ?></span><?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-open">Unreconciled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td class="empty" colspan="7">No transactions match these filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span>Showing <?= $totalCount === 0 ? 0 : $offset + 1 ?>–<?= min($offset + $pageSize, $totalCount) ?> of <?= number_format($totalCount) ?> transactions</span>
            <div class="pagination-links">
                <a class="button" href="<?= h($previousUrl) ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
                <span class="button" aria-current="page"><?= $page ?> / <?= $pageCount ?></span>
                <a class="button" href="<?= h($nextUrl) ?>" aria-disabled="<?= $page >= $pageCount ? 'true' : 'false' ?>">Next</a>
            </div>
        </div>
    </section>
</main>
</div>
</div>
<script src="<?= e(app_url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
