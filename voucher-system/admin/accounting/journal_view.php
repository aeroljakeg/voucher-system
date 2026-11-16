<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';

require_permission('journal.view');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_amount(string $currencySymbol, $amount): string
{
    return h($currencySymbol) . number_format((float) $amount, 2);
}

$rawId = $_GET['id'] ?? '';
$journalId = app_positive_int_id($rawId);
$journal = false;
$lines = [];
$reversals = [];
$currencySymbol = '$';

if ($journalId !== false) {
    $connection = db();
    $statement = $connection->prepare(
        'SELECT je.*, fy.name AS fiscal_year_name, u.full_name AS posted_by_name,
                v.voucher_no, original.entry_no AS original_entry_no
         FROM journal_entries je
         JOIN fiscal_years fy ON fy.id = je.fiscal_year_id
         JOIN users u ON u.id = je.posted_by
         LEFT JOIN vouchers v ON v.id = je.voucher_id
         LEFT JOIN journal_entries original ON original.id = je.reversal_of
         WHERE je.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $journalId]);
    $journal = $statement->fetch();

    if ($journal) {
        $lineStatement = $connection->prepare(
            'SELECT jl.description, jl.debit, jl.credit, a.code AS account_code, a.name AS account_name
             FROM journal_lines jl
             JOIN accounts a ON a.id = jl.account_id
             WHERE jl.journal_id = :journal_id
             ORDER BY jl.id'
        );
        $lineStatement->execute(['journal_id' => $journalId]);
        $lines = $lineStatement->fetchAll();

        $reversalStatement = $connection->prepare(
            'SELECT id, entry_no, entry_date, status
             FROM journal_entries
             WHERE reversal_of = :journal_id
             ORDER BY entry_date, id'
        );
        $reversalStatement->execute(['journal_id' => $journalId]);
        $reversals = $reversalStatement->fetchAll();

        $settingStatement = $connection->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1'
        );
        $settingStatement->execute(['setting_key' => 'currency_symbol']);
        $symbol = $settingStatement->fetchColumn();
        if (is_string($symbol) && $symbol !== '') {
            $currencySymbol = $symbol;
        }
    } else {
        http_response_code(404);
    }
} else {
    http_response_code(400);
}

$errorMessage = $journalId === false
    ? 'A valid journal entry ID is required.'
    : (!$journal ? 'The requested journal entry could not be found.' : '');
$statusClass = $journal && $journal['status'] === 'reversed' ? 'status-reversed' : 'status-posted';
$isBalanced = $journal
    && abs((float) $journal['total_debit'] - (float) $journal['total_credit']) < 0.005;
?>
<!doctype html>
<html lang="en" data-theme="light" data-theme-key="journal-view-theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $journal ? h($journal['entry_no']) . ' | Journal entry' : 'Journal entry' ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
    <style>
        :root {
            color-scheme: light;
            --page: #f3f6fb;
            --surface: #fff;
            --surface-muted: #f7f9fc;
            --text: #192536;
            --muted: #64748b;
            --border: #dce4ef;
            --accent: #315ee8;
            --accent-soft: #edf2ff;
            --success: #087443;
            --success-soft: #e7f7ef;
            --warning: #965b00;
            --warning-soft: #fff3d9;
            --danger: #a52b24;
            --danger-soft: #fff1f0;
            --shadow: 0 16px 40px #12213a0d;
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #111827;
            --surface: #1c2738;
            --surface-muted: #222f43;
            --text: #e8eef8;
            --muted: #a0aec0;
            --border: #354257;
            --accent: #9ab0ff;
            --accent-soft: #29385e;
            --success: #78ddb0;
            --success-soft: #183c33;
            --warning: #ffd083;
            --warning-soft: #49391e;
            --danger: #ffaaa3;
            --danger-soft: #482b31;
            --shadow: 0 16px 40px #00000024;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--page); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px max(24px, calc((100% - 1120px) / 2)); background: var(--surface); border-bottom: 1px solid var(--border); }
        .brand { color: var(--text); font-weight: 750; letter-spacing: .01em; }
        .top-actions { display: flex; align-items: center; gap: 10px; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface); color: var(--text); font: inherit; font-weight: 650; cursor: pointer; }
        .button:hover { background: var(--surface-muted); text-decoration: none; }
        .button-primary { border-color: var(--accent); background: var(--accent); color: #fff; }
        .button-primary:hover { opacity: .9; background: var(--accent); }
        main { width: min(1120px, calc(100% - 40px)); margin: 32px auto 56px; }
        .breadcrumbs { margin-bottom: 18px; color: var(--muted); font-size: .92rem; }
        .heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
        h1 { margin: 0; font-size: clamp(1.7rem, 3vw, 2.2rem); letter-spacing: -.025em; }
        .subtitle { margin: 5px 0 0; color: var(--muted); }
        .card { margin-bottom: 18px; overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); box-shadow: var(--shadow); }
        .card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 17px 20px; border-bottom: 1px solid var(--border); }
        h2 { margin: 0; font-size: 1rem; }
        .details { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 22px; padding: 22px 20px; }
        .detail-label { display: block; margin-bottom: 5px; color: var(--muted); font-size: .8rem; font-weight: 650; text-transform: uppercase; letter-spacing: .045em; }
        .detail-value { overflow-wrap: anywhere; font-weight: 650; }
        .status { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: .82rem; font-weight: 750; text-transform: capitalize; }
        .status-posted { background: var(--success-soft); color: var(--success); }
        .status-reversed { background: var(--warning-soft); color: var(--warning); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th, td { padding: 12px 16px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
        th { background: var(--surface-muted); color: var(--muted); font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .045em; }
        td.numeric, th.numeric { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tbody tr:last-child td { border-bottom: 0; }
        .account-code { display: block; color: var(--muted); font-size: .84rem; }
        .empty-row { padding: 24px; color: var(--muted); text-align: center; }
        tfoot td { border-top: 1px solid var(--border); border-bottom: 0; background: var(--surface-muted); font-weight: 750; }
        .balance { padding: 12px 20px; border-top: 1px solid var(--border); font-size: .9rem; }
        .balance-ok { color: var(--success); }
        .balance-warning { color: var(--danger); }
        .related-list { display: grid; gap: 10px; padding: 18px 20px; }
        .related-entry { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 18px; }
        .alert { padding: 18px 20px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); box-shadow: var(--shadow); }
        .alert strong { display: block; margin-bottom: 4px; }
        @media (max-width: 720px) {
            .topbar { padding: 12px 18px; }
            main { width: min(100% - 28px, 1120px); margin-top: 22px; }
            .details { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
            .heading { flex-direction: column; }
        }
        @media (max-width: 420px) { .details { grid-template-columns: 1fr; } .topbar { align-items: flex-start; } .top-actions { gap: 6px; } }
        @media print { .topbar, .breadcrumbs, .heading .button, .logout-form { display: none !important; } body { background: #fff; color: #111; } main { width: 100%; margin: 0; } .card { box-shadow: none; break-inside: avoid; } }
    </style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="app-main">
<header class="topbar">
    <strong>Journal entry</strong>
    <div class="top-actions">
        <button class="button" type="button" data-theme-toggle aria-label="Switch to dark mode">Dark mode</button>
        <form class="logout-form" action="../../auth/logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <button class="button" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="journals.php">Accounting</a> / Journal entry
    </nav>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert" role="alert">
            <strong>Journal entry unavailable</strong>
            <?= h($errorMessage) ?>
        </div>
    <?php else: ?>
        <section class="heading">
            <div>
                <h1><?= h($journal['entry_no']) ?></h1>
                <p class="subtitle">Journal entry details and posted lines</p>
            </div>
            <button class="button" type="button" onclick="window.print()">Print</button>
        </section>

        <section class="card" aria-labelledby="entry-details-heading">
            <div class="card-head">
                <h2 id="entry-details-heading">Entry details</h2>
                <span class="status <?= h($statusClass) ?>"><?= h($journal['status']) ?></span>
            </div>
            <div class="details">
                <div><span class="detail-label">Entry date</span><span class="detail-value"><?= h($journal['entry_date']) ?></span></div>
                <div><span class="detail-label">Fiscal year</span><span class="detail-value"><?= h($journal['fiscal_year_name']) ?></span></div>
                <div><span class="detail-label">Posted by</span><span class="detail-value"><?= h($journal['posted_by_name']) ?></span></div>
                <div><span class="detail-label">Source voucher</span><span class="detail-value"><?= $journal['voucher_no'] ? h($journal['voucher_no']) : '—' ?></span></div>
                <div><span class="detail-label">Created</span><span class="detail-value"><?= h($journal['created_at']) ?></span></div>
                <div><span class="detail-label">Description</span><span class="detail-value"><?= $journal['description'] !== null && $journal['description'] !== '' ? h($journal['description']) : '—' ?></span></div>
                <?php if ($journal['original_entry_no']): ?>
                    <div><span class="detail-label">Reverses</span><span class="detail-value"><?= h($journal['original_entry_no']) ?></span></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card" aria-labelledby="lines-heading">
            <div class="card-head"><h2 id="lines-heading">Journal lines</h2></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="numeric">Debit</th>
                            <th scope="col" class="numeric">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($lines): ?>
                            <?php foreach ($lines as $line): ?>
                                <tr>
                                    <td><strong><?= h($line['account_name']) ?></strong><span class="account-code"><?= h($line['account_code']) ?></span></td>
                                    <td><?= $line['description'] !== null && $line['description'] !== '' ? h($line['description']) : '—' ?></td>
                                    <td class="numeric"><?= (float) $line['debit'] > 0 ? format_amount($currencySymbol, $line['debit']) : '—' ?></td>
                                    <td class="numeric"><?= (float) $line['credit'] > 0 ? format_amount($currencySymbol, $line['credit']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td class="empty-row" colspan="4">No journal lines were found for this entry.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Entry totals</td>
                            <td class="numeric"><?= format_amount($currencySymbol, $journal['total_debit']) ?></td>
                            <td class="numeric"><?= format_amount($currencySymbol, $journal['total_credit']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="balance <?= $isBalanced ? 'balance-ok' : 'balance-warning' ?>">
                <?= $isBalanced ? 'Debits and credits are balanced.' : 'Warning: debits and credits do not balance.' ?>
            </div>
        </section>

        <?php if ($reversals): ?>
            <section class="card" aria-labelledby="reversals-heading">
                <div class="card-head"><h2 id="reversals-heading">Reversal entries</h2></div>
                <div class="related-list">
                    <?php foreach ($reversals as $reversal): ?>
                        <div class="related-entry">
                            <a href="journal_view.php?id=<?= (int) $reversal['id'] ?>"><?= h($reversal['entry_no']) ?></a>
                            <span><?= h($reversal['entry_date']) ?> · <?= h($reversal['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
</div>
</div>
<script src="<?= e(app_url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
