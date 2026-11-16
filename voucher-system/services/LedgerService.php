<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit.php';

final class LedgerService
{
    public static function accountLedger(
        PDO $connection,
        int $accountId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $accountStatement = $connection->prepare(
            'SELECT id, code, name, type, normal_balance
             FROM accounts WHERE id = :id LIMIT 1'
        );
        $accountStatement->execute(['id' => $accountId]);
        $account = $accountStatement->fetch();
        if (!$account) {
            throw new DomainException('Account not found.');
        }

        if ($fromDate !== null && !is_iso_date($fromDate)) {
            throw new InvalidArgumentException('Enter a valid start date.');
        }
        if ($toDate !== null && !is_iso_date($toDate)) {
            throw new InvalidArgumentException('Enter a valid end date.');
        }
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        $openingCents = 0;
        if ($fromDate !== null) {
            $openingStatement = $connection->prepare(
                'SELECT COALESCE(SUM(jl.debit), 0) AS debit_total,
                        COALESCE(SUM(jl.credit), 0) AS credit_total
                 FROM journal_lines jl
                 JOIN journal_entries je ON je.id = jl.journal_id
                 WHERE jl.account_id = :account_id AND je.entry_date < :from_date'
            );
            $openingStatement->execute([
                'account_id' => $accountId,
                'from_date' => $fromDate,
            ]);
            $opening = $openingStatement->fetch();
            $openingCents = amount_to_cents((string) $opening['debit_total'])
                - amount_to_cents((string) $opening['credit_total']);
        }

        $where = ['jl.account_id = :account_id'];
        $parameters = ['account_id' => $accountId];
        if ($fromDate !== null) {
            $where[] = 'je.entry_date >= :from_date';
            $parameters['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $where[] = 'je.entry_date <= :to_date';
            $parameters['to_date'] = $toDate;
        }

        $lineStatement = $connection->prepare(
            'SELECT je.id AS journal_id, je.entry_no, je.entry_date, je.description AS entry_description,
                    jl.description AS line_description, jl.debit, jl.credit
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY je.entry_date, je.id, jl.id'
        );
        $lineStatement->execute($parameters);
        $lines = $lineStatement->fetchAll();

        $balance = $openingCents;
        foreach ($lines as &$line) {
            $balance += amount_to_cents((string) $line['debit'])
                - amount_to_cents((string) $line['credit']);
            $line['running_balance'] = $balance;
        }
        unset($line);

        return [
            'account' => $account,
            'opening_balance' => $openingCents,
            'closing_balance' => $balance,
            'lines' => $lines,
        ];
    }

    public static function trialBalance(
        PDO $connection,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        if ($fromDate !== null && !is_iso_date($fromDate)) {
            throw new InvalidArgumentException('Enter a valid start date.');
        }
        if ($toDate !== null && !is_iso_date($toDate)) {
            throw new InvalidArgumentException('Enter a valid end date.');
        }
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        $dateWhere = [];
        $parameters = [];
        if ($fromDate !== null) {
            $dateWhere[] = 'je.entry_date >= :from_date';
            $parameters['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $dateWhere[] = 'je.entry_date <= :to_date';
            $parameters['to_date'] = $toDate;
        }
        $dateSql = $dateWhere ? ' AND ' . implode(' AND ', $dateWhere) : '';

        $statement = $connection->prepare(
            'SELECT a.id, a.code, a.name, a.type, a.normal_balance,
                    COALESCE(entry_totals.total_debit, 0) AS total_debit,
                    COALESCE(entry_totals.total_credit, 0) AS total_credit
             FROM accounts a
             LEFT JOIN (
                SELECT jl.account_id, SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit
                FROM journal_lines jl
                JOIN journal_entries je ON je.id = jl.journal_id
                WHERE 1 = 1' . $dateSql . '
                GROUP BY jl.account_id
             ) entry_totals ON entry_totals.account_id = a.id
             WHERE a.is_active = 1
             ORDER BY a.code'
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        $totalDebits = 0;
        $totalCredits = 0;
        foreach ($rows as &$row) {
            $debit = amount_to_cents((string) $row['total_debit']);
            $credit = amount_to_cents((string) $row['total_credit']);
            $row['debit_balance'] = max($debit - $credit, 0);
            $row['credit_balance'] = max($credit - $debit, 0);
            $totalDebits += $row['debit_balance'];
            $totalCredits += $row['credit_balance'];
        }
        unset($row);

        return [
            'accounts' => $rows,
            'total_debit_balance' => $totalDebits,
            'total_credit_balance' => $totalCredits,
        ];
    }

    public static function reverse(PDO $connection, int $journalId, int $userId, string $reversalDate, string $reason): int
    {
        if (!is_iso_date($reversalDate)) {
            throw new InvalidArgumentException('Enter a valid reversal date.');
        }
        $reason = trim($reason);
        if ($reason === '' || app_text_length($reason) > 255) {
            throw new InvalidArgumentException('A reversal reason of 255 characters or fewer is required.');
        }

        $connection->beginTransaction();
        try {
            $entryStatement = $connection->prepare(
                'SELECT * FROM journal_entries WHERE id = :id FOR UPDATE'
            );
            $entryStatement->execute(['id' => $journalId]);
            $entry = $entryStatement->fetch();
            if (!$entry) {
                throw new DomainException('Journal entry not found.');
            }
            if ($entry['status'] !== 'posted' || $entry['reversal_of'] !== null) {
                throw new DomainException('Only an unreversed posted entry can be reversed.');
            }

            $existingStatement = $connection->prepare(
                'SELECT id FROM journal_entries WHERE reversal_of = :id LIMIT 1'
            );
            $existingStatement->execute(['id' => $journalId]);
            if ($existingStatement->fetchColumn()) {
                throw new DomainException('A reversal already exists for this entry.');
            }

            $fiscalYearId = open_fiscal_year_id($connection, $reversalDate);
            $lineStatement = $connection->prepare(
                'SELECT account_id, description, debit, credit
                 FROM journal_lines WHERE journal_id = :id ORDER BY id'
            );
            $lineStatement->execute(['id' => $journalId]);
            $lines = $lineStatement->fetchAll();
            if (!$lines) {
                throw new DomainException('The journal entry has no lines to reverse.');
            }

            $entryNo = next_document_number($connection, 'entry', $fiscalYearId);
            $description = 'Reversal of ' . $entry['entry_no'] . ': ' . $reason;
            if (app_text_length($description) > 255) {
                $description = 'Reversal of ' . $entry['entry_no'];
            }
            $insertEntry = $connection->prepare(
                'INSERT INTO journal_entries
                    (entry_no, entry_date, fiscal_year_id, description, total_debit, total_credit,
                     status, reversal_of, posted_by)
                 VALUES
                    (:entry_no, :entry_date, :fiscal_year_id, :description, :total_debit, :total_credit,
                     \'posted\', :reversal_of, :posted_by)'
            );
            $insertEntry->execute([
                'entry_no' => $entryNo,
                'entry_date' => $reversalDate,
                'fiscal_year_id' => $fiscalYearId,
                'description' => $description,
                'total_debit' => $entry['total_credit'],
                'total_credit' => $entry['total_debit'],
                'reversal_of' => $journalId,
                'posted_by' => $userId,
            ]);
            $reversalId = (int) $connection->lastInsertId();

            $insertLine = $connection->prepare(
                'INSERT INTO journal_lines (journal_id, account_id, description, debit, credit)
                 VALUES (:journal_id, :account_id, :description, :debit, :credit)'
            );
            foreach ($lines as $line) {
                $insertLine->execute([
                    'journal_id' => $reversalId,
                    'account_id' => (int) $line['account_id'],
                    'description' => $line['description'],
                    'debit' => $line['credit'],
                    'credit' => $line['debit'],
                ]);
            }

            $markReversed = $connection->prepare(
                'UPDATE journal_entries SET status = \'reversed\' WHERE id = :id'
            );
            $markReversed->execute(['id' => $journalId]);
            log_audit('reverse', 'journal_entry', $journalId, null, [
                'reversal_journal_id' => $reversalId,
                'reason' => $reason,
            ]);
            $connection->commit();

            return $reversalId;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}
