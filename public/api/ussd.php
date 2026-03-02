<?php
/**
 * USSD Webhook Endpoint for VouchMorph - DEBUG VERSION
 */

// Show errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load bootstrap
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/controllers/USSDController.php';

use BUSINESS_LOGIC_LAYER\controllers\USSDController;

try {
    // Load configuration
    $config = require_once __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
    
    // Initialize controller
    $ussdController = new USSDController($config);
    
    // Get request data
    $request = array_merge($_GET, $_POST);
    
    // Handle JSON input
    $input = file_get_contents('php://input');
    if (!empty($input) && $input[0] === '{') {
        $jsonData = json_decode($input, true);
        if (is_array($jsonData)) {
            $request = array_merge($request, $jsonData);
        }
    }
    
    // Set defaults for testing
    if (empty($request)) {
        $request = [
            'sessionId' => 'TEST_' . time(),
            'phoneNumber' => '26771123456',
            'text' => '',
            'serviceCode' => '*384*1234#'
        ];
    }
    
    // Handle request
    $response = $ussdController->handleUSSDRequest($request);
    
    header('Content-Type: text/plain');
    echo $response;
    
} catch (Exception $e) {
    // Show actual error
    header('Content-Type: text/plain');
    echo "END ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString();
}
