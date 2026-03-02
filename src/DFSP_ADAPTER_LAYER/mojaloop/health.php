<?php
// CORE_CONFIG/health.php

// Only fatal errors, suppress notices/warnings to prevent JSON corruption
error_reporting(E_ERROR | E_PARSE);

// Force HTTP 200
http_response_code(200);
header('Content-Type: application/json');

// Strict JSON structure expected by Mojaloop TTK
$response = [
    'status'    => 'OK',                 // Must be uppercase
    'timestamp' => gmdate('c'),          // ISO8601 UTC timestamp
    'service'   => 'VouchMorphn Mojaloop Adapter',
    'version'   => '1.0.0',
    'country'   => 'BW'
];

// Encode JSON and prevent extra characters
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;

