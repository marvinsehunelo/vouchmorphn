<?php
declare(strict_types=1);

// ============================================
// BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// ============================================
// HEADERS
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
// LOAD COUNTRY & ENV
// ============================================
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

/**
 * FIX: Loading .env_BW instead of .env.BW
 */
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

// ============================================
// AUTHENTICATION
// ============================================
$headers = getallheaders();
// Support both standard and lowercase header keys
$apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;

// Helper to fetch keys reliably from any superglobal
function get_env_secure(string $key) {
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? null));
}

$validKeys = [
    get_env_secure('API_KEY_SYSTEM'),
    get_env_secure('API_KEY_PARTNER_1'),
    get_env_secure('API_KEY_PARTNER_2'),
    get_env_secure('API_KEY_PARTNER_3'),
    get_env_secure('API_KEY_PARTNER_4'),
    get_env_secure('API_KEY_ZURUBANK'),
    get_env_secure('API_KEY_SACCUSSALIS')
];

// Remove nulls and check if provided key exists in our allowed list
if (!$apiKey || !in_array($apiKey, array_filter($validKeys), true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Invalid or missing API key',
        'trace_id' => bin2hex(random_bytes(4)) 
    ]);
    exit();
}

// ============================================
// EXECUTE SWAP
// ============================================
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception("Invalid JSON payload");

    $pdo = DBConnection::getConnection();
    
    // Load participants specifically for the SwapService
    $partPath = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";
    $config = json_decode(file_get_contents($partPath), true);
    
    // Use the specific key from your .env
    $encKey = get_env_secure('APP_ENCRYPTION_KEY');

    $swapService = new SwapService($pdo, [], $country, $encKey, ['participants' => $config]);
    $result = $swapService->executeSwap($input);

    echo json_encode(['success' => true, 'data' => $result]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
