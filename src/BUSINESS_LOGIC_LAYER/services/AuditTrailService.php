<?php

namespace BUSINESS_LOGIC_LAYER\Services;

require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\Config\DBConnection;
use PDO;
use Throwable;

/**
 * Service class for country-specific audit logging.
 * When a Nigerian admin uses this, it logs to Nigeria.
 * When a Botswana admin uses this, it logs to Botswana.
 */
class AuditTrailService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        try {
            // Checks the injected config (e.g., config_NG.php or config_BW.php)
            if (empty($this->config['db']['swap'])) {
                throw new \Exception("Audit service: No database mapping for the current country context.");
            }

            // Points to the specific country ledger (PostgreSQL)
            $this->db = DBConnection::getInstance($this->config['db']['swap']);
        } catch (Throwable $e) {
            error_log("AuditTrailService Connection Error: " . $e->getMessage());
            throw new \RuntimeException("Could not initialize Audit Service for the current country.");
        }
    }

    /**
     * Records an action for the local country admin.
     */
    public function recordLog(
        string $entity, 
        ?int $entityId, 
        string $action, 
        string $category, 
        string $severity = 'INFO',
        ?string $oldValue = null, 
        ?string $newValue = null, 
        ?int $performedBy = null,
        ?string $ipAddress = null, 
        ?string $userAgent = null,
        ?string $geoLocation = null,
        bool $immutable = true
    ): bool {
        $sql = "INSERT INTO audit_logs (
                    entity, entity_id, action, category, severity, old_value, new_value, 
                    performed_by, ip_address, user_agent, geo_location, performed_at, immutable
                ) VALUES (
                    :entity, :entity_id, :action, :category, :severity, :old_value, :new_value, 
                    :performed_by, :ip_address, :user_agent, :geo_location, NOW(), :immutable
                )";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':entity'       => $entity,
                ':entity_id'    => $entityId,
                ':action'       => $action,
                ':category'     => $category,
                ':severity'     => $severity,
                ':old_value'    => $oldValue,
                ':new_value'    => $newValue,
                ':performed_by' => $performedBy,
                ':ip_address'   => $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent'   => $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':geo_location' => $geoLocation,
                ':immutable'    => $immutable ? 1 : 0
            ]);
        } catch (Throwable $e) {
            error_log("Audit Log Failure: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns logs ONLY for the admin's currently loaded country.
     */
    public function getAuditLogs(int $limit = 100): array
    {
        // PostgreSQL query using || for concatenation
        $sql = "
            SELECT 
                al.audit_id AS id, 
                COALESCE(a.username, 'Admin ID: ' || al.performed_by) AS username, 
                al.action, 
                al.category,
                al.severity,
                al.performed_at AS timestamp, 
                al.ip_address,
                al.old_value,
                al.new_value
            FROM 
                audit_logs al
            LEFT JOIN 
                admins a ON al.performed_by = a.admin_id
            ORDER BY 
                al.performed_at DESC
            LIMIT :limit
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Audit View Error: " . $e->getMessage());
            return [];
        }
    }
}
