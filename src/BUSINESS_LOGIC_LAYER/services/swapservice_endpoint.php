<?php
declare(strict_types=1);

use BUSINESS_LOGIC_LAYER\services\SwapService;
use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

header('Content-Type: application/json');

// Ensure no accidental white-space before JSON
while (ob_get_level()) { ob_end_clean(); }

// 1️⃣ BOOTSTRAP & ERROR HANDLING
// Log errors to file, don't show them to the user
ini_set('display_errors', '0');
error_reporting(E_ALL); 

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Internal System Error",
        "trace_id" => bin2hex(random_bytes(8)) // For log correlation
    ]);
    // Log the actual $e->getMessage() to your server logs here
    exit;
});

try {
    $config = require_once __DIR__ . '/../../CORE_CONFIG/load_country.php';
    $country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW'; 

    require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/DBConnection.php';
    require_once __DIR__ . '/../../BUSINESS_LOGIC_LAYER/services/SwapService.php';
    require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
} catch (Throwable $e) {
    throw $e; // Caught by global handler
}

// 2️⃣ AUTHENTICATION & VERBS
SessionManager::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

// 3️⃣ INPUT SANITIZATION
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Malformed JSON"]);
    exit;
}

// 4️⃣ MANDATORY FIELDS & IDEMPOTENCY
$required = ['fromParticipant', 'toParticipant', 'amount'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
        exit;
    }
}

// Crucial for Financial Sandboxes: Client-generated unique ID
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $data['request_id'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "X-Idempotency-Key header required"]);
    exit;
}

// 5️⃣ SERVICE INIT
$swapDB = DBConnection::getInstance($config['db']['swap']);
$swapService = new SwapService(
    $swapDB, 
    $config['settings'] ?? [], 
    $country, 
    $config['encryption']['key'] ?? 'SECRET', 
    $config
);

// 6️⃣ COMPLIANCE & PRE-FLIGHT
$user = SessionManager::getUser();
$userId = (int)($user['id'] ?? 0);

// Validate Amount
$amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
if ($amount === false || $amount <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid amount"]);
    exit;
}

// KYC/AML Check
if (!($user['kyc_verified'] ?? false) || ($user['aml_score'] ?? 0) > 90) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Compliance verification required"]);
    exit;
}

// 7️⃣ EXECUTION
try {
    // Inject validated values back into data array
    $data['user_id'] = $userId;
    $data['amount'] = $amount;
    $data['idempotency_key'] = $idempotencyKey;

    $result = $swapService->initiateSwap($data);

    // Fee Logging (Audit Trail)
    if (!empty($result['fees']) && is_array($result['fees'])) {
        foreach ($result['fees'] as $fee) {
            $swapService->logFeeSplit($userId, $fee);
        }
    }

    $httpCode = (isset($result['status']) && $result['status'] === 'success') ? 200 : 400;
    http_response_code($httpCode);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Log error locally: error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Transaction failed during processing"]);
}
