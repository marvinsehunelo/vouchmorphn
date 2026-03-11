<?php
declare(strict_types=1);

/**
 * VouchMorph - Admin: Create Card Batch
 * Requires admin authentication
 */

define('ROOT_PATH', dirname(__DIR__, 5));

// ... (same bootstrapping code with admin check)

// Verify admin role (implement your admin auth)
$userId = $this->getUserIdFromToken(); // Your auth logic
$isAdmin = $this->checkUserRole($userId, 'admin');

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$required = ['bin_prefix', 'card_scheme', 'quantity', 'expiry_year', 'expiry_month'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "$field required"]);
        exit();
    }
}

try {
    $pdo = DBConnection::getConnection();
    $pdo->beginTransaction();
    
    $batchRef = 'BATCH-' . date('Ymd') . '-' . strtoupper(uniqid());
    
    // Create batch record
    $batchStmt = $pdo->prepare("
        INSERT INTO card_batches (
            batch_reference, bin_prefix, card_scheme, card_type,
            quantity_produced, quantity_remaining, expiry_year, expiry_month,
            status, received_at, metadata
        ) VALUES (
            :ref, :bin, :scheme, 'PHYSICAL',
            :qty, :qty, :exp_year, :exp_month,
            'INVENTORY', NOW(), :metadata
        ) RETURNING batch_id
    ");
    
    $batchStmt->execute([
        ':ref' => $batchRef,
        ':bin' => $input['bin_prefix'],
        ':scheme' => $input['card_scheme'],
        ':qty' => $input['quantity'],
        ':exp_year' => $input['expiry_year'],
        ':exp_month' => $input['expiry_month'],
        ':metadata' => json_encode($input['metadata'] ?? [])
    ]);
    
    $batchId = $batchStmt->fetchColumn();
    
    // Generate individual cards in the batch
    for ($i = 0; $i < $input['quantity']; $i++) {
        $this->generateBatchCard($pdo, $batchId, $input, $i);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'batch_reference' => $batchRef,
        'cards_generated' => $input['quantity'],
        'message' => 'Card batch created successfully'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
