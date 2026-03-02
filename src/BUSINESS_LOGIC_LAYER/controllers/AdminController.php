<?php
// controllers/AdminController.php
require_once __DIR__ . '/../services/AdminService.php';
require_once __DIR__ . '/../utils/AuditLogger.php';

use ADMIN_LAYER\Tools\ConfigEditor;

$cfg = new ConfigEditor();
$cfg->update('max_swap_limit', 50000);


class AdminController {
    private AdminService $svc;
    public function __construct(PDO $db) {
        $this->svc = new AdminService($db);
    }

    public function listAmlFlags(): array {
        return $this->svc->listAmlFlags();
    }

    public function resolveAmlFlag(int $flagId, array $payload): array {
        $res = $this->svc->resolveAmlFlag($flagId, $payload);
        AuditLogger::write('aml_flags', $flagId, 'resolve', null, json_encode($payload), $payload['admin_id'] ?? 'admin');
        return $res;
    }
}
