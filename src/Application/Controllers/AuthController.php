<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// controllers/AuthController.php
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../utils/AuditLogger.php';

use SECURITY_LAYER\Auth\JwtAuth;

$jwt = new JwtAuth();
$token = $jwt->generateToken(['user_id' => $user['id'], 'role' => $user['role']]);


class AuthController {
    private AuthService $service;
    public function __construct(PDO $swapDB) {
        $this->service = new AuthService($swapDB);
    }

    public function login(array $data): array {
        $res = $this->service->login($data['phone'] ?? '', $data['password'] ?? '');
        AuditLogger::write('auth', null, 'login_attempt', null, json_encode(['phone'=>$data['phone']]), $res['user'] ?? 'system');
        return $res;
    }

    public function register(array $data): array {
        $res = $this->service->register($data);
        AuditLogger::write('auth', $res['user_id'] ?? null, 'register', null, json_encode($data), $res['user_id'] ?? 'system');
        return $res;
    }
}
