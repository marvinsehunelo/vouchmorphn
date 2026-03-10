<?php
// GET /api/v1/cards/balance?card_number=4111111111111234

require_once __DIR__ . '/../../../../src/bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

$cardNumber = $_GET['card_number'] ?? '';

if (!$cardNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'card_number required']);
    exit();
}

try {
    $cardService = new CardService($db, $countryCode, $config);
    $result = $cardService->getCardBalance($cardNumber);
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
