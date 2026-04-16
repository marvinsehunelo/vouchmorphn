<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_once '../../bootstrap.php';

use DFSP_ADAPTER_LAYER\MojaloopAdapter;

$fspId = $_GET['fspId'] ?? '';

$adapter = new MojaloopAdapter($swapService);

header('Content-Type: application/json');
echo json_encode(
    $adapter->handle('participants', [], ['fspId' => $fspId])
);

