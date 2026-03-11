<?php
declare(strict_types=1);

/**
 * VouchMorph - Card Application API
 * For general public card applications
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
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

// Load system config
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';
$country = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : 'BW';

// Load required classes
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/CardApplicationService.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/KYCDocumentService.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\CardApplicationService;

// ============================================
// ADD THIS MISSING HELPER FUNCTION
// ============================================
if (!function_exists('get_env_val')) {
    function get_env_val(string $key) {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? ($_SERVER[$key] ?? null);
        }
        return $val;
    }
}

// Load environment
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

// Authentication
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headersLower = array_change_key_case($headers, CASE_LOWER);
$providedKey = $headersLower['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

$validKeys = array_filter([get_env_val('API_KEY_SYSTEM')]);
if (!$providedKey || !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

// Validate required fields
$required = ['full_name', 'id_number', 'id_type', 'date_of_birth', 'phone', 'email', 'card_type'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "$field is required"]);
        exit();
    }
}

// Validate card type
if (!in_array($input['card_type'], ['PHYSICAL', 'VIRTUAL'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_type must be PHYSICAL or VIRTUAL']);
    exit();
}

// Database connection
try {
    $pdo = DBConnection::getConnection();
    if (!$pdo) throw new Exception('Database connection failed');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit();
}

// Process application
try {
    $config = [];
    $cardConfigPath = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/card_config_{$country}.json";
    if (file_exists($cardConfigPath)) {
        $config = json_decode(file_get_contents($cardConfigPath), true);
    }
    
    $applicationService = new CardApplicationService($pdo, $country, $config);
    $result = $applicationService->processApplication($input);
    
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Card application error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
