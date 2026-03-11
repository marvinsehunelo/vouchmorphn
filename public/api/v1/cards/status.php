<?php
declare(strict_types=1);

/**
 * VouchMorph - Card Application Status API
 */

define('ROOT_PATH', dirname(__DIR__, 5));

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit();
}

// Load system config (same pattern as other files)
// ... (include all the same bootstrapping code)

$applicationId = $_GET['id'] ?? '';
if (!$applicationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Application ID required']);
    exit();
}

try {
    $pdo = DBConnection::getConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            ca.application_id,
            ca.full_name,
            ca.card_type,
            ca.status,
            ca.submitted_at,
            ca.kyc_submitted_at,
            ca.kyc_verified_at,
            ca.card_assigned_at,
            ca.completed_at,
            mc.card_suffix,
            mc.lifecycle_status as card_status,
            mc.delivery_status,
            mc.tracking_number
        FROM card_applications ca
        LEFT JOIN message_cards mc ON ca.card_id = mc.card_id
        WHERE ca.application_id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$application) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Application not found']);
        exit();
    }
    
    echo json_encode(['success' => true, 'data' => $application], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
