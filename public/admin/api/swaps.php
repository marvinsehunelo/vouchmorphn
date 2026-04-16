<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Admin/Auth/AdminAuth.php';
require_once __DIR__ . '/../../../src/Core/Database/config/DBConnection.php';

use ADMIN_LAYER\Auth\AdminAuth;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

$config = require __DIR__ . '/../../../src/Core/Config/load_country.php';
$db = DBConnection::getInstance($config['db']['swap']);
$auth = new AdminAuth($db);

if (!$auth->getCurrentAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$limit = $_GET['limit'] ?? 10;

try {
    $stmt = $db->prepare("
        SELECT 
            swap_uuid::text,
            amount,
            status,
            created_at,
            source_details->>'institution' as source_institution,
            destination_details->>'institution' as destination_institution
        FROM swap_requests 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $swaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($swaps);
} catch (Exception $e) {
    echo json_encode([]);
}
