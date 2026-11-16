<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit.php';

final class BankService
{
    public static function accounts(PDO $connection, bool $includeInactive = true): array
    {
        $where = $includeInactive ? '' : ' WHERE ba.is_active = 1';
        $statement = $connection->query(
            'SELECT ba.*, a.code AS account_code, a.name AS ledger_account_name
             FROM bank_accounts ba
             JOIN accounts a ON a.id = ba.account_id'
            . $where . '
             ORDER BY ba.bank_name, ba.account_name'
        );

        return $statement->fetchAll();
    }

    public static function createAccount(PDO $connection, array $data): int
    {
        $bankName = self::requiredText($data['bank_name'] ?? null, 'Bank name', 100);
        $accountName = self::requiredText($data['account_name'] ?? null, 'Account name', 120);
        $accountNumber = self::requiredText($data['account_number'] ?? null, 'Account number', 50);
        $rawAccountId = $data['account_id'] ?? null;
        if (!is_string($rawAccountId) && !is_int($rawAccountId)) {
            throw new InvalidArgumentException('Choose a valid chart-of-accounts entry.');
        }
        $accountId = filter_var($rawAccountId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($accountId === false) {
            throw new InvalidArgumentException('Choose a valid chart-of-accounts entry.');
        }

        $openingBalance = self::signedAmount($data['opening_balance'] ?? '0');
        $connection->beginTransaction();
        try {
            $accountStatement = $connection->prepare(
                'SELECT id FROM accounts WHERE id = :id AND is_active = 1 AND is_bank = 1 FOR UPDATE'
            );
            $accountStatement->execute(['id' => (int) $accountId]);
            if (!$accountStatement->fetchColumn()) {
                throw new InvalidArgumentException('Choose an active bank account from the chart of accounts.');
            }

            $existing = $connection->prepare('SELECT id FROM bank_accounts WHERE account_id = :account_id LIMIT 1');
            $existing->execute(['account_id' => (int) $accountId]);
            if ($existing->fetchColumn()) {
                throw new DomainException('That ledger account is already linked to a bank account.');
            }

            $statement = $connection->prepare(
                'INSERT INTO bank_accounts
                    (account_id, bank_name, account_name, account_number, opening_balance)
                 VALUES
                    (:account_id, :bank_name, :account_name, :account_number, :opening_balance)'
            );
            $statement->execute([
                'account_id' => (int) $accountId,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'opening_balance' => $openingBalance,
            ]);
            $bankAccountId = (int) $connection->lastInsertId();
            log_audit('create', 'bank_account', $bankAccountId, null, [
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_id' => (int) $accountId,
            ]);
            $connection->commit();

            return $bankAccountId;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function setAccountActive(PDO $connection, int $bankAccountId, bool $isActive): void
    {
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT is_active FROM bank_accounts WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $bankAccountId]);
            $account = $statement->fetch();
            if (!$account) {
                throw new DomainException('Bank account not found.');
            }

            if (!$isActive) {
                $pending = $connection->prepare(
                    'SELECT COUNT(*) FROM bank_transactions
                     WHERE bank_account_id = :id AND is_reconciled = 0'
                );
                $pending->execute(['id' => $bankAccountId]);
                if ((int) $pending->fetchColumn() > 0) {
                    throw new DomainException('Reconcile outstanding transactions before deactivating this account.');
                }
            }

            $update = $connection->prepare(
                'UPDATE bank_accounts SET is_active = :is_active WHERE id = :id'
            );
            $update->execute([
                'is_active' => $isActive ? 1 : 0,
                'id' => $bankAccountId,
            ]);
            log_audit($isActive ? 'activate' : 'deactivate', 'bank_account', $bankAccountId, null, [
                'is_active' => $isActive,
            ]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function recordStatementTransaction(PDO $connection, array $data): int
    {
        $rawBankAccountId = $data['bank_account_id'] ?? null;
        if (!is_string($rawBankAccountId) && !is_int($rawBankAccountId)) {
            throw new InvalidArgumentException('Choose an active bank account.');
        }
        $bankAccountId = filter_var($rawBankAccountId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($bankAccountId === false) {
            throw new InvalidArgumentException('Choose an active bank account.');
        }

        $date = $data['txn_date'] ?? '';
        if (!is_string($date) || !is_iso_date($date)) {
            throw new InvalidArgumentException('Enter a valid transaction date.');
        }

        $reference = $data['reference'] ?? '';
        $description = $data['description'] ?? '';
        if (!is_string($reference) || app_text_length($reference) < 0 || app_text_length($reference) > 60) {
            throw new InvalidArgumentException('Reference must be 60 characters or fewer.');
        }
        if (!is_string($description) || app_text_length($description) < 0 || app_text_length($description) > 255) {
            throw new InvalidArgumentException('Description must be 255 characters or fewer.');
        }

        $deposit = amount_to_cents($data['deposit'] ?? '0');
        $withdrawal = amount_to_cents($data['withdrawal'] ?? '0');
        if (($deposit === 0 && $withdrawal === 0) || ($deposit > 0 && $withdrawal > 0)) {
            throw new InvalidArgumentException('Enter a positive deposit or a positive withdrawal, but not both.');
        }

        $accountStatement = $connection->prepare(
            'SELECT id FROM bank_accounts WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $accountStatement->execute(['id' => (int) $bankAccountId]);
        if (!$accountStatement->fetchColumn()) {
            throw new DomainException('The selected bank account is inactive or unavailable.');
        }

        $statement = $connection->prepare(
            'INSERT INTO bank_transactions
                (bank_account_id, txn_date, reference, description, deposit, withdrawal)
             VALUES
                (:bank_account_id, :txn_date, :reference, :description, :deposit, :withdrawal)'
        );
        $statement->execute([
            'bank_account_id' => (int) $bankAccountId,
            'txn_date' => $date,
            'reference' => trim($reference) === '' ? null : trim($reference),
            'description' => trim($description) === '' ? null : trim($description),
            'deposit' => cents_to_decimal($deposit),
            'withdrawal' => cents_to_decimal($withdrawal),
        ]);

        return (int) $connection->lastInsertId();
    }

    public static function reconcile(
        PDO $connection,
        int $bankAccountId,
        string $statementDate,
        string $statementBalance,
        array $transactionIds,
        int $userId
    ): array {
        if (!is_iso_date($statementDate)) {
            throw new InvalidArgumentException('Enter a valid statement date.');
        }
        $statementBalanceCents = self::signedAmountToCents($statementBalance);
        $validatedIds = [];
        foreach ($transactionIds as $rawTransactionId) {
            if (!is_string($rawTransactionId) && !is_int($rawTransactionId)) {
                throw new InvalidArgumentException('Selected transactions are invalid.');
            }
            $transactionId = filter_var($rawTransactionId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($transactionId === false) {
                throw new InvalidArgumentException('Selected transactions are invalid.');
            }
            $validatedIds[] = (int) $transactionId;
        }
        $transactionIds = array_values(array_unique($validatedIds));

        $connection->beginTransaction();
        try {
            $accountStatement = $connection->prepare(
                'SELECT ba.opening_balance, ba.account_id
                 FROM bank_accounts ba
                 WHERE ba.id = :id AND ba.is_active = 1
                 FOR UPDATE'
            );
            $accountStatement->execute(['id' => $bankAccountId]);
            $account = $accountStatement->fetch();
            if (!$account) {
                throw new DomainException('Bank account is inactive or unavailable.');
            }

            if ($transactionIds) {
                $placeholders = [];
                $parameters = [
                    'bank_account_id' => $bankAccountId,
                    'statement_date' => $statementDate,
                ];
                foreach ($transactionIds as $index => $transactionId) {
                    $key = 'transaction_' . $index;
                    $placeholders[] = ':' . $key;
                    $parameters[$key] = $transactionId;
                }
                $selection = $connection->prepare(
                    'SELECT id FROM bank_transactions
                     WHERE bank_account_id = :bank_account_id
                       AND txn_date <= :statement_date AND is_reconciled = 0
                       AND id IN (' . implode(', ', $placeholders) . ')
                     FOR UPDATE'
                );
                $selection->execute($parameters);
                $matchedIds = array_map('intval', array_column($selection->fetchAll(), 'id'));
                sort($matchedIds);
                $expectedIds = $transactionIds;
                sort($expectedIds);
                if ($matchedIds !== $expectedIds) {
                    throw new DomainException('One or more selected transactions are already reconciled or do not belong to this statement.');
                }

                $updatePlaceholders = [];
                $updateParameters = [];
                foreach ($transactionIds as $index => $transactionId) {
                    $key = 'selected_' . $index;
                    $updatePlaceholders[] = ':' . $key;
                    $updateParameters[$key] = $transactionId;
                }
                $mark = $connection->prepare(
                    'UPDATE bank_transactions
                     SET is_reconciled = 1, reconciled_at = NOW()
                     WHERE id IN (' . implode(', ', $updatePlaceholders) . ')'
                );
                $mark->execute($updateParameters);
            }

            $balanceStatement = $connection->prepare(
                'SELECT COALESCE(SUM(jl.debit), 0) AS debit_total,
                        COALESCE(SUM(jl.credit), 0) AS credit_total
                 FROM journal_lines jl
                 JOIN journal_entries je ON je.id = jl.journal_id
                 WHERE jl.account_id = :account_id AND je.entry_date <= :statement_date'
            );
            $balanceStatement->execute([
                'account_id' => (int) $account['account_id'],
                'statement_date' => $statementDate,
            ]);
            $bookTotals = $balanceStatement->fetch();
            $bookBalanceCents = self::signedAmountToCents((string) $account['opening_balance'])
                + amount_to_cents((string) $bookTotals['debit_total'])
                - amount_to_cents((string) $bookTotals['credit_total']);

            $reconciliation = $connection->prepare(
                'INSERT INTO bank_reconciliations
                    (bank_account_id, statement_date, statement_balance, book_balance, reconciled_by)
                 VALUES
                    (:bank_account_id, :statement_date, :statement_balance, :book_balance, :reconciled_by)'
            );
            $reconciliation->execute([
                'bank_account_id' => $bankAccountId,
                'statement_date' => $statementDate,
                'statement_balance' => cents_to_decimal($statementBalanceCents),
                'book_balance' => signed_cents_to_decimal($bookBalanceCents),
                'reconciled_by' => $userId,
            ]);
            $reconciliationId = (int) $connection->lastInsertId();
            log_audit('reconcile', 'bank_account', $bankAccountId, null, [
                'reconciliation_id' => $reconciliationId,
                'statement_date' => $statementDate,
                'matched_count' => count($transactionIds),
                'variance' => signed_cents_to_decimal($statementBalanceCents - $bookBalanceCents),
            ]);
            $connection->commit();

            return [
                'id' => $reconciliationId,
                'statement_balance' => $statementBalanceCents,
                'book_balance' => $bookBalanceCents,
                'variance' => $statementBalanceCents - $bookBalanceCents,
                'matched_count' => count($transactionIds),
            ];
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private static function requiredText($value, string $label, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        $value = trim($value);
        $length = app_text_length($value);
        if ($value === '' || $length < 0 || $length > $maxLength) {
            throw new InvalidArgumentException($label . ' is required and must be ' . $maxLength . ' characters or fewer.');
        }

        return $value;
    }

    private static function signedAmount($value): string
    {
        return signed_cents_to_decimal(self::signedAmountToCents($value));
    }

    private static function signedAmountToCents($value): int
    {
        return signed_amount_to_cents($value);
    }
}
