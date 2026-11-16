<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit.php';

final class VoucherService
{
    private const TYPES = ['payment', 'receipt', 'journal', 'contra'];

    public static function list(PDO $connection, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $parameters = [];

        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'submitted', 'approved', 'rejected', 'posted', 'cancelled'], true)) {
            $where[] = 'v.status = :status';
            $parameters['status'] = $filters['status'];
        }
        if (!empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
            $where[] = 'v.voucher_type = :voucher_type';
            $parameters['voucher_type'] = $filters['type'];
        }
        if (!empty($filters['from']) && is_iso_date((string) $filters['from'])) {
            $where[] = 'v.voucher_date >= :from_date';
            $parameters['from_date'] = $filters['from'];
        }
        if (!empty($filters['to']) && is_iso_date((string) $filters['to'])) {
            $where[] = 'v.voucher_date <= :to_date';
            $parameters['to_date'] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(v.voucher_no LIKE :search_number OR v.payee LIKE :search_payee OR v.description LIKE :search_description)';
            $search = '%' . trim((string) $filters['q']) . '%';
            $parameters['search_number'] = $search;
            $parameters['search_payee'] = $search;
            $parameters['search_description'] = $search;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);
        $statement = $connection->prepare(
            'SELECT v.*, u.full_name AS created_by_name
             FROM vouchers v
             JOIN users u ON u.id = v.created_by'
            . $whereSql
            . ' ORDER BY v.voucher_date DESC, v.id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public static function get(PDO $connection, int $voucherId): ?array
    {
        $statement = $connection->prepare(
            'SELECT v.*, creator.full_name AS created_by_name,
                    approver.full_name AS approved_by_name, fy.name AS fiscal_year_name
             FROM vouchers v
             JOIN users creator ON creator.id = v.created_by
             JOIN fiscal_years fy ON fy.id = v.fiscal_year_id
             LEFT JOIN users approver ON approver.id = v.approved_by
             WHERE v.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $voucherId]);
        $voucher = $statement->fetch();
        if (!$voucher) {
            return null;
        }

        $lineStatement = $connection->prepare(
            'SELECT vl.*, a.code AS account_code, a.name AS account_name
             FROM voucher_lines vl
             JOIN accounts a ON a.id = vl.account_id
             WHERE vl.voucher_id = :voucher_id
             ORDER BY vl.id'
        );
        $lineStatement->execute(['voucher_id' => $voucherId]);
        $voucher['lines'] = $lineStatement->fetchAll();

        return $voucher;
    }

    public static function linesFromForm($lines): array
    {
        if (!is_array($lines)) {
            throw new InvalidArgumentException('Voucher lines must be submitted as a list.');
        }

        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('Each voucher line must be valid.');
            }
            foreach (['account_id', 'description', 'debit', 'credit'] as $field) {
                if (isset($line[$field]) && !is_string($line[$field]) && !is_int($line[$field])) {
                    throw new InvalidArgumentException('Voucher line values must be text or numeric values.');
                }
            }
            $accountId = $line['account_id'] ?? '';
            $description = $line['description'] ?? '';
            $debit = $line['debit'] ?? '';
            $credit = $line['credit'] ?? '';

            if (is_string($accountId) && trim($accountId) === ''
                && is_string($description) && trim($description) === ''
                && is_string($debit) && trim($debit) === ''
                && is_string($credit) && trim($credit) === '') {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    public static function linesForForm($lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $safeLine = [];
            foreach (['account_id', 'description', 'debit', 'credit'] as $field) {
                $value = $line[$field] ?? '';
                $safeLine[$field] = is_string($value) || is_int($value) ? (string) $value : '';
            }
            $result[] = $safeLine;
        }

        return $result;
    }

    public static function createDraft(PDO $connection, array $data, array $lines, int $userId): int
    {
        $header = self::validateHeader($data);
        $validatedLines = self::validateLines($connection, $lines);
        [$totalDebit, $totalCredit] = self::totals($validatedLines);
        if ($totalDebit <= 0 || $totalDebit !== $totalCredit) {
            throw new DomainException('Voucher debits and credits must be equal and greater than zero.');
        }

        $connection->beginTransaction();
        try {
            $fiscalYearId = open_fiscal_year_id($connection, $header['voucher_date']);
            $voucherNo = next_document_number($connection, $header['voucher_type'], $fiscalYearId);
            $insert = $connection->prepare(
                'INSERT INTO vouchers
                    (voucher_no, voucher_type, voucher_date, fiscal_year_id, payee, description,
                     total_amount, status, created_by)
                 VALUES
                    (:voucher_no, :voucher_type, :voucher_date, :fiscal_year_id, :payee, :description,
                     :total_amount, \'draft\', :created_by)'
            );
            $insert->execute([
                'voucher_no' => $voucherNo,
                'voucher_type' => $header['voucher_type'],
                'voucher_date' => $header['voucher_date'],
                'fiscal_year_id' => $fiscalYearId,
                'payee' => $header['payee'],
                'description' => $header['description'],
                'total_amount' => cents_to_decimal($totalDebit),
                'created_by' => $userId,
            ]);
            $voucherId = (int) $connection->lastInsertId();
            self::insertLines($connection, $voucherId, $validatedLines);
            log_audit('create', 'voucher', $voucherId, null, [
                'voucher_no' => $voucherNo,
                'voucher_type' => $header['voucher_type'],
                'voucher_date' => $header['voucher_date'],
            ]);
            $connection->commit();

            return $voucherId;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function updateDraft(PDO $connection, int $voucherId, array $data, array $lines): void
    {
        $header = self::validateHeader($data);
        $validatedLines = self::validateLines($connection, $lines);
        [$totalDebit, $totalCredit] = self::totals($validatedLines);
        if ($totalDebit <= 0 || $totalDebit !== $totalCredit) {
            throw new DomainException('Voucher debits and credits must be equal and greater than zero.');
        }

        $connection->beginTransaction();
        try {
            $currentStatement = $connection->prepare(
                'SELECT fiscal_year_id, voucher_type, status FROM vouchers WHERE id = :id FOR UPDATE'
            );
            $currentStatement->execute(['id' => $voucherId]);
            $current = $currentStatement->fetch();
            if (!$current) {
                throw new DomainException('Voucher not found.');
            }
            if ($current['status'] !== 'draft') {
                throw new DomainException('Only draft vouchers can be edited.');
            }
            if ($current['voucher_type'] !== $header['voucher_type']) {
                throw new DomainException('Voucher type cannot be changed after a document number is assigned.');
            }

            $fiscalYearId = open_fiscal_year_id($connection, $header['voucher_date']);
            if ((int) $current['fiscal_year_id'] !== $fiscalYearId) {
                throw new DomainException('A voucher cannot be moved to another fiscal year.');
            }

            $update = $connection->prepare(
                'UPDATE vouchers
                 SET voucher_type = :voucher_type, voucher_date = :voucher_date,
                     payee = :payee, description = :description, total_amount = :total_amount
                 WHERE id = :id AND status = \'draft\''
            );
            $update->execute([
                'voucher_type' => $header['voucher_type'],
                'voucher_date' => $header['voucher_date'],
                'payee' => $header['payee'],
                'description' => $header['description'],
                'total_amount' => cents_to_decimal($totalDebit),
                'id' => $voucherId,
            ]);

            $deleteLines = $connection->prepare('DELETE FROM voucher_lines WHERE voucher_id = :id');
            $deleteLines->execute(['id' => $voucherId]);
            self::insertLines($connection, $voucherId, $validatedLines);
            log_audit('update', 'voucher', $voucherId, null, [
                'voucher_type' => $header['voucher_type'],
                'voucher_date' => $header['voucher_date'],
            ]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private static function validateHeader(array $data): array
    {
        $type = $data['voucher_type'] ?? '';
        $date = $data['voucher_date'] ?? '';
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Choose a valid voucher type.');
        }
        if (!is_string($date) || !is_iso_date($date)) {
            throw new InvalidArgumentException('Enter a valid voucher date.');
        }

        $payee = $data['payee'] ?? '';
        $description = $data['description'] ?? '';
        if (!is_string($payee) || app_text_length($payee) < 0 || app_text_length($payee) > 150) {
            throw new InvalidArgumentException('Payee must be 150 characters or fewer.');
        }
        if (!is_string($description) || app_text_length($description) < 0 || app_text_length($description) > 4000) {
            throw new InvalidArgumentException('Description must be 4,000 characters or fewer.');
        }

        return [
            'voucher_type' => $type,
            'voucher_date' => $date,
            'payee' => trim($payee) === '' ? null : trim($payee),
            'description' => trim($description) === '' ? null : trim($description),
        ];
    }

    private static function validateLines(PDO $connection, array $lines): array
    {
        if (count($lines) < 2 || count($lines) > 200) {
            throw new InvalidArgumentException('A voucher must contain between 2 and 200 lines.');
        }

        $validated = [];
        $accountStatement = $connection->prepare(
            'SELECT id FROM accounts WHERE id = :id AND is_active = 1 LIMIT 1'
        );

        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('Each voucher line must be valid.');
            }

            $accountId = app_positive_int_id($line['account_id'] ?? null);
            if ($accountId === false) {
                throw new InvalidArgumentException('Choose an active account for every line.');
            }
            $accountStatement->execute(['id' => (int) $accountId]);
            if (!$accountStatement->fetchColumn()) {
                throw new InvalidArgumentException('A selected account is unavailable or inactive.');
            }

            $debit = amount_to_cents($line['debit'] ?? '0');
            $credit = amount_to_cents($line['credit'] ?? '0');
            if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
                throw new InvalidArgumentException('Each voucher line must have either a debit or a credit.');
            }
            $description = $line['description'] ?? '';
            if (!is_string($description) || app_text_length($description) < 0 || app_text_length($description) > 255) {
                throw new InvalidArgumentException('Line descriptions must be 255 characters or fewer.');
            }

            $validated[] = [
                'account_id' => (int) $accountId,
                'description' => trim($description) === '' ? null : trim($description),
                'debit' => cents_to_decimal($debit),
                'credit' => cents_to_decimal($credit),
                'debit_cents' => $debit,
                'credit_cents' => $credit,
            ];
        }

        return $validated;
    }

    private static function totals(array $lines): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $debit += $line['debit_cents'];
            $credit += $line['credit_cents'];
        }

        return [$debit, $credit];
    }

    private static function insertLines(PDO $connection, int $voucherId, array $lines): void
    {
        $statement = $connection->prepare(
            'INSERT INTO voucher_lines (voucher_id, account_id, description, debit, credit)
             VALUES (:voucher_id, :account_id, :description, :debit, :credit)'
        );

        foreach ($lines as $line) {
            $statement->execute([
                'voucher_id' => $voucherId,
                'account_id' => $line['account_id'],
                'description' => $line['description'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ]);
        }
    }
}
