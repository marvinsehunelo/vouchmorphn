<?php
declare(strict_types=1);

// ============================================
// BOOTSTRAP - Define ROOT_PATH first
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4)); // Adjust according to folder depth

// ============================================
// ENABLE CORS & JSON HEADERS
// ============================================
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-API-Key, Authorization");

// Preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// ============================================
// LOAD COUNTRY CONFIG
// ============================================
require_once ROOT_PATH . '/src/CORE_CONFIG/system_country.php';
require_once ROOT_PATH . '/src/CORE_CONFIG/load_country.php';

// Fallback
if (empty($country)) {
    $country = 'BW';
}

// ============================================
// LOAD COUNTRY-SPECIFIC ENV FILE DYNAMICALLY
// ============================================
$envFile = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/.env.{$country}";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    error_log("Loaded environment from: $envFile");
} else {
    error_log("Environment file not found: $envFile");
}

// ============================================
// LOAD REQUIRED CLASSES
// ============================================
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php';
require_once ROOT_PATH . '/src/SECURITY_LAYER/Encryption/TokenEncryptor.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

// ============================================
// SAFELY FIX DECIMAL AND RESOLVE FEES
// ============================================
if (!function_exists('decimal')) {
    function decimal($value): string {
        if (!is_numeric($value)) return (string)$value;
        return number_format((float)$value, 6, '.', '');
    }
}

if (!function_exists('resolveFees')) {
    function resolveFees(array $feeConfig): array {
        $resolved = [];
        if (isset($feeConfig['fees'])) {
            foreach ($feeConfig['fees'] as $key => $value) {
                if (is_array($value)) {
                    if (isset($value['amount'])) {
                        $value['amount'] = decimal($value['amount']);
                        $resolved[$key] = $value;
                        continue;
                    }
                    foreach ($value as $k => $v) {
                        $value[$k] = decimal($v);
                    }
                    $resolved[$key] = $value;
                } else {
                    $resolved[$key] = decimal($value);
                }
            }
        }
        foreach (['metadata','regulatory','limits','currency','aliases','rules'] as $section) {
            if (isset($feeConfig[$section])) {
                $resolved[$section] = $feeConfig[$section];
            }
        }
        return $resolved;
    }
}

// ============================================
// PARSE INPUT
// ============================================
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
    exit();
}

// Basic required fields
$requiredFields = [
    'source' => ['institution', 'asset_type', 'amount'],
    'destination' => ['institution', 'asset_type']
];

foreach ($requiredFields as $section => $fields) {
    foreach ($fields as $field) {
        if (!isset($input[$section][$field]) || ($field === 'amount' && $input[$section][$field] <= 0)) {
            http_response_code(400);
            echo json_encode(['error' => "Missing or invalid field: $section.$field"]);
            exit();
        }
    }
}

// ============================================
// AUTHENTICATION - MULTI-KEY VALIDATION
// ============================================
$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;

// Collect valid API keys dynamically from environment
$validKeyMap = array_filter([
    'SYSTEM'        => getenv('API_KEY_SYSTEM'),
    'PARTNER_1'     => getenv('API_KEY_PARTNER_1'),
    'PARTNER_2'     => getenv('API_KEY_PARTNER_2'),
    'PARTNER_3'     => getenv('API_KEY_PARTNER_3'),
    'PARTNER_4'     => getenv('API_KEY_PARTNER_4'),
    'ZURUBANK'      => getenv('API_KEY_ZURUBANK'),
    'SACCUSSALIS'   => getenv('API_KEY_SACCUSSALIS'),
]);

if (!$apiKey || !in_array($apiKey, $validKeyMap, true)) {
    error_log("API key validation failed. Provided: " . ($apiKey ?? 'none'));
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid or missing API key']);
    exit();
}

error_log("API key validated successfully");

// ============================================
// DATABASE CONNECTION
// ============================================
try {
    $pdo = DBConnection::getConnection();
    if (!$pdo) throw new Exception('DB connection returned null');
    error_log("Database connected successfully");
} catch (Exception $e) {
    error_log("Database connection exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error']);
    exit();
}

// ============================================
// LOAD PARTICIPANTS CONFIGURATION
// ============================================
$participantsFile = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";

if (!file_exists($participantsFile)) {
    error_log("Participants file not found: " . $participantsFile);
    http_response_code(500);
    echo json_encode(['error' => "Server configuration error: Participants config not found for country {$country}"]);
    exit();
}

$configJson = file_get_contents($participantsFile);
$config = json_decode($configJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['error' => "Invalid JSON in participants config for {$country}"]);
    exit();
}

error_log("Loaded participants config for {$country}");

// ============================================
// GET ENCRYPTION KEY
// ============================================
$encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-test-key-32-chars-1234567890';
if ($encryptionKey === 'default-test-key-32-chars-1234567890') {
    error_log("WARNING: Using default encryption key");
}

// ============================================
// PREPARE CONFIG FOR SWAPSERVICE
// ============================================
$settings = [];
$fullConfig = ['participants' => $config];

// ============================================
// EXECUTE SWAP
// ============================================
try {
    $swapService = new SwapService($pdo, $settings, $country, $encryptionKey, $fullConfig);
    $result = $swapService->executeSwap($input);

    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Swap API Error: " . $e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
