<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class ComplianceFlag
{
    public int $id;
    public string $entity;
    public string $reason;
    public string $flagged_by;
    public string $created_at;

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    public function flag(array $data): int {
        $stmt = $this->db->prepare(
            "INSERT INTO compliance_flags (entity, reason, flagged_by, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$data['entity'], $data['reason'], $data['flagged_by']]);
        return (int)$this->db->lastInsertId();
    }
}
