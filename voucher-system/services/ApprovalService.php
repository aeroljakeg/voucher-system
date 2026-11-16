<?php

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/audit.php';

final class ApprovalService
{
    public static function queue(PDO $connection): array
    {
        $statement = $connection->query(
            'SELECT v.id, v.voucher_no, v.voucher_type, v.voucher_date, v.payee,
                    v.total_amount, v.submitted_at, creator.full_name AS created_by_name
             FROM vouchers v
             JOIN users creator ON creator.id = v.created_by
             WHERE v.status = \'submitted\'
             ORDER BY v.submitted_at, v.id'
        );

        return $statement->fetchAll();
    }

    public static function submit(PDO $connection, int $voucherId, int $userId): void
    {
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT status, created_by FROM vouchers WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $voucherId]);
            $voucher = $statement->fetch();
            if (!$voucher) {
                throw new DomainException('Voucher not found.');
            }
            if ((int) $voucher['created_by'] !== $userId) {
                throw new DomainException('Only the voucher creator can submit this voucher.');
            }
            if ($voucher['status'] !== 'draft') {
                throw new DomainException('Only draft vouchers can be submitted.');
            }

            $update = $connection->prepare(
                'UPDATE vouchers SET status = \'submitted\', submitted_at = NOW() WHERE id = :id'
            );
            $update->execute(['id' => $voucherId]);
            self::record($connection, $voucherId, 'submitted', $userId, null);
            log_audit('submit', 'voucher', $voucherId);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function decide(
        PDO $connection,
        int $voucherId,
        int $userId,
        string $decision,
        ?string $remarks = null
    ): void {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }
        $remarks = $remarks === null ? null : trim($remarks);
        if ($decision === 'rejected' && ($remarks === null || $remarks === '')) {
            throw new InvalidArgumentException('A reason is required when rejecting a voucher.');
        }
        if ($remarks !== null && (app_text_length($remarks) < 0 || app_text_length($remarks) > 255)) {
            throw new InvalidArgumentException('Approval remarks must be 255 characters or fewer.');
        }

        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT status, created_by FROM vouchers WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $voucherId]);
            $voucher = $statement->fetch();
            if (!$voucher) {
                throw new DomainException('Voucher not found.');
            }
            if ($voucher['status'] !== 'submitted') {
                throw new DomainException('Only submitted vouchers can be approved or rejected.');
            }
            if ((int) $voucher['created_by'] === $userId) {
                throw new DomainException('You cannot approve or reject a voucher you created.');
            }

            $update = $connection->prepare(
                'UPDATE vouchers
                 SET status = :status, approved_by = :approved_by, approved_at = NOW(),
                     rejection_reason = :rejection_reason
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $decision,
                'approved_by' => $userId,
                'rejection_reason' => $decision === 'rejected' ? $remarks : null,
                'id' => $voucherId,
            ]);
            self::record($connection, $voucherId, $decision, $userId, $remarks);
            log_audit($decision, 'voucher', $voucherId, null, ['remarks' => $remarks]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public static function cancel(PDO $connection, int $voucherId, int $userId, ?string $remarks = null): void
    {
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                'SELECT status, created_by FROM vouchers WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $voucherId]);
            $voucher = $statement->fetch();
            if (!$voucher) {
                throw new DomainException('Voucher not found.');
            }
            if ((int) $voucher['created_by'] !== $userId) {
                throw new DomainException('Only the voucher creator can cancel it.');
            }
            if (!in_array($voucher['status'], ['draft', 'submitted'], true)) {
                throw new DomainException('This voucher can no longer be cancelled.');
            }

            $update = $connection->prepare(
                'UPDATE vouchers SET status = \'cancelled\' WHERE id = :id'
            );
            $update->execute(['id' => $voucherId]);
            self::record($connection, $voucherId, 'cancelled', $userId, $remarks);
            log_audit('cancel', 'voucher', $voucherId, null, ['remarks' => $remarks]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private static function record(
        PDO $connection,
        int $voucherId,
        string $action,
        int $userId,
        ?string $remarks
    ): void {
        $statement = $connection->prepare(
            'INSERT INTO approvals (voucher_id, action, acted_by, remarks)
             VALUES (:voucher_id, :action, :acted_by, :remarks)'
        );
        $statement->execute([
            'voucher_id' => $voucherId,
            'action' => $action,
            'acted_by' => $userId,
            'remarks' => $remarks,
        ]);
    }
}
