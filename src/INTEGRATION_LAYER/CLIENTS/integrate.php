<?php
// /opt/lampp/htdocs/vouchmorphn/test_final.php
$apiKey = 'test_key_123';
$baseUrl = 'http://localhost/zurubank/Backend';

echo "🔍 TESTING ZURUBANK API INTEGRATION\n";
echo "====================================\n\n";

// Test 1: Verify Account
echo "1. VERIFY ACCOUNT\n";
echo "-----------------\n";
$ch = curl_init($baseUrl . '/api/v1/verify_account.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-API-KEY: $apiKey"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'account_number' => '1234567890',
    'bank_code' => 'ZURUBWXX'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Generate Voucher Code
echo "2. GENERATE VOUCHER CODE\n";
echo "------------------------\n";
$ch = curl_init($baseUrl . '/api/v1/atm/generate_code.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-API-KEY: $apiKey"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'amount' => 500,
    'account_number' => '1234567890'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// Test 3: Direct Deposit
echo "3. DIRECT DEPOSIT\n";
echo "-----------------\n";
$idempotencyKey = 'IDEMP' . time();
$ch = curl_init($baseUrl . '/api/v1/deposit/direct.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-API-KEY: $apiKey",
    "X-Idempotency-Key: $idempotencyKey"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'depositRef' => 'DEP' . time(),
    'amount' => 1000,
    'account_number' => '1234567890',
    'request_id' => $idempotencyKey
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// Test 4: Notify Debit
echo "4. NOTIFY DEBIT\n";
echo "---------------\n";
$idempotencyKey = 'IDEMP' . (time() + 1);
$ch = curl_init($baseUrl . '/api/v1/settlement/notify_debit.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-API-KEY: $apiKey",
    "X-Idempotency-Key: $idempotencyKey"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'debitRef' => 'DEB' . time(),
    'amount' => 250,
    'account_number' => '1234567890',
    'request_id' => $idempotencyKey
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Transaction Status
echo "5. TRANSACTION STATUS\n";
echo "---------------------\n";
$ch = curl_init($baseUrl . '/api/v1/transaction/status.php?trace=' . urlencode('DEP' . time()));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "X-API-KEY: $apiKey"
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: " . json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";
