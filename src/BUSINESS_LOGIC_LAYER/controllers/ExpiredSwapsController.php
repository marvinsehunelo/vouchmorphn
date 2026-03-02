<?php
// controllers/ExpiredSwapsController.php
declare(strict_types=1);

// ------------------------
// 1️⃣ LOAD ESSENTIAL FILES & CLASSES
// ------------------------

require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/DBConnection.php';
$country = require __DIR__ . '/../../CORE_CONFIG/system_country.php';
$config = require __DIR__ . '/../../CORE_CONFIG/config_' . $country . '.php';

use APP_LAYER\Utils\SessionManager;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

// ------------------------
// 2️⃣ AUTHENTICATION
// ------------------------
SessionManager::start();
// ... (your auth check logic) ...

// ------------------------
// 3️⃣ INITIALIZE ALL BANK DATABASES
// ------------------------
$banks = [];
foreach ($config['db'] as $bankName => $dbConfig) {
    try {
        $banks[$bankName] = DBConnection::getInstance($dbConfig);
    } catch (\Throwable $e) {
        error_log("Failed to connect to {$bankName}: " . $e->getMessage());
    }
}

// ------------------------
// 4️⃣ HANDLE POST REQUEST
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../services/ExpiredSwapsService.php';
    require_once __DIR__ . '/../../APP_LAYER/utils/AuditLogger.php';

    header('Content-Type: application/json');

    try {
        $service = new BUSINESS_LOGIC_LAYER\Services\ExpiredSwapsService($banks, $config);
        $result = $service->processExpiredSwaps();

        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    } catch (Throwable $e) {
        AuditLogger::write('expired_swaps', null, 'process_error', null, $e->getMessage(), 'system');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ------------------------
// 5️⃣ HANDLE GET REQUEST (Dashboard)
// ------------------------
$admins = [];
try {
    // Using first available bank DB for admin table display
    $firstDB = reset($banks);
    if ($firstDB) {
        $stmt = $firstDB->query("SELECT id, username, email, role_id, mfa_enabled, created_at FROM admin_users ORDER BY id ASC");
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("Database Error: " . $e->getMessage());
    $admins = [];
}

// ... continue to render dashboard HTML ...
