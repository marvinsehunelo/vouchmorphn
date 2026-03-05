<?php
declare(strict_types=1);

/**
 * VouchMorphn - Swap Execution API
 * Deployment: Railway (2026 Sandbox)
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// 2. HEADERS & CORS
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
// 3. LOAD SYSTEM CONFIG & CORE
// ============================================
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// ============================================
// 4. LOAD ALL REQUIRED CLASSES (Dependencies)
// ============================================
// Persistence & Logic
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

// Communication & Notifications
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SmsNotificationService.php';
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/CommunicationClients/SmsGatewayClient.php';

// Banking & Security
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php';
require_once ROOT_PATH . '/src/SECURITY_LAYER/Encryption/TokenEncryptor.php';

// NAMESPACE IMPORTS (Only once!)
use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

// ============================================
// 5. DYNAMIC ENV LOADER (.env_BW)
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

if (!function_exists('get_env_val')) {
    function get_env_val(string $key) {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
        }
        return $val;
    }
}

// ============================================
// 6. AUTHENTICATION
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
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
        'debug' => ['country' => $country]
    ]);
    exit();
}

// ============================================
// 7. EXECUTE SWAP
// ============================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Invalid JSON payload');

    $pdo = DBConnection::getConnection();
    
    // 1. Path to your BW participants file
    $pPath = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";
    if (!file_exists($pPath)) throw new Exception("Config missing: $pPath");
    
    $rawConfig = json_decode(file_get_contents($pPath), true);

    // 2. DATA NORMALIZATION
    // If your JSON has "participants" at the root, use it directly.
    // If not, we wrap it so the Service finds $config['participants']
    $finalConfig = isset($rawConfig['participants']) ? $rawConfig : ['participants' => $rawConfig];

    // 3. RESOLVE ENCRYPTION
    // Since you have settlement accounts in all partner institutions, 
    // we use the Sandbox key for AES-256-GCM
    $encryptionKey = get_env_val('APP_ENCRYPTION_KEY');

    // 4. INITIALIZE SWAP SERVICE
    // We pass the normalized $finalConfig which contains the "SACCUSSALIS" key
    $swapService = new SwapService(
        $pdo, 
        [], 
        $country, 
        $encryptionKey, 
        $finalConfig 
    );

    // 5. EXECUTION
    $result = $swapService->executeSwap($input);

    // 6. OUTPUT
    echo json_encode([
        'success' => true, 
        'data' => $result
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => 'API_EXECUTE_FAIL'
    ]);
}

