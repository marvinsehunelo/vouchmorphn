<?php
// GET /api/v1/cards/transactions?card_number=4111111111111234&limit=10

require_once __DIR__ . '/../../../../src/bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

$cardNumber = $_GET['card_number'] ?? '';
$limit = (int)($_GET['limit'] ?? 10);

if (!$cardNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'card_number required']);
    exit();
}

try {
    $cardService = new CardService($db, $countryCode, $config);
    $result = $cardService->getCardTransactions($cardNumber, $limit);
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
