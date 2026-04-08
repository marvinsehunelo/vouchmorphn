<?php
// Disable error reporting for AJAX requests to prevent JSON corruption
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

ob_start();
require_once __DIR__ . '/../../src/APP_LAYER/utils/SessionManager.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? null;
$systemCountry = $user['country'] ?? 'BW';

$config = require __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
$dbConfig = $config['db']['swap'] ?? null;

try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("System error");
}

/* =========================
   PHONE NUMBER FORMATTING FUNCTION
========================= */
function formatPhoneNumberForSwap($phoneNumber, $countryCode = 'BW') {
    // Remove any non-numeric characters
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // Country code mappings
    $countryCodes = [
        'BW' => '267',  // Botswana
        'KE' => '254',  // Kenya
        'NG' => '234',  // Nigeria
    ];
    
    $code = $countryCodes[$countryCode] ?? '267';
    
    // If number is empty
    if (empty($cleanNumber)) {
        return '';
    }
    
    // If number already starts with country code
    if (substr($cleanNumber, 0, strlen($code)) === $code) {
        return '+' . $cleanNumber;
    }
    
    // If number starts with 0 (local format), remove the 0
    if (substr($cleanNumber, 0, 1) === '0') {
        $cleanNumber = substr($cleanNumber, 1);
    }
    
    // Add country code and plus sign
    return '+' . $code . $cleanNumber;
}

/* =========================
   HELPERS
========================= */
function safeJsonDecode($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function participantIcon(array $participant): string
{
    $type = strtoupper((string)($participant['type'] ?? ''));
    $category = strtoupper((string)($participant['category'] ?? ''));

    if ($type === 'MNO') {
        return '📱';
    }

    if ($category === 'CARD') {
        return '💳';
    }

    return '🏦';
}

function maskValue(string $value, int $visible = 4): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $len = strlen($value);
    if ($len <= $visible) {
        return str_repeat('*', $len);
    }

    return str_repeat('*', $len - $visible) . substr($value, -$visible);
}

/* =========================
   LOAD DATA (PERFECT PATTERN FROM ORIGINAL)
========================= */
$stmt = $db->prepare("
    SELECT participant_id, name, type, category, provider_code, auth_type, base_url, resource_endpoints
    FROM participants
    WHERE status = 'ACTIVE'
    ORDER BY name
");
$stmt->execute();
$institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT swap_id, swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata
    FROM swap_requests
    ORDER BY created_at DESC
    LIMIT 150
");
$stmt->execute();
$allRecentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$recentTransactions = [];

foreach ($allRecentSwaps as $row) {
    $metadata = safeJsonDecode($row['metadata'] ?? null);
    $sourceDetails = safeJsonDecode($row['source_details'] ?? null);
    $destinationDetails = safeJsonDecode($row['destination_details'] ?? null);

    $matchesUser =
        ((string)($metadata['user_id'] ?? '') === (string)$userId) ||
        ((string)($metadata['user_phone'] ?? '') === (string)$userPhone) ||
        ((string)($sourceDetails['phone'] ?? '') === (string)$userPhone) ||
        ((string)($sourceDetails['wallet_phone'] ?? '') === (string)$userPhone) ||
        ((string)($sourceDetails['account_phone'] ?? '') === (string)$userPhone) ||
        ((string)($sourceDetails['card_phone'] ?? '') === (string)$userPhone) ||
        ((string)($sourceDetails['claimant_phone'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['beneficiary_wallet'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['beneficiary_account'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['beneficiary_phone'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['cashout']['beneficiary_phone'] ?? '') === (string)$userPhone);

    if ($matchesUser) {
        $row['source_type'] = $sourceDetails['asset_type'] ?? ($sourceDetails['source_type'] ?? 'SOURCE');
        $row['source_institution'] = $sourceDetails['institution'] ?? '';
        $row['destination_type'] = $destinationDetails['delivery_mode'] ?? ($destinationDetails['asset_type'] ?? 'DESTINATION');
        $row['destination_institution'] = $destinationDetails['institution'] ?? '';
        $recentTransactions[] = $row;
    }

    if (count($recentTransactions) >= 10) {
        break;
    }
}

/* =========================
   HANDLE SWAP - FIXED JSON RESPONSE
========================= */
$error = null;
$success = null;
$swapResult = null;

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap') {
    
    // For AJAX requests, we need to ensure NO output before JSON
    if ($isAjax) {
        // Clean all output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Set JSON header
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
        
        // Format destination phone number if it looks like a phone
        if (preg_match('/^[0-9+\-\(\)\s]+$/', $destinationValue) && strlen(preg_replace('/[^0-9]/', '', $destinationValue)) >= 8) {
            $destinationValue = formatPhoneNumberForSwap($destinationValue, $systemCountry);
        }
        
        // Validate required fields
        if ($sourceInstitution === '' || $destinationInstitution === '' || $amount <= 0 || $destinationValue === '') {
            throw new \Exception("Please complete all required fields.");
        }
        
        // Get source reference based on source type
        $sourceReference = null;
        
        switch ($sourceType) {
            case 'WALLET':
                $sourceReference = formatPhoneNumberForSwap($userPhone, $systemCountry);
                if (empty($sourceReference)) throw new \Exception("Phone number is required");
                break;
            case 'ACCOUNT':
                $sourceReference = trim($_POST['account_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Account number is required");
                break;
            case 'CARD':
                $sourceReference = trim($_POST['card_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Card number is required");
                break;
            case 'VOUCHER':
                $sourceReference = trim($_POST['voucher_number'] ?? '');
                if (empty($sourceReference)) throw new \Exception("Voucher number is required");
                break;
            default:
                throw new \Exception("Invalid source type");
        }
        
        // Build source payload
        $sourcePayload = [
            'institution' => $sourceInstitution,
            'asset_type' => $sourceType,
            'amount' => $amount,
            'reference' => $sourceReference
        ];
        
        // Add source-specific fields
        switch ($sourceType) {
            case 'WALLET':
                $sourcePayload['wallet_phone'] = $sourceReference;
                $sourcePayload['phone'] = $sourceReference;
                break;
            case 'ACCOUNT':
                $sourcePayload['account_number'] = $sourceReference;
                $sourcePayload['account_phone'] = trim($_POST['account_phone'] ?? '');
                if (!empty($_POST['account_pin'])) {
                    $sourcePayload['account_pin'] = $_POST['account_pin'];
                }
                break;
            case 'CARD':
                $sourcePayload['card_number'] = $sourceReference;
                $sourcePayload['card_phone'] = trim($_POST['card_phone'] ?? '');
                if (!empty($_POST['card_pin'])) {
                    $sourcePayload['card_pin'] = $_POST['card_pin'];
                }
                break;
            case 'VOUCHER':
                $sourcePayload['voucher_number'] = $sourceReference;
                $sourcePayload['claimant_phone'] = trim($_POST['voucher_phone'] ?? $userPhone);
                if (!empty($_POST['voucher_pin'])) {
                    $sourcePayload['voucher_pin'] = $_POST['voucher_pin'];
                }
                break;
        }
        
        // Build destination payload
        $destinationPayload = [
            'institution' => $destinationInstitution,
            'delivery_mode' => $destinationType,
            'amount' => $amount
        ];
        
        switch ($destinationType) {
            case 'cashout':
                $destinationPayload['beneficiary_phone'] = $destinationValue;
                $destinationPayload['beneficiary'] = $destinationValue;
                break;
            case 'wallet':
                $destinationPayload['beneficiary_wallet'] = $destinationValue;
                break;
            case 'bank':
                $destinationPayload['beneficiary_account'] = $destinationValue;
                break;
            case 'card':
                $destinationPayload['card_suffix'] = $destinationValue;
                break;
            default:
                $destinationPayload['beneficiary_phone'] = $destinationValue;
        }
        
        // Create swap reference
        $swapRef = 'SWP-' . strtoupper(bin2hex(random_bytes(6)));
        
        $metadata = [
            'user_id' => $userId,
            'user_phone' => $userPhone,
            'channel' => 'user_dashboard',
            'system_country' => $systemCountry,
            'ui_source_type' => $sourceType,
            'ui_destination_type' => $destinationType,
            'masked_source_reference' => maskValue($sourceReference),
            'masked_destination_value' => maskValue($destinationValue)
        ];
        
        // Insert swap request into database
        $stmt = $db->prepare("
            INSERT INTO swap_requests (
                swap_uuid,
                from_currency,
                to_currency,
                amount,
                source_details,
                destination_details,
                status,
                created_at,
                metadata
            ) VALUES (
                :swap_uuid,
                :from_currency,
                :to_currency,
                :amount,
                :source_details,
                :destination_details,
                :status,
                NOW(),
                :metadata
            )
        ");
        
        $stmt->execute([
            ':swap_uuid' => $swapRef,
            ':from_currency' => 'BWP',
            ':to_currency' => 'BWP',
            ':amount' => $amount,
            ':source_details' => json_encode($sourcePayload, JSON_UNESCAPED_UNICODE),
            ':destination_details' => json_encode($destinationPayload, JSON_UNESCAPED_UNICODE),
            ':status' => 'pending',
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ]);
        
        $swapResult = [
            'status' => 'success',
            'swap_reference' => $swapRef,
            'amount' => $amount,
            'delivery_mode' => $destinationType,
            'fee' => $destinationType === 'cashout' ? 10.00 : 6.00,
            'net_amount' => $amount - ($destinationType === 'cashout' ? 10.00 : 6.00)
        ];
        
        $success = "✅ Swap created successfully! Reference: " . substr($swapRef, 0, 16) . "…";
        
        // If AJAX request, return JSON and exit
        if ($isAjax) {
            echo json_encode([
                'status' => 'success',
                'swap_reference' => $swapRef,
                'amount' => $amount,
                'delivery_mode' => $destinationType,
                'fee' => $swapResult['fee'],
                'net_amount' => $swapResult['net_amount'],
                'message' => $success
            ]);
            exit;
        }
        
    } catch (\Exception $e) {
        error_log("USER DASHBOARD SWAP ERROR: " . $e->getMessage());
        $error = $e->getMessage();
        
        if ($isAjax) {
            echo json_encode([
                'status' => 'error',
                'message' => $error
            ]);
            exit;
        }
    }
}

// If we're here and it's an AJAX request with no POST action, return error
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request'
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>VouchMorph | Secure Swap Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        padding: 20px;
    }
    .main-container {
        max-width: 760px;
        margin: 0 auto;
    }
    .header-card {
        background: white;
        border-radius: 28px;
        padding: 24px 28px;
        margin-bottom: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        position: relative;
        overflow: hidden;
    }
    .header-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .user-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }
    .user-details {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .user-avatar {
        width: 56px;
        height: 56px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: 600;
    }
    .user-text h2 {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 4px;
    }
    .user-text p {
        font-size: 13px;
        color: #6b7280;
    }
    .system-badge {
        background: #f3f4f6;
        padding: 8px 16px;
        border-radius: 40px;
        font-size: 12px;
        font-weight: 500;
        color: #4b5563;
    }
    .logout-btn {
        background: #f3f4f6;
        padding: 8px 16px;
        border-radius: 40px;
        text-decoration: none;
        color: #6b7280;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s;
    }
    .logout-btn:hover {
        background: #e5e7eb;
        color: #374151;
    }
    .alert {
        background: white;
        border-radius: 20px;
        padding: 16px 20px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    }
    .alert-error {
        border-left: 4px solid #ef4444;
    }
    .alert-error i {
        color: #ef4444;
    }
    .alert-success {
        border-left: 4px solid #10b981;
    }
    .alert-success i {
        color: #10b981;
    }
    .swap-card {
        background: white;
        border-radius: 28px;
        padding: 28px;
        margin-bottom: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    .card-title {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f3f4f6;
    }
    .card-title i {
        font-size: 24px;
        color: #667eea;
    }
    .card-title h3 {
        font-size: 20px;
        font-weight: 700;
        color: #1f2937;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 8px;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .form-control, .form-select {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 16px;
        font-size: 15px;
        font-family: 'Inter', sans-serif;
        transition: all 0.3s;
        background: white;
    }
    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .swap-btn {
        width: 100%;
        padding: 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 20px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .swap-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102,126,234,0.4);
    }
    .swap-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    .dynamic-fields {
        margin-top: 16px;
        margin-bottom: 16px;
        padding: 16px;
        background: #f9fafb;
        border-radius: 20px;
        border: 1px solid #e5e7eb;
    }
    .info-box {
        background: #fef3c7;
        border-radius: 16px;
        padding: 14px 16px;
        margin-top: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 13px;
        color: #92400e;
    }
    .info-box i {
        font-size: 18px;
    }
    .transactions-card {
        background: white;
        border-radius: 28px;
        padding: 28px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    .transaction-item {
        padding: 14px 0;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s;
    }
    .transaction-item:last-child {
        border-bottom: none;
    }
    .transaction-item:hover {
        transform: translateX(4px);
    }
    .transaction-left {
        flex: 1;
    }
    .transaction-date {
        font-size: 11px;
        color: #9ca3af;
        margin-bottom: 4px;
    }
    .transaction-details {
        font-size: 13px;
        color: #4b5563;
    }
    .transaction-right {
        text-align: right;
    }
    .transaction-amount {
        font-weight: 700;
        font-size: 16px;
        color: #1f2937;
    }
    .transaction-status {
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 20px;
        font-weight: 600;
        display: inline-block;
        margin-top: 4px;
    }
    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }
    .status-pending_auth {
        background: #fef3c7;
        color: #92400e;
    }
    .status-completed {
        background: #d1fae5;
        color: #065f46;
    }
    .status-failed {
        background: #fee2e2;
        color: #991b1b;
    }
    .status-unknown {
        background: #e5e7eb;
        color: #374151;
    }
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #9ca3af;
    }
    .empty-state i {
        font-size: 48px;
        margin-bottom: 12px;
        opacity: 0.5;
    }
    .phone-highlight {
        font-family: monospace;
        font-size: 16px;
        font-weight: 600;
        color: #667eea;
        background: #eef2ff;
        padding: 4px 12px;
        border-radius: 20px;
        display: inline-block;
    }
    .report-container {
        margin-top: 20px;
        background: white;
        border-radius: 28px;
        padding: 28px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        display: none;
    }
    .report-container.visible {
        display: block;
    }
    .fee-breakdown {
        background: #f0fdf4;
        border-radius: 16px;
        padding: 16px;
        margin-top: 16px;
        border: 1px solid #bbf7d0;
    }
    .fee-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #dcfce7;
    }
    .fee-row.total {
        border-bottom: none;
        margin-top: 8px;
        padding-top: 12px;
        border-top: 2px solid #86efac;
        font-weight: 700;
        color: #166534;
    }
    @media (max-width: 640px) {
        body {
            padding: 12px;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .user-info {
            flex-direction: column;
            align-items: flex-start;
        }
        .swap-card, .transactions-card, .header-card {
            padding: 20px;
        }
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
                        <span style="margin-left: 8px;">• Your VouchMorph ID</span>
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <div class="system-badge">
                    <i class="fas fa-globe"></i> <?= htmlspecialchars(strtoupper($systemCountry)) ?>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Exit
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle" style="font-size: 20px;"></i>
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
                        <?php foreach ($institutions as $i): ?>
                            <option value="<?= htmlspecialchars($i['provider_code'] ?: $i['name']) ?>">
                                <?= participantIcon($i) ?> <?= htmlspecialchars($i['name']) ?>
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
                    <?php foreach ($institutions as $i): ?>
                        <option value="<?= htmlspecialchars($i['provider_code'] ?: $i['name']) ?>">
                            <?= participantIcon($i) ?> <?= htmlspecialchars($i['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user-check"></i> Destination Details</label>
                <input type="text" name="destination_value" id="destValue" class="form-control" placeholder="Phone number or account number" required>
            </div>

            <button type="submit" class="swap-btn" id="submitBtn">
                <i class="fas fa-arrow-right"></i>
                Execute Swap
            </button>

            <div class="info-box">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <strong>Secure by Design</strong><br>
                    Your transaction will be added to the settlement queue for netting. 
                    Fee: Cashout 10 BWP · Deposit 6 BWP
                </div>
            </div>
        </form>
    </div>

    <!-- Swap Report Container -->
    <div id="swapReportContainer" class="report-container">
        <div class="card-title">
            <i class="fas fa-chart-line"></i>
            <h3>Swap Execution Report</h3>
        </div>
        <div id="swapReportContent"></div>
    </div>

    <div class="transactions-card">
        <div class="card-title">
            <i class="fas fa-clock"></i>
            <h3>Recent Transactions</h3>
        </div>

        <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No transactions yet</p>
                <p style="font-size: 12px; margin-top: 8px;">Start your first swap above</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentTransactions as $tx): ?>
                <?php $statusClass = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($tx['status'] ?? 'unknown')); ?>
                <div class="transaction-item">
                    <div class="transaction-left">
                        <div class="transaction-date">
                            <?= date('d M Y • H:i', strtotime($tx['created_at'])) ?>
                        </div>
                        <div class="transaction-details">
                            <?= htmlspecialchars($tx['source_type'] ?? '?') ?>
                            <i class="fas fa-arrow-right" style="font-size: 10px; margin: 0 4px;"></i>
                            <?= htmlspecialchars($tx['destination_type'] ?? '?') ?>
                            <?php if (!empty($tx['source_institution'])): ?>
                                <span style="margin-left: 8px; color: #6b7280;">
                                    • <?= htmlspecialchars($tx['source_institution']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($tx['destination_institution'])): ?>
                                <span style="margin-left: 8px; color: #6b7280;">
                                    → <?= htmlspecialchars($tx['destination_institution']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="transaction-right">
                        <div class="transaction-amount">
                            <?= number_format((float)($tx['amount'] ?? 0), 2) ?>
                            <?= htmlspecialchars($tx['from_currency'] ?? 'BWP') ?>
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

    if (type === 'WALLET') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-check-circle" style="color:#10b981; font-size:20px;"></i>
                    <div>
                        <strong style="color:#1f2937;">Using your registered phone number</strong><br>
                        <span style="font-size:13px; color:#6b7280;">Source: ${document.querySelector('.phone-highlight')?.innerText || 'Your phone'}</span>
                    </div>
                </div>
            </div>
        `;
    } else if (type === 'ACCOUNT') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label><i class="fas fa-hashtag"></i> Account Number</label>
                    <input type="text" name="account_number" class="form-control" placeholder="Enter your account number" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Account Phone (Optional)</label>
                    <input type="text" name="account_phone" class="form-control" placeholder="Linked phone number">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Account PIN</label>
                    <input type="password" name="account_pin" class="form-control" placeholder="Enter your PIN">
                </div>
            </div>
        `;
    } else if (type === 'CARD') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label><i class="fas fa-credit-card"></i> Card Number</label>
                    <input type="text" name="card_number" class="form-control" placeholder="16-digit card number" maxlength="19" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Card Phone (Optional)</label>
                    <input type="text" name="card_phone" class="form-control" placeholder="Linked phone number">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Card PIN</label>
                    <input type="password" name="card_pin" class="form-control" placeholder="Enter your PIN" maxlength="6">
                </div>
            </div>
        `;
    } else if (type === 'VOUCHER') {
        dynamicContainer.innerHTML = `
            <div class="dynamic-fields">
                <div class="form-group">
                    <label><i class="fas fa-ticket"></i> Voucher Number</label>
                    <input type="text" name="voucher_number" class="form-control" placeholder="Enter voucher number" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Claimant Phone</label>
                    <input type="text" name="voucher_phone" class="form-control" placeholder="Claimant phone number" value="${document.querySelector('.phone-highlight')?.innerText || ''}">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Voucher PIN</label>
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
    
    content.innerHTML = `
        <div style="padding: 1rem;">
            <div style="background: #f0fdf4; border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <i class="fas fa-check-circle" style="color: #10b981; font-size: 28px;"></i>
                    <h4 style="color: #065f46; margin: 0;">Swap Executed Successfully</h4>
                </div>
                <div style="font-family: monospace; font-size: 14px;">
                    <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                        <span>Reference:</span>
                        <span style="font-weight: 600;">${data.swap_reference.substring(0, 16)}…</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                        <span>Amount:</span>
                        <span style="font-weight: 600; color: #059669;">${data.amount.toFixed(2)} BWP</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                        <span>Delivery Mode:</span>
                        <span style="font-weight: 600;">${data.delivery_mode.toUpperCase()}</span>
                    </div>
                </div>
            </div>
            
            <div class="fee-breakdown">
                <div class="fee-row">
                    <span>Subtotal:</span>
                    <span>${data.amount.toFixed(2)} BWP</span>
                </div>
                <div class="fee-row">
                    <span>Fee (${data.delivery_mode === 'cashout' ? 'Cashout' : 'Deposit'}):</span>
                    <span style="color: #dc2626;">-${fee.toFixed(2)} BWP</span>
                </div>
                <div class="fee-row total">
                    <span>Net Amount:</span>
                    <span style="color: #059669;">${netAmount.toFixed(2)} BWP</span>
                </div>
            </div>
            
            <div class="info-box" style="margin-top: 20px; background: #e0f2fe; color: #075985;">
                <i class="fas fa-chart-line"></i>
                <div>
                    <strong>Settlement Queue Updated</strong><br>
                    This transaction has been added to the settlement queue for netting.
                </div>
            </div>
        </div>
    `;
    
    container.classList.add('visible');
    container.scrollIntoView({ behavior: 'smooth' });
}

async function executeSwap(formData) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });
        
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        
        // Try to parse JSON, handle potential HTML responses
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text.substring(0, 500));
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.status === 'success') {
            displaySwapReport(data);
            
            // Clear form
            document.getElementById('swapForm').reset();
            updateDynamicFields();
            
            // Show success message temporarily
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success';
            successDiv.innerHTML = `<i class="fas fa-check-circle"></i><div>${data.message || 'Swap completed successfully!'}</div>`;
            const mainContainer = document.querySelector('.main-container');
            const swapCard = document.querySelector('.swap-card');
            mainContainer.insertBefore(successDiv, swapCard);
            setTimeout(() => successDiv.remove(), 5000);
            
            // Reload transactions after 2 seconds
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Failed: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Swap error:', error);
        alert('Error: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-arrow-right"></i> Execute Swap';
    }
}

// Form validation
document.getElementById('swapForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const requiredFields = dynamicContainer.querySelectorAll('[required]');
    let isValid = true;
    
    for (let field of requiredFields) {
        if (!field.value.trim()) {
            field.style.borderColor = '#ef4444';
            isValid = false;
        }
    }
    
    if (!destValue.value.trim()) {
        destValue.style.borderColor = '#ef4444';
        isValid = false;
    }
    
    if (!amountInput.value || parseFloat(amountInput.value) <= 0) {
        amountInput.style.borderColor = '#ef4444';
        isValid = false;
    }
    
    if (!isValid) {
        alert('Please fill in all required fields');
        return;
    }
    
    await executeSwap(new FormData(this));
});

// Clear error styles on focus
document.addEventListener('focusin', function(e) {
    if (e.target.classList && e.target.classList.contains('form-control')) {
        e.target.style.borderColor = '';
    }
});

// Initialize
sourceType.addEventListener('change', updateDynamicFields);
destType.addEventListener('change', updateDestinationPlaceholder);

updateDynamicFields();
updateDestinationPlaceholder();

const sourceInstSelect = document.querySelector('select[name="source_institution"]');
const destInstSelect = document.querySelector('select[name="destination_institution"]');

if (sourceInstSelect && sourceInstSelect.options.length === 2) {
    sourceInstSelect.selectedIndex = 1;
}
if (destInstSelect && destInstSelect.options.length === 2) {
    destInstSelect.selectedIndex = 1;
}
</script>

</body>
</html>
