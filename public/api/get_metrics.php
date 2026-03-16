<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/ADMIN_LAYER/Auth/AdminAuth.php';
require_once __DIR__ . '/../../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use ADMIN_LAYER\Auth\AdminAuth;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// Check authentication
$config = require __DIR__ . '/../../../src/CORE_CONFIG/load_country.php';
$db = DBConnection::getInstance($config['db']['swap']);
$auth = new AdminAuth($db);

if (!$auth->getCurrentAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $metrics = [];

    // Total transactions today
    $stmt = $db->query("SELECT COUNT(*) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $metrics['total_transactions'] = number_format($stmt->fetchColumn());

    // Total volume today
    $stmt = $db->query("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $metrics['total_volume'] = 'BWP ' . number_format($stmt->fetchColumn(), 2);

    // Active participants
    $stmt = $db->query("SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE'");
    $metrics['active_participants'] = number_format($stmt->fetchColumn());

    // Pending settlements
    $stmt = $db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $metrics['pending_settlements'] = number_format($stmt->fetchColumn());

    // Total fees today
    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM swap_fee_collections WHERE DATE(collected_at) = CURRENT_DATE");
    $metrics['total_fees'] = 'BWP ' . number_format($stmt->fetchColumn(), 2);

    // Net position (example calculation)
    $stmt = $db->query("
        SELECT COALESCE(SUM(CASE WHEN type = 'CARD_SWIPE' THEN amount ELSE 0 END), 0) as due_to_merchants
        FROM settlement_queue 
        WHERE status = 'PENDING'
    ");
    $dueToMerchants = $stmt->fetchColumn();
    $metrics['net_position'] = 'BWP ' . number_format($dueToMerchants, 2);

    echo json_encode($metrics);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
