<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('journal.reverse');

$connection = db();
$errors = [];
$success = '';
$reversalDate = date('Y-m-d');
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawJournalId = $_POST['journal_id'] ?? null;
    $journalId = app_positive_int_id($rawJournalId);
    $rawDate = $_POST['reversal_date'] ?? '';
    $rawReason = $_POST['reason'] ?? '';
    $reversalDate = is_string($rawDate) ? trim($rawDate) : '';
    $reason = is_string($rawReason) ? trim($rawReason) : '';
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($journalId === false || !is_string($rawDate) || !is_string($rawReason)) {
        $errors[] = 'The reversal request is invalid.';
    } else {
        try {
            $reversalId = LedgerService::reverse(
                $connection,
                (int) $journalId,
                (int) $_SESSION['user_id'],
                $reversalDate,
                $reason
            );
            header('Location: ' . app_url('admin/accounting/journal_view.php?id=' . $reversalId));
            exit;
        } catch (InvalidArgumentException | DomainException $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$eligible = $connection->query(
    'SELECT je.id, je.entry_no, je.entry_date, je.description, je.total_debit, je.total_credit,
            v.voucher_no
     FROM journal_entries je
     LEFT JOIN vouchers v ON v.id = je.voucher_id
     WHERE je.status = \'posted\' AND je.reversal_of IS NULL
       AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reversal_of = je.id)
     ORDER BY je.entry_date DESC, je.id DESC
     LIMIT 200'
)->fetchAll();

$pageTitle = 'Reverse journal entry';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<p class="app-muted">Reversal creates an equal and opposite journal entry in an open fiscal year. The original entry remains in the audit trail.</p>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card app-card-body">
    <?php if (!$eligible): ?>
        <p class="app-empty">There are no posted entries available to reverse.</p>
    <?php else: ?>
        <form class="app-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label for="journal_id">Journal entry</label>
                <select id="journal_id" name="journal_id" required>
                    <option value="">Select an entry</option>
                    <?php foreach ($eligible as $entry): ?>
                        <option value="<?= (int) $entry['id'] ?>" <?= (string) ($_POST['journal_id'] ?? '') === (string) $entry['id'] ? 'selected' : '' ?>>
                            <?= e($entry['entry_no'] . ' · ' . $entry['entry_date'] . ' · ' . format_currency($entry['total_debit'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="reversal_date">Reversal date</label><input id="reversal_date" type="date" name="reversal_date" value="<?= e($reversalDate) ?>" required></div>
            <div><label for="reason">Reason</label><input id="reason" name="reason" maxlength="255" value="<?= e($reason) ?>" required></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit" data-confirm="Create a reversing journal entry?">Create reversal</button></div>
        </form>
    <?php endif; ?>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Recent eligible entries</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Entry</th><th>Date</th><th>Source</th><th>Description</th><th class="numeric">Amount</th><th></th></tr></thead>
            <tbody>
                <?php if ($eligible): foreach ($eligible as $entry): ?>
                    <tr><td><?= e($entry['entry_no']) ?></td><td><?= e($entry['entry_date']) ?></td><td><?= e($entry['voucher_no'] ?: 'Manual journal') ?></td><td><?= e($entry['description'] ?: '—') ?></td><td class="numeric"><?= format_currency($entry['total_debit']) ?></td><td><a href="<?= e(app_url('admin/accounting/journal_view.php?id=' . (int) $entry['id'])) ?>">View</a></td></tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">No eligible entries.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
