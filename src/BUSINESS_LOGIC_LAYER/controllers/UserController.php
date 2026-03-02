<?php
// controllers/UserController.php
require_once __DIR__ . '/../services/UserService.php';
require_once __DIR__ . '/../../APP_LAYER/utils/AuditLogger.php';

class UserController {
    private UserService $svc;
    public function __construct(PDO $db) {
        $this->svc = new UserService($db);
    }

    public function getProfile(int $userId): array {
        return $this->svc->getUserById($userId);
    }

    public function updateProfile(int $userId, array $data): array {
        $res = $this->svc->updateUser($userId, $data);
        AuditLogger::write('users', $userId, 'update_profile', null, json_encode($data), $userId);
        return $res;
    }
}
