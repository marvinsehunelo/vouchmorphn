<?php
// POST /api/v1/swap/cancel
// {
//     "swap_reference": "6ba6bb1970b0b53f297ade7c86715966",
//     "reason": "User changed mind"
// }

require_once '../../../bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\SwapService;

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['swap_reference'])) {
    http_response_code(400);
    echo json_encode(['error' => 'swap_reference required']);
    exit;
}

$swapService = new SwapService($db, $settings, $countryCode, $encryptionKey, $config);

$result = $swapService->cancelSwap(
    $input['swap_reference'],
    $input['reason'] ?? 'User requested cancellation',
    $_SESSION['user_id'] ?? null
);

header('Content-Type: application/json');
echo json_encode($result);
