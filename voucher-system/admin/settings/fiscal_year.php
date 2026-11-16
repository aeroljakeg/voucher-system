<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('settings.manage');

$connection = db();
$errors = [];
$success = '';
$formData = ['name' => '', 'start_date' => '', 'end_date' => '', 'is_current' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif (!is_string($action)) {
        $errors[] = 'The requested action is invalid.';
    } else {
        try {
            if ($action === 'create') {
                foreach (array_keys($formData) as $field) {
                    if (isset($_POST[$field]) && is_string($_POST[$field])) {
                        $formData[$field] = trim($_POST[$field]);
                    }
                }
                if ($formData['name'] === '' || app_text_length($formData['name']) > 30) {
                    throw new InvalidArgumentException('Fiscal year name is required and must be 30 characters or fewer.');
                }
                if (!is_iso_date($formData['start_date']) || !is_iso_date($formData['end_date'])
                    || $formData['start_date'] > $formData['end_date']) {
                    throw new InvalidArgumentException('Enter a valid fiscal year date range.');
                }
                if (!in_array($formData['is_current'], ['0', '1'], true)) {
                    throw new InvalidArgumentException('Current fiscal year option is invalid.');
                }

                $connection->beginTransaction();
                $previousRows = $connection->query(
                    'SELECT doc_type, prefix, padding
                     FROM doc_numbering
                     WHERE fiscal_year_id = (SELECT id FROM fiscal_years WHERE is_current = 1 LIMIT 1)'
                )->fetchAll();
                $numbering = [];
                foreach ($previousRows as $row) {
                    $numbering[$row['doc_type']] = $row;
                }
                $overlap = $connection->prepare(
                    'SELECT id FROM fiscal_years
                     WHERE NOT (end_date < :new_start OR start_date > :new_end)
                     LIMIT 1 FOR UPDATE'
                );
                $overlap->execute([
                    'new_start' => $formData['start_date'],
                    'new_end' => $formData['end_date'],
                ]);
                if ($overlap->fetchColumn()) {
                    throw new DomainException('Fiscal year dates cannot overlap an existing fiscal year.');
                }
                $duplicateName = $connection->prepare('SELECT id FROM fiscal_years WHERE name = :name LIMIT 1');
                $duplicateName->execute(['name' => $formData['name']]);
                if ($duplicateName->fetchColumn()) {
                    throw new DomainException('Fiscal year name is already in use.');
                }
                if ($formData['is_current'] === '1') {
                    $connection->exec('UPDATE fiscal_years SET is_current = 0');
                }
                $insert = $connection->prepare(
                    'INSERT INTO fiscal_years (name, start_date, end_date, is_current)
                     VALUES (:name, :start_date, :end_date, :is_current)'
                );
                $insert->execute([
                    'name' => $formData['name'],
                    'start_date' => $formData['start_date'],
                    'end_date' => $formData['end_date'],
                    'is_current' => (int) $formData['is_current'],
                ]);
                $fiscalYearId = (int) $connection->lastInsertId();

                if (!$numbering) {
                    $numbering = [
                        'payment' => ['prefix' => 'PV', 'padding' => 5],
                        'receipt' => ['prefix' => 'RV', 'padding' => 5],
                        'journal' => ['prefix' => 'JV', 'padding' => 5],
                        'contra' => ['prefix' => 'CV', 'padding' => 5],
                        'entry' => ['prefix' => 'JE', 'padding' => 6],
                    ];
                }
                $types = ['payment', 'receipt', 'journal', 'contra', 'entry'];
                $numberingStatement = $connection->prepare(
                    'INSERT INTO doc_numbering (doc_type, fiscal_year_id, prefix, next_number, padding)
                     VALUES (:doc_type, :fiscal_year_id, :prefix, 1, :padding)'
                );
                foreach ($types as $type) {
                    $source = $numbering[$type] ?? ['prefix' => strtoupper(substr($type, 0, 2)), 'padding' => 5];
                    $numberingStatement->execute([
                        'doc_type' => $type,
                        'fiscal_year_id' => $fiscalYearId,
                        'prefix' => $source['prefix'],
                        'padding' => (int) $source['padding'],
                    ]);
                }
                log_audit('create', 'fiscal_year', $fiscalYearId, null, $formData);
                $connection->commit();
                header('Location: ' . app_url('admin/settings/fiscal_year.php?saved=1'));
                exit;
            }

            $rawId = $_POST['fiscal_year_id'] ?? null;
            $fiscalYearId = is_string($rawId) || is_int($rawId)
                ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($fiscalYearId === false) {
                throw new InvalidArgumentException('Choose a valid fiscal year.');
            }

            $connection->beginTransaction();
            $lock = $connection->prepare('SELECT * FROM fiscal_years WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => (int) $fiscalYearId]);
            $year = $lock->fetch();
            if (!$year) {
                throw new DomainException('Fiscal year not found.');
            }

            if ($action === 'make_current') {
                if ((int) $year['is_closed'] === 1) {
                    throw new DomainException('A closed fiscal year cannot be made current.');
                }
                $connection->exec('UPDATE fiscal_years SET is_current = 0');
                $update = $connection->prepare('UPDATE fiscal_years SET is_current = 1 WHERE id = :id');
                $update->execute(['id' => (int) $fiscalYearId]);
            } elseif ($action === 'close') {
                if ((int) $year['is_closed'] === 1) {
                    throw new DomainException('Fiscal year is already closed.');
                }
                if ($year['end_date'] >= date('Y-m-d')) {
                    throw new DomainException('A fiscal year can only be closed after its end date.');
                }
                $openVouchers = $connection->prepare(
                    'SELECT COUNT(*) FROM vouchers
                     WHERE fiscal_year_id = :id AND status IN (\'draft\', \'submitted\', \'approved\')'
                );
                $openVouchers->execute(['id' => (int) $fiscalYearId]);
                if ((int) $openVouchers->fetchColumn() > 0) {
                    throw new DomainException('Resolve draft and pending vouchers before closing this fiscal year.');
                }
                $update = $connection->prepare(
                    'UPDATE fiscal_years SET is_closed = 1, is_current = 0 WHERE id = :id'
                );
                $update->execute(['id' => (int) $fiscalYearId]);
            } else {
                throw new InvalidArgumentException('Unknown fiscal year action.');
            }

            log_audit($action, 'fiscal_year', (int) $fiscalYearId, null, ['name' => $year['name']]);
            $connection->commit();
            $success = $action === 'close' ? 'Fiscal year closed.' : 'Current fiscal year updated.';
        } catch (InvalidArgumentException | DomainException $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}

$years = $connection->query(
    'SELECT * FROM fiscal_years ORDER BY start_date DESC, id DESC'
)->fetchAll();
$pageTitle = 'Fiscal years';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?><div class="app-alert" role="status">Fiscal year created with document numbering.</div><?php endif; ?>
<?php if ($success !== ''): ?><div class="app-alert" role="status"><?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<section class="app-card">
    <div class="app-card-header"><h2>Create fiscal year</h2></div>
    <div class="app-card-body">
        <form class="app-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <div><label for="name">Name</label><input id="name" name="name" maxlength="30" required value="<?= e($formData['name']) ?>" placeholder="FY2027"></div>
            <div><label for="start_date">Start date</label><input id="start_date" type="date" name="start_date" required value="<?= e($formData['start_date']) ?>"></div>
            <div><label for="end_date">End date</label><input id="end_date" type="date" name="end_date" required value="<?= e($formData['end_date']) ?>"></div>
            <div><label><input type="checkbox" name="is_current" value="1" <?= $formData['is_current'] === '1' ? 'checked' : '' ?>> Make this the current fiscal year</label></div>
            <div class="app-actions"><button class="app-button app-button-primary" type="submit">Create fiscal year</button></div>
        </form>
    </div>
</section>
<section class="app-card">
    <div class="app-card-header"><h2>Fiscal years</h2></div>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Name</th><th>Start</th><th>End</th><th>Current</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($years): foreach ($years as $year): ?>
                    <tr>
                        <td><?= e($year['name']) ?></td><td><?= e($year['start_date']) ?></td><td><?= e($year['end_date']) ?></td>
                        <td><?= (int) $year['is_current'] === 1 ? 'Yes' : 'No' ?></td><td><?= (int) $year['is_closed'] === 1 ? 'Closed' : 'Open' ?></td>
                        <td>
                            <?php if ((int) $year['is_closed'] === 0): ?>
                                <form class="app-actions" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="fiscal_year_id" value="<?= (int) $year['id'] ?>">
                                    <?php if ((int) $year['is_current'] !== 1): ?><button class="app-button" name="action" value="make_current" type="submit">Make current</button><?php endif; ?>
                                    <button class="app-button" name="action" value="close" type="submit" data-confirm="Close this fiscal year?">Close year</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">No fiscal years configured.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
