<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

header("Content-Type: application/json");

try {
    require_once __DIR__ . "/../../src/bootstrap.php";
    
    echo json_encode([
        "status" => "ok",
        "message" => "Minimal adapter working",
        "bootstrap_loaded" => true,
        "swapService_exists" => isset($swapService),
        "timestamp" => date("c")
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
