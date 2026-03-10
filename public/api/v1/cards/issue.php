<?php
declare(strict_types=1);

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight
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

// Bootstrap
require_once __DIR__ . '/../../../../src/bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

// Authenticate
$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;

// Simple API key validation (enhance as needed)
$validApiKeys = [
    'test_key_123' => 'TEST',
    getenv('API_KEY_CARDS') ?: 'card_key_2025' => 'PRODUCTION'
];

if (!$apiKey || !isset($validApiKeys[$apiKey])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid API key']);
    exit();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

try {
    $cardService = new CardService($db, $countryCode, $config);
    $result = $cardService->issueCard($input);
    
    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
