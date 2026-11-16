<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit.php';

final class PostingService
{
    public static function post(PDO $connection, int $voucherId, int $userId): int
    {
        $connection->beginTransaction();
        try {
            $voucherStatement = $connection->prepare(
                'SELECT * FROM vouchers WHERE id = :id FOR UPDATE'
            );
            $voucherStatement->execute(['id' => $voucherId]);
            $voucher = $voucherStatement->fetch();
            if (!$voucher) {
                throw new DomainException('Voucher not found.');
            }
            if ($voucher['status'] !== 'approved' || $voucher['journal_id'] !== null) {
                throw new DomainException('Only approved, unposted vouchers can be posted.');
            }

            $fiscalYearId = open_fiscal_year_id($connection, $voucher['voucher_date']);
            if ($fiscalYearId !== (int) $voucher['fiscal_year_id']) {
                throw new DomainException('Voucher fiscal year does not match its transaction date.');
            }

            $lineStatement = $connection->prepare(
                'SELECT account_id, description, debit, credit
                 FROM voucher_lines WHERE voucher_id = :voucher_id ORDER BY id'
            );
            $lineStatement->execute(['voucher_id' => $voucherId]);
            $lines = $lineStatement->fetchAll();
            if (count($lines) < 2) {
                throw new DomainException('A voucher must contain at least two lines before posting.');
            }

            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($lines as $line) {
                $totalDebit += amount_to_cents((string) $line['debit']);
                $totalCredit += amount_to_cents((string) $line['credit']);
            }
            if ($totalDebit <= 0 || $totalDebit !== $totalCredit) {
                throw new DomainException('Voucher debits and credits must be equal and greater than zero.');
            }
            if ($totalDebit !== amount_to_cents((string) $voucher['total_amount'])) {
                throw new DomainException('Voucher total does not match its lines.');
            }

            $entryNo = next_document_number($connection, 'entry', $fiscalYearId);
            $entryStatement = $connection->prepare(
                'INSERT INTO journal_entries
                    (entry_no, entry_date, fiscal_year_id, voucher_id, description,
                     total_debit, total_credit, posted_by)
                 VALUES
                    (:entry_no, :entry_date, :fiscal_year_id, :voucher_id, :description,
                     :total_debit, :total_credit, :posted_by)'
            );
            $entryStatement->execute([
                'entry_no' => $entryNo,
                'entry_date' => $voucher['voucher_date'],
                'fiscal_year_id' => $fiscalYearId,
                'voucher_id' => $voucherId,
                'description' => $voucher['description'],
                'total_debit' => cents_to_decimal($totalDebit),
                'total_credit' => cents_to_decimal($totalCredit),
                'posted_by' => $userId,
            ]);
            $journalId = (int) $connection->lastInsertId();

            $insertLine = $connection->prepare(
                'INSERT INTO journal_lines (journal_id, account_id, description, debit, credit)
                 VALUES (:journal_id, :account_id, :description, :debit, :credit)'
            );
            foreach ($lines as $line) {
                $insertLine->execute([
                    'journal_id' => $journalId,
                    'account_id' => (int) $line['account_id'],
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            $updateVoucher = $connection->prepare(
                'UPDATE vouchers SET status = \'posted\', journal_id = :journal_id WHERE id = :id'
            );
            $updateVoucher->execute(['journal_id' => $journalId, 'id' => $voucherId]);
            log_audit('post', 'voucher', $voucherId, null, ['journal_id' => $journalId]);
            $connection->commit();

            return $journalId;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}
