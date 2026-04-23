<?php
// /public/api/get_active_codes.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;

// Start session to get user info
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated', 'codes' => []]);
    exit;
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? null;

if (empty($userPhone) && empty($userId)) {
    echo json_encode(['success' => false, 'error' => 'No user identifier', 'codes' => []]);
    exit;
}

// Load country configuration
$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("GET ACTIVE CODES DB ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error', 'codes' => []]);
    exit;
}

function safeJsonDecode($value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

// Get active cashout vouchers with codes
$userPhonePattern = '%' . $userPhone . '%';
$userIdPattern = '%' . $userId . '%';

$stmt = $db->prepare("
    SELECT sv.voucher_id, sv.swap_id, sv.code_suffix, sv.amount, 
           sv.expiry_at, sv.status, sv.claimant_phone, sv.created_at,
           sr.swap_uuid, sr.destination_details, sr.metadata as swap_metadata
    FROM swap_vouchers sv
    JOIN swap_requests sr ON sv.swap_id = sr.swap_id
    WHERE (CAST(sr.metadata AS TEXT) LIKE :phone_pattern 
       OR CAST(sr.metadata AS TEXT) LIKE :user_pattern)
    AND sv.status = 'ACTIVE'
    AND sv.expiry_at > NOW()
    ORDER BY sv.created_at DESC
");
$stmt->bindValue(':phone_pattern', $userPhonePattern);
$stmt->bindValue(':user_pattern', $userIdPattern);
$stmt->execute();
$activeVouchers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// FIXED: Use @> operator instead of ? to avoid PDO parameter conflict
$stmt = $db->prepare("
    SELECT swap_uuid, metadata, amount, created_at, status
    FROM swap_requests
    WHERE (CAST(metadata AS TEXT) LIKE :phone_pattern 
       OR CAST(metadata AS TEXT) LIKE :user_pattern)
    AND metadata @> '{\"destination_token\": null}'
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->bindValue(':phone_pattern', $userPhonePattern);
$stmt->bindValue(':user_pattern', $userIdPattern);
$stmt->execute();
$destinationTokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Combine and format codes
$codes = [];

foreach ($activeVouchers as $voucher) {
    $metadata = safeJsonDecode($voucher['swap_metadata'] ?? '{}');
    $destinationToken = $metadata['destination_token'] ?? null;
    $expiryTime = strtotime($voucher['expiry_at']);
    
    $codes[] = [
        'id' => $voucher['voucher_id'],
        'type' => 'voucher',
        'amount' => (float)$voucher['amount'],
        'created_at' => $voucher['created_at'],
        'expires_at' => $voucher['expiry_at'],
        'expiry_timestamp' => $expiryTime,
        'is_expiring_soon' => ($expiryTime - time()) < 3600,
        'code' => $destinationToken['generated_code'] ?? null,
        'sat_number' => $destinationToken['sat_number'] ?? null,
        'reference' => substr($voucher['swap_uuid'], 0, 16) . '…',
        'status' => 'ACTIVE'
    ];
}

foreach ($destinationTokens as $token) {
    $tokenData = safeJsonDecode($token['metadata'] ?? '{}');
    $destToken = $tokenData['destination_token'] ?? null;
    if (!$destToken) continue;
    
    $expiryTime = isset($destToken['expires_at']) ? strtotime($destToken['expires_at']) : (time() + 86400);
    
    $codes[] = [
        'id' => $token['swap_uuid'],
        'type' => 'token',
        'amount' => (float)($token['amount'] ?? 0),
        'created_at' => $token['created_at'],
        'expires_at' => $destToken['expires_at'] ?? null,
        'expiry_timestamp' => $expiryTime,
        'is_expiring_soon' => ($expiryTime - time()) < 3600,
        'code' => $destToken['generated_code'] ?? null,
        'sat_number' => $destToken['sat_number'] ?? $destToken['token_reference'] ?? null,
        'reference' => substr($token['swap_uuid'], 0, 16) . '…',
        'status' => 'ACTIVE'
    ];
}

// Sort by expiry (soonest first)
usort($codes, function($a, $b) {
    return $a['expiry_timestamp'] - $b['expiry_timestamp'];
});

echo json_encode([
    'success' => true,
    'codes' => $codes,
    'count' => count($codes)
]);
