<?php
declare(strict_types=1);
use BUSINESS_LOGIC_LAYER\services\SwapService;

require __DIR__ . '/bootstrap.php';
$swapService = $GLOBALS['swapService'];

// 1. FORCED CONFIG INJECTION (Fixes the MNC/MNO naming error)
// This ensures that when the system looks for TEST_MNC_A, it uses the MNO config
if (isset($GLOBALS['participants']['test_mno_a'])) {
    $GLOBALS['participants']['TEST_MNC_A'] = $GLOBALS['participants']['test_mno_a'];
    $GLOBALS['participants']['test_mnc_a'] = $GLOBALS['participants']['test_mno_a'];
}

// 2. THE "KITCHEN SINK" SOURCE (Sending everything at once)
$sourceData = [
    'institution' => 'saccussalis',
    'asset_type'  => 'E-WALLET', // Primary key the Service looks for
    'amount'      => 10.00,
    // Provide both nested styles
    'ewallet' => [
        'ewallet_phone' => '71123456',
        'phone' => '71123456'
    ],
    'wallet' => [
        'wallet_phone' => '71123456',
        'phone' => '71123456'
    ],
    // Provide flat styles
    'phone' => '71123456',
    'account_number' => '71123456'
];

$payload = [
    'currency' => 'BWP',
    'source' => $sourceData,
    'destination' => [
        'institution' => 'zurubank',
        'asset_type' => 'ACCOUNT',
        'amount' => 10.00,
        'delivery_mode' => 'deposit',
        'account' => ['account_number' => '1010012345678']
    ],
    'reference' => 'KITCHEN_SINK_' . time()
];

// 3. RUN TEST
try {
    echo "Testing Saccussalis with Kitchen Sink Payload...\n";
    $result = $swapService->executeSwap($payload);
    echo json_encode(['saccussalis_result' => $result], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

// 4. TEST MNO WITH FORCED MAPPING
try {
    echo "\nTesting MNO with Forced Mapping...\n";
    $mnoPayload = $payload;
    $mnoPayload['source']['institution'] = 'test_mno_a';
    $mnoPayload['source']['asset_type'] = 'WALLET';
    $resultMno = $swapService->executeSwap($mnoPayload);
    echo json_encode(['mno_result' => $resultMno], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode(['mno_error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
