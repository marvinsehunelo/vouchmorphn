<?php
declare(strict_types=1);

/**
 * VouchMorphn - Card Block API
 * Blocks a message card (cancels further transactions)
 */

// ============================================
// 1. BOOTSTRAP & PATHS
// ============================================
define('ROOT_PATH', dirname(__DIR__, 5)); // Goes up 5 levels to project root

// ============================================
// 2. HEADERS & CORS
// ============================================
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// ============================================
// 3. LOAD SYSTEM CONFIG & CORE
// ============================================
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';

$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// ============================================
// 4. LOAD REQUIRED CLASSES
// ============================================
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/CardService.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/Helpers/CardHelper.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\CardService;

// ============================================
// 5. LOAD COUNTRY-SPECIFIC ENVIRONMENT VARIABLES
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
// 6. AUTHENTICATION - SAME PATTERN AS OTHER ENDPOINTS
// ============================================
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Start with SYSTEM key (global)
$validKeys = array_filter([
    get_env_val('API_KEY_SYSTEM')
]);

// Load country-specific participants to get bank keys
$participantsFile = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";
if (file_exists($participantsFile)) {
    $participantsData = json_decode(file_get_contents($participantsFile), true);
    
    // For each participant, look for its API key in environment
    if (isset($participantsData['participants'])) {
        foreach ($participantsData['participants'] as $participantName => $participant) {
            // Convert participant name to env key format (e.g., ZURUBANK -> API_KEY_ZURUBANK)
            $envKey = 'API_KEY_' . strtoupper($participantName);
            $keyValue = get_env_val($envKey);
            if ($keyValue) {
                $validKeys[] = $keyValue;
            }
            
            // Also check for provider_code based keys
            if (isset($participant['provider_code'])) {
                $providerEnvKey = 'API_KEY_' . strtoupper($participant['provider_code']);
                $providerKeyValue = get_env_val($providerEnvKey);
                if ($providerKeyValue) {
                    $validKeys[] = $providerKeyValue;
                }
            }
        }
    }
}

// Remove any empty values
$validKeys = array_filter($validKeys);

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
// 7. GET INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON payload: ' . json_last_error_msg()
    ]);
    exit();
}

// Validate required fields
if (empty($input['card_number'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'card_number is required'
    ]);
    exit();
}

// Basic card number sanitization (remove spaces, dashes)
$cardNumber = preg_replace('/[^0-9]/', '', $input['card_number']);

if (strlen($cardNumber) < 15 || strlen($cardNumber) > 19) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid card number format'
    ]);
    exit();
}

// ============================================
// 8. DATABASE CONNECTION
// ============================================
try {
    $pdo = DBConnection::getConnection();
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    error_log("Database connection error in block API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection error'
    ]);
    exit();
}

// ============================================
// 9. LOAD COUNTRY-SPECIFIC CARD CONFIG
// ============================================
$config = [];
$cardConfigPath = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/card_config_{$country}.json";
if (file_exists($cardConfigPath)) {
    $config = json_decode(file_get_contents($cardConfigPath), true);
}

// ============================================
// 10. EXECUTE CARD BLOCK
// ============================================
try {
    $cardService = new CardService($pdo, $country, $config);
    $result = $cardService->blockCard(
        $cardNumber,
        $input['reason'] ?? 'User requested block'
    );
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Card block error for {$country} - card ending in " . substr($cardNumber, -4) . ": " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
