<?php
declare(strict_types=1);

// Path: /opt/lampp/htdocs/vouchmorphn/public/api/callback/index.php

// Disable error display but log them
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Use a safe log location
$logFile = '/tmp/ttk_callback_debug.log';  // Use /tmp which is always writable

// Function to safely log
function safeLog($message, $data = null) {
    global $logFile;
    $entry = date('Y-m-d H:i:s') . " - $message";
    if ($data) {
        $entry .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    $entry .= "\n\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// Log the request
safeLog("CALLBACK RECEIVED", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders()
]);

$input = file_get_contents('php://input');
$payload = json_decode($input, true) ?: [];
safeLog("PAYLOAD", $payload);

// --- HANDSHAKE LOGIC ---
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$ttkSimulator = "http://172.17.0.1:5050";

// Extract the original path (remove /callback prefix)
$originalPath = str_replace('/callback', '', $uri);

// Set required FSPIOP headers for response
header('Content-Type: application/vnd.interoperability.parties+json;version=1.0');
header('FSPIOP-Source: VOUCHMORPHN');

$source = $_SERVER['HTTP_FSPIOP_SOURCE'] ?? $_SERVER['HTTP_FSPIOP-SOURCE'] ?? 'switch';
header('FSPIOP-Destination: ' . $source);

// Always return 202 for async processing
http_response_code(202);

// Handle different callback types
if (strpos($originalPath, '/parties') === 0) {
    safeLog("PARTIES CALLBACK - No action needed");
    
} elseif (strpos($originalPath, '/quotes') === 0 && $method === 'POST') {
    safeLog("QUOTES CALLBACK - Sending PUT response");
    
    $quoteId = $payload['quoteId'] ?? '';
    $transactionId = $payload['transactionId'] ?? '';
    $amount = $payload['amount'] ?? ['amount' => '100', 'currency' => 'BWP'];
    
    // We must send a PUT /quotes/{id} back to TTK
    $putUrl = "$ttkSimulator/quotes/$quoteId";
    $quoteResponse = [
        "quoteId" => $quoteId,
        "transactionId" => $transactionId,
        "transferAmount" => $amount,
        "expiration" => gmdate('Y-m-d\TH:i:s.v\Z', strtotime('+2 minutes')),
        "ilpPacket" => "AYIBwgQAAAAAAAASAwBYZXhhbXBsZSB0ZXN0IHZhbHVlIG9ubHk",
        "condition" => "ILPcZXy5K8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q",
        "payeeReceiveAmount" => $amount,
        "payeeFspFee" => ["amount" => "0", "currency" => $amount['currency']],
        "payeeFspCommission" => ["amount" => "0", "currency" => $amount['currency']]
    ];
    
    safeLog("Sending PUT to $putUrl", $quoteResponse);
    
    $ch = curl_init($putUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($quoteResponse));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/vnd.interoperability.quotes+json;version=1.0',
        'FSPIOP-Source: VOUCHMORPHN',
        'FSPIOP-Destination: ' . $source,
        'Date: ' . gmdate('D, d M Y H:i:s \G\M\T')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        safeLog("QUOTES PUT FAILED", ['error' => $error]);
    } else {
        safeLog("QUOTES PUT SUCCESS", ['httpCode' => $httpCode, 'response' => $response]);
    }
    
} elseif (strpos($originalPath, '/transfers') === 0 && $method === 'POST') {
    safeLog("TRANSFERS CALLBACK - Sending PUT response");
    
    $transferId = $payload['transferId'] ?? '';
    $payerFsp = $payload['payerFsp'] ?? 'unknown';
    $payeeFsp = $payload['payeeFsp'] ?? 'unknown';
    $amount = $payload['amount'] ?? ['amount' => '100', 'currency' => 'BWP'];
    
    // We must send a PUT /transfers/{id} back to TTK
    $putUrl = "$ttkSimulator/transfers/$transferId";
    $transferResponse = [
        "transferId" => $transferId,
        "payerFsp" => $payerFsp,
        "payeeFsp" => $payeeFsp,
        "amount" => $amount,
        "completedTimestamp" => gmdate('Y-m-d\TH:i:s.v\Z'),
        "transferState" => "COMMITTED",
        "fulfilment" => "ILPf4q8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q",
        "condition" => "ILPcZXy5K8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q",
        "ilpPacket" => "AYIBwgQAAAAAAAASAwBYZXhhbXBsZSB0ZXN0IHZhbHVlIG9ubHk"
    ];
    
    safeLog("Sending PUT to $putUrl", $transferResponse);
    
    $ch = curl_init($putUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transferResponse));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/vnd.interoperability.transfers+json;version=1.0',
        'FSPIOP-Source: VOUCHMORPHN',
        'FSPIOP-Destination: ' . $source,
        'Date: ' . gmdate('D, d M Y H:i:s \G\M\T')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        safeLog("TRANSFERS PUT FAILED", ['error' => $error]);
    } else {
        safeLog("TRANSFERS PUT SUCCESS", ['httpCode' => $httpCode, 'response' => $response]);
    }
    
} else {
    safeLog("UNHANDLED CALLBACK", ['method' => $method, 'path' => $originalPath]);
}

// Return empty response as per Mojaloop spec
echo '';
