<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

use BUSINESS_LOGIC_LAYER\Services\SwapService;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;
use APP_LAYER\Utils\SessionManager;

require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/db_connection.php';
require_once __DIR__ . '/../../BUSINESS_LOGIC_LAYER/services/SwapService.php';

SessionManager::start();
if (!SessionManager::isLoggedIn()) die("User not logged in\n");

$user = SessionManager::getUser();
$loggedPhone = $user['phone'] ?? '+26770000000';
$userToken = (string)($user['token'] ?? $user['id'] ?? $loggedPhone);

$country = require __DIR__ . '/../../CORE_CONFIG/system_country.php';
// FIX: Removed invisible non-breaking space after $config
$config = require __DIR__ . "/../../CORE_CONFIG/config_{$country}.php";

// ------------------------------------------------------
// LOAD PARTICIPANTS FROM JSON
// ------------------------------------------------------
$participantsFile = __DIR__ . "/../../CORE_CONFIG/env/participants_{$country}.json";
if (!file_exists($participantsFile)) die("Participants file not found: $participantsFile\n");
$participantsData = json_decode(file_get_contents($participantsFile), true);
$participants = $participantsData['participants'] ?? [];


// ------------------------------------------------------
// Connect to swap DB and instantiate Service
// ------------------------------------------------------
try {
    $swapDB = DBConnection::getInstance($config['db']['swap']);
} catch (Throwable $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

$settings = ['swap_enabled' => 1];
$encryptionKey = $config['encryption']['key'] ?? 'DEFAULT_KEY';

// CRITICAL FIX: Inject the loaded participants list into the service
$swapService = new SwapService($swapDB, $settings, $country, $encryptionKey, $participants);


// ------------------------------------------------------
// HELPER: Get a real, active PIN or voucher from participant DB
// ------------------------------------------------------
function getActiveItem(array $participants, string $participantName, string $type) {
    global $config;
    $p = $participants[strtolower($participantName)] ?? null; // Ensure case-insensitivity
    if (!$p) throw new Exception("Participant $participantName configuration not found.");

    // Use participant DB config if available, otherwise fallback to swap DB
    $dbConfig = $config['db'][strtolower($participantName)] ?? $config['db']['swap'];
    
    try {
        $bankDB = DBConnection::getInstance($dbConfig);
    } catch (Throwable $e) {
        throw new Exception("Could not connect to $participantName DB: " . $e->getMessage());
    }

    $table = $p['swap_table'] ?? null;
    if (!$table) throw new Exception("Participant table ('swap_table' in config) not defined for $participantName.");
    
    if ($type === 'ewallet') {
        $enabledColumn = $p['enabled_column'] ?? 'swap_enabled';
        $stmt = $bankDB->query("SELECT pin FROM `$table` WHERE `$enabledColumn`=1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['pin'] ?? null;
    } elseif ($type === 'voucher') {
        $stmt = $bankDB->query("SELECT voucher_number, pin FROM `$table` WHERE status='active' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?? null;
    }
    return null;
}

// ------------------------------------------------------
// FETCH REAL VALUES (with validation)
// ------------------------------------------------------
try {
    $realEwalletPin = getActiveItem($participants, 'saccussalis', 'ewallet');
    $realVoucher    = getActiveItem($participants, 'zurubank', 'voucher');
} catch (Exception $e) {
    die("Setup Error: " . $e->getMessage() . "\n");
}

if (empty($realEwalletPin)) die("Setup Error: No active e-wallet PIN found for saccussalis. Check DB: saccussalis\n");
if (empty($realVoucher['voucher_number'])) die("Setup Error: No active voucher found for zurubank. Check DB: zurubank\n");

$realVoucherNumber = $realVoucher['voucher_number'];

// ------------------------------------------------------
// RUN TEST CASE: ewallet → voucher
// ------------------------------------------------------
$payload = [
    'fromParticipant' => 'saccussalis',
    'toParticipant'   => 'zurubank',
    'to_type'         => 'voucher',
    // Data from session
    'token'           => $userToken,
    'sender_phone'    => $loggedPhone, // Sender phone is usually required for cashout
    'recipient_phone' => $loggedPhone, // Recipient phone for the voucher
    // Data from DB
    'pin'             => $realEwalletPin,
    'voucher_number'  => $realVoucherNumber,
    'amount'          => 1000
];

echo "==== TEST: ewallet → voucher with real values ====\n";
echo "Payload sent to SwapService:\n";
print_r($payload);

try {
    $result = $swapService->initiateSwap($payload);
} catch (Throwable $e) {
    $result = ['status'=>'error','message'=>$e->getMessage()];
}

echo "=== SWAP SERVICE RESULT ===\n";
print_r($result);

$step1Ref = $result['step1']['step1_reference'] ?? null;
echo "Step 1 reference: " . ($step1Ref ?? 'MISSING') . "\n";
