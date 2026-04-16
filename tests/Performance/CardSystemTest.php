<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Test script for the card system
// Run from command line: php tests/test_card_system.php

require_once __DIR__ . '/../../bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

echo "🧪 TESTING MESSAGE CARD SYSTEM\n";
echo "==============================\n\n";

$cardService = new CardService($db, 'BW', $config);

// 1. Create a test hold (you'd normally have this from a swap)
echo "1. Creating test hold...\n";
$holdRef = 'HOLD-TEST-' . date('YmdHis');

// Insert a test hold
$db->prepare("
    INSERT INTO hold_transactions (
        hold_reference, swap_reference, asset_type, amount, 
        currency, status, hold_expiry, source_institution
    ) VALUES (?, ?, 'E-WALLET', 5000, 'BWP', 'ACTIVE', NOW() + interval '24 hours', 'SACCUSSALIS')
")->execute([$holdRef, 'SWAP-TEST']);

echo "   Hold created: $holdRef with 5000 BWP\n\n";

// 2. Issue a card
echo "2. Issuing card from hold...\n";
$cardResult = $cardService->issueCard([
    'hold_reference' => $holdRef,
    'cardholder_name' => 'Test Student',
    'initial_amount' => 3000,
    'purpose' => 'student',
    'student_id' => 'STU-TEST-001'
]);

if (!$cardResult['success']) {
    die("Failed to issue card: " . ($cardResult['error'] ?? 'Unknown error'));
}

echo "   Card issued successfully!\n";
echo "   Card Number: {$cardResult['card_number']}\n";
echo "   Expiry: {$cardResult['expiry']}\n";
echo "   CVV: {$cardResult['cvv']}\n";
echo "   Balance: {$cardResult['remaining_amount']} {$cardResult['currency']}\n\n";

// 3. Check balance
echo "3. Checking card balance...\n";
$balanceResult = $cardService->getCardBalance($cardResult['card_number']);
echo "   Balance: {$balanceResult['balance']} {$balanceResult['currency']}\n\n";

// 4. Simulate ATM withdrawal
echo "4. Simulating ATM withdrawal of 500 BWP...\n";
$authResult = $cardService->authorizeTransaction([
    'card_number' => $cardResult['card_number'],
    'cvv' => $cardResult['cvv'],
    'amount' => 500,
    'channel' => 'ATM',
    'atm_id' => 'ATM001',
    'acquirer' => 'ZURUBANK'
]);

if ($authResult['authorized']) {
    echo "   ✅ Approved! Auth Code: {$authResult['auth_code']}\n";
    echo "   Remaining balance: {$authResult['remaining_balance']} BWP\n";
} else {
    echo "   ❌ Declined: {$authResult['error']}\n";
}

// 5. Simulate POS purchase
echo "\n5. Simulating POS purchase of 1200 BWP...\n";
$authResult2 = $cardService->authorizeTransaction([
    'card_number' => $cardResult['card_number'],
    'cvv' => $cardResult['cvv'],
    'amount' => 1200,
    'channel' => 'POS',
    'merchant_name' => 'Test Store',
    'merchant_id' => 'M12345',
    'terminal_id' => 'T001',
    'acquirer' => 'FNBB'
]);

if ($authResult2['authorized']) {
    echo "   ✅ Approved! Auth Code: {$authResult2['auth_code']}\n";
    echo "   Remaining balance: {$authResult2['remaining_balance']} BWP\n";
} else {
    echo "   ❌ Declined: {$authResult2['error']}\n";
}

// 6. Get transaction history
echo "\n6. Fetching transaction history...\n";
$txResult = $cardService->getCardTransactions($cardResult['card_number'], 5);
echo "   Found {$txResult['count']} transactions:\n";
foreach ($txResult['transactions'] as $tx) {
    echo "   - {$tx['created_at']}: {$tx['transaction_type']} {$tx['amount']} BWP ({$tx['auth_status']})\n";
}

// 7. Final balance
echo "\n7. Final card balance:\n";
$finalBalance = $cardService->getCardBalance($cardResult['card_number']);
echo "   Balance: {$finalBalance['balance']} BWP\n";
echo "   Total spent: {$finalBalance['total_spent']} BWP\n";

echo "\n✅ Test complete!\n";
