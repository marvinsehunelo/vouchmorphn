<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace Domain\ValueObjects;

use PDO;
use Exception;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

class SwapStatusResolver
{
    private array $participants;
    private array $dbConfig;
    private $logger;

    public function __construct(callable $logger, array $config, array $participants)
    {
        $this->logger = $logger;
        $this->dbConfig = $config;
        $this->participants = $participants;
    }

    /**
     * Resolves SAT (Smart Asset Token) status for ISO20022 compliance.
     * sandbox-ready: Detailed logging for failed lookups.
     */
    public function isSatPurchased(string $participantName, array $data): bool
    {
        $participantKey = strtolower($participantName);
        $p = $this->participants[$participantKey] ?? null;

        if (!$p) {
            ($this->logger)("SAT_STATUS_ERR", ['msg' => 'Unknown Participant', 'id' => $participantName]);
            return false;
        }

        if (!isset($this->dbConfig['db'][$participantKey])) {
            ($this->logger)("SAT_CONFIG_ERR", ['msg' => 'DB Config Missing', 'id' => $participantName]);
            return false;
        }

        try {
            $pdo = DBConnection::getInstance($this->dbConfig['db'][$participantKey]);

            // ISO20022 Identifier Resolution
            $identifier = $data['voucher_number'] ?? $data['pin'] ?? $data['phone'] ?? null;
            $column = !empty($data['voucher_number']) ? ($p['voucher_column'] ?? null) : 
                     (!empty($data['pin']) ? ($p['pin_column'] ?? null) : ($p['phone_column'] ?? null));

            if (!$identifier || !$column) {
                ($this->logger)("SAT_ID_MISSING", ['participant' => $participantName]);
                return false;
            }

            $table = $p['sat_table'] ?? 'users_sat_registry';
            $enabledColumn = $p['sat_purchased_column'] ?? 'sat_purchased'; 

            $sql = "SELECT {$enabledColumn} FROM {$table} WHERE {$column} = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $identifier]);

            $value = $stmt->fetchColumn();

            return $value !== false && filter_var($value, FILTER_VALIDATE_BOOLEAN);
            
        } catch (Exception $ex) {
            ($this->logger)("SAT_DB_FATAL", ['msg' => $ex->getMessage()]);
            return false;
        }
    }
}
