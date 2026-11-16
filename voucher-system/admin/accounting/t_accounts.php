<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('journal.view');

$connection = db();
$accounts = $connection->query(
    'SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code'
)->fetchAll();
$accountId = app_positive_int_id($_GET['account_id'] ?? null);
if ($accountId === false) {
    $accountId = $accounts ? (int) $accounts[0]['id'] : null;
}
$from = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$to = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$ledger = null;
$errors = [];
if ($accountId !== null) {
    try {
        $ledger = LedgerService::accountLedger(
            $connection,
            (int) $accountId,
            $from === '' ? null : $from,
            $to === '' ? null : $to
        );
    } catch (InvalidArgumentException | DomainException $error) {
        $errors[] = $error->getMessage();
    }
}

$debitRows = [];
$creditRows = [];
$debitTotal = 0;
$creditTotal = 0;
if ($ledger) {
    if ($from !== '' && (int) $ledger['opening_balance'] !== 0) {
        $opening = (int) $ledger['opening_balance'];
        if ($opening > 0) {
            $debitRows[] = ['label' => 'Opening balance', 'date' => $from, 'amount' => $opening];
            $debitTotal += $opening;
        } else {
            $creditRows[] = ['label' => 'Opening balance', 'date' => $from, 'amount' => abs($opening)];
            $creditTotal += abs($opening);
        }
    }
    foreach ($ledger['lines'] as $line) {
        $debit = amount_to_cents((string) $line['debit']);
        $credit = amount_to_cents((string) $line['credit']);
        if ($debit > 0) {
            $debitRows[] = ['label' => $line['entry_no'] . ' · ' . ($line['line_description'] ?: $line['entry_description'] ?: '—'), 'date' => $line['entry_date'], 'amount' => $debit];
            $debitTotal += $debit;
        }
        if ($credit > 0) {
            $creditRows[] = ['label' => $line['entry_no'] . ' · ' . ($line['line_description'] ?: $line['entry_description'] ?: '—'), 'date' => $line['entry_date'], 'amount' => $credit];
            $creditTotal += $credit;
        }
    }
}

$pageTitle = 'T-accounts';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <form class="app-form" method="get">
        <div><label for="account_id">Account</label><select id="account_id" name="account_id"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $accountId === (int) $account['id'] ? 'selected' : '' ?>><?= e($account['code'] . ' - ' . $account['name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="app-actions"><button class="app-button app-button-primary" type="submit">Show T-account</button></div>
    </form>
</section>
<?php if (!$accounts): ?>
    <div class="app-card app-empty">No accounts are available.</div>
<?php elseif ($ledger): ?>
    <section class="app-card">
        <div class="app-card-header"><h2><?= e($ledger['account']['code'] . ' - ' . $ledger['account']['name']) ?></h2></div>
        <div class="app-grid app-card-body" style="grid-template-columns:repeat(2,minmax(0,1fr))">
            <section>
                <h3>Debit</h3>
                <table class="app-table">
                    <thead><tr><th>Date / entry</th><th class="numeric">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($debitRows as $row): ?><tr><td><?= e($row['date']) ?><span class="app-muted"> · <?= e($row['label']) ?></span></td><td class="numeric"><?= format_currency(cents_to_decimal($row['amount'])) ?></td></tr><?php endforeach; ?>
                        <?php if (!$debitRows): ?><tr><td class="app-empty" colspan="2">No debit activity.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><th>Total debit</th><th class="numeric"><?= format_currency(cents_to_decimal($debitTotal)) ?></th></tr></tfoot>
                </table>
            </section>
            <section>
                <h3>Credit</h3>
                <table class="app-table">
                    <thead><tr><th>Date / entry</th><th class="numeric">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($creditRows as $row): ?><tr><td><?= e($row['date']) ?><span class="app-muted"> · <?= e($row['label']) ?></span></td><td class="numeric"><?= format_currency(cents_to_decimal($row['amount'])) ?></td></tr><?php endforeach; ?>
                        <?php if (!$creditRows): ?><tr><td class="app-empty" colspan="2">No credit activity.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><th>Total credit</th><th class="numeric"><?= format_currency(cents_to_decimal($creditTotal)) ?></th></tr></tfoot>
                </table>
            </section>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
