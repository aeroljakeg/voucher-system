<?php

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $database = getenv('DB_NAME') ?: 'voucher_system_app';
    $portValue = getenv('DB_PORT');
    $port = $portValue === false || $portValue === ''
        ? 3306
        : filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        throw new RuntimeException('DB_PORT must be a valid TCP port number.');
    }

    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');

    if ($username === false || $username === '') {
        if ($host === '127.0.0.1' || $host === 'localhost') {
            $username = 'root';
        } else {
            throw new RuntimeException('The DB_USER environment variable is required for remote database hosts.');
        }
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $sslCaPath = getenv('DB_SSL_CA');
    if ($sslCaPath !== false && $sslCaPath !== '') {
        if (!is_file($sslCaPath) || !is_readable($sslCaPath)) {
            throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate file.');
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCaPath;
    }

    $connection = new PDO($dsn, $username, $password === false ? '' : $password, $options);

    return $connection;
}
