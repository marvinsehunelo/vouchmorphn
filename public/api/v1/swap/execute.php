<?php
declare(strict_types=1);

// Enable CORS if needed
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-API-Key, Authorization");

// Handle preflight OPTIONS request
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
// BOOTSTRAP - Define paths and load dependencies
// ============================================

define('ROOT_PATH', dirname(__DIR__, 4)); // Goes up 4 levels from api/v1/swap/execute.php

// Load required classes
require_once ROOT_PATH . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once ROOT_PATH . '/src/BUSINESS_LOGIC_LAYER/services/SwapService.php';
require_once ROOT_PATH . '/src/INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php';
require_once ROOT_PATH . '/src/SECURITY_LAYER/Encryption/TokenEncryptor.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

// ============================================
// GET COUNTRY FROM INPUT FIRST (needed for env loading)
// ============================================

$input = json_decode(file_get_contents('php://input'), true);
$country = strtoupper($input['country'] ?? 'BW'); // Default to Botswana

// ============================================
// LOAD ENVIRONMENT VARIABLES FROM COUNTRY-SPECIFIC .ENV FILE
// ============================================

$envFile = ROOT_PATH . "/src/CORE_CONFIG/countries/{$country}/.env.{$country}";
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    error_log("Loaded environment from: " . $envFile);
} else {
    error_log("Environment file not found: " . $envFile);
}

// ============================================
// AUTHENTICATION - API Key validation from environment
// ============================================

$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;

// Get API key from environment (loaded from country-specific .env file)
$validApiKey = getenv('API_KEY_PRODUCTION');

if (!$apiKey || !$validApiKey || $apiKey !== $validApiKey) {
    error_log("API key validation failed. Provided: " . ($apiKey ?? 'none') . ", Expected: " . ($validApiKey ?? 'none'));
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid or missing API key']);
    exit();
}

error_log("API key validated successfully");

// ============================================
// VALIDATE INPUT PAYLOAD
// ============================================

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload: ' . json_last_error_msg()]);
    exit();
}

// Basic validation
if (empty($input['source']['institution'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: source.institution']);
    exit();
}

if (empty($input['destination']['institution'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: destination.institution']);
    exit();
}

if (empty($input['source']['asset_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: source.asset_type']);
    exit();
}

if (empty($input['destination']['asset_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: destination.asset_type']);
    exit();
}

if (!isset($input['source']['amount']) || $input['source']['amount'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid source amount']);
    exit();
}

// ============================================
// GET DATABASE CONNECTION
// ============================================

try {
    $pdo = DBConnection::getConnection();
    
    if (!$pdo) {
        // Try to get connection status for debugging
        $status = DBConnection::getConnectionStatus();
        error_log("Database connection failed. Status: " . json_encode($status));
        
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit();
    }
    
    error_log("Database connected successfully to: " . ($pdo->query("SELECT current_database()")->fetchColumn() ?? 'unknown'));
    
} catch (Exception $e) {
    error_log("Database connection exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error: ' . $e->getMessage()]);
    exit();
}

// ============================================
// LOAD PARTICIPANTS CONFIGURATION - participants_{country}.json
// ============================================

// Construct the participants file path correctly
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
    echo json_encode(['error' => "Server configuration error: Invalid JSON in participants config for {$country}"]);
    exit();
}

error_log("Loaded participants config for {$country} from: " . $participantsFile);

// ============================================
// GET ENCRYPTION KEY
// ============================================

$encryptionKey = getenv('ENCRYPTION_KEY');
if (!$encryptionKey) {
    // Generate a default for testing (in production, this must be set)
    $encryptionKey = 'default-test-key-32-chars-1234567890';
    error_log("WARNING: Using default encryption key. Set ENCRYPTION_KEY in environment.");
}

// ============================================
// PREPARE CONFIG FOR SWAPSERVICE
// ============================================

// The SwapService expects the participants array - use empty settings array
$settings = []; // Empty settings array as we don't have settings files

// For the config, we need to pass it in the format SwapService expects
// Looking at your SwapService constructor, it accepts $config which can contain participants
$fullConfig = [
    'participants' => $config // This is the content of participants_{country}.json
];

// ============================================
// EXECUTE SWAP
// ============================================

try {
    // Instantiate SwapService
    $swapService = new SwapService(
        $pdo,
        $settings, // Empty settings array
        $country,
        $encryptionKey,
        $fullConfig  // Pass the config with participants
    );
    
    // Execute the swap
    $result = $swapService->executeSwap($input);
    
    // Return successful response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Log the error
    error_log("Swap API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
