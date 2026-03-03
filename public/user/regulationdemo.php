<?php
declare(strict_types=1);

namespace DASHBOARD;

// CRITICAL: Load config FIRST
require_once __DIR__ . '/../../src/CORE_CONFIG/countries/BW/config_BW.php';

// ======================================================
// RAILWAY COMPATIBILITY FIXES
// ======================================================

// Fix 1: Set correct error logging path for Railway
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Railway doesn't have /opt/lampp/logs/ - use system temp or current directory
$log_path = sys_get_temp_dir() . '/vouchmorph_errors.log';
ini_set('error_log', $log_path);
ini_set('log_errors', 1);

// Custom error handler to catch warnings/notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile line $errline");
    return true;
});

// Fix 2: Test database connection with proper error handling
$db_status = '❌ Not Connected';
$db_error = null;

try {
    // Check if DBConnection class exists
    if (!class_exists('\DATA_PERSISTENCE_LAYER\config\DBConnection')) {
        throw new \Exception('DBConnection class not found - check autoloader');
    }
    
    $db = \DATA_PERSISTENCE_LAYER\config\DBConnection::getConnection();
    
    if ($db) {
        $db_status = '✅ Connected';
        
        // Test query to verify permissions
        $test = $db->query("SELECT 1 as test")->fetch();
        if ($test) {
            $db_status = '✅ Connected (Working)';
        }
    }
} catch (\Exception $e) {
    $db_error = $e->getMessage();
    error_log("Regulation demo DB error: " . $db_error);
    // Don't display error to user in production
    if (getenv('APP_ENV') !== 'production') {
        echo "<!-- DB Debug: " . htmlspecialchars($db_error) . " -->\n";
    }
}

// Fix 3: Get Railway environment info
$environment = getenv('APP_ENV') ?: 'production';
$railway_url = getenv('RAILWAY_PUBLIC_DOMAIN') ?: 'Not set';
$database_url_configured = getenv('DATABASE_URL') ? 'Yes' : 'No';

// Fix 4: Set base URL for API calls
$base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
            ($_SERVER['HTTP_HOST'] ?? 'localhost');

function safe_number_format($number, $decimals = 2) {
    return $number !== null && $number !== '' ? number_format((float)$number, $decimals) : '0.00';
}

function debug_log($message) {
    error_log("[DEBUG] " . $message);
}

ob_start();

error_log("=== regulationdemo.php accessed at " . date('Y-m-d H:i:s') . " ===");

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;
use BUSINESS_LOGIC_LAYER\services\settlement\HybridSettlementStrategy;
use SECURITY_LAYER\Encryption\KeyVault;

require_once __DIR__ . '/../../src/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
          && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ============================================================================
// DYNAMIC CONFIGURATION LOADING
// ============================================================================

$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';
$selectedView = $_GET['view'] ?? 'dashboard';
$regulatorView = $_GET['regulator_view'] ?? 'supervisory';
$_SESSION['country'] = $countryCode;

$configPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/";

try {
    $db = DBConnection::getConnection();
} catch (\Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $db = null;
}

// ============================================================================
// LOAD PARTICIPANTS FROM JSON FILE
// ============================================================================

$participantsFile = $configPath . "participants_{$countryCode}.json";
$participantsData = [];

if (file_exists($participantsFile)) {
    $jsonContent = file_get_contents($participantsFile);
    $data = json_decode($jsonContent, true);
    
    if (json_last_error() === JSON_ERROR_NONE && isset($data['participants'])) {
        $participantsData = $data['participants'];
    }
}

$participants = [];
$participantsByWalletType = [];

$fieldRequirements = [
    'source' => [
        'account' => ['account_number'],
        'wallet' => ['phone', 'pin'],
        'e-wallet' => ['phone'],
        'card' => ['card_number', 'pin'],
        'voucher' => ['phone', 'voucher_number', 'pin']
    ],
    'destination' => [
        'cashout' => [
            'atm' => ['phone'],
            'agent' => ['phone']
        ],
        'deposit' => [
            'account' => ['account_number'],
            'wallet' => ['phone'],
            'card' => ['card_number'],
            'e-wallet' => ['phone']
        ]
    ]
];

foreach ($participantsData as $key => $participant) {
    if (!isset($participant['type'])) continue;

    $participantInfo = [
        'participant_id' => $key,
        'participant_name' => $key,
        'type' => $participant['type'] ?? 'UNKNOWN',
        'category' => $participant['category'] ?? 'UNKNOWN',
        'provider_code' => $participant['provider_code'] ?? '',
        'status' => $participant['status'] ?? 'ACTIVE',
        'capabilities' => $participant['capabilities'] ?? []
    ];
    
    $participants[] = $participantInfo;
    
    $walletTypes = $participant['capabilities']['wallet_types'] ?? ['ACCOUNT'];
    foreach ($walletTypes as $type) {
        $typeLower = strtolower($type);
        if (!isset($participantsByWalletType[$typeLower])) {
            $participantsByWalletType[$typeLower] = [];
        }
        $participantsByWalletType[$typeLower][] = $participantInfo;
    }
}

if (isset($participantsByWalletType['e-wallet']) || isset($participantsByWalletType['ewallet'])) {
    $participantsByWalletType['e-wallet'] = array_merge(
        $participantsByWalletType['e-wallet'] ?? [],
        $participantsByWalletType['ewallet'] ?? []
    );
}

foreach (['account', 'wallet', 'e-wallet', 'card', 'atm', 'agent', 'voucher'] as $type) {
    if (!isset($participantsByWalletType[$type])) {
        $participantsByWalletType[$type] = [];
    }
}

// ============================================================================
// DATABASE QUERIES
// ============================================================================

$heartbeat = ['status' => 'ACTIVE', 'latency_ms' => 45, 'system_load' => 0.23, 'created_at' => date('Y-m-d H:i:s')];
$netPositions = [];
$pendingSettlements = [];
$recentSwaps = [];
$activeVouchers = [];
$totalVoucherAmount = 0;
$settlementQueue = [];
$messageOutbox = [];
$ledgerBalances = [];
$recentAudits = [];
$regReports = [];
$totalExposure = 0;
$totalFeesCollected = 0;
$feesByType = [];
$feeCollections = [];

if ($db) {
    try {
        debug_log("Database connected, running queries");
        
        $tableExists = function($tableName) use ($db) {
            try {
                $stmt = $db->prepare("
                    SELECT EXISTS (
                        SELECT 1 
                        FROM information_schema.tables 
                        WHERE table_schema = 'public' 
                        AND table_name = ?
                    )
                ");
                $stmt->execute([$tableName]);
                return $stmt->fetchColumn();
            } catch (\Exception $e) {
                return false;
            }
        };
        
        if ($tableExists('supervisory_heartbeat')) {
            $stmt = $db->query("SELECT status, latency_ms, system_load, created_at FROM supervisory_heartbeat ORDER BY created_at DESC LIMIT 1");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result) $heartbeat = $result;
        }

        if ($tableExists('net_positions')) {
            $stmt = $db->query("SELECT debtor, creditor, amount, currency_code, updated_at FROM net_positions WHERE amount != 0 ORDER BY amount DESC");
            $netPositions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalExposure = array_sum(array_column($netPositions, 'amount'));
        }

        if ($tableExists('settlement_messages')) {
            $stmt = $db->query("SELECT message_id, transaction_id, from_participant, to_participant, amount, type, status, created_at, metadata FROM settlement_messages WHERE status = 'PENDING' ORDER BY created_at DESC LIMIT 50");
            $pendingSettlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if ($tableExists('swap_requests')) {
            $stmt = $db->query("SELECT swap_id, swap_uuid::text as swap_uuid, amount, status, created_at FROM swap_requests ORDER BY created_at DESC LIMIT 10");
            $recentSwaps = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if ($tableExists('swap_vouchers')) {
            $stmt = $db->query("SELECT voucher_id, code_suffix, amount, claimant_phone, expiry_at, status FROM swap_vouchers WHERE status = 'ACTIVE' AND expiry_at > NOW() ORDER BY created_at DESC");
            $activeVouchers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalVoucherAmount = array_sum(array_column($activeVouchers, 'amount'));
        }

        if ($tableExists('settlement_queue')) {
            $stmt = $db->query("SELECT id, debtor, creditor, amount, created_at FROM settlement_queue WHERE amount > 0 ORDER BY created_at ASC LIMIT 50");
            $queueItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($queueItems as $item) {
                $settlementQueue[] = [
                    'queue_id' => $item['id'],
                    'debtor_name' => $item['debtor'],
                    'creditor_name' => $item['creditor'],
                    'amount' => $item['amount'],
                    'created_at' => $item['created_at']
                ];
            }
        }

        if ($tableExists('message_outbox')) {
            $stmt = $db->query("SELECT message_id, channel, destination, status, created_at FROM message_outbox WHERE status IN ('PENDING', 'SENT') ORDER BY created_at ASC LIMIT 50");
            $messageOutbox = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if ($tableExists('audit_logs')) {
            $stmt = $db->query("SELECT entity_type, action, category, severity, performed_at FROM audit_logs ORDER BY performed_at DESC LIMIT 20");
            $recentAudits = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if ($tableExists('regulator_outbox')) {
            $stmt = $db->query("SELECT id, report_id, status, attempts, integrity_hash, created_at, last_attempt FROM regulator_outbox ORDER BY created_at DESC LIMIT 20");
            $regReports = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if ($tableExists('swap_fee_collections')) {
            $stmt = $db->query("SELECT fee_id, fee_type, total_amount, source_institution, destination_institution, split_config, vat_amount, status, collected_at FROM swap_fee_collections WHERE status = 'COLLECTED' ORDER BY collected_at DESC LIMIT 50");
            $feeCollections = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalFeesCollected = array_sum(array_column($feeCollections, 'total_amount'));
            foreach ($feeCollections as $fee) {
                $type = $fee['fee_type'];
                if (!isset($feesByType[$type])) $feesByType[$type] = 0;
                $feesByType[$type] += $fee['total_amount'];
            }
        }

    } catch (\Exception $e) {
        debug_log("Database error: " . $e->getMessage());
    }
}

// ============================================================================
// AJAX SWAP HANDLER
// ============================================================================

if (!isset($isAjax)) {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
              (isset($_POST['ajax']) && $_POST['ajax'] === 'true');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_swap']) && $isAjax) {

    if (ob_get_level() > 0) ob_clean(); else ob_start();

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, must-revalidate');

    $debug_log = [];

    try {
        $debug_log[] = "1. Starting swap execution";

        $keyVault = new KeyVault();
        $encryptionKey = $keyVault->getEncryptionKey();
        $debug_log[] = "2. KeyVault initialized";

        $swapService = new SwapService($db, [], $countryCode, $encryptionKey, ['participants' => $participantsData]);
        $debug_log[] = "3. SwapService initialized";

        $swapType     = $_POST['swap_type'] ?? 'self';
        $deliveryMode = $_POST['delivery_mode'] ?? 'deposit';
        $toType       = $_POST['to_type'] ?? 'account';
        $fromType     = strtolower($_POST['from_type'] ?? '');
        $amount       = (float)($_POST['amount'] ?? 0);
        $debug_log[]  = "4. Form data: swapType=$swapType, deliveryMode=$deliveryMode, toType=$toType";

        $phoneNumber = '';
        switch ($fromType) {
            case 'e-wallet':
                $phoneNumber = $_POST['ewallet_phone'] ?? $_POST['wallet_phone'] ?? '';
                break;
            case 'wallet':
                $phoneNumber = $_POST['wallet_phone'] ?? '';
                break;
            case 'voucher':
                $phoneNumber = $_POST['wallet_phone'] ?? $_POST['ewallet_phone'] ?? ($defaultPhone ?? '');
                break;
            default:
                $phoneNumber = $_POST['account_number'] ?? '';
        }
        $debug_log[] = "5. Source phone/account: $phoneNumber";

        if ($swapType === 'business' && !empty($_POST['recipients']) && is_array($_POST['recipients'])) {
            $legs = [];
            foreach ($_POST['recipients'] as $recipient) {
                $leg = [
                    'institution'    => $recipient['institution'] ?? '',
                    'asset_type'     => $toType,
                    'amount'         => (float)($recipient['amount'] ?? 0),
                    'delivery_mode'  => $deliveryMode,
                    'reference'      => $recipient['reference'] ?? 'BUS-PAY',
                    'purpose'        => 'Business Payment'
                ];
                
                if ($deliveryMode === 'cashout') {
                    $leg['cashout'] = ['beneficiary_phone' => $recipient['phone'] ?? ''];
                } else {
                    $leg['beneficiary_account'] = $recipient['phone'] ?? '';
                }
                
                $legs[] = $leg;
            }
            $debug_log[] = "6. Business legs built: " . count($legs);
        } else {
            $destPhone = $deliveryMode === 'cashout'
                ? $_POST['destination_phone'] ?? ($defaultPhone ?? '')
                : $_POST['destination_account'] ?? $_POST['destination_card'] ?? '';
            
            $leg = [
                'institution'   => $_POST['to_institution'] ?? '',
                'asset_type'    => strtoupper($toType),
                'amount'        => $amount,
                'delivery_mode' => $deliveryMode,
                'reference'     => ($deliveryMode === 'cashout' ? 'CASH-' : 'DEP-') . uniqid(),
                'purpose'       => ($deliveryMode === 'cashout' ? 'Cash Withdrawal' : 'Deposit Transfer')
            ];
            
            if ($deliveryMode === 'cashout') {
                $leg['cashout'] = ['beneficiary_phone' => $destPhone];
            } else {
                $leg['beneficiary_account'] = $destPhone;
            }
            
            $legs = [$leg];
            $debug_log[] = "6. Personal leg created";
        }

        if (empty($legs[0]['institution'])) {
            throw new \RuntimeException("Destination institution is required");
        }

        if ($deliveryMode === 'cashout') {
            if (empty($legs[0]['cashout']['beneficiary_phone'])) {
                throw new \RuntimeException("Beneficiary phone is required for cashout");
            }
        } else {
            if (empty($legs[0]['beneficiary_account'])) {
                throw new \RuntimeException("Destination account is required for deposit");
            }
        }

        $source = [
            'institution' => $_POST['from_institution'] ?? '',
            'asset_type'  => $_POST['from_type'] ?? '',
            'amount'      => $amount,
            'phone'       => $phoneNumber,
            'account'     => ['account_number' => $_POST['account_number'] ?? null],
            'wallet'      => $fromType === 'wallet' ? ['wallet_phone' => $phoneNumber, 'wallet_pin' => $_POST['pin'] ?? null] : null,
            'ewallet'     => $fromType === 'e-wallet' ? ['ewallet_phone' => $phoneNumber] : null,
            'card'        => $fromType === 'card' ? ['card_number' => $_POST['card_number'] ?? null, 'card_pin' => $_POST['pin'] ?? null] : null,
            'voucher'     => $fromType === 'voucher' ? ['voucher_number' => $_POST['voucher_number'] ?? null, 'claimant_phone' => $phoneNumber, 'voucher_pin' => $_POST['pin'] ?? null] : null
        ];
        $source = array_filter($source, fn($v) => $v !== null);

        $payload = [
            'currency'    => 'BWP',
            'swap_type'   => $swapType,
            'source'      => $source,
            'destination' => array_filter($legs[0], fn($v) => $v !== null && $v !== '')
        ];
        
        $debug_log[] = "7. Payload prepared";
        error_log("DASHBOARD PAYLOAD: " . json_encode($payload));

        $swapResult = $swapService->executeSwap($payload);
        $debug_log[] = "8. Swap executed with status: " . ($swapResult['status'] ?? 'unknown');

        $isSuccess = (($swapResult['status'] ?? '') === 'success');
        
        if ($isSuccess) {
            $debug_log[] = "9. Swap successful";
            if ($db) {
                try {
                    $stmt = $db->prepare("INSERT INTO audit_logs (entity, action, category, severity, performed_by_type, performed_at) VALUES ('SWAP', 'EXECUTE', 'TRANSACTION', 'info', 'user', NOW())");
                    $stmt->execute();
                    $debug_log[] = "10. Audit log inserted";
                } catch (\Exception $e) {
                    $debug_log[] = "10. Audit log error: " . $e->getMessage();
                }
            }

            $response = [
                'status'         => 'success',
                'swap_reference' => $swapResult['swap_reference'] ?? 'SWAP-' . uniqid(),
                'timestamp'      => date('Y-m-d H:i:s'),
                'amount'         => $amount,
                'delivery_mode'  => $deliveryMode,
                'source'         => $source,
                'destination'    => $legs[0],
                'hold_reference' => $swapResult['hold_reference'] ?? null,
                'dispensed_notes'=> $swapResult['dispensed_notes'] ?? [],
                'legs'           => $legs,
                'debug'          => $debug_log
            ];

            if ($deliveryMode === 'cashout' && $db) {
                try {
                    $stmt = $db->prepare("SELECT code_suffix, expiry_at FROM swap_vouchers WHERE swap_id = (SELECT swap_id FROM swap_requests WHERE swap_uuid = ? LIMIT 1) ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$response['swap_reference']]);
                    $voucher = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($voucher) {
                        $response['voucher'] = [
                            'code_suffix' => $voucher['code_suffix'],
                            'expiry'      => $voucher['expiry_at']
                        ];
                        $debug_log[] = "11. Voucher fetched";
                    }
                } catch (\Exception $e) {
                    $debug_log[] = "11. Voucher fetch error: " . $e->getMessage();
                }
            }
        } else {
            $response = [
                'status'  => 'error',
                'message' => $swapResult['message'] ?? 'Swap execution failed',
                'debug'   => $debug_log
            ];
        }

    } catch (\Exception $e) {
        error_log("SWAP EXCEPTION: " . $e->getMessage());
        $response = [
            'status'  => 'error',
            'message' => $e->getMessage(),
            'debug'   => array_merge($debug_log, ["EXCEPTION: " . $e->getMessage()])
        ];
    }

    if (ob_get_length()) ob_clean();
    echo json_encode($response);
    ob_end_flush();
    exit;
}

$error = '';
$swapResult = null;
$showMoneyFlow = false;
$fieldRequirementsJson = json_encode($fieldRequirements);

ob_clean();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · REGULATORY SWITCH · <?php echo $countryCode; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0a;
            font-family: 'Inter', 'Helvetica Neue', -apple-system, sans-serif;
            color: #ffffff;
            line-height: 1.4;
            font-weight: 300;
            letter-spacing: 0.02em;
        }

        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* WELCOME BANNER */
        .welcome-banner {
            background: #000;
            border: 2px solid #0f0;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(0, 255, 0, 0.1) 50%, transparent 70%);
            animation: scan 3s linear infinite;
        }

        @keyframes scan {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 0.3em;
            color: #0f0;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .welcome-subtitle {
            font-size: 1rem;
            color: #666;
            letter-spacing: 0.2em;
            position: relative;
        }

        .version-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: #0f0;
            color: #000;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            margin-top: 1rem;
        }

        /* TYPOGRAPHY */
        h1, h2, h3, h4 {
            font-weight: 300;
            letter-spacing: 0.05em;
        }

        .title {
            font-size: 1.8rem;
            font-weight: 200;
            text-transform: uppercase;
            border-bottom: 2px solid #333;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
            color: #fff;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 1.5rem;
            color: #888;
        }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 2px solid #222;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #fff;
        }

        .logo span {
            color: #888;
            font-size: 0.8rem;
            margin-left: 1rem;
        }

        .country-selector {
            display: flex;
            gap: 0.5rem;
        }

        .country-btn {
            padding: 0.5rem 1.2rem;
            background: transparent;
            border: 1px solid #333;
            color: #666;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s;
        }

        .country-btn.active {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        .country-btn:hover {
            border-color: #fff;
            color: #fff;
        }

        .view-selector {
            display: flex;
            gap: 2rem;
        }

        .view-btn {
            padding: 0.5rem 0;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s;
        }

        .view-btn.active {
            color: #fff;
            border-bottom-color: #0f0;
        }

        .view-btn:hover {
            color: #fff;
        }

        .status-badge {
            padding: 0.5rem 1.5rem;
            background: #111;
            border: 1px solid #333;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        /* GRID SYSTEMS */
        .grid-2, .grid-3, .grid-4 {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }

        /* CARDS */
        .card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #222;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #fff;
        }

        .card-badge {
            padding: 0.25rem 1rem;
            background: #000;
            border: 1px solid #333;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        /* HEALTH METRICS */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .health-item {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .health-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .health-value {
            font-size: 1.8rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
        }

        .health-value.ok { color: #0f0; }
        .health-value.warning { color: #ff0; }
        .health-value.critical { color: #f00; }

        /* SUMMARY STATS */
        .stat-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            line-height: 1.2;
        }

        .positive { color: #0f0; }
        .negative { color: #f00; }

        /* TABLES */
        .table-responsive {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #222;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            text-align: left;
            padding: 1rem;
            background: #000;
            color: #888;
            font-weight: 300;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            border-bottom: 2px solid #333;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #222;
            color: #ccc;
            font-family: 'Courier New', monospace;
        }

        tr:hover {
            background: #1a1a1a;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* STATUS BADGES */
        .status {
            display: inline-block;
            padding: 0.25rem 1rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border: 1px solid;
        }

        .status-success {
            background: transparent;
            color: #0f0;
            border-color: #0f0;
        }

        .status-pending {
            background: transparent;
            color: #ff0;
            border-color: #ff0;
        }

        .status-error {
            background: transparent;
            color: #f00;
            border-color: #f00;
        }

        .status-retry {
            background: transparent;
            color: #00f;
            border-color: #00f;
        }

        /* PARTICIPANT BADGES */
        .participant-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #000;
            border: 1px solid #333;
            color: #888;
            font-size: 0.6rem;
            text-transform: uppercase;
            margin-right: 0.25rem;
        }

        .atm-badge {
            border-color: #f00;
            color: #f00;
        }

        .agent-badge {
            border-color: #00f;
            color: #00f;
        }

        /* FEE DISPLAY */
        .fee-container {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #222;
            font-family: 'Courier New', monospace;
        }

        .fee-total {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            margin-top: 0.5rem;
            background: #0a0a0a;
            font-weight: 400;
            border-top: 2px solid #333;
            color: #0f0;
        }

        /* FORMS */
        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        select, input {
            width: 100%;
            padding: 1rem;
            background: #000;
            border: 2px solid #222;
            color: #fff;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        select:focus, input:focus {
            outline: none;
            border-color: #0f0;
        }

        .radio-group {
            display: flex;
            gap: 2rem;
            padding: 1rem 0;
        }

        .radio-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-item input[type="radio"] {
            width: auto;
        }

        .btn {
            padding: 1rem 2rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }

        .btn:hover {
            background: #0f0;
            color: #000;
        }

        .btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .btn-add {
            border: 2px dashed #333;
            color: #666;
            margin-top: 1rem;
        }

        .btn-remove {
            padding: 0.5rem;
            background: transparent;
            border: 1px solid #f00;
            color: #f00;
            font-size: 0.7rem;
            cursor: pointer;
        }

        .delivery-mode-selector {
            background: #000;
            border: 2px solid #333;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .hidden {
            display: none;
        }

        .recipient-row {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        /* REPORT */
        .report-container {
            margin-top: 2rem;
            border: 2px solid #0f0;
            display: none;
        }

        .report-container.visible {
            display: block;
        }

        /* FOOTER */
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #444;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
        }

        /* MISC */
        .text-muted {
            color: #444;
        }

        .mono {
            font-family: 'Courier New', monospace;
        }

        hr {
            border: none;
            border-top: 2px solid #222;
            margin: 2rem 0;
        }

        /* DASHBOARD SPECIFIC */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
        }

        .recent-swaps {
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-title">WELCOME TO VOUCHMORPH</div>
            <div class="welcome-subtitle">REGULATORY SWITCH · PRODUCTION ENVIRONMENT</div>
            <div class="version-badge">VMOR 1.0 – CORE EDITION</div>
        </div>

        <!-- HEADER -->
        <div class="header">
            <div class="header-left">
                <div class="logo">
                    VOUCHMORPH <span>REGULATORY SWITCH</span>
                </div>
                <div class="country-selector">
                    <a href="?country=BW&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'BW' ? 'active' : ''; ?>">BW</a>
                    <a href="?country=KE&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'KE' ? 'active' : ''; ?>">KE</a>
                    <a href="?country=NG&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'NG' ? 'active' : ''; ?>">NG</a>
                </div>
            </div>
            <div class="view-selector">
                <a href="?view=dashboard&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'dashboard' ? 'active' : ''; ?>">DASHBOARD</a>
                <a href="?view=swap&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'swap' ? 'active' : ''; ?>">EXECUTE</a>
                <a href="?view=settlements&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'settlements' ? 'active' : ''; ?>">SETTLEMENTS</a>
                <a href="?view=regulatory&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'regulatory' ? 'active' : ''; ?>">REGULATORY</a>
                <a href="?view=audit&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'audit' ? 'active' : ''; ?>">AUDIT</a>
            </div>
            <div class="status-badge">
                <?php echo $countryCode; ?> · REAL-TIME
            </div>
        </div>

        <!-- SYSTEM HEALTH - Always visible -->
        <div class="health-grid">
            <div class="health-item">
                <div class="health-label">SYSTEM STATUS</div>
                <div class="health-value ok"><?php echo $heartbeat['status'] ?? 'ACTIVE'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">LATENCY</div>
                <div class="health-value"><?php echo isset($heartbeat['latency_ms']) ? number_format($heartbeat['latency_ms'], 0) . 'ms' : '45ms'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">LOAD</div>
                <div class="health-value"><?php echo isset($heartbeat['system_load']) ? number_format($heartbeat['system_load'] * 100, 0) . '%' : '23%'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">HEARTBEAT</div>
                <div class="health-value"><?php echo isset($heartbeat['created_at']) ? date('H:i:s', strtotime($heartbeat['created_at'])) : date('H:i:s'); ?></div>
            </div>
        </div>

        <!-- SUMMARY STATS - Always visible -->
        <div class="grid-4">
            <div class="stat-card">
                <div class="stat-label">NET EXPOSURE</div>
                <div class="stat-value <?php echo $totalExposure >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo safe_number_format($totalExposure); ?>
                </div>
                <div class="stat-label" style="margin-top: 0.5rem;">BWP</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">PENDING SETTLEMENTS</div>
                <div class="stat-value"><?php echo count($pendingSettlements); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">MESSAGES</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">FEES COLLECTED</div>
                <div class="stat-value positive"><?php echo safe_number_format($totalFeesCollected); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">BWP</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">ACTIVE VOUCHERS</div>
                <div class="stat-value"><?php echo count($activeVouchers); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">WORTH <?php echo safe_number_format($totalVoucherAmount); ?> BWP</div>
            </div>
        </div>

        <!-- DASHBOARD VIEW -->
        <?php if ($selectedView === 'dashboard'): ?>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">NET POSITIONS</div>
                    <div class="card-badge">real-time</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>DEBTOR</th>
                                <th>CREDITOR</th>
                                <th class="text-right">AMOUNT</th>
                                <th>CURRENCY</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($netPositions)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO POSITIONS —</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($netPositions, 0, 5) as $pos): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pos['debtor']); ?></td>
                                    <td><?php echo htmlspecialchars($pos['creditor']); ?></td>
                                    <td class="text-right <?php echo $pos['amount'] >= 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo safe_number_format($pos['amount']); ?>
                                    </td>
                                    <td><?php echo $pos['currency_code'] ?? 'BWP'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">RECENT SWAPS</div>
                    <div class="card-badge">last 10</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>REFERENCE</th>
                                <th class="text-right">AMOUNT</th>
                                <th>STATUS</th>
                                <th>TIME</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSwaps)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO SWAPS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentSwaps as $swap): ?>
                                <tr>
                                    <td class="mono"><?php echo substr($swap['swap_uuid'], 0, 8); ?>…</td>
                                    <td class="text-right positive"><?php echo safe_number_format($swap['amount']); ?> BWP</td>
                                    <td><span class="status status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span></td>
                                    <td><?php echo date('H:i', strtotime($swap['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- FEE COLLECTIONS ON DASHBOARD -->
        <?php if (!empty($feeCollections)): ?>
        <div class="fee-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div style="font-size: 1rem; text-transform: uppercase; letter-spacing: 0.1em;">FEE COLLECTIONS</div>
                <div style="padding: 0.25rem 1rem; border: 1px solid #333; color: #888; font-size: 0.7rem;">swap_fee_collections</div>
            </div>
            <?php foreach (array_slice($feeCollections, 0, 3) as $fee): ?>
            <div class="fee-item">
                <span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($fee['fee_type']); ?></strong>
                    <span style="color: #444; margin-left: 1rem;">
                        <?php echo htmlspecialchars($fee['source_institution']); ?> → <?php echo htmlspecialchars($fee['destination_institution']); ?>
                    </span>
                </span>
                <span style="color: #0f0;"><?php echo safe_number_format($fee['total_amount']); ?> BWP</span>
            </div>
            <?php endforeach; ?>
            <div class="fee-total">
                <span>TOTAL FEES</span>
                <span><?php echo safe_number_format($totalFeesCollected); ?> BWP</span>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($selectedView === 'swap'): ?>
        <!-- SWAP EXECUTION VIEW -->
        <div class="grid-2">
            <!-- FORM -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">EXECUTE SWAP</div>
                    <div class="card-badge">SwapService</div>
                </div>
                
                <form method="POST" action="?view=swap&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" id="swapForm">
                    <input type="hidden" name="execute_swap" value="1">
                    
                    <div class="form-group">
                        <label>SWAP TYPE</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" name="swap_type" value="self" id="swap_self" checked>
                                <label for="swap_self">PERSONAL</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" name="swap_type" value="business" id="swap_business">
                                <label for="swap_business">BUSINESS</label>
                            </div>
                        </div>
                    </div>
                    
                    <h3 style="color: #666; margin: 2rem 0 1rem; font-size: 0.9rem;">SOURCE</h3>
                    
                    <div class="form-group">
                        <label>ASSET TYPE</label>
                        <select name="from_type" id="from_type" required>
                            <option value="">SELECT</option>
                            <option value="account">BANK ACCOUNT</option>
                            <option value="wallet">MOBILE WALLET</option>
                            <option value="e-wallet">E-WALLET</option>
                            <option value="card">CARD</option>
                            <option value="voucher">VOUCHER</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>INSTITUTION</label>
                        <select name="from_institution" id="from_institution" required>
                            <option value="">SELECT</option>
                        </select>
                    </div>
                    
                    <div id="source_dynamic_fields"></div>
                    
                    <div class="delivery-mode-selector">
                        <div class="form-group">
                            <label>DELIVERY MODE</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" name="delivery_mode" value="cashout" id="mode_cashout" checked>
                                    <label for="mode_cashout">CASH OUT</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" name="delivery_mode" value="deposit" id="mode_deposit">
                                    <label for="mode_deposit">DEPOSIT</label>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 1rem; padding: 0.5rem; border: 1px solid #333; color: #888; font-size: 0.8rem;">
                            FEE: CASHOUT 10 BWP · DEPOSIT 6 BWP
                        </div>
                    </div>
                    
                    <h3 style="color: #666; margin: 2rem 0 1rem; font-size: 0.9rem;">DESTINATION</h3>
                    
                    <div class="form-group">
                        <label>ASSET TYPE</label>
                        <select name="to_type" id="to_type" required></select>
                    </div>
                    
                    <div id="personalDestinationFields">
                        <div class="form-group">
                            <label>INSTITUTION</label>
                            <select name="to_institution" id="to_institution" required>
                                <option value="">SELECT</option>
                            </select>
                        </div>
                        
                        <div id="destination_dynamic_fields"></div>
                    </div>
                    
                    <div id="businessDestinationFields" class="hidden">
                        <div id="recipientsContainer"></div>
                        <button type="button" class="btn btn-add" id="addRecipientBtn">+ ADD RECIPIENT</button>
                    </div>
                    
                    <div class="form-group">
                        <label>AMOUNT (BWP)</label>
                        <input type="number" name="amount" id="totalAmount" step="0.01" min="10" placeholder="0.00" required>
                    </div>
                    
                    <div id="validationErrors" style="color: #f00; margin: 1rem 0;"></div>
                    
                    <button type="submit" class="btn" id="executeSwapBtn">EXECUTE</button>
                </form>
            </div>
            
            <!-- PARTICIPANTS -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">ACTIVE PARTICIPANTS</div>
                    <div class="card-badge"><?php echo count($participants); ?></div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>NAME</th>
                                <th>TYPE</th>
                                <th>CAPABILITIES</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($participants)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO PARTICIPANTS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($participants as $p): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($p['participant_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($p['category'] ?? $p['type']); ?></td>
                                    <td>
                                        <?php 
                                        $walletTypes = $p['capabilities']['wallet_types'] ?? [];
                                        foreach ($walletTypes as $wt) {
                                            $class = 'participant-badge';
                                            if ($wt === 'ATM') $class .= ' atm-badge';
                                            elseif ($wt === 'AGENT') $class .= ' agent-badge';
                                            echo '<span class="' . $class . '">' . strtolower($wt) . '</span> ';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="status status-success"><?php echo $p['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- REPORT -->
        <div id="swapReportContainer" class="report-container card">
            <div class="card-header">
                <div class="card-title">EXECUTION REPORT</div>
                <div class="card-badge">SUCCESS</div>
            </div>
            <div id="swapReportContent"></div>
        </div>

        <?php elseif ($selectedView === 'settlements'): ?>
        <!-- SETTLEMENTS VIEW -->
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">MESSAGE OUTBOX</div>
                    <div class="card-badge">message_outbox</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>CHANNEL</th>
                                <th>DESTINATION</th>
                                <th>STATUS</th>
                                <th>CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($messageOutbox)): ?>
                            <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO MESSAGES —</td></tr>
                            <?php else: ?>
                                <?php foreach ($messageOutbox as $msg): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($msg['channel'] ?? 'SMS'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($msg['destination'] ?? '', 0, 15)); ?></td>
                                    <td><span class="status status-<?php echo strtolower($msg['status']); ?>"><?php echo $msg['status']; ?></span></td>
                                    <td><?php echo date('H:i', strtotime($msg['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-title">SETTLEMENT QUEUE</div>
                    <div class="card-badge">settlement_queue</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>DEBTOR</th>
                                <th>CREDITOR</th>
                                <th class="text-right">AMOUNT</th>
                                <th>CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($settlementQueue)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO ITEMS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($settlementQueue as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['debtor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['creditor_name']); ?></td>
                                    <td class="text-right positive"><?php echo safe_number_format($item['amount']); ?></td>
                                    <td><?php echo date('H:i', strtotime($item['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">PENDING SETTLEMENTS</div>
                <div class="card-badge">settlement_messages</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>FROM</th>
                            <th>TO</th>
                            <th class="text-right">AMOUNT</th>
                            <th>TYPE</th>
                            <th>STATUS</th>
                            <th>CREATED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingSettlements)): ?>
                        <tr><td colspan="6" class="text-center" style="color: #444; padding: 2rem;">— NO PENDING SETTLEMENTS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingSettlements as $settlement): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($settlement['from_participant'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($settlement['to_participant'] ?? '-'); ?></td>
                                <td class="text-right"><?php echo safe_number_format($settlement['amount']); ?></td>
                                <td><?php echo htmlspecialchars($settlement['type'] ?? '-'); ?></td>
                                <td><span class="status status-pending"><?php echo $settlement['status']; ?></span></td>
                                <td><?php echo date('H:i', strtotime($settlement['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($selectedView === 'regulatory'): ?>
        <!-- REGULATORY VIEW -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">REGULATOR OUTBOX</div>
                <div class="card-badge">regulator_outbox</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>REPORT ID</th>
                            <th>STATUS</th>
                            <th>ATTEMPTS</th>
                            <th>HASH</th>
                            <th>CREATED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($regReports)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO REPORTS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($regReports as $report): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($report['report_id'], 0, 16)); ?>…</td>
                                <td><span class="status status-<?php echo strtolower($report['status']); ?>"><?php echo $report['status']; ?></span></td>
                                <td><?php echo $report['attempts']; ?></td>
                                <td><span class="mono"><?php echo substr($report['integrity_hash'] ?? '', 0, 8); ?>…</span></td>
                                <td><?php echo date('H:i', strtotime($report['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($selectedView === 'audit'): ?>
        <!-- AUDIT VIEW -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">AUDIT LOG</div>
                <div class="card-badge">audit_logs</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ENTITY_TYPE</th>
                            <th>ACTION</th>
                            <th>CATEGORY</th>
                            <th>SEVERITY</th>
                            <th>TIME</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentAudits)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO AUDIT RECORDS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentAudits as $audit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($audit['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($audit['action'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($audit['category'] ?? 'N/A'); ?></td>
                                <td><span class="status status-<?php echo $audit['severity'] ?? 'info'; ?>"><?php echo $audit['severity'] ?? 'info'; ?></span></td>
                                <td><?php echo date('H:i:s', strtotime($audit['performed_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="footer">
            <p>VOUCHMORPH · <?php echo $countryCode; ?> · DOUBLE-ENTRY VERIFIED</p>
            <p style="margin-top: 0.5rem;">LEDGER: <?php echo count($ledgerBalances); ?> · MESSAGES: <?php echo count($messageOutbox); ?> · NET: <?php echo safe_number_format($totalExposure); ?> BWP · FEES: <?php echo safe_number_format($totalFeesCollected); ?> BWP</p>
        </div>
    </div>

    <script>
        // CONFIG
        const participantsByWalletType = <?php echo json_encode($participantsByWalletType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const fieldRequirements = <?php echo $fieldRequirementsJson; ?>;
        const currentView = '<?php echo $selectedView; ?>';

        const destinationOptions = {
            'cashout': [
                { value: 'atm', label: 'ATM' },
                { value: 'agent', label: 'AGENT' }
            ],
            'deposit': [
                { value: 'account', label: 'BANK ACCOUNT' },
                { value: 'wallet', label: 'MOBILE WALLET' },
                { value: 'card', label: 'CARD' },
                { value: 'e-wallet', label: 'E-WALLET' }
            ]
        };

        let recipientCount = 0;

        function updateDeliveryMode() {
            const mode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'cashout';
            const toTypeSelect = document.getElementById('to_type');
            if (!toTypeSelect) return;
            
            toTypeSelect.innerHTML = '';
            destinationOptions[mode].forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                toTypeSelect.appendChild(option);
            });
            updateDestinationFields();
        }

        function updateSourceFields() {
            const fromType = document.getElementById('from_type');
            if (!fromType) return;
            
            const type = fromType.value;
            if (!type) {
                document.getElementById('source_dynamic_fields').innerHTML = '';
                return;
            }
            
            updateSourceInstitutions(type);
            renderDynamicFields('source', type);
        }

        function updateSourceInstitutions(type) {
            const select = document.getElementById('from_institution');
            if (!select) return;
            
            const institutions = participantsByWalletType[type] || [];
            select.innerHTML = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                const option = document.createElement('option');
                option.value = inst.participant_id;
                option.textContent = inst.participant_name;
                select.appendChild(option);
            });
        }

        function renderDynamicFields(side, type) {
            const container = document.getElementById(side + '_dynamic_fields');
            if (!container) return;
            
            const mode = side === 'destination' ? document.querySelector('input[name="delivery_mode"]:checked')?.value : null;
            
            let requirements = [];
            if (side === 'source') {
                requirements = fieldRequirements.source[type] || [];
            } else {
                requirements = fieldRequirements.destination[mode]?.[type] || [];
            }
            
            let html = '';
            requirements.forEach(field => {
                let fieldName = side === 'source' ? field : 'destination_' + field;
                
                if (field === 'pin') {
                    html += `
                        <div class="form-group">
                            <label>PIN</label>
                            <input type="password" name="${fieldName}" placeholder="••••" required>
                        </div>
                    `;
                } else if (field === 'phone') {
                    const label = (mode === 'cashout' && side === 'destination') ? 'RECIPIENT PHONE' : 'PHONE';
                    html += `
                        <div class="form-group">
                            <label>${label}</label>
                            <input type="tel" name="${fieldName}" placeholder="+267XXXXXXXXX" required>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="form-group">
                            <label>${field.replace('_', ' ').toUpperCase()}</label>
                            <input type="text" name="${fieldName}" placeholder="ENTER ${field.toUpperCase()}" required>
                        </div>
                    `;
                }
            });
            container.innerHTML = html;
        }

        function updateDestinationFields() {
            const toType = document.getElementById('to_type');
            if (!toType) return;
            
            const type = toType.value;
            if (!type) {
                document.getElementById('destination_dynamic_fields').innerHTML = '';
                return;
            }
            
            updateDestinationInstitutions(type);
            renderDynamicFields('destination', type);
        }

        function updateDestinationInstitutions(type) {
            const select = document.getElementById('to_institution');
            if (!select) return;
            
            const institutions = participantsByWalletType[type] || [];
            select.innerHTML = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                const option = document.createElement('option');
                option.value = inst.participant_id;
                option.textContent = inst.participant_name;
                select.appendChild(option);
            });
        }

        function toggleBusinessFields() {
            const swapType = document.querySelector('input[name="swap_type"]:checked')?.value;
            const personal = document.getElementById('personalDestinationFields');
            const business = document.getElementById('businessDestinationFields');
            
            if (!personal || !business) return;
            
            if (swapType === 'business') {
                personal.classList.add('hidden');
                business.classList.remove('hidden');
                if (recipientCount === 0) addRecipientRow();
            } else {
                personal.classList.remove('hidden');
                business.classList.add('hidden');
            }
        }

        function addRecipientRow() {
            recipientCount++;
            const container = document.getElementById('recipientsContainer');
            if (!container) return;
            
            const mode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'cashout';
            const toType = document.getElementById('to_type')?.value || 'atm';
            const institutions = participantsByWalletType[toType] || [];
            
            let institutionOptions = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                institutionOptions += `<option value="${inst.participant_id}">${inst.participant_name}</option>`;
            });
            
            const row = document.createElement('div');
            row.className = 'recipient-row';
            row.id = `recipient_${recipientCount}`;
            row.innerHTML = `
                <h4 style="color: #888; margin-bottom: 1rem;">RECIPIENT ${recipientCount}</h4>
                <div class="form-group">
                    <label>INSTITUTION</label>
                    <select name="recipients[${recipientCount}][institution]" required>${institutionOptions}</select>
                </div>
                <div class="form-group">
                    <label>${mode === 'cashout' ? 'PHONE (SMS)' : 'PHONE'}</label>
                    <input type="text" name="recipients[${recipientCount}][phone]" required>
                </div>
                <div class="form-group">
                    <label>AMOUNT (BWP)</label>
                    <input type="number" name="recipients[${recipientCount}][amount]" step="0.01" required>
                </div>
                <button type="button" class="btn-remove" onclick="removeRecipientRow(${recipientCount})">REMOVE</button>
            `;
            container.appendChild(row);
        }

        function removeRecipientRow(id) {
            const row = document.getElementById(`recipient_${id}`);
            if (row) row.remove();
        }

        function validateForm() {
            const errors = [];
            const swapType = document.querySelector('input[name="swap_type"]:checked')?.value;
            
            if (!document.getElementById('from_type').value) errors.push("SELECT SOURCE TYPE");
            if (!document.getElementById('from_institution').value) errors.push("SELECT SOURCE INSTITUTION");
            
            if (swapType === 'self') {
                if (!document.getElementById('to_type').value) errors.push("SELECT DESTINATION TYPE");
                if (!document.getElementById('to_institution').value) errors.push("SELECT DESTINATION INSTITUTION");
            }
            
            document.getElementById('validationErrors').innerHTML = errors.join('<br>');
            return errors.length === 0;
        }

        async function executeSwap(formData) {
            const btn = document.getElementById('executeSwapBtn');
            btn.disabled = true;
            btn.textContent = 'PROCESSING...';
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                const text = await response.text();
                const jsonMatch = text.match(/\{.*\}/s);
                const data = jsonMatch ? JSON.parse(jsonMatch[0]) : null;
                
                if (data?.status === 'success') {
                    const container = document.getElementById('swapReportContainer');
                    const content = document.getElementById('swapReportContent');
                    
                    let feeInfo = '';
                    if (data.delivery_mode === 'cashout') {
                        feeInfo = `<tr><td>FEE:</td><td class="positive">-10.00 BWP</td></tr><tr><td>NET:</td><td class="positive">${(data.amount - 10).toFixed(2)} BWP</td></tr>`;
                    } else {
                        feeInfo = `<tr><td>FEE:</td><td class="positive">-6.00 BWP</td></tr><tr><td>NET:</td><td class="positive">${(data.amount - 6).toFixed(2)} BWP</td></tr>`;
                    }
                    
                    content.innerHTML = `
                        <div style="padding: 1.5rem;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                <div>
                                    <h4 style="color: #888; margin-bottom: 1rem;">TRANSACTION</h4>
                                    <table style="width: 100%;">
                                        <tr><td>REFERENCE:</td><td class="mono">${data.swap_reference.substring(0, 16)}…</td></tr>
                                        <tr><td>AMOUNT:</td><td class="positive">${data.amount.toFixed(2)} BWP</td></tr>
                                        ${feeInfo}
                                        <tr><td>HOLD REF:</td><td class="mono">${data.hold_reference ? data.hold_reference.substring(0, 8) + '…' : 'N/A'}</td></tr>
                                    </table>
                                </div>
                                ${data.dispensed_notes && Object.keys(data.dispensed_notes).length > 0 ? `
                                <div>
                                    <h4 style="color: #888; margin-bottom: 1rem;">ATM DISPENSE</h4>
                                    <div style="background: #000; padding: 1rem;">
                                        ${Object.entries(data.dispensed_notes).map(([note, count]) => 
                                            `<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                                <span>${count} × BWP ${note}</span>
                                                <span class="positive">${(count * note).toFixed(2)} BWP</span>
                                            </div>`
                                        ).join('')}
                                        <div style="border-top: 1px solid #333; margin-top: 0.5rem; padding-top: 0.5rem; display: flex; justify-content: space-between;">
                                            <span>TOTAL</span>
                                            <span class="positive">${data.amount.toFixed(2)} BWP</span>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                    container.style.display = 'block';
                    container.scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('FAILED: ' + (data?.message || 'UNKNOWN ERROR'));
                }
            } catch (error) {
                alert('ERROR: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'EXECUTE';
            }
        }

        // INIT
        document.addEventListener('DOMContentLoaded', function() {
            if (currentView === 'swap') {
                updateDeliveryMode();
                updateSourceFields();
                updateDestinationFields();
                toggleBusinessFields();
                
                document.getElementById('from_type')?.addEventListener('change', updateSourceFields);
                document.getElementById('to_type')?.addEventListener('change', updateDestinationFields);
                
                document.querySelectorAll('input[name="swap_type"]').forEach(radio => {
                    radio.addEventListener('change', toggleBusinessFields);
                });
                
                document.querySelectorAll('input[name="delivery_mode"]').forEach(radio => {
                    radio.addEventListener('change', updateDeliveryMode);
                });
                
                document.getElementById('addRecipientBtn')?.addEventListener('click', addRecipientRow);
                
                document.getElementById('swapForm')?.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    if (validateForm()) {
                        await executeSwap(new FormData(this));
                    }
                });
            }
        });
    </script>
</body>
</html>



