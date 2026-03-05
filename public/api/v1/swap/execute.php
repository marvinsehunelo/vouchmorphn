<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Deployment: Railway (2026 Sandbox)
 */

// ============================================
// BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// HEADERS & CORS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// LOAD SYSTEM CONFIG
// ============================================
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';

// ============================================
// LOAD CORE CLASSES
// ============================================
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

// ADD THESE LINES TO FIX THE SMS GATEWAY ERROR:
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SmsNotificationService.php';
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/CommunicationClients/SmsGatewayClient.php';

// Also load these to prevent similar errors with Bank integration:
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php';
require_once ROOT_PATH . '/src/SECURITY_LAYER/Encryption/TokenEncryptor.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// ============================================
// DYNAMIC ENV LOADER (Handles .env_BW)
// ============================================
$envFile = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/.env_{$country}";

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Secure Env Helper
 */
function get_env_val(string $key) {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
    }
    return $val;
}

// ============================================
// AUTHENTICATION (Robust Header Handling)
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Check X-API-Key (hyphen) and HTTP_X_API_KEY (server normalized)
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = array_filter([
    get_env_val('API_KEY_SYSTEM'),
    get_env_val('API_KEY_PARTNER_1'),
    get_env_val('API_KEY_PARTNER_2'),
    get_env_val('API_KEY_PARTNER_3'),
    get_env_val('API_KEY_PARTNER_4'),
    get_env_val('API_KEY_ZURUBANK'),
    get_env_val('API_KEY_SACCUSSALIS')
]);

if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid or missing API key',
        'debug' => [
            'country' => $country,
            'key_sent' => $providedKey ? substr($providedKey, 0, 4) . '***' : 'none'
        ]
    ]);
    exit();
}

// ============================================
// LOAD CORE CLASSES
// ============================================
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

// ============================================
// EXECUTE TRANSACTION
// ============================================
try {
    // 1. Parse Input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
    }

    // 2. Database Connection
    $pdo = DBConnection::getConnection();
    if (!$pdo) throw new Exception('Database connection failed.');

    // 3. Load Participants (Alpha, Bravo, etc.)
    $participantsPath = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";
    if (!file_exists($participantsPath)) {
        throw new Exception("Participants configuration not found for {$country}");
    }
    $participantsConfig = json_decode(file_get_contents($participantsPath), true);

    // 4. Resolve Encryption Key
    $encryptionKey = get_env_val('APP_ENCRYPTION_KEY') ?: 'default-test-key-32-chars-12345678';

    // 5. Initialize Service & Execute
    $swapService = new SwapService(
        $pdo, 
        [], // Settings handled inside service via country_config
        $country, 
        $encryptionKey, 
        ['participants' => $participantsConfig]
    );

    $result = $swapService->executeSwap($input);

    // 6. Success Response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result,
        'settlement_status' => 'accounts_verified' // Based on your settlement account status
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Swap API Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
