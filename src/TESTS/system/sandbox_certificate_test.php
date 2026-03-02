<?php
declare(strict_types=1);

/**
 * UPDATED SANDBOX CERTIFICATION TEST - WITH FIXED SYNTAX
 */

require_once __DIR__ . '/../../bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\SwapService;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// 1. Setup Environment
if (!defined('SYSTEM_COUNTRY')) define('SYSTEM_COUNTRY', 'BW');
$countryConfig = require __DIR__ . '/../../CORE_CONFIG/load_country.php';

echo "==== SANDBOX CERTIFICATION TEST START ====\n";

// 2. Test connection and permissions first
echo "-- Testing Database Connection --\n";
$testResults = DBConnection::testConnection();
print_r($testResults);

if (!$testResults['connection']) {
    die("❌ Cannot connect to database\n");
}

$pdo = DBConnection::getConnection();

// 3. Ensure we're using the correct schema
$pdo->exec("SET search_path TO public");

// 4. Check if tables exist
echo "\n-- Verifying Schema --\n";
$requiredTables = ['roles', 'users', 'participants', 'ledger_accounts', 'swap_requests', 'message_outbox'];
$missingTables = [];

foreach ($requiredTables as $table) {
    $check = $pdo->query("SELECT to_regclass('public.$table')")->fetchColumn();
    if (!$check) {
        $missingTables[] = $table;
    } else {
        echo "✔ Table exists: $table\n";
    }
}

if (!empty($missingTables)) {
    echo "❌ Missing tables: " . implode(', ', $missingTables) . "\n";
    echo "Please run your schema rebuild script first.\n";
    exit(1);
}

// 5. Seed data
echo "\n-- Seeding Sandbox Participants --\n";

try {
    $pdo->beginTransaction();

    // Insert test participants
    $participants = ['BANK_A', 'BANK_B', 'BANK_C', 'BANK_D'];
    foreach ($participants as $p) {
        // Check if participant exists - FIXED: Properly execute and fetch
        $stmt = $pdo->prepare("SELECT 1 FROM participants WHERE name = ?");
        $stmt->execute([$p]);
        $exists = $stmt->fetchColumn(); // Use fetchColumn() instead of fetch()
        
        if (!$exists) {
            // Insert participant
            $stmt = $pdo->prepare("
                INSERT INTO participants (name, provider_code, type, status, settlement_type) 
                VALUES (?, ?, 'BANK', 'ACTIVE', 'GROSS')
            ");
            $stmt->execute([$p, $p]);
            echo "✔ Created participant: $p\n";
            
            // Create settlement account
            $accountCode = $p . "_SETTLEMENT";
            $stmt = $pdo->prepare("SELECT 1 FROM ledger_accounts WHERE account_code = ?");
            $stmt->execute([$accountCode]);
            $accExists = $stmt->fetchColumn();
            
            if (!$accExists) {
                $stmt = $pdo->prepare("
                    INSERT INTO ledger_accounts (account_code, account_name, account_type, balance, currency_code) 
                    VALUES (?, ?, 'settlement', 1000000, 'BWP')
                ");
                $stmt->execute([$accountCode, $p . " Settlement Account"]);
                echo "✔ Created ledger account: $accountCode\n";
            }
        } else {
            echo "✔ Participant already exists: $p\n";
        }
    }

    // Also create a test user if needed
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
    $stmt->execute(['test@vouchmorph.com']);
    $userExists = $stmt->fetchColumn();
    
    if (!$userExists) {
        // Get default role ID
        $roleStmt = $pdo->query("SELECT role_id FROM roles WHERE role_name = 'user'");
        $roleId = $roleStmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, phone, password_hash, role_id, verified, kyc_verified) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'test_user', 
            'test@vouchmorph.com', 
            '26771000000', 
            password_hash('password123', PASSWORD_DEFAULT),
            $roleId ?: 1,
            true, 
            true
        ]);
        echo "✔ Created test user\n";
    }

    $pdo->commit();
    echo "✔ Seeding Complete.\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Seeding Failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 6. Initialize Swap Service
echo "\n-- Initializing Swap Service --\n";
try {
    $swapService = new SwapService(
        $pdo,
        $countryConfig,
        SYSTEM_COUNTRY,
        'test-encryption-key',
        $countryConfig['channel'] ?? []
    );
    echo "✔ Swap Service initialized successfully\n";
} catch (Exception $e) {
    echo "❌ Failed to initialize Swap Service: " . $e->getMessage() . "\n";
    exit(1);
}

// ==================================================
// TEST 1: SINGLE CLIENT SWAP (DEPOSIT)
// ==================================================
echo "\n-- Testing Single Client Swap (Bank A to Bank B) --\n";

try {
    $payload = [
        'swap_type' => 'CLIENT_TO_CLIENT',
        'currency'  => 'BWP',
        'origin'    => [
            'institution' => 'BANK_A',
            'asset_type'  => 'EWALLET'
        ],
        'legs' => [[
            'destination_institution' => 'BANK_B',
            'destination_asset_type'  => 'EWALLET',
            'amount'        => 500.00,
            'delivery_mode' => 'deposit',
            'fee_mode'      => 'deduct'
        ]]
    ];

    $result = $swapService->executeSwap($payload);
    echo "✔ Single swap executed successfully\n";
    print_r($result);
    
} catch (Exception $e) {
    echo "❌ Single swap failed: " . $e->getMessage() . "\n";
}

// ==================================================
// TEST 2: MULTI-LEG BATCH (CASHOUT)
// ==================================================
echo "\n-- Testing Multi-Leg Business Swap (Cashout Logic) --\n";

try {
    $multiPayload = [
        'swap_type' => 'BUSINESS_BATCH',
        'currency'  => 'BWP',
        'origin'    => [
            'institution' => 'BANK_A',
            'asset_type'  => 'EWALLET'
        ],
        'legs' => [
            [
                'destination_institution' => 'BANK_C',
                'destination_asset_type'  => 'EWALLET',
                'amount'        => 1000.00,
                'delivery_mode' => 'deposit',
                'fee_mode'      => 'deduct'
            ],
            [
                'destination_institution' => 'BANK_D',
                'destination_asset_type'  => 'voucher',
                'amount'        => 250.00,
                'delivery_mode' => 'cashout',
                'fee_mode'      => 'separate'
            ]
        ]
    ];

    $resultMulti = $swapService->executeSwap($multiPayload);
    echo "✔ Multi-leg swap executed successfully\n";
    print_r($resultMulti);
    
} catch (Exception $e) {
    echo "❌ Multi-leg swap failed: " . $e->getMessage() . "\n";
}

// ==================================================
// 7. FINAL AUDIT CHECK
// ==================================================
echo "\n-- Final Audit Check --\n";

try {
    $audit = [
        'Swap Requests' => "SELECT COUNT(*) FROM swap_requests",
        'Ledger Entries' => "SELECT COUNT(*) FROM ledger_entries",
        'Outbox Messages' => "SELECT COUNT(*) FROM message_outbox",
        'Settlement Messages' => "SELECT COUNT(*) FROM settlement_messages"
    ];

    foreach ($audit as $label => $sql) {
        try {
            $count = $pdo->query($sql)->fetchColumn();
            echo "$label: $count\n";
        } catch (Exception $e) {
            echo "⚠ Could not query $label: " . $e->getMessage() . "\n";
        }
    }

    echo "\n✅ CERTIFICATION TEST COMPLETED\n";

} catch (Exception $e) {
    echo "❌ Verification Error: " . $e->getMessage() . "\n";
}

echo "\n==== SANDBOX CERTIFICATION TEST COMPLETE ====\n";
