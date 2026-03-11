<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Correlation-ID');

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../BUSINESS_LOGIC_LAYER/services/CardService.php';

use BUSINESS_LOGIC_LAYER\services\CardService;
use RuntimeException;

try {
    // Verify API key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey !== 'sys_key_2026_sandbox_001') {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Initialize CardService
    $db = getDatabaseConnection(); // Your DB connection function
    $cardService = new CardService($db, 'BWP', []);
    
    // Extract verification data
    $assetType = $input['asset_type'] ?? '';
    $amount = (float)($input['amount'] ?? 0);
    $reference = $input['reference'] ?? '';
    
    // Handle different verification types
    if ($assetType === 'CARD') {
        // Verifying a card authorization (message-based)
        $cardSuffix = $input['card']['card_suffix'] ?? 
                     $input['card_suffix'] ?? 
                     $input['card_number'] ?? null;
        
        if (!$cardSuffix) {
            throw new RuntimeException("Card identifier required");
        }
        
        // Get card authorization (the message)
        $cardInfo = $cardService->getCardAuthorization($cardSuffix);
        
        // Check if enough authorized amount remains
        if ($cardInfo['remaining_balance'] < $amount) {
            echo json_encode([
                'verified' => false,
                'message' => 'Insufficient authorization on card',
                'available' => $cardInfo['remaining_balance'],
                'requested' => $amount
            ]);
            exit;
        }
        
        // Return verification of the MESSAGE, not real money
        echo json_encode([
            'verified' => true,
            'asset_id' => $cardInfo['authorization_id'],
            'asset_type' => 'CARD_AUTHORIZATION',
            'available_balance' => $cardInfo['remaining_balance'],
            'holder_name' => $cardInfo['cardholder_name'],
            'hold_reference' => $cardInfo['hold_reference'],
            'is_message' => true, // Explicitly mark as message-based
            'metadata' => [
                'card_suffix' => $cardSuffix,
                'authorized_amount' => $cardInfo['authorized_amount'],
                'expiry' => $cardInfo['expiry']
            ]
        ]);
        
    } elseif ($assetType === 'E-WALLET' || $assetType === 'ACCOUNT') {
        // Regular asset verification (real money)
        // ... existing verification logic ...
        
    } else {
        throw new RuntimeException("Unsupported asset type: {$assetType}");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'verified' => false,
        'error' => $e->getMessage()
    ]);
}
