<?php
declare(strict_types=1);

/**
 * VouchMorph - Admin: Mark Card as Shipped
 */

define('ROOT_PATH', dirname(__DIR__, 6));

// ... (same bootstrapping with admin auth)

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['card_id']) && empty($input['card_suffix'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'card_id or card_suffix required']);
    exit();
}

try {
    $pdo = DBConnection::getConnection();
    
    $sql = !empty($input['card_id']) 
        ? "SELECT * FROM message_cards WHERE card_id = ? AND lifecycle_status = 'ASSIGNED'"
        : "SELECT * FROM message_cards WHERE card_suffix = ? AND lifecycle_status = 'ASSIGNED'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$input['card_id'] ?? $input['card_suffix']]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Card not found or not in ASSIGNED state']);
        exit();
    }
    
    // Generate tracking number (mock)
    $tracking = 'VM' . strtoupper(uniqid());
    
    $update = $pdo->prepare("
        UPDATE message_cards 
        SET lifecycle_status = 'SHIPPED',
            delivery_status = 'IN_TRANSIT',
            tracking_number = :tracking,
            shipped_at = NOW(),
            updated_at = NOW()
        WHERE card_id = :card_id
    ");
    
    $update->execute([
        ':tracking' => $tracking,
        ':card_id' => $card['card_id']
    ]);
    
    // Notify user
    $notify = $pdo->prepare("
        INSERT INTO message_outbox 
        (message_id, channel, destination, payload, status, created_at)
        VALUES (?, 'SMS', ?, ?, 'PENDING', NOW())
    ");
    
    $message = "Your VouchMorph card has been shipped! Tracking: {$tracking}";
    $notify->execute([
        'SHIP-' . uniqid(),
        $card['cardholder_phone'] ?? $this->getUserPhone($card['user_id']),
        json_encode(['message' => $message])
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Card marked as shipped',
        'tracking_number' => $tracking
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
