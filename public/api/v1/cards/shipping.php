<?php
declare(strict_types=1);

/**
 * VouchMorph - Card Shipping Tracking API
 */

define('ROOT_PATH', dirname(__DIR__, 5));

// ... (same bootstrapping code)

$cardId = $_GET['id'] ?? $_GET['card_suffix'] ?? '';
if (!$cardId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Card ID or suffix required']);
    exit();
}

try {
    $pdo = DBConnection::getConnection();
    
    $sql = is_numeric($cardId) 
        ? "SELECT * FROM message_cards WHERE card_id = ?"
        : "SELECT * FROM message_cards WHERE card_suffix = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card || $card['card_category'] !== 'PHYSICAL') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Physical card not found']);
        exit();
    }
    
    $response = [
        'success' => true,
        'card_suffix' => $card['card_suffix'],
        'status' => $card['lifecycle_status'],
        'delivery_status' => $card['delivery_status'],
        'tracking_number' => $card['tracking_number'],
        'estimated_delivery' => $this->calculateEstimatedDelivery($card)
    ];
    
    if ($card['delivered_at']) {
        $response['delivered_at'] = $card['delivered_at'];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
