<?php

$formLines = array_values($formLines);
while (count($formLines) < 2) {
    $formLines[] = ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''];
}
?>
<form class="app-card app-card-body" method="post" action="<?= e($formAction) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="app-form">
        <div>
            <label for="voucher_type">Voucher type</label>
            <select id="voucher_type" name="voucher_type" required>
                <?php foreach (['payment', 'receipt', 'journal', 'contra'] as $type): ?>
                    <option value="<?= e($type) ?>" <?= ($formData['voucher_type'] ?? '') === $type ? 'selected' : '' ?>>
                        <?= e(ucfirst($type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="voucher_date">Date</label>
            <input id="voucher_date" type="date" name="voucher_date" value="<?= e($formData['voucher_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div>
            <label for="payee">Payee</label>
            <input id="payee" name="payee" maxlength="150" value="<?= e($formData['payee'] ?? '') ?>">
        </div>
        <div>
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="4000"><?= e($formData['description'] ?? '') ?></textarea>
        </div>
    </div>

    <h2>Voucher lines</h2>
    <div class="app-table-wrap">
        <table class="app-table">
            <thead><tr><th>Account</th><th>Description</th><th class="numeric">Debit</th><th class="numeric">Credit</th><th></th></tr></thead>
            <tbody data-voucher-lines data-next-index="<?= count($formLines) ?>">
                <?php foreach ($formLines as $index => $line): ?>
                    <tr data-voucher-line>
                        <td>
                            <select name="lines[<?= $index ?>][account_id]" required>
                                <option value="">Select account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>" <?= (string) ($line['account_id'] ?? '') === (string) $account['id'] ? 'selected' : '' ?>>
                                        <?= e($account['code'] . ' - ' . $account['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input name="lines[<?= $index ?>][description]" maxlength="255" value="<?= e($line['description'] ?? '') ?>"></td>
                        <td><input class="numeric" name="lines[<?= $index ?>][debit]" type="number" min="0" max="9999999999999.99" step="0.01" value="<?= e($line['debit'] ?? '') ?>"></td>
                        <td><input class="numeric" name="lines[<?= $index ?>][credit]" type="number" min="0" max="9999999999999.99" step="0.01" value="<?= e($line['credit'] ?? '') ?>"></td>
                        <td><button class="app-button" type="button" data-remove-voucher-line aria-label="Remove line">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <template id="voucher-line-template">
        <tr data-voucher-line>
            <td>
                <select name="lines[__INDEX__][account_id]" required>
                    <option value="">Select account</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"><?= e($account['code'] . ' - ' . $account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input name="lines[__INDEX__][description]" maxlength="255"></td>
            <td><input class="numeric" name="lines[__INDEX__][debit]" type="number" min="0" max="9999999999999.99" step="0.01"></td>
            <td><input class="numeric" name="lines[__INDEX__][credit]" type="number" min="0" max="9999999999999.99" step="0.01"></td>
            <td><button class="app-button" type="button" data-remove-voucher-line aria-label="Remove line">Remove</button></td>
        </tr>
    </template>
    <div class="app-actions">
        <button class="app-button" type="button" data-add-voucher-line>Add line</button>
        <button class="app-button app-button-primary" type="submit"><?= e($submitLabel) ?></button>
    </div>
</form>
