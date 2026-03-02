<?php
declare(strict_types=1);

use BUSINESS_LOGIC_LAYER\Services\ExpiredSwapsService;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/ExpiredSwapsService.php';

header('Content-Type: application/json');
// --- 1. CONFIGURATION AND DB SETUP ---
try {
    $countryFile = __DIR__ . '/../../src/CORE_CONFIG/system_country.php';
    if (!file_exists($countryFile)) throw new Exception("system_country.php missing");
    $country = trim(require $countryFile);

    $configFile = __DIR__ . "/../../src/CORE_CONFIG/config_{$country}.php";
    if (!file_exists($configFile)) throw new Exception("config_{$country}.php missing");
    $config = require $configFile;

    // Ensure logs folder exists
    $logsDir = __DIR__ . '/../../src/APP_LAYER/logs';
    if (!is_dir($logsDir)) mkdir($logsDir, 0777, true);

    $serviceLogFile = $config['logging']['log_file'] ?? $logsDir . '/expired_swaps.log';

    // PDO options
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
    ];

    // Central DB (swap_system)
    $swapConfig = $config['db']['swap'] ?? null;
    if (!$swapConfig) throw new Exception("Missing 'swap' database config.");

    $centralDB = new PDO(
        "mysql:host={$swapConfig['host']};dbname={$swapConfig['name']};charset=utf8mb4",
        $swapConfig['user'],
        $swapConfig['pass'],
        $pdoOptions
    );

    // Bank DB connections
    $banksDB = [];
    foreach ($config['db'] as $name => $c) {
        if (strtolower($name) === 'swap') continue;
        $banksDB[strtolower($name)] = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            $pdoOptions
        );
    }

    // Participants
    $participantsFile = __DIR__ . "/../../src/CORE_CONFIG/env/participants_{$country}.json";
    if (!file_exists($participantsFile)) throw new Exception("participants_{$country}.json missing");
    $participantsRaw = file_get_contents($participantsFile);
    $participantsData = json_decode($participantsRaw, true);
    if (!$participantsData || !isset($participantsData['participants'])) {
        throw new Exception("Invalid participants JSON structure");
    }
    $participants = array_change_key_case($participantsData['participants'], CASE_LOWER);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Configuration/DB setup failed: ' . $e->getMessage()
    ]);
    exit;
}

// --- 2. SERVICE EXECUTION ---
try {

    // ✅ FIX: Instantiating the service with the correct 3 arguments 
    // to match the ExpiredSwapsService::__construct(PDO $swapDB, array $banksDB, array $participants) signature.
    $service = new ExpiredSwapsService(
        $centralDB,
        $banksDB,
        $participants
    );

    $result = $service->processExpiredSwaps();

    echo json_encode([
        'status' => 'success',
        'message' => $result['report'],
        'processed' => $result['totalProcessed'] ?? 0
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Processing failed: ' . $e->getMessage()
    ]);
    exit;
}
