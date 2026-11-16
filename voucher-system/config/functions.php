<?php

require_once __DIR__ . '/database.php';

function app_base_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!is_string($scriptName) || $scriptName === '') {
        return '';
    }

    $scriptPath = parse_url(str_replace('\\', '/', $scriptName), PHP_URL_PATH);
    if (!is_string($scriptPath)) {
        return '';
    }

    $lastSlash = strrpos($scriptPath, '/');
    $directory = $lastSlash === false ? '' : substr($scriptPath, 0, $lastSlash);
    $segments = explode('/', trim($directory, '/'));
    $baseSegments = [];
    foreach ($segments as $segment) {
        if (in_array($segment, ['admin', 'auth', 'shop'], true)) {
            break;
        }
        if ($segment !== '') {
            $baseSegments[] = $segment;
        }
    }

    return $baseSegments ? '/' . implode('/', $baseSegments) : '';
}

function app_url(string $path = ''): string
{
    $basePath = app_base_path();
    $path = ltrim($path, '/');

    return $basePath . '/' . $path;
}

function app_absolute_url(string $path = ''): string
{
    $baseUrl = getenv('APP_BASE_URL');
    if ($baseUrl === false || trim($baseUrl) === '') {
        $baseUrl = 'http://localhost' . app_base_path();
    }

    $baseUrl = rtrim(trim($baseUrl), '/');
    $parts = parse_url($baseUrl);
    if (!is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('APP_BASE_URL must be an absolute HTTP or HTTPS base URL without query parameters.');
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_setting(string $key, $default = null)
{
    static $settings = [];

    if (!array_key_exists($key, $settings)) {
        $statement = db()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1'
        );
        $statement->execute(['setting_key' => $key]);
        $value = $statement->fetchColumn();
        $settings[$key] = $value === false ? null : $value;
    }

    return $settings[$key] === null ? $default : (string) $settings[$key];
}

function app_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $length = preg_match_all('/./us', $value);

    return $length === false ? -1 : $length;
}

function format_currency($amount, ?string $symbol = null): string
{
    $symbol = $symbol ?? app_setting('currency_symbol', '$') ?? '$';

    return e($symbol) . number_format((float) $amount, 2);
}

function format_date(string $value): string
{
    $date = new DateTimeImmutable($value);
    $format = app_setting('date_format', 'Y-m-d') ?? 'Y-m-d';

    return $date->format($format);
}

function is_iso_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function app_positive_int_id($value)
{
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

function amount_to_cents($value): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Amount must be a non-negative number with at most two decimal places.');
    }

    $value = trim((string) $value);
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Amount must be a non-negative number with at most two decimal places.');
    }

    $parts = explode('.', $value, 2);
    $whole = ltrim($parts[0], '0');
    $cents = ((int) ($whole === '' ? '0' : $whole) * 100)
        + (int) str_pad($parts[1] ?? '', 2, '0');

    return $cents;
}

function cents_to_decimal(int $cents): string
{
    if ($cents < 0) {
        throw new InvalidArgumentException('Amount in cents cannot be negative.');
    }

    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function signed_cents_to_decimal(int $cents): string
{
    return ($cents < 0 ? '-' : '') . cents_to_decimal(abs($cents));
}

function signed_amount_to_cents($value): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Balance must be a valid amount.');
    }

    $value = trim((string) $value);
    $negative = strpos($value, '-') === 0;
    $absoluteValue = $negative ? substr($value, 1) : $value;
    $cents = amount_to_cents($absoluteValue);

    return $negative ? -$cents : $cents;
}

function mask_account_number(string $accountNumber): string
{
    $length = strlen($accountNumber);
    if ($length <= 4) {
        return $accountNumber;
    }

    return str_repeat('*', $length - 4) . substr($accountNumber, -4);
}

function open_fiscal_year_id(PDO $connection, string $date): int
{
    if (!is_iso_date($date)) {
        throw new InvalidArgumentException('Enter a valid fiscal date.');
    }

    $statement = $connection->prepare(
        'SELECT id, is_closed
         FROM fiscal_years
         WHERE start_date <= :date_start AND end_date >= :date_end
         ORDER BY start_date DESC
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute([
        'date_start' => $date,
        'date_end' => $date,
    ]);
    $fiscalYear = $statement->fetch();

    if (!$fiscalYear) {
        throw new DomainException('The date is not within a configured fiscal year.');
    }
    if ((int) $fiscalYear['is_closed'] === 1) {
        throw new DomainException('The selected fiscal year is closed.');
    }

    return (int) $fiscalYear['id'];
}

function next_document_number(PDO $connection, string $documentType, int $fiscalYearId): string
{
    $documentTypes = ['payment', 'receipt', 'journal', 'contra', 'entry'];
    if (!in_array($documentType, $documentTypes, true) || $fiscalYearId < 1) {
        throw new InvalidArgumentException('Document numbering values are invalid.');
    }
    if (!$connection->inTransaction()) {
        throw new LogicException('Document numbers must be allocated inside a transaction.');
    }

    $numberingStatement = $connection->prepare(
        'SELECT dn.id, dn.prefix, dn.padding, dn.next_number, fy.is_closed
         FROM doc_numbering dn
         JOIN fiscal_years fy ON fy.id = dn.fiscal_year_id
         WHERE dn.doc_type = :doc_type AND dn.fiscal_year_id = :fiscal_year_id
         FOR UPDATE'
    );
    $numberingStatement->execute([
        'doc_type' => $documentType,
        'fiscal_year_id' => $fiscalYearId,
    ]);
    $numbering = $numberingStatement->fetch();

    if (!$numbering) {
        throw new DomainException('Document numbering is not configured for this fiscal year.');
    }
    if ((int) $numbering['is_closed'] === 1) {
        throw new DomainException('Document numbers cannot be allocated in a closed fiscal year.');
    }

    $number = (int) $numbering['next_number'];
    $table = $documentType === 'entry' ? 'journal_entries' : 'vouchers';
    $column = $documentType === 'entry' ? 'entry_no' : 'voucher_no';
    $exists = $connection->prepare('SELECT id FROM ' . $table . ' WHERE ' . $column . ' = :number LIMIT 1');
    $update = $connection->prepare('UPDATE doc_numbering SET next_number = :next_number WHERE id = :id');

    while ($number < 4294967295) {
        $candidate = (string) $numbering['prefix']
            . str_pad((string) $number, (int) $numbering['padding'], '0', STR_PAD_LEFT);
        $exists->execute(['number' => $candidate]);

        if (!$exists->fetchColumn()) {
            $update->execute([
                'next_number' => $number + 1,
                'id' => (int) $numbering['id'],
            ]);

            return $candidate;
        }

        $number++;
    }

    throw new DomainException('The document number sequence has reached its limit.');
}
