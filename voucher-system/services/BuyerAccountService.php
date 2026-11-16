<?php

require_once __DIR__ . '/../config/functions.php';

final class BuyerAccountService
{
    public static function current(PDO $connection): ?array
    {
        self::startSession();
        $accountId = $_SESSION['buyer_account_id'] ?? null;
        if (!is_int($accountId) || $accountId < 1) {
            return null;
        }

        $statement = $connection->prepare(
            'SELECT id, full_name, email FROM buyer_accounts
             WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch();
        if (!$account) {
            unset($_SESSION['buyer_account_id']);
            return null;
        }

        return $account;
    }

    public static function register(PDO $connection, string $fullName, string $email, string $password): array
    {
        $fullName = trim($fullName);
        $email = strtolower(trim($email));
        if ($fullName === '' || app_text_length($fullName) > 120) {
            throw new InvalidArgumentException('Enter your name (up to 120 characters).');
        }
        if (app_text_length($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (strlen($password) < 12 || strlen($password) > 200) {
            throw new InvalidArgumentException('Password must be at least 12 characters.');
        }

        try {
            $statement = $connection->prepare(
                'INSERT INTO buyer_accounts (full_name, email, password_hash)
                 VALUES (:full_name, :email, :password_hash)'
            );
            $statement->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }

            throw new DomainException('An account already uses that email address.', 0, $error);
        }

        return self::getById($connection, (int) $connection->lastInsertId());
    }

    public static function authenticate(PDO $connection, string $email, string $password): ?array
    {
        $statement = $connection->prepare(
            'SELECT id, full_name, email, password_hash
             FROM buyer_accounts
             WHERE email = :email AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $account = $statement->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            return null;
        }

        unset($account['password_hash']);

        return $account;
    }

    public static function signIn(array $account): void
    {
        self::startSession();
        session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['buyer_account_id'] = (int) $account['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function signOut(): void
    {
        self::startSession();
        unset($_SESSION['buyer_account_id']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function getById(PDO $connection, int $accountId): array
    {
        $statement = $connection->prepare(
            'SELECT id, full_name, email FROM buyer_accounts WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch();
        if (!$account) {
            throw new RuntimeException('The buyer account could not be loaded after it was created.');
        }

        return $account;
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
