<?php
// POST /api/v1/cards/block
// { "card_number": "4111111111111234", "reason": "Lost card" }

require_once __DIR__ . '/../../../../src/bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['card_number'])) {
    http_response_code(400);
    echo json_encode(['error' => 'card_number required']);
    exit();
}

try {
    $cardService = new CardService($db, $countryCode, $config);
    $result = $cardService->blockCard(
        $input['card_number'],
        $input['reason'] ?? 'User requested'
    );
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
