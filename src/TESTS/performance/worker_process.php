<?php
declare(strict_types=1);

/**
 * Simple test for worker process
 * FIXED: Includes all required NOT NULL columns
 */

require_once __DIR__ . '/../../bootstrap.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

$pdo = DBConnection::getConnection();

echo "Testing worker process setup...\n";

// Check message_outbox table structure
$stmt = $pdo->query("
    SELECT column_name, data_type, is_nullable
    FROM information_schema.columns 
    WHERE table_name = 'message_outbox'
    ORDER BY ordinal_position
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n📋 message_outbox table columns:\n";
foreach ($columns as $col) {
    $nullable = $col['is_nullable'] === 'YES' ? 'NULLABLE' : 'NOT NULL';
    echo "  - {$col['column_name']}: {$col['data_type']} ($nullable)\n";
}

// Insert a test message with ALL required fields
$messageId = uniqid('test_');
$channel = 'TEST_CHANNEL';
$destination = 'TEST_WORKER';
$payload = json_encode([
    'type' => 'TEST',
    'message' => 'Test message for worker',
    'timestamp' => date('c')
]);

try {
    $stmt = $pdo->prepare("
        INSERT INTO message_outbox 
        (message_id, channel, destination, payload, status, attempts, created_at)
        VALUES (?, ?, ?, ?, 'PENDING', 0, NOW())
    ");
    $stmt->execute([$messageId, $channel, $destination, $payload]);
    
    echo "\n✅ Test message inserted: $messageId\n";
    echo "   Channel: $channel\n";
    echo "   Destination: $destination\n";
    
} catch (PDOException $e) {
    echo "\n❌ Failed to insert test message: " . $e->getMessage() . "\n";
    
    // Show the required columns
    echo "\nRequired columns for message_outbox:\n";
    $reqStmt = $pdo->query("
        SELECT column_name, data_type
        FROM information_schema.columns 
        WHERE table_name = 'message_outbox' 
        AND is_nullable = 'NO'
        AND column_name NOT IN ('id', 'created_at') -- auto-generated
        ORDER BY ordinal_position
    ");
    $required = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($required as $col) {
        echo "  - {$col['column_name']} ({$col['data_type']})\n";
    }
}

// Check settlement_queue table
echo "\n🔍 Checking settlement_queue table...\n";
$stmt = $pdo->query("
    SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_name = 'settlement_queue'
    )
");
$hasQueue = $stmt->fetchColumn();

if ($hasQueue) {
    echo "✅ settlement_queue table exists\n";
    
    // Check settlement_queue columns
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable
        FROM information_schema.columns 
        WHERE table_name = 'settlement_queue'
        ORDER BY ordinal_position
    ");
    $queueColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📋 settlement_queue columns:\n";
    foreach ($queueColumns as $col) {
        $nullable = $col['is_nullable'] === 'YES' ? 'NULLABLE' : 'NOT NULL';
        echo "  - {$col['column_name']}: {$col['data_type']} ($nullable)\n";
    }
    
    // Insert test settlement
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settlement_queue (debtor, creditor, amount, created_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (debtor, creditor) DO NOTHING
        ");
        $stmt->execute(['TEST_BANK_A', 'TEST_BANK_B', 1000.00]);
        echo "\n✅ Test settlement inserted: TEST_BANK_A → TEST_BANK_B = BWP 1,000.00\n";
        
    } catch (PDOException $e) {
        echo "\n❌ Failed to insert test settlement: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "⚠️ settlement_queue table does not exist\n";
}

// Show current queue status
echo "\n📊 CURRENT QUEUE STATUS:\n";

// Message outbox count
$stmt = $pdo->query("SELECT COUNT(*) FROM message_outbox WHERE status = 'PENDING'");
$pending = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM message_outbox");
$total = $stmt->fetchColumn();
echo "  Message Outbox: $total total ($pending pending)\n";

// Settlement queue count
if ($hasQueue) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM settlement_queue");
    $queueCount = $stmt->fetchColumn();
    echo "  Settlement Queue: $queueCount items\n";
}

// Net positions
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM net_positions WHERE amount > 0");
    $netCount = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM net_positions WHERE amount > 0");
    $netTotal = $stmt->fetchColumn();
    echo "  Net Positions: $netCount positions, Total: BWP " . number_format((float)$netTotal, 2) . "\n";
} catch (PDOException $e) {
    echo "  Net Positions: Table not found\n";
}

// Settlement messages
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM settlement_messages WHERE status = 'PENDING'");
    $settlementPending = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM settlement_messages");
    $settlementTotal = $stmt->fetchColumn();
    echo "  Settlement Messages: $settlementTotal total ($settlementPending pending)\n";
} catch (PDOException $e) {
    echo "  Settlement Messages: Table not found\n";
}

echo "\n" . str_repeat("-", 50) . "\n";
echo "✅ Worker process test complete!\n";
echo str_repeat("-", 50) . "\n\n";

echo "Now you can run the worker process:\n";
echo "  php src/TESTS/performance/worker_process.php 0\n\n";
echo "To monitor the worker log:\n";
echo "  tail -f /tmp/vouchmorphn_worker_0.log\n";
