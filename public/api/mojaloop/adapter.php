<?php
/**
 * Mojaloop Adapter - Public Entry Point
 */

// Get the request path FIRST before using it
$request_uri = $_SERVER["REQUEST_URI"];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove the script name from the path
$script_name = $_SERVER['SCRIPT_NAME'];
if (strpos($path, $script_name) === 0) {
    $path = substr($path, strlen($script_name));
}

// Get body for callback handling
$body = [];
if ($_SERVER["REQUEST_METHOD"] === "POST" || $_SERVER["REQUEST_METHOD"] === "PUT") {
    $input = file_get_contents("php://input");
    $body = json_decode($input, true) ?: [];
}

// Handle callback requests from TTK
if (strpos($path, '/callback') === 0) {
    error_log("[Callback] Received callback from TTK: " . json_encode($body));
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'received' => true]);
    exit;
}

// Load bootstrap
require_once __DIR__ . "/../../src/bootstrap.php";

// Use the Router instead of Adapter
use DFSP_ADAPTER_LAYER\MojaloopRouter;

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, FSPIOP-Source, FSPIOP-Destination, FSPIOP-Signature, Date");

// Handle preflight requests
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Get headers (normalized to uppercase for consistency)
$headers = getallheaders();
$headers = array_change_key_case($headers, CASE_UPPER);

// Initialize router
$router = new MojaloopRouter();

// Route the request
$result = $router->route($path, $body, $headers);

// Determine content type based on endpoint
$contentType = 'application/json';
if (strpos($path, '/parties') === 0) {
    $contentType = 'application/vnd.interoperability.parties+json;version=1.0';
} elseif (strpos($path, '/quotes') === 0) {
    $contentType = 'application/vnd.interoperability.quotes+json;version=1.0';
} elseif (strpos($path, '/transfers') === 0) {
    $contentType = 'application/vnd.interoperability.transfers+json;version=1.0';
} elseif ($path === '/health') {
    $contentType = 'application/json';
}

header("Content-Type: $contentType");

// ===== FIX: Conditionally set response code =====
if ($path === '/health' || $path === '/health/') {
    // Health check returns 200 immediately
    http_response_code(200);
    echo json_encode([
        "status" => "OK",
        "service" => "vouchmorph-mojaloop-adapter",
        "timestamp" => date('c')
    ]);
    exit;
} else {
    // For all Mojaloop async endpoints, return 202 Accepted immediately
    http_response_code(202);
    
    // Send async callback for relevant endpoints (only on success)
    if ($result['status'] === 'success' && 
        (strpos($path, '/parties') === 0 || 
         strpos($path, '/quotes') === 0 || 
         strpos($path, '/transfers') === 0)) {
        
        error_log("[MojaloopAdapter] ABOUT TO SEND CALLBACK for path: $path");
        error_log("[MojaloopAdapter] Callback data: " . json_encode($result['data']));
        
        $callbackResult = $router->sendCallback($path, $result['data'], $headers);
        
        error_log("[MojaloopAdapter] Callback sent result: " . ($callbackResult ? 'SUCCESS' : 'FAILED'));
    }
    
    // Return empty response body as per Mojaloop async spec
    echo '';
}
