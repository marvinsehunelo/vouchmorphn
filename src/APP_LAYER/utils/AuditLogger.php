<?php
namespace APP_LAYER\utils;

use PDO;
use Throwable;

/**
 * AuditLogger is responsible for writing immutable audit logs
 * to the `audit_logs` table.
 */
class AuditLogger
{
    /**
     * Write an audit log entry.
     *
     * @param PDO $pdo Database connection
     * @param string $entity Module/table name
     * @param mixed $entityId Entity ID (optional)
     * @param string $action Action name
     * @param string $category Category (SYSTEM, FINANCIAL, etc.)
     * @param string $severity Severity (INFO, WARNING, ERROR)
     * @param string|null $oldValue Previous state (JSON)
     * @param string|null $newValue New state (JSON)
     * @param string $performedBy User/actor
     * @param int $immutable 0/1
     */
    public static function write(
        PDO $pdo,
        string $entity,
        $entityId,
        string $action,
        string $category = 'GENERAL',
        string $severity = 'INFO',
        ?string $oldValue = null,
        ?string $newValue = null,
        string $performedBy = 'SYSTEM',
        int $immutable = 1
    ): void {
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent';
            $geoLocation = 'Unknown';

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (
                    entity, entity_id, action, category, severity, old_value, new_value,
                    performed_by, ip_address, user_agent, geo_location, performed_at, immutable
                )
                VALUES (
                    :entity, :entity_id, :action, :category, :severity, :old_value, :new_value,
                    :performed_by, :ip_address, :user_agent, :geo_location, NOW(), :immutable
                )
            ");

            $stmt->execute([
                ':entity'       => $entity,
                ':entity_id'    => (string)$entityId,
                ':action'       => $action,
                ':category'     => strtoupper($category),
                ':severity'     => strtoupper($severity),
                ':old_value'    => $oldValue,
                ':new_value'    => $newValue,
                ':performed_by' => $performedBy,
                ':ip_address'   => $ipAddress,
                ':user_agent'   => $userAgent,
                ':geo_location' => $geoLocation,
                ':immutable'    => $immutable,
            ]);

        } catch (Throwable $e) {
            error_log("CRITICAL AuditLogger failed: " . $e->getMessage());
        }
    }
}
