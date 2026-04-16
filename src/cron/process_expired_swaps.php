<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../BUSINESS_LOGIC_LAYER/services/ExpiredSwapsService.php';

try {
    $service = new \..\..\BUSINESS_LOGIC_LAYER\services\ExpiredSwapsService();
    $result = $service->processExpiredSwaps();

    echo json_encode([
        'status' => 'success',
        'processed' => $result['totalProcessed'] ?? 0
    ]);

} catch (\Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

