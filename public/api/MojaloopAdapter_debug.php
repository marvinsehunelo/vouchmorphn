<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
/**
 * Mojaloop Adapter - Main Entry Point
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use DFSP_ADAPTER_LAYER\MojaloopAdapter;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Parse the request
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Remove /mojaloop prefix if present
if (strpos($path, '/mojaloop') === 0) {
    $path = substr($path, 9);
}
$path = trim($path, '/');

// Parse path parts
$parts = explode('/', $path);
$endpoint = $parts[0] ?? 'health';

// Extract route parameters
$routeParams = [];
if (count($parts) > 1) {
    if ($endpoint === 'parties' && isset($parts[2])) {
        $routeParams = [
            'type' => $parts[1] ?? 'MSISDN',
            'id' => $parts[2] ?? ''
        ];
    } elseif ($endpoint === 'participants' && isset($parts[1])) {
        $routeParams = [
            'fspId' => $parts[1] ?? ''
        ];
    }
}

// Get payload for POST/PUT requests
$payload = [];
if ($method === 'POST' || $method === 'PUT') {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true) ?: [];
}

// Initialize adapter with SwapService from bootstrap
global $swapService;
$adapter = new MojaloopAdapter($swapService, $GLOBALS['participants'] ?? []);

// Handle the request
$response = $adapter->handle($endpoint, $payload, $routeParams);

// Set HTTP status code based on response
if (isset($response['error']) || (isset($response['status']) && $response['status'] === 'error')) {
    http_response_code(400);
} elseif (empty($response)) {
    http_response_code(404);
} else {
    http_response_code(200);
}

// Output response
echo json_encode($response, JSON_PRETTY_PRINT);
