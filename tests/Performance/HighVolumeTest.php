<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

/**
 * WIPE ALL TEST DATA - Use with caution!
 */

require_once __DIR__ . '/../../bootstrap.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

echo "\n" . str_repeat("⚠️", 60) . "\n";
echo "⚠️  DANGER: This will DELETE ALL test messages!\n";
echo str_repeat("⚠️", 60) . "\n\n";

$pdo = DBConnection::getConnection();

// Show current counts
echo "📊 CURRENT TABLE COUNTS:\n";
$tables = [
    'message_outbox',
    'settlement_messages',
    'swap_ledgers',
    'swap_requests',
    'settlement_queue',
    'net_positions'
];

$before = [];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        $before[$table] = $count;
        echo "  $table: " . number_format($count) . "\n";
    } catch (Exception $e) {
        echo "  $table: Error - " . $e->getMessage() . "\n";
        $before[$table] = 0;
    }
}

echo "\n";

// Confirm deletion
echo "Type 'DELETE ALL' to confirm: ";
$handle = fopen("php://stdin", "r");
$confirm = trim(fgets($handle));

if ($confirm !== 'DELETE ALL') {
    die("❌ Cleanup cancelled\n");
}

// Perform deletion
try {
    $pdo->beginTransaction();
    
    echo "\n🧹 Wiping test data...\n";
    $totalDeleted = 0;
    
    // Delete patterns for test messages
    $patterns = ['WARMUP_%', 'STRESS_TEST_%', 'test_%'];
    
    foreach ($patterns as $pattern) {
        // Message outbox
        $stmt = $pdo->prepare("DELETE FROM message_outbox WHERE message_id LIKE ?");
        $stmt->execute([$pattern]);
        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;
        echo "  ✅ Deleted $deleted from message_outbox ($pattern)\n";
        
        // Settlement messages
        $stmt = $pdo->prepare("DELETE FROM settlement_messages WHERE transaction_id LIKE ?");
        $stmt->execute([$pattern]);
        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;
        echo "  ✅ Deleted $deleted from settlement_messages ($pattern)\n";
        
        // Swap ledgers
        $stmt = $pdo->prepare("DELETE FROM swap_ledgers WHERE swap_reference LIKE ?");
        $stmt->execute([$pattern]);
        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;
        echo "  ✅ Deleted $deleted from swap_ledgers ($pattern)\n";
    }
    
    // Delete test settlements from queue
    $stmt = $pdo->prepare("DELETE FROM settlement_queue WHERE debtor LIKE 'TEST_%' OR creditor LIKE 'TEST_%'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  ✅ Deleted $deleted from settlement_queue\n";
    
    // Delete test net positions
    $stmt = $pdo->prepare("DELETE FROM net_positions WHERE debtor LIKE 'TEST_%' OR creditor LIKE 'TEST_%'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  ✅ Deleted $deleted from net_positions\n";
    
    // Delete test swap requests
    $stmt = $pdo->prepare("
        DELETE FROM swap_requests 
        WHERE metadata->>'source' IN ('WARMUP', 'STRESS_TEST')
        OR swap_uuid::text LIKE 'test_%'
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "  ✅ Deleted $deleted from swap_requests\n";
    
    $pdo->commit();
    
    echo "\n📊 FINAL COUNTS:\n";
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        $removed = $before[$table] - $count;
        echo "  $table: " . number_format($count) . " (removed " . number_format($removed) . ")\n";
    }
    
    echo "\n✅ TOTAL DELETED: " . number_format($totalDeleted) . " messages\n";
    echo "✅ Database cleaned successfully!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
