<?php

require_once __DIR__ . '/database.php';

function log_audit(
    string $action,
    ?string $entity = null,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    if (strlen($action) > 60 || ($entity !== null && strlen($entity) > 60)) {
        throw new InvalidArgumentException('Audit action and entity names must be 60 characters or fewer.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        $ipAddress = null;
    }

    $statement = db()->prepare(
        'INSERT INTO audit_log
            (user_id, action, entity, entity_id, old_values, new_values, ip_address)
         VALUES
            (:user_id, :action, :entity, :entity_id, :old_values, :new_values, :ip_address)'
    );
    $statement->execute([
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
        'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR),
        'ip_address' => $ipAddress,
    ]);
}
