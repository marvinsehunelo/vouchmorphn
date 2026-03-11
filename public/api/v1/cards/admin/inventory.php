<?php
declare(strict_types=1);

/**
 * VouchMorph - Admin: Card Inventory View
 */

define('ROOT_PATH', dirname(__DIR__, 6));

// ... (same bootstrapping with admin auth)

try {
    $pdo = DBConnection::getConnection();
    
    // Get batches summary
    $batches = $pdo->query("
        SELECT 
            batch_id,
            batch_reference,
            bin_prefix,
            card_scheme,
            quantity_produced,
            quantity_remaining,
            expiry_month || '/' || expiry_year as expiry,
            status,
            received_at
        FROM card_batches
        WHERE card_type = 'PHYSICAL'
        ORDER BY received_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cards by status
    $cardsByStatus = $pdo->query("
        SELECT 
            lifecycle_status,
            COUNT(*) as count
        FROM message_cards
        WHERE card_category = 'PHYSICAL'
        GROUP BY lifecycle_status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent assignments
    $recentAssignments = $pdo->query("
        SELECT 
            mc.card_suffix,
            mc.cardholder_name,
            mc.lifecycle_status,
            mc.batch_assigned_at,
            u.phone
        FROM message_cards mc
        JOIN users u ON mc.user_id = u.user_id
        WHERE mc.batch_assigned_at IS NOT NULL
        ORDER BY mc.batch_assigned_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'batches' => $batches,
            'cards_by_status' => $cardsByStatus,
            'recent_assignments' => $recentAssignments,
            'total_inventory' => array_sum(array_column($cardsByStatus, 'count'))
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
