<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/audit.php';

require_permission('settings.manage');

$connection = db();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $rawId = $_POST['numbering_id'] ?? null;
    $id = (is_string($rawId) || is_int($rawId))
        ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $prefix = $_POST['prefix'] ?? null;
    $padding = $_POST['padding'] ?? null;
    $nextNumber = $_POST['next_number'] ?? null;

    if (!csrf_token_is_valid(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($id === false || !is_string($prefix) || !is_string($padding) || !is_string($nextNumber)) {
        $errors[] = 'Numbering values are invalid.';
    } else {
        $prefix = trim($prefix);
        $paddingValue = filter_var($padding, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        $nextValue = filter_var($nextNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if (!preg_match('/^[A-Za-z0-9-]{1,10}$/D', $prefix)) {
            $errors[] = 'Prefix may contain 1 to 10 letters, numbers, or hyphens.';
        }
        if ($paddingValue === false || $nextValue === false) {
            $errors[] = 'Padding must be 1–10 and next number must be positive.';
        }

        if (!$errors) {
            $connection->beginTransaction();
            try {
                $currentStatement = $connection->prepare(
                    'SELECT dn.*, fy.is_closed
                     FROM doc_numbering dn
                     JOIN fiscal_years fy ON fy.id = dn.fiscal_year_id
                     WHERE dn.id = :id
                     FOR UPDATE'
                );
                $currentStatement->execute(['id' => (int) $id]);
                $current = $currentStatement->fetch();
                if (!$current) {
                    throw new DomainException('Document numbering entry not found.');
                }
                if ((int) $current['is_closed'] === 1) {
                    throw new DomainException('Document numbering for a closed fiscal year cannot be changed.');
                }

                $update = $connection->prepare(
                    'UPDATE doc_numbering
                     SET prefix = :prefix, padding = :padding, next_number = :next_number
                     WHERE id = :id'
                );
                $update->execute([
                    'prefix' => $prefix,
                    'padding' => (int) $paddingValue,
                    'next_number' => (int) $nextValue,
                    'id' => (int) $id,
                ]);
                log_audit('update', 'doc_numbering', (int) $id, [
                    'prefix' => $current['prefix'],
                    'padding' => (int) $current['padding'],
                    'next_number' => (int) $current['next_number'],
                ], [
                    'prefix' => $prefix,
                    'padding' => (int) $paddingValue,
                    'next_number' => (int) $nextValue,
                ]);
                $connection->commit();
                $success = true;
            } catch (DomainException $error) {
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
}

$numbering = $connection->query(
    'SELECT dn.id, dn.doc_type, dn.prefix, dn.padding, dn.next_number,
            fy.name AS fiscal_year_name, fy.is_current, fy.is_closed
     FROM doc_numbering dn
     JOIN fiscal_years fy ON fy.id = dn.fiscal_year_id
     ORDER BY fy.start_date DESC, dn.doc_type'
)->fetchAll();

$pageTitle = 'Document numbering';
require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
require __DIR__ . '/../../includes/navbar.php';
?>
<?php if ($success): ?><div class="app-alert" role="status">Document numbering updated.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="app-alert" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<p class="app-muted">Document numbers are locked and allocated transactionally. Existing numbers are skipped to avoid duplicates.</p>
<section class="app-card">
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Fiscal year</th><th>Document type</th><th>Prefix</th><th>Padding</th><th>Next number</th><th>Save</th></tr></thead>
            <tbody>
                <?php if ($numbering): foreach ($numbering as $row): ?>
                    <tr>
                        <?php $formId = 'numbering-form-' . (int) $row['id']; ?>
                        <td>
                            <form id="<?= e($formId) ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="numbering_id" value="<?= (int) $row['id'] ?>">
                            </form>
                            <?= e($row['fiscal_year_name']) ?><?= (int) $row['is_current'] === 1 ? ' · Current' : '' ?><?= (int) $row['is_closed'] === 1 ? ' · Closed' : '' ?>
                        </td>
                        <td><?= e(ucfirst($row['doc_type'])) ?></td>
                        <td><input form="<?= e($formId) ?>" aria-label="Prefix" name="prefix" maxlength="10" value="<?= e($row['prefix']) ?>" required></td>
                        <td><input form="<?= e($formId) ?>" aria-label="Padding" name="padding" type="number" min="1" max="10" value="<?= (int) $row['padding'] ?>" required></td>
                        <td><input form="<?= e($formId) ?>" aria-label="Next number" name="next_number" type="number" min="1" max="4294967295" value="<?= (int) $row['next_number'] ?>" required></td>
                        <td><button class="app-button" form="<?= e($formId) ?>" type="submit" <?= (int) $row['is_closed'] === 1 ? 'disabled' : '' ?>>Save</button></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td class="app-empty" colspan="6">Create a fiscal year before configuring numbering.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
