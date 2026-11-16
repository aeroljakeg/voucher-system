<?php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/LedgerService.php';

require_permission('reports.view');

function csv_text($value): string
{
    $value = (string) ($value ?? '');
    if ($value !== '' && preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

$report = $_GET['report'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$allowedReports = ['profit_loss', 'trial_balance', 'journals', 'vouchers'];
if (!is_string($report) || !in_array($report, $allowedReports, true)) {
    http_response_code(400);
    exit('Choose a supported report type.');
}
if (!is_string($from) || !is_string($to) || !is_iso_date($from) || !is_iso_date($to) || $from > $to) {
    http_response_code(400);
    exit('Choose a valid date range with the start on or before the end.');
}

$connection = db();
$filename = $report . '-' . $from . '-to-' . $to . '.csv';
$headers = [];
$statement = null;
$rows = null;

if ($report === 'trial_balance') {
    $trialBalance = LedgerService::trialBalance($connection, $from, $to);
    $headers = ['Account code', 'Account name', 'Type', 'Debit balance', 'Credit balance'];
    $rows = [];
    foreach ($trialBalance['accounts'] as $account) {
        if ((int) $account['debit_balance'] === 0 && (int) $account['credit_balance'] === 0) {
            continue;
        }
        $rows[] = [
            csv_text($account['code']),
            csv_text($account['name']),
            csv_text($account['type']),
            cents_to_decimal((int) $account['debit_balance']),
            cents_to_decimal((int) $account['credit_balance']),
        ];
    }
    $rows[] = ['', '', 'Totals', cents_to_decimal($trialBalance['total_debit_balance']), cents_to_decimal($trialBalance['total_credit_balance'])];
} elseif ($report === 'profit_loss') {
    $headers = ['Account code', 'Account name', 'Type', 'Activity'];
    $statement = $connection->prepare(
        'SELECT a.code, a.name, a.type,
                COALESCE(SUM(jl.debit), 0) AS debit_total,
                COALESCE(SUM(jl.credit), 0) AS credit_total
         FROM accounts a
         JOIN journal_lines jl ON jl.account_id = a.id
         JOIN journal_entries je ON je.id = jl.journal_id
         WHERE a.type IN (\'revenue\', \'expense\')
           AND je.entry_date >= :from_date AND je.entry_date <= :to_date
         GROUP BY a.id, a.code, a.name, a.type
         ORDER BY a.type, a.code'
    );
    $statement->execute(['from_date' => $from, 'to_date' => $to]);
} elseif ($report === 'journals') {
    $headers = ['Entry number', 'Date', 'Description', 'Fiscal year', 'Status', 'Debit', 'Credit'];
    $statement = $connection->prepare(
        'SELECT je.entry_no, je.entry_date, je.description, fy.name, je.status,
                je.total_debit, je.total_credit
         FROM journal_entries je
         JOIN fiscal_years fy ON fy.id = je.fiscal_year_id
         WHERE je.entry_date >= :from_date AND je.entry_date <= :to_date
         ORDER BY je.entry_date, je.id'
    );
    $statement->execute(['from_date' => $from, 'to_date' => $to]);
} else {
    $headers = ['Voucher number', 'Date', 'Type', 'Payee', 'Status', 'Amount'];
    $statement = $connection->prepare(
        'SELECT voucher_no, voucher_date, voucher_type, payee, status, total_amount
         FROM vouchers
         WHERE voucher_date >= :from_date AND voucher_date <= :to_date
         ORDER BY voucher_date, id'
    );
    $statement->execute(['from_date' => $from, 'to_date' => $to]);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to open the CSV output stream.');
}
fputcsv($output, $headers, ',', '"', '');

if ($report === 'trial_balance') {
    foreach ($rows as $row) {
        fputcsv($output, $row, ',', '"', '');
    }
} else {
    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
        if ($report === 'profit_loss') {
            $debit = amount_to_cents((string) $row[3]);
            $credit = amount_to_cents((string) $row[4]);
            $activity = $row[2] === 'revenue' ? $credit - $debit : $debit - $credit;
            fputcsv($output, [
                csv_text($row[0]),
                csv_text($row[1]),
                csv_text($row[2]),
                signed_cents_to_decimal($activity),
            ], ',', '"', '');
        } elseif ($report === 'journals') {
            fputcsv($output, [
                csv_text($row[0]),
                csv_text($row[1]),
                csv_text($row[2]),
                csv_text($row[3]),
                csv_text($row[4]),
                $row[5],
                $row[6],
            ], ',', '"', '');
        } else {
            fputcsv($output, [
                csv_text($row[0]),
                csv_text($row[1]),
                csv_text($row[2]),
                csv_text($row[3]),
                csv_text($row[4]),
                $row[5],
            ], ',', '"', '');
        }
    }
}

fclose($output);
