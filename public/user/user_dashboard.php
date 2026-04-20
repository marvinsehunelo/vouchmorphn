<?php
// MUST be the very first thing - no whitespace before this!
ob_start();

// Disable error reporting for AJAX requests to prevent JSON corruption
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Include files AFTER output buffering starts
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

// Start session
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? null;
$systemCountry = $user['country'] ?? 'BW';

// Load country configuration
$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("System error");
}

/* =========================
   HELPER FUNCTIONS
========================= */
function formatPhoneNumberForSwap($phoneNumber, $countryCode = 'BW') {
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $countryCodes = ['BW' => '267', 'KE' => '254', 'NG' => '234', 'ZA' => '27', 'GH' => '233'];
    $code = $countryCodes[$countryCode] ?? '267';
    if (empty($cleanNumber)) return '';
    if (substr($cleanNumber, 0, strlen($code)) === $code) return '+' . $cleanNumber;
    if (substr($cleanNumber, 0, 1) === '0') $cleanNumber = substr($cleanNumber, 1);
    return '+' . $code . $cleanNumber;
}

function safeJsonDecode($value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function participantIcon(array $participant): string {
    $type = strtoupper((string)($participant['type'] ?? ''));
    $category = strtoupper((string)($participant['category'] ?? ''));
    if ($type === 'MNO') return '📱';
    if ($category === 'CARD') return '💳';
    return '🏦';
}

function maskValue(string $value, int $visible = 4): string {
    $value = trim($value);
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= $visible) return str_repeat('*', $len);
    return str_repeat('*', $len - $visible) . substr($value, -$visible);
}

/* =========================
   LOAD PARTICIPANTS FOR SWAPSERVICE
========================= */
$stmt = $db->prepare("
    SELECT participant_id, name, type, category, provider_code, auth_type, base_url,
           capabilities, resource_endpoints, phone_format, security_config,
           message_profile, routing_info, metadata, status
    FROM participants
    WHERE status = 'ACTIVE'
    ORDER BY name
");
$stmt->execute();
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build participant config for SwapService
$participantConfig = [];
foreach ($participants as $p) {
   $participantConfig[$p['provider_code']] = [
    'participant_id' => $p['participant_id'],
    'name' => $p['name'],
    'provider_code' => $p['provider_code'],
    'type' => $p['type'],
    'category' => $p['category'],
    'auth_type' => $p['auth_type'],
    'base_url' => $p['base_url'],
    'capabilities' => safeJsonDecode($p['capabilities'] ?? '{}'),
    'resource_endpoints' => safeJsonDecode($p['resource_endpoints'] ?? '{}'),
    'phone_format' => safeJsonDecode($p['phone_format'] ?? '{}'),
    'security_config' => safeJsonDecode($p['security_config'] ?? '{}'),
    'message_profile' => safeJsonDecode($p['message_profile'] ?? '{}'),
    'routing_info' => safeJsonDecode($p['routing_info'] ?? '{}'),
    'metadata' => safeJsonDecode($p['metadata'] ?? '{}')
];
}

// Load country-specific configs
$countryConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$systemCountry}/config.php";
$countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : [];

// Initialize SwapService
$encryptionKey = $config['encryption']['key'] ?? getenv('ENCRYPTION_KEY') ?: 'default-encryption-key-32-chars!!';
$swapService = null;

try {
    $swapService = new SwapService(
        $db,
        $countryConfig,
        $systemCountry,
        $encryptionKey,
        $participantConfig
    );
    error_log("SwapService initialized successfully in dashboard");
} catch (\Exception $e) {
    error_log("Failed to initialize SwapService: " . $e->getMessage());
    // Continue without SwapService - will use fallback
}

/* =========================
   LOAD USER TRANSACTIONS
========================= */
// For PostgreSQL JSONB query
$stmt = $db->prepare("
    SELECT swap_id, swap_uuid, from_currency, to_currency, amount, 
           source_details, destination_details, status, created_at, metadata
    FROM swap_requests
    WHERE CAST(metadata AS TEXT) LIKE :phone_pattern 
       OR CAST(metadata AS TEXT) LIKE :user_pattern
    ORDER BY created_at DESC
    LIMIT 50
");
$userPhonePattern = '%' . $userPhone . '%';
$userIdPattern = '%' . $userId . '%';
$stmt->bindValue(':phone_pattern', $userPhonePattern);
$stmt->bindValue(':user_pattern', $userIdPattern);
$stmt->execute();
$userTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also get card authorizations for this user
$stmt = $db->prepare("
    SELECT ca.*, sr.amount, sr.created_at as swap_created_at
    FROM card_authorizations ca
    JOIN swap_requests sr ON ca.swap_reference = sr.swap_uuid
    WHERE CAST(sr.metadata AS TEXT) LIKE :phone_pattern 
       OR CAST(sr.metadata AS TEXT) LIKE :user_pattern
    ORDER BY ca.created_at DESC
    LIMIT 20
");
$stmt->bindValue(':phone_pattern', $userPhonePattern);
$stmt->bindValue(':user_pattern', $userIdPattern);
$stmt->execute();
$cardAuthorizations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   HANDLE SWAP VIA SWAPSERVICE
========================= */
$error = null;
$success = null;
$swapResult = null;

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap') {
    
    if ($isAjax) {
        // Clean output buffers before sending JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
    }
    
    try {
        $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
        $sourceInstitution = trim($_POST['source_institution'] ?? '');
        $destinationInstitution = trim($_POST['destination_institution'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $destinationType = strtolower(trim($_POST['destination_type'] ?? ''));
        $destinationValue = trim($_POST['destination_value'] ?? '');
        
        // Validate amount
        if ($amount <= 0) {
            throw new \Exception("Amount must be greater than 0");
        }
        
        // Format destination value if it's a phone number
        if (preg_match('/^[0-9+\-\(\)\s]+$/', $destinationValue) && strlen(preg_replace('/[^0-9]/', '', $destinationValue)) >= 8) {
            $destinationValue = formatPhoneNumberForSwap($destinationValue, $systemCountry);
        }
        
        // Get source reference based on source type
        $sourceReference = null;
        $sourceExtra = [];
        
        switch ($sourceType) {
            case 'WALLET':
                $sourceReference = formatPhoneNumberForSwap($userPhone, $systemCountry);
                if (empty($sourceReference)) throw new \Exception("Phone number is required");
                $sourceExtra = ['phone' => $sourceReference, 'wallet_phone' => $sourceReference];
                break;
            case 'ACCOUNT':
                $sourceReference = trim($_POST['account_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Account number is required");
                $sourceExtra = [
                    'account_number' => $sourceReference,
                    'account_phone' => trim($_POST['account_phone'] ?? '')
                ];
                if (!empty($_POST['account_pin'])) {
                    $sourceExtra['account_pin'] = $_POST['account_pin'];
                }
                break;
            case 'CARD':
                $sourceReference = trim($_POST['card_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Card number is required");
                $sourceExtra = [
                    'card_number' => $sourceReference,
                    'card_phone' => trim($_POST['card_phone'] ?? '')
                ];
                if (!empty($_POST['card_pin'])) {
                    $sourceExtra['card_pin'] = $_POST['card_pin'];
                }
                break;
            case 'VOUCHER':
                $sourceReference = trim($_POST['voucher_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Voucher number is required");
                $sourceExtra = [
                    'voucher_number' => $sourceReference,
                    'claimant_phone' => trim($_POST['voucher_phone'] ?? $userPhone)
                ];
                if (!empty($_POST['voucher_pin'])) {
                    $sourceExtra['voucher_pin'] = $_POST['voucher_pin'];
                }
                break;
            default:
                throw new \Exception("Invalid source type");
        }
        
        // Build destination details
        $destinationDetails = [];
        switch ($destinationType) {
            case 'cashout':
                if (empty($destinationValue)) throw new \Exception("Beneficiary phone is required");
                $destinationDetails = [
                    'cashout' => [
                        'beneficiary_phone' => $destinationValue,
                        'beneficiary' => $destinationValue
                    ]
                ];
                break;
            case 'wallet':
                if (empty($destinationValue)) throw new \Exception("Wallet number is required");
                $destinationDetails = ['beneficiary_wallet' => $destinationValue];
                break;
            case 'bank':
                if (empty($destinationValue)) throw new \Exception("Account number is required");
                $destinationDetails = ['beneficiary_account' => $destinationValue];
                break;
            case 'card':
                if (empty($destinationValue)) throw new \Exception("Card suffix is required");
                $destinationDetails = ['card_suffix' => $destinationValue];
                $destinationType = 'card_load'; // Map to card_load for SwapService
                break;
            default:
                throw new \Exception("Invalid destination type");
        }
        
        // Build payload for SwapService
        $swapPayload = [
            'source' => array_merge([
                'institution' => $sourceInstitution,
                'asset_type' => $sourceType,
                'amount' => $amount,
                'currency' => 'BWP',
                'reference' => $sourceReference
            ], $sourceExtra),
            'destination' => array_merge([
                'institution' => $destinationInstitution,
                'delivery_mode' => $destinationType,
                'amount' => $amount,
                'currency' => 'BWP'
            ], $destinationDetails),
            'currency' => 'BWP',
            'metadata' => [
                'user_id' => $userId,
                'user_phone' => $userPhone,
                'channel' => 'user_dashboard',
                'system_country' => $systemCountry,
                'ui_source_type' => $sourceType,
                'ui_destination_type' => $destinationType,
                'masked_source_reference' => maskValue($sourceReference),
                'masked_destination_value' => maskValue($destinationValue)
            ]
        ];
        
        // Execute swap via SwapService
        if ($swapService !== null) {
            error_log("Executing swap via SwapService: " . json_encode($swapPayload));
            $result = $swapService->executeSwap($swapPayload);
            error_log("SwapService result: " . json_encode($result));
            
            if ($result['status'] === 'success') {
                $swapRef = $result['swap_reference'];
                
                // Calculate fee and net amount
                $fee = $result['fee'] ?? ($destinationType === 'cashout' ? 10.00 : ($destinationType === 'card_load' ? 6.00 : 6.00));
                $netAmount = $result['net_amount'] ?? ($amount - $fee);
                
                $swapResult = [
                    'status' => 'success',
                    'swap_reference' => $swapRef,
                    'amount' => $amount,
                    'delivery_mode' => $destinationType,
                    'fee' => $fee,
                    'net_amount' => $netAmount,
                    'hold_reference' => $result['hold_reference'] ?? null
                ];
                
                // Add card details if present
                if (isset($result['card_details'])) {
                    $swapResult['card_details'] = $result['card_details'];
                }
                
                // Add dispensed notes if present
                if (isset($result['dispensed_notes'])) {
                    $swapResult['dispensed_notes'] = $result['dispensed_notes'];
                }
                
                $success = "✅ Swap executed successfully! Reference: " . substr($swapRef, 0, 16) . "…";
                
                if ($isAjax) {
                    echo json_encode($swapResult);
                    exit;
                }
            } else {
                throw new \Exception($result['message'] ?? 'Swap execution failed');
            }
        } else {
            // Fallback: Store as pending if SwapService not available
            $swapRef = 'SWP-' . strtoupper(bin2hex(random_bytes(6)));
            $metadata = array_merge($swapPayload['metadata'], ['fallback_mode' => true]);
            
            $stmt = $db->prepare("
                INSERT INTO swap_requests (swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata)
                VALUES (:swap_uuid, :from_currency, :to_currency, :amount, :source_details, :destination_details, :status, NOW(), :metadata)
            ");
            $stmt->execute([
                ':swap_uuid' => $swapRef,
                ':from_currency' => 'BWP',
                ':to_currency' => 'BWP',
                ':amount' => $amount,
                ':source_details' => json_encode($swapPayload['source'], JSON_UNESCAPED_UNICODE),
                ':destination_details' => json_encode($swapPayload['destination'], JSON_UNESCAPED_UNICODE),
                ':status' => 'pending',
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
            ]);
            
            $fee = $destinationType === 'cashout' ? 10.00 : 6.00;
            $swapResult = [
                'status' => 'success',
                'swap_reference' => $swapRef,
                'amount' => $amount,
                'delivery_mode' => $destinationType,
                'fee' => $fee,
                'net_amount' => $amount - $fee
            ];
            $success = "✅ Swap request created! Reference: " . substr($swapRef, 0, 16) . "… (pending processing)";
            
            if ($isAjax) {
                echo json_encode($swapResult);
                exit;
            }
        }
        
    } catch (\Exception $e) {
        error_log("USER DASHBOARD SWAP ERROR: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $error = $e->getMessage();
        
        if ($isAjax) {
            echo json_encode(['status' => 'error', 'message' => $error]);
            exit;
        }
    }
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

// Flush output buffer before HTML
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph™ – Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=general-sans@400,500,600&f[]=space-grotesk@400,500,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    /* ... (same CSS as before) ... */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background: #050505;
        font-family: 'Inter', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
        padding: 1.5rem;
        position: relative;
        overflow-x: hidden;
    }

    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: 
            linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
        background-size: 50px 50px;
        pointer-events: none;
        z-index: 0;
    }

    .main-container {
        max-width: 860px;
        margin: 0 auto;
        position: relative;
        z-index: 2;
    }

    .header-card, .swap-card, .transactions-card, .report-container, .cards-card {
        background: rgba(5, 5, 5, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(10px);
        margin-bottom: 1.5rem;
        overflow: hidden;
        padding: 1.75rem;
    }

    .header-card { padding: 1.5rem; }

    .user-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .user-details {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .user-avatar {
        width: 56px;
        height: 56px;
        background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .user-avatar i {
        font-size: 1.75rem;
        color: #050505;
    }

    .user-text h2 {
        font-family: 'Clash Display', sans-serif;
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .phone-highlight {
        font-family: 'Space Grotesk', monospace;
        font-size: 0.875rem;
        font-weight: 600;
        color: #00F0FF;
        background: rgba(0, 240, 255, 0.1);
        padding: 0.25rem 0.75rem;
        border: 1px solid rgba(0, 240, 255, 0.3);
        display: inline-block;
    }

    .system-badge, .logout-btn {
        padding: 0.5rem 1rem;
        font-size: 0.75rem;
        font-weight: 500;
        text-decoration: none;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #E0E0E0;
        transition: all 0.2s;
    }

    .logout-btn:hover {
        border-color: #00F0FF;
        color: #00F0FF;
        background: rgba(0, 240, 255, 0.05);
    }

    .alert {
        padding: 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-left: 3px solid;
        background: rgba(5, 5, 5, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-left-width: 3px;
    }

    .alert-error { border-left-color: #FF3030; }
    .alert-success { border-left-color: #00F0FF; }

    .card-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .card-title i {
        font-size: 1.25rem;
        color: #00F0FF;
    }

    .card-title h3 {
        font-family: 'Clash Display', sans-serif;
        font-size: 1.25rem;
        font-weight: 600;
    }

    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
        color: #C0C0D0;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 0.875rem 1rem;
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #FFFFFF;
        font-family: 'Inter', sans-serif;
        font-size: 0.875rem;
        transition: all 0.2s;
    }

    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: #00F0FF;
        box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .dynamic-fields {
        background: rgba(0, 240, 255, 0.03);
        border: 1px solid rgba(0, 240, 255, 0.1);
        padding: 1.25rem;
        margin: 1rem 0;
    }

    .swap-btn {
        width: 100%;
        padding: 1rem;
        background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
        color: #050505;
        border: none;
        font-family: 'General Sans', sans-serif;
        font-weight: 700;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 0.5rem;
    }

    .swap-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4);
    }

    .swap-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .info-box {
        background: rgba(0, 240, 255, 0.05);
        border-left: 3px solid #00F0FF;
        padding: 1rem;
        margin-top: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.75rem;
        color: #A0A0B0;
    }

    .info-box i {
        color: #00F0FF;
        font-size: 1rem;
    }

    .transaction-item, .card-item {
        padding: 1rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }

    .transaction-item:last-child, .card-item:last-child { border-bottom: none; }

    .transaction-date, .card-date {
        font-size: 0.7rem;
        color: #606070;
        margin-bottom: 0.25rem;
    }

    .transaction-amount, .card-amount {
        font-weight: 700;
        font-size: 1rem;
        color: #00F0FF;
    }

    .transaction-status, .card-status {
        font-size: 0.65rem;
        padding: 0.2rem 0.5rem;
        font-weight: 600;
        display: inline-block;
        margin-top: 0.25rem;
    }

    .status-pending, .status-pending_auth {
        background: rgba(255, 193, 7, 0.15);
        color: #FFC107;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }
    .status-completed, .status-processing {
        background: rgba(0, 240, 255, 0.15);
        color: #00F0FF;
        border: 1px solid rgba(0, 240, 255, 0.3);
    }
    .status-failed, .status-cancelled, .status-expired {
        background: rgba(255, 48, 48, 0.15);
        color: #FF6060;
        border: 1px solid rgba(255, 48, 48, 0.3);
    }
    .status-active {
        background: rgba(0, 240, 255, 0.15);
        color: #00F0FF;
        border: 1px solid rgba(0, 240, 255, 0.3);
    }

    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #606070;
    }

    .empty-state i {
        font-size: 2rem;
        margin-bottom: 0.75rem;
        opacity: 0.5;
    }

    .report-container {
        display: none;
    }
    .report-container.visible { display: block; }

    .fee-breakdown {
        background: rgba(0, 240, 255, 0.05);
        padding: 1rem;
        margin-top: 1rem;
        border: 1px solid rgba(0, 240, 255, 0.1);
    }
    .fee-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        font-size: 0.8125rem;
    }
    .fee-row.total {
        border-bottom: none;
        margin-top: 0.5rem;
        padding-top: 0.75rem;
        border-top: 2px solid rgba(0, 240, 255, 0.2);
        font-weight: 700;
        color: #00F0FF;
    }

    .card-suffix {
        font-family: 'Space Grotesk', monospace;
        font-size: 0.875rem;
        font-weight: 600;
        color: #00F0FF;
    }

    @media (max-width: 640px) {
        body { padding: 1rem; }
        .form-row { grid-template-columns: 1fr; }
        .user-info { flex-direction: column; align-items: flex-start; }
        .swap-card, .transactions-card, .cards-card { padding: 1.25rem; }
    }
</style>
</head>
<body>

<div class="main-container">

    <div class="header-card">
        <div class="user-info">
            <div class="user-details">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-text">
                    <h2>Welcome Back</h2>
                    <p>
                        <span class="phone-highlight"><?= htmlspecialchars($userPhone) ?></span>
                        <span style="margin-left: 0.5rem; color: #606070;">• Your VouchMorph ID</span>
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <div class="system-badge">
                    <i class="fas fa-globe"></i> <?= htmlspecialchars(strtoupper($systemCountry)) ?>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> EXIT
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <div class="swap-card">
        <div class="card-title">
            <i class="fas fa-bolt"></i>
            <h3>New Swap</h3>
        </div>

        <form method="POST" id="swapForm">
            <input type="hidden" name="action" value="swap">

            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-exchange-alt"></i> Source Type</label>
                    <select name="source_type" id="sourceType" class="form-select" required>
                        <option value="WALLET">📱 Mobile Wallet (My Phone)</option>
                        <option value="ACCOUNT">🏦 Bank Account</option>
                        <option value="CARD">💳 Card</option>
                        <option value="VOUCHER">🎫 Voucher</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-building"></i> Source Institution</label>
                    <select name="source_institution" class="form-select" required>
                        <option value="">Select institution</option>
                        <?php foreach ($participants as $p): ?>
                            <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                                <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="dynamicContainer"></div>

            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-money-bill-wave"></i> Amount (BWP)</label>
                    <input type="number" name="amount" id="amount" class="form-control" step="0.01" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Destination Type</label>
                    <select name="destination_type" id="destType" class="form-select" required>
                        <option value="cashout">💰 Cashout</option>
                        <option value="card">💳 Load Card</option>
                        <option value="bank">🏦 Bank Account</option>
                        <option value="wallet">📱 Mobile Wallet</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-building"></i> Destination Institution</label>
                <select name="destination_institution" class="form-select" required>
                    <option value="">Select destination institution</option>
                    <?php foreach ($participants as $p): ?>
                        <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                            <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user-check"></i> Destination Details</label>
                <input type="text" name="destination_value" id="destValue" class="form-control" placeholder="Phone number or account number" required>
            </div>

            <button type="submit" class="swap-btn" id="submitBtn">
                <i class="fas fa-arrow-right"></i> EXECUTE SWAP
            </button>

            <div class="info-box">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <strong>Powered by SwapService Engine</strong><br>
                    Fee: Cashout 10 BWP · Deposit/Card 6 BWP
                </div>
            </div>
        </form>
    </div>

    <div id="swapReportContainer" class="report-container">
        <div class="card-title">
            <i class="fas fa-chart-line"></i>
            <h3>Swap Execution Report</h3>
        </div>
        <div id="swapReportContent"></div>
    </div>

    <?php if (!empty($cardAuthorizations)): ?>
    <div class="cards-card">
        <div class="card-title">
            <i class="fas fa-credit-card"></i>
            <h3>Your Active Cards</h3>
        </div>
        <?php foreach ($cardAuthorizations as $card): ?>
            <?php if ($card['status'] === 'ACTIVE'): ?>
            <div class="card-item">
                <div class="card-left">
                    <div class="card-date">
                        Issued: <?= date('d M Y', strtotime($card['created_at'])) ?>
                    </div>
                    <div class="card-details">
                        <span class="card-suffix">•••• <?= htmlspecialchars($card['card_suffix']) ?></span>
                        <span class="card-balance"> • Expires: <?= date('d M Y', strtotime($card['expiry_at'])) ?></span>
                    </div>
                </div>
                <div class="card-right">
                    <div class="card-amount">
                        <?= number_format($card['remaining_balance'], 2) ?> BWP
                    </div>
                    <span class="card-status status-<?= strtolower($card['status']) ?>">
                        <?= htmlspecialchars($card['status']) ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="transactions-card">
        <div class="card-title">
            <i class="fas fa-clock"></i>
            <h3>Recent Transactions</h3>
        </div>

        <?php if (empty($userTransactions)): ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No transactions yet</p>
                <p style="font-size: 0.7rem; margin-top: 0.5rem;">Start your first swap above</p>
            </div>
        <?php else: ?>
            <?php foreach ($userTransactions as $tx): ?>
                <?php 
                    $statusClass = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($tx['status'] ?? 'unknown'));
                    $sourceDetails = safeJsonDecode($tx['source_details'] ?? '{}');
                    $destDetails = safeJsonDecode($tx['destination_details'] ?? '{}');
                    $sourceType = $sourceDetails['asset_type'] ?? $sourceDetails['delivery_mode'] ?? 'UNKNOWN';
                    $destType = $destDetails['delivery_mode'] ?? $destDetails['asset_type'] ?? 'UNKNOWN';
                ?>
                <div class="transaction-item">
                    <div class="transaction-left">
                        <div class="transaction-date">
                            <?= date('d M Y • H:i', strtotime($tx['created_at'])) ?>
                        </div>
                        <div class="transaction-details">
                            <?= htmlspecialchars($sourceType) ?>
                            <i class="fas fa-arrow-right" style="font-size: 0.6rem; margin: 0 0.25rem;"></i>
                            <?= htmlspecialchars($destType) ?>
                        </div>
                    </div>
                    <div class="transaction-right">
                        <div class="transaction-amount">
                            <?= number_format((float)($tx['amount'] ?? 0), 2) ?> BWP
                        </div>
                        <span class="transaction-status status-<?= htmlspecialchars($statusClass) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', strtoupper($tx['status'] ?? 'UNKNOWN'))) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
const sourceType = document.getElementById('sourceType');
const dynamicContainer = document.getElementById('dynamicContainer');
const destType = document.getElementById('destType');
const destValue = document.getElementById('destValue');
const submitBtn = document.getElementById('submitBtn');
const amountInput = document.getElementById('amount');

function updateDestinationPlaceholder() {
    const type = destType.value;
    const placeholders = {
        cashout: 'Beneficiary phone number for cash pickup',
        card: 'Card suffix / last 4 digits',
        bank: 'Beneficiary bank account number',
        wallet: 'Beneficiary mobile wallet number'
    };
    destValue.placeholder = placeholders[type] || 'Enter destination';
}

function updateDynamicFields() {
    const type = sourceType.value;
    const userPhoneEl = document.querySelector('.phone-highlight');
    const userPhone = userPhoneEl ? userPhoneEl.innerText.trim() : '';

    if (type === 'WALLET') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-check-circle" style="color:#00F0FF; font-size:20px;"></i>
                    <div>
                        <strong style="color:#FFFFFF;">Using your registered phone number</strong><br>
                        <span style="font-size:13px; color:#A0A0B0;">Source: ${userPhone || 'Your phone'}</span>
                    </div>
                </div>
            </div>
        `;
    } else if (type === 'ACCOUNT') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label>ACCOUNT NUMBER</label>
                    <input type="text" name="account_number" class="form-control" placeholder="Enter your account number" required>
                </div>
                <div class="form-group">
                    <label>ACCOUNT PHONE (Optional)</label>
                    <input type="text" name="account_phone" class="form-control" placeholder="Linked phone number">
                </div>
                <div class="form-group">
                    <label>ACCOUNT PIN</label>
                    <input type="password" name="account_pin" class="form-control" placeholder="Enter your PIN">
                </div>
            </div>
        `;
    } else if (type === 'CARD') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label>CARD NUMBER</label>
                    <input type="text" name="card_number" class="form-control" placeholder="16-digit card number" maxlength="19" required>
                </div>
                <div class="form-group">
                    <label>CARD PHONE (Optional)</label>
                    <input type="text" name="card_phone" class="form-control" placeholder="Linked phone number">
                </div>
                <div class="form-group">
                    <label>CARD PIN</label>
                    <input type="password" name="card_pin" class="form-control" placeholder="Enter your PIN" maxlength="6">
                </div>
            </div>
        `;
    } else if (type === 'VOUCHER') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label>VOUCHER NUMBER</label>
                    <input type="text" name="voucher_number" class="form-control" placeholder="Enter voucher number" required>
                </div>
                <div class="form-group">
                    <label>CLAIMANT PHONE</label>
                    <input type="text" name="voucher_phone" class="form-control" placeholder="Claimant phone number" value="${userPhone || ''}">
                </div>
                <div class="form-group">
                    <label>VOUCHER PIN</label>
                    <input type="password" name="voucher_pin" class="form-control" placeholder="Enter voucher PIN">
                </div>
            </div>
        `;
    }
}

function displaySwapReport(data) {
    const container = document.getElementById('swapReportContainer');
    const content = document.getElementById('swapReportContent');
    
    const fee = data.fee || (data.delivery_mode === 'cashout' ? 10.00 : 6.00);
    const netAmount = data.net_amount || (data.amount - fee);
    
    let cardHtml = '';
    if (data.card_details) {
        cardHtml = `
            <div style="background: rgba(0, 240, 255, 0.1); border-left: 3px solid #00F0FF; padding: 1rem; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-credit-card" style="color: #00F0FF;"></i>
                    <div>
                        <strong>Card Details</strong><br>
                        <span style="font-family: monospace;">${data.card_details.card_suffix ? '•••• ' + data.card_details.card_suffix : ''}</span>
                        ${data.card_details.expiry ? `<br><span style="font-size: 0.7rem;">Expires: ${data.card_details.expiry}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
    
    let notesHtml = '';
    if (data.dispensed_notes && Object.keys(data.dispensed_notes).length > 0) {
        let notesList = [];
        for (const [note, count] of Object.entries(data.dispensed_notes)) {
            notesList.push(`${count} × ${note} BWP`);
        }
        notesHtml = `
            <div style="background: rgba(0, 240, 255, 0.05); padding: 1rem; margin-bottom: 1rem;">
                <strong><i class="fas fa-money-bill"></i> Dispensed Notes:</strong><br>
                ${notesList.join(' + ')}
            </div>
        `;
    }
    
    content.innerHTML = `
        <div>
            <div style="background: rgba(0, 240, 255, 0.05); border-left: 3px solid #00F0FF; padding: 1.25rem; margin-bottom: 1.25rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                    <i class="fas fa-check-circle" style="color: #00F0FF; font-size: 1.5rem;"></i>
                    <h4 style="color: #00F0FF; margin: 0; font-family: 'Clash Display';">Swap Executed Successfully</h4>
                </div>
                <div style="font-family: 'Space Grotesk', monospace; font-size: 0.875rem;">
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                        <span style="color: #A0A0B0;">Reference:</span>
                        <span style="color: #00F0FF;">${data.swap_reference.substring(0, 16)}…</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                        <span style="color: #A0A0B0;">Amount:</span>
                        <span style="color: #00F0FF;">${parseFloat(data.amount).toFixed(2)} BWP</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                        <span style="color: #A0A0B0;">Delivery Mode:</span>
                        <span>${data.delivery_mode.toUpperCase()}</span>
                    </div>
                    ${data.hold_reference ? `
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                        <span style="color: #A0A0B0;">Hold Reference:</span>
                        <span style="color: #00F0FF;">${data.hold_reference.substring(0, 16)}…</span>
                    </div>
                    ` : ''}
                </div>
            </div>
            
            ${cardHtml}
            ${notesHtml}
            
            <div class="fee-breakdown">
                <div class="fee-row">
                    <span>Subtotal:</span>
                    <span>${parseFloat(data.amount).toFixed(2)} BWP</span>
                </div>
                <div class="fee-row">
                    <span>Fee (${data.delivery_mode === 'cashout' ? 'Cashout' : (data.delivery_mode === 'card_load' ? 'Card Load' : 'Deposit')}):</span>
                    <span style="color: #FF6060;">-${parseFloat(fee).toFixed(2)} BWP</span>
                </div>
                <div class="fee-row total">
                    <span>Net Amount:</span>
                    <span>${parseFloat(netAmount).toFixed(2)} BWP</span>
                </div>
            </div>
            
            <div class="info-box" style="margin-top: 1.25rem;">
                <i class="fas fa-chart-line"></i>
                <div>
                    <strong>SwapService Engine</strong><br>
                    This transaction has been processed through the SwapService engine.
                </div>
            </div>
        </div>
    `;
    
    container.classList.add('visible');
    container.scrollIntoView({ behavior: 'smooth' });
}

async function executeSwap(formData) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSING...';
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
        
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Invalid JSON response'); }
        
        if (data.status === 'success') {
            displaySwapReport(data);
            document.getElementById('swapForm').reset();
            updateDynamicFields();
            setTimeout(() => location.reload(), 3000);
        } else {
            alert('❌ Failed: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Swap error:', error);
        alert('Error: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-arrow-right"></i> EXECUTE SWAP';
    }
}

document.getElementById('swapForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const requiredFields = dynamicContainer.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => { 
        if (!field.value.trim()) { 
            field.style.borderColor = '#FF3030'; 
            isValid = false; 
        } 
    });
    if (!destValue.value.trim()) { 
        destValue.style.borderColor = '#FF3030'; 
        isValid = false; 
    }
    if (!amountInput.value || parseFloat(amountInput.value) <= 0) { 
        amountInput.style.borderColor = '#FF3030'; 
        isValid = false; 
    }
    
    if (!isValid) { 
        alert('Please fill in all required fields'); 
        return; 
    }
    
    await executeSwap(new FormData(this));
});

document.addEventListener('focusin', function(e) {
    if (e.target.classList && e.target.classList.contains('form-control')) {
        e.target.style.borderColor = '';
    }
});

sourceType.addEventListener('change', updateDynamicFields);
destType.addEventListener('change', updateDestinationPlaceholder);

updateDynamicFields();
updateDestinationPlaceholder();
</script>

</body>
</html>
