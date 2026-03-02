<?php
/**
 * Authentication Security Test
 * For passwordless phone login
 */

date_default_timezone_set('Africa/Gaborone');
$validPhone   = '+26770000000';
$invalidPhone = '+26779999999';

// Endpoint of login page
$endpoint = "http://localhost/prestagedSWAP/APP_LAYER/views/login.php";

// ------------------------------------------------------------
// Helper: Send POST request
// ------------------------------------------------------------
function postLogin($phone) {
    global $endpoint;
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['phone' => $phone]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // capture headers
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // check redirect
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['httpCode'=>$httpCode, 'response'=>$response];
}

// ------------------------------------------------------------
// Test 1: Valid login
// ------------------------------------------------------------
$res = postLogin($validPhone);
if (strpos($res['response'], 'Location:') !== false || $res['httpCode']==302) {
    echo "[✔] Valid login succeeded\n";
} else {
    echo "[✘] Valid login FAILED! Code: {$res['httpCode']}\n";
}

// ------------------------------------------------------------
// Test 2: Invalid login
// ------------------------------------------------------------
$res = postLogin($invalidPhone);
if ($res['httpCode']===200 && strpos($res['response'], 'Number not registered')!==false) {
    echo "[✔] Invalid login rejected\n";
} else {
    echo "[✘] Invalid login not rejected! Code: {$res['httpCode']}\n";
}

// ------------------------------------------------------------
// Test 3: Missing phone
// ------------------------------------------------------------
$res = postLogin('');
if ($res['httpCode']===200 && strpos($res['response'], 'Please enter your phone')!==false) {
    echo "[✔] Missing-field correctly rejected\n";
} else {
    echo "[✘] Missing-field not blocked! Code: {$res['httpCode']}\n";
}

// ------------------------------------------------------------
// Test 4: GET request should fail (login requires POST)
// ------------------------------------------------------------
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
$resGET = curl_exec($ch);
$httpCodeGET = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCodeGET===200 && strpos($resGET, 'Enter your registered phone')!==false) {
    echo "[✔] GET request blocked (no login performed)\n";
} else {
    echo "[✘] GET request should be rejected! Code: {$httpCodeGET}\n";
}

echo "=== Authentication Tests Completed ===\n";

