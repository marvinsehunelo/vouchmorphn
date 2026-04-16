<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// ADMINISTRATION_LAYER/api/add_admin.php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/db_connection.php';
require_once __DIR__ . '/../../APP_LAYER/utils/AuditLogger.php';

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

SessionManager::start();
header('Content-Type: application/json');

// --- 0. SESSION + ADMIN CHECK ---
$user = SessionManager::getUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !SessionManager::isLoggedIn() || ($user['role_id'] ?? 0) !== 2) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access or invalid request method.']);
    exit;
}

// --- 1. DATABASE CONNECTION ---
$country = require __DIR__ . '/../../CORE_CONFIG/system_country.php';
$config = require __DIR__ . '/../../CORE_CONFIG/config_' . $country . '.php';

try {
    $swapDB = DBConnection::getInstance($config['db']['swap']);
} catch (\Throwable $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server database connection failed.']);
    exit;
}

// --- 2. EXTRACT INPUT ---
$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$role_id = 2; // always admin
$password = $data['password'] ?? '';
$confirm_password = $data['confirm_password'] ?? '';
$mfa_enabled = (int)($data['mfa_enabled'] ?? 0);

// --- 3. VALIDATION ---
if (empty($username) || empty($password) || empty($confirm_password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password fields are required.']);
    exit;
}
if ($password !== $confirm_password) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
    exit;
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long.']);
    exit;
}

// --- 4. DATABASE INSERT ---
try {
    $swapDB->beginTransaction();

    // Check if username exists in users or admins
    $checkStmt = $swapDB->prepare("SELECT user_id FROM users WHERE username = :username UNION SELECT admin_id FROM admins WHERE username = :username");
    $checkStmt->execute([':username' => $username]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
        exit;
    }

   $passwordHash = password_hash($tempPassword, VM_HASH_ALGO, VM_HASH_OPTIONS);

    // 4a. Insert into users
    $stmtUser = $swapDB->prepare("
        INSERT INTO users (username, email, phone, role_id, password_hash, verified, mfa_enabled, created_at, updated_at)
        VALUES (:username, :email, :phone, :role_id, :password_hash, 1, :mfa_enabled, NOW(), NOW())
    ");
    $stmtUser->execute([
        ':username' => $username,
        ':email' => $email ?: null,
        ':phone' => $phone ?: null,
        ':role_id' => $role_id,
        ':password_hash' => $password_hash,
        ':mfa_enabled' => $mfa_enabled,
    ]);
    $newUserId = $swapDB->lastInsertId();

    // 4b. Insert into admins table
    $stmtAdmin = $swapDB->prepare("
        INSERT INTO admins (username, password_hash, email, phone, role_id, mfa_enabled, created_at, updated_at)
        VALUES (:username, :password_hash, :email, :phone, :role_id, :mfa_enabled, NOW(), NOW())
    ");
    $stmtAdmin->execute([
        ':username' => $username,
        ':password_hash' => $password_hash,
        ':email' => $email ?: null,
        ':phone' => $phone ?: null,
        ':role_id' => $role_id,
        ':mfa_enabled' => $mfa_enabled,
    ]);
    $newAdminId = $swapDB->lastInsertId();

    // 4c. Log creation
    \APP_LAYER\Utils\AuditLogger::write('users', $newUserId, 'create_admin', null, json_encode($data), $user['username']);

    $swapDB->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Administrator {$username} created successfully.",
        'new_user_id' => $newUserId,
        'new_admin_id' => $newAdminId
    ]);

} catch (\Throwable $e) {
    $swapDB->rollBack();
    error_log("Admin Creation DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'A server error occurred during database transaction.']);
}

