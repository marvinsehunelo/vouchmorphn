<?php
// quick_test.php - Updated to test fee collection

// Option 1: Use the full namespace path
require_once 'src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

// Import the class with its namespace
use BUSINESS_LOGIC_LAYER\services\SwapService;

$pdo = new PDO("pgsql:host=localhost;dbname=swap_system_bw", "postgres", "StrongPassword!");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$settings = [];
$country = "BW";
$encryptionKey = "test-key";

// You need to load your participants config
// This should match what your application normally uses
$config = [
    'participants' => [
        'zurubank' => [
            'name' => 'ZURUBANK',
            'provider_code' => 'ZURUBWXX',
            'base_url' => 'http://localhost/zurubank/Backend',
            'capabilities' => [
                'wallet_types' => ['VOUCHER']
            ],
            'resource_endpoints' => [
                'verify_asset' => '/api/v1/verify_asset.php',
                'place_hold' => '/api/v1/hold.php',
                'debit_funds' => '/api/v1/settlement/notify_debit.php'
            ]
        ],
        'saccussalis' => [
            'name' => 'SACCUSSALIS',
            'provider_code' => 'SACCUSBWXX',
            'base_url' => 'http://localhost/SaccusSalisbank/backend',
            'capabilities' => [
                'wallet_types' => ['E-WALLET', 'ATM']
            ],
            'resource_endpoints' => [
                'verify_asset' => '/api/v1/verify_asset.php',
                'place_hold' => '/api/v1/hold.php',
                'generate_token' => '/api/v1/atm/generate_code.php'
            ]
        ]
    ]
];

try {
    $service = new SwapService($pdo, $settings, $country, $encryptionKey, $config);
    echo "✅ SwapService initialized successfully\n\n";

    // Test payload
    $payload = [
        'source' => [
            'institution' => 'ZURUBANK',
            'asset_type' => 'VOUCHER',
            'amount' => 1500.00,
            'voucher' => [
                'voucher_number' => '458063031195',
                'voucher_pin' => '377975',
                'claimant_phone' => '+26770000000'
            ]
        ],
        'destination' => [
            'institution' => 'SACCUSSALIS',
                    'amount' => 1500.00,  // <-- ADD THIS
            'delivery_mode' => 'cashout',
            'cashout' => [
                'beneficiary_phone' => '+26770000000'
            ]
        ],
        'currency' => 'BWP'
    ];

    echo "Executing swap with payload:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

    $result = $service->executeSwap($payload);
    
    echo "Result:\n";
    print_r($result);
    
    // If successful, check the logged data including fees
    if ($result['status'] === 'success') {
        $swapRef = $result['swap_reference'];
        
        echo "\n✅ Swap successful!\n";
        echo "Swap Reference: " . $swapRef . "\n";
        echo "Hold Reference: " . ($result['hold_reference'] ?? 'N/A') . "\n";
        
        // 1. Check API logs
        $stmt = $pdo->prepare("SELECT * FROM api_message_logs WHERE message_id = ? ORDER BY created_at");
        $stmt->execute([$swapRef]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n📋 API Logs recorded: " . count($logs) . "\n";
        foreach ($logs as $i => $log) {
            echo "  " . ($i+1) . ". " . $log['message_type'] . " - " . ($log['success'] ? '✅' : '❌') . "\n";
        }
        
        // 2. Check hold transactions
        $stmt = $pdo->prepare("SELECT * FROM hold_transactions WHERE swap_reference = ?");
        $stmt->execute([$swapRef]);
        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n💰 Hold transactions recorded: " . count($holds) . "\n";
        foreach ($holds as $hold) {
            echo "  Hold Ref: " . $hold['hold_reference'] . " - Amount: " . $hold['amount'] . " " . $hold['currency'] . " - Status: " . $hold['status'] . "\n";
        }
        
        // 3. 🔥 CHECK FEE COLLECTIONS (NEW)
        $stmt = $pdo->prepare("SELECT * FROM swap_fee_collections WHERE swap_reference = ?");
        $stmt->execute([$swapRef]);
        $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n💰💰 FEE COLLECTIONS: " . count($fees) . "\n";
        foreach ($fees as $fee) {
            echo "  Fee ID: " . $fee['fee_id'] . "\n";
            echo "  Type: " . $fee['fee_type'] . "\n";
            echo "  Total Fee: " . $fee['total_amount'] . " " . $fee['currency'] . "\n";
            echo "  Source: " . $fee['source_institution'] . "\n";
            echo "  Destination: " . $fee['destination_institution'] . "\n";
            echo "  Split Config: " . json_encode(json_decode($fee['split_config']), JSON_PRETTY_PRINT) . "\n";
            echo "  VAT: " . $fee['vat_amount'] . "\n";
            echo "  Status: " . $fee['status'] . "\n";
        }
        
        // 4. Check swap_ledgers for fee column
        $stmt = $pdo->prepare("SELECT * FROM swap_ledgers WHERE swap_reference = ?");
        $stmt->execute([$swapRef]);
        $ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n📒 Swap Ledger entries: " . count($ledgers) . "\n";
        foreach ($ledgers as $ledger) {
            echo "  From: " . $ledger['from_institution'] . " To: " . $ledger['to_institution'] . "\n";
            echo "  Amount: " . $ledger['amount'] . " " . $ledger['currency_code'] . "\n";
            echo "  Fee Deducted: " . ($ledger['swap_fee'] ?? 0) . "\n";
        }
        
        // 5. Check settlement messages for fee info in metadata
        $stmt = $pdo->prepare("SELECT * FROM settlement_messages WHERE transaction_id = ?");
        $stmt->execute([$swapRef]);
        $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n📨 Settlement Messages: " . count($settlements) . "\n";
        foreach ($settlements as $settlement) {
            $metadata = json_decode($settlement['metadata'], true);
            echo "  From: " . $settlement['from_participant'] . " To: " . $settlement['to_participant'] . "\n";
            echo "  Amount: " . $settlement['amount'] . "\n";
            if (isset($metadata['fee'])) {
                echo "  Fee Info in Metadata:\n";
                echo "    Fee ID: " . $metadata['fee']['fee_id'] . "\n";
                echo "    Total Fee: " . $metadata['fee']['total_fee'] . "\n";
                echo "    Net Amount: " . $metadata['fee']['net_amount'] . "\n";
                echo "    Split: " . json_encode($metadata['fee']['split']) . "\n";
            }
        }
        
        // 6. Calculate expected vs actual
        $grossAmount = 1500.00;
        $expectedFee = 10.00; // CASHOUT_SWAP_FEE
        $expectedNet = $grossAmount - $expectedFee;
        
        echo "\n📊 FEE CALCULATION CHECK:\n";
        echo "  Gross Amount: " . $grossAmount . " BWP\n";
        echo "  Expected Fee: " . $expectedFee . " BWP (CASHOUT_SWAP_FEE)\n";
        echo "  Expected Net: " . $expectedNet . " BWP\n";
        
        if (!empty($fees)) {
            $actualFee = $fees[0]['total_amount'];
            echo "  Actual Fee: " . $actualFee . " BWP\n";
            echo "  Fee Match: " . ($actualFee == $expectedFee ? "✅ YES" : "❌ NO") . "\n";
        }
        
        // Summary
        echo "\n📈 SUMMARY:\n";
        echo "  API Logs: " . count($logs) . "\n";
        echo "  Hold Transactions: " . count($holds) . "\n";
        echo "  Fee Collections: " . count($fees) . "\n";
        echo "  Swap Ledgers: " . count($ledgers) . "\n";
        echo "  Settlement Messages: " . count($settlements) . "\n";
        
        // Check message_outbox for SMS
        $stmt = $pdo->prepare("SELECT * FROM message_outbox WHERE payload->>'code' IS NOT NULL ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $sms = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sms) {
            echo "\n📱 SMS Queued: Yes (to " . $sms['destination'] . ")\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
