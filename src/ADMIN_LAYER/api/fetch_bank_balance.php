<?php
declare(strict_types=1);

// fetch_bank_balances_for_dashboard.php
// Calls each bank's balance endpoint, merges results and returns JSON for the dashboard.

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// --- bootstrap & deps ---
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/db_connection.php';

// --- session auth ---
SessionManager::start();
$user = SessionManager::getUser();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// --- load country & participants ---
$countryFile = __DIR__ . '/../../CORE_CONFIG/system_country.php';
$country = file_exists($countryFile) ? require $countryFile : 'botswana';

$participantsFile = __DIR__ . "/../../CORE_CONFIG/env/participants_{$country}.json";
if (!file_exists($participantsFile)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Participants file missing']);
    exit;
}

$json = file_get_contents($participantsFile);
$participantsData = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Invalid participants JSON']);
    exit;
}
$participants = $participantsData['participants'] ?? [];

// --- debug log ---
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
$debugFile = $logDir . '/fetch_bank_balances_debug.log';

// helper: write debug (silent if cannot write)
$debug = function(string $line) use ($debugFile) {
    @file_put_contents($debugFile, date('Y-m-d H:i:s') . ' | ' . $line . PHP_EOL, FILE_APPEND);
};

// --- prepare output ---
$bankBalances = [];

foreach ($participants as $name => $p) {
    if (($p['type'] ?? '') !== 'bank') continue;

    $upperName = strtoupper($name);
    $defaultBalances = $p['balances'] ?? [
        'middleman_escrow' => 0.0,
        'partner_bank_settlement' => 0.0,
        'middleman_revenue' => 0.0
    ];

    // Build API URL
    $base = rtrim($p['api_url'] ?? $p['url'] ?? '', '/');
    $balanceEndpoint = $p['endpoints']['balance'] ?? '/get_balance.php';
    $apiUrl = $base . '/' . ltrim($balanceEndpoint, '/');

    $apiKey = $p['api_key'] ?? ($p['api_key'] ?? '');
    $userId = intval($p['system_user_id'] ?? 0);

    // If no api key or user id, skip calling API and use defaults
    if (empty($apiKey) || $userId <= 0) {
        $debug("{$upperName}: Missing api_key or system_user_id — using static balances");
        $bankBalances[$upperName] = $defaultBalances;
        continue;
    }

    // Prepare CURL
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            // send both headers because different endpoints expect different header names
            'X-API-Key: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode(['user_id' => $userId]),
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);

    // Log raw response for debugging
    $debug("Request {$upperName} -> {$apiUrl} | HTTP {$httpCode} | curlErr: {$curlErr} | resp: " . substr((string)$response,0,800));

    // Default to fallback balances
    $balances = $defaultBalances;

    if ($curlErr) {
        // network error -> fallback
        $debug("{$upperName}: CURL error - {$curlErr}. Using static balances.");
        $bankBalances[$upperName] = $balances;
        continue;
    }

    // Try to parse JSON
    $data = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $debug("{$upperName}: Invalid JSON response. Using static balances.");
        $bankBalances[$upperName] = $balances;
        continue;
    }

    // Many bank endpoints return {status: 'success', accounts: [...]}
    if (isset($data['status']) && strtolower($data['status']) === 'error') {
        $debug("{$upperName}: API returned error: " . ($data['message'] ?? 'no message') . " — using static balances.");
        $bankBalances[$upperName] = $balances;
        continue;
    }

    if (isset($data['accounts']) && is_array($data['accounts'])) {
        // map accounts -> balances keyed by account_type
        $mapped = [];
        foreach ($data['accounts'] as $acct) {
            $type = $acct['account_type'] ?? ($acct['accountType'] ?? null);
            $bal  = $acct['balance'] ?? ($acct['amount'] ?? null);
            if ($type === null) continue;
            // normalize numeric
            $mapped[$type] = is_numeric($bal) ? floatval($bal) : (is_string($bal) ? floatval(str_replace(',','',$bal)) : 0.0);
        }
        // ensure the three expected keys exist
        $mapped['middleman_escrow'] = $mapped['middleman_escrow'] ?? ($defaultBalances['middleman_escrow'] ?? 0.0);
        $mapped['partner_bank_settlement'] = $mapped['partner_bank_settlement'] ?? ($defaultBalances['partner_bank_settlement'] ?? 0.0);
        $mapped['middleman_revenue'] = $mapped['middleman_revenue'] ?? ($defaultBalances['middleman_revenue'] ?? 0.0);

        $bankBalances[$upperName] = $mapped;
        continue;
    }

    // Some banks return flat object with named balances (rare) — try to map them
    $possibleKeys = ['middleman_escrow','partner_bank_settlement','middleman_revenue','escrow','settlement','revenue'];
    $mapped2 = [];
    foreach ($possibleKeys as $k) {
        if (isset($data[$k])) {
            $mapped2[$k] = is_numeric($data[$k]) ? floatval($data[$k]) : floatval(str_replace(',','',$data[$k]));
        }
    }
    if (!empty($mapped2)) {
        // ensure canonical keys
        $res = [
            'middleman_escrow' => $mapped2['middleman_escrow'] ?? ($mapped2['escrow'] ?? $defaultBalances['middleman_escrow']),
            'partner_bank_settlement' => $mapped2['partner_bank_settlement'] ?? ($mapped2['settlement'] ?? $defaultBalances['partner_bank_settlement']),
            'middleman_revenue' => $mapped2['middleman_revenue'] ?? ($mapped2['revenue'] ?? $defaultBalances['middleman_revenue']),
        ];
        $bankBalances[$upperName] = $res;
        continue;
    }

    // If we reach here, we couldn't extract balances — use defaults
    $debug("{$upperName}: Couldn't extract balances from API response — using static balances.");
    $bankBalances[$upperName] = $balances;
}

// --- output ---
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'banks' => $bankBalances
], JSON_PRETTY_PRINT);

exit;

