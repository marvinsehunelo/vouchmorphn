<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use DFSP_ADAPTER_LAYER\MojaloopHttpClient;

echo "Testing MojaloopHttpClient...\n";

// Test with localhost first
$config = [
    'scheme' => 'http',
    'host' => 'localhost',
    'port' => 4040,
    'fspid' => 'VOUCHMORPHN'
];

echo "Config: " . json_encode($config) . "\n";

try {
    $client = new MojaloopHttpClient($config);
    echo "Client created successfully\n";
    
    // Try to send a test callback
    $client->putParties('MSISDN', 'ALPHA', [
        'party' => [
            'partyIdInfo' => [
                'partyIdType' => 'MSISDN',
                'partyIdentifier' => 'ALPHA',
                'fspId' => 'VOUCHMORPHN'
            ],
            'name' => 'Alpha User'
        ]
    ]);
    
    echo "Callback sent\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
