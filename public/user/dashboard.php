<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Disable error reporting for AJAX requests to prevent JSON corruption
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

ob_start();
require_once __DIR__ . '/../../src/Application/utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/config/DBConnection.php';

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

$config = require __DIR__ . '/../../src/Core/Config/load_country.php';
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
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $countryCodes = ['BW' => '267', 'KE' => '254', 'NG' => '234'];
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
   LOAD DATA
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
    if (count($recentTransactions) >= 10) break;
}

/* =========================
   HANDLE SWAP
========================= */
$error = null;
$success = null;
$swapResult = null;

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap') {
    
    if ($isAjax) {
        while (ob_get_level() > 0) ob_end_clean();
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
        
        if (preg_match('/^[0-9+\-\(\)\s]+$/', $destinationValue) && strlen(preg_replace('/[^0-9]/', '', $destinationValue)) >= 8) {
            $destinationValue = formatPhoneNumberForSwap($destinationValue, $systemCountry);
        }
        
        if ($sourceInstitution === '' || $destinationInstitution === '' || $amount <= 0 || $destinationValue === '') {
            throw new \Exception("Please complete all required fields.");
        }
        
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
        
        $sourcePayload = ['institution' => $sourceInstitution, 'asset_type' => $sourceType, 'amount' => $amount, 'reference' => $sourceReference];
        switch ($sourceType) {
            case 'WALLET': $sourcePayload['wallet_phone'] = $sourceReference; $sourcePayload['phone'] = $sourceReference; break;
            case 'ACCOUNT': $sourcePayload['account_number'] = $sourceReference; $sourcePayload['account_phone'] = trim($_POST['account_phone'] ?? ''); if (!empty($_POST['account_pin'])) $sourcePayload['account_pin'] = $_POST['account_pin']; break;
            case 'CARD': $sourcePayload['card_number'] = $sourceReference; $sourcePayload['card_phone'] = trim($_POST['card_phone'] ?? ''); if (!empty($_POST['card_pin'])) $sourcePayload['card_pin'] = $_POST['card_pin']; break;
            case 'VOUCHER': $sourcePayload['voucher_number'] = $sourceReference; $sourcePayload['claimant_phone'] = trim($_POST['voucher_phone'] ?? $userPhone); if (!empty($_POST['voucher_pin'])) $sourcePayload['voucher_pin'] = $_POST['voucher_pin']; break;
        }
        
        $destinationPayload = ['institution' => $destinationInstitution, 'delivery_mode' => $destinationType, 'amount' => $amount];
        switch ($destinationType) {
            case 'cashout': $destinationPayload['beneficiary_phone'] = $destinationValue; $destinationPayload['beneficiary'] = $destinationValue; break;
            case 'wallet': $destinationPayload['beneficiary_wallet'] = $destinationValue; break;
            case 'bank': $destinationPayload['beneficiary_account'] = $destinationValue; break;
            case 'card': $destinationPayload['card_suffix'] = $destinationValue; break;
            default: $destinationPayload['beneficiary_phone'] = $destinationValue;
        }
        
        $swapRef = 'SWP-' . strtoupper(bin2hex(random_bytes(6)));
        $metadata = ['user_id' => $userId, 'user_phone' => $userPhone, 'channel' => 'user_dashboard', 'system_country' => $systemCountry, 'ui_source_type' => $sourceType, 'ui_destination_type' => $destinationType, 'masked_source_reference' => maskValue($sourceReference), 'masked_destination_value' => maskValue($destinationValue)];
        
        $stmt = $db->prepare("
            INSERT INTO swap_requests (swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata)
            VALUES (:swap_uuid, :from_currency, :to_currency, :amount, :source_details, :destination_details, :status, NOW(), :metadata)
        ");
        $stmt->execute([
            ':swap_uuid' => $swapRef, ':from_currency' => 'BWP', ':to_currency' => 'BWP',
            ':amount' => $amount, ':source_details' => json_encode($sourcePayload, JSON_UNESCAPED_UNICODE),
            ':destination_details' => json_encode($destinationPayload, JSON_UNESCAPED_UNICODE),
            ':status' => 'pending', ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ]);
        
        $swapResult = ['status' => 'success', 'swap_reference' => $swapRef, 'amount' => $amount, 'delivery_mode' => $destinationType, 'fee' => $destinationType === 'cashout' ? 10.00 : 6.00, 'net_amount' => $amount - ($destinationType === 'cashout' ? 10.00 : 6.00)];
        $success = "✅ Swap created successfully! Reference: " . substr($swapRef, 0, 16) . "…";
        
        if ($isAjax) {
            echo json_encode(['status' => 'success', 'swap_reference' => $swapRef, 'amount' => $amount, 'delivery_mode' => $destinationType, 'fee' => $swapResult['fee'], 'net_amount' => $swapResult['net_amount'], 'message' => $success]);
            exit;
        }
    } catch (\Exception $e) {
        error_log("USER DASHBOARD SWAP ERROR: " . $e->getMessage());
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

    /* GRID BACKGROUND */
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

    /* CUSTOM CURSOR */
    .cursor {
        width: 8px;
        height: 8px;
        background: #00F0FF;
        position: fixed;
        pointer-events: none;
        z-index: 9999;
        mix-blend-mode: difference;
        transition: transform 0.1s ease;
    }

    .cursor-follower {
        width: 40px;
        height: 40px;
        border: 1px solid rgba(0, 240, 255, 0.5);
        position: fixed;
        pointer-events: none;
        z-index: 9998;
        transition: 0.15s ease;
    }

    @media (max-width: 768px) {
        .cursor, .cursor-follower { display: none; }
    }

    .main-container {
        max-width: 760px;
        margin: 0 auto;
        position: relative;
        z-index: 2;
    }

    /* CARDS - SHARP EDGES */
    .header-card, .swap-card, .transactions-card, .report-container {
        background: rgba(5, 5, 5, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(10px);
        border-radius: 0px;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }

    .header-card {
        padding: 1.5rem;
    }

    .swap-card, .transactions-card, .report-container {
        padding: 1.75rem;
    }

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
        border-radius: 0px;
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

    .user-text p {
        font-size: 0.75rem;
        color: #A0A0B0;
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
        border-radius: 0px;
    }

    .logout-btn:hover {
        border-color: #00F0FF;
        color: #00F0FF;
        background: rgba(0, 240, 255, 0.05);
    }

    /* ALERTS */
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
        border-radius: 0px;
    }

    .alert-error { border-left-color: #FF3030; }
    .alert-error i { color: #FF3030; }
    .alert-success { border-left-color: #00F0FF; }
    .alert-success i { color: #00F0FF; }

    /* CARD TITLES */
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

    /* FORMS */
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
        border-radius: 0px;
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

    /* DYNAMIC FIELDS */
    .dynamic-fields {
        background: rgba(0, 240, 255, 0.03);
        border: 1px solid rgba(0, 240, 255, 0.1);
        padding: 1.25rem;
        margin: 1rem 0;
        border-radius: 0px;
    }

    /* BUTTONS */
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
        border-radius: 0px;
    }

    .swap-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4);
    }

    .swap-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    /* INFO BOX */
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

    /* TRANSACTIONS */
    .transaction-item {
        padding: 1rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }

    .transaction-item:last-child { border-bottom: none; }
    .transaction-item:hover { transform: translateX(4px); }

    .transaction-date {
        font-size: 0.7rem;
        color: #606070;
        margin-bottom: 0.25rem;
    }

    .transaction-details {
        font-size: 0.75rem;
        color: #C0C0D0;
    }

    .transaction-amount {
        font-weight: 700;
        font-size: 1rem;
        color: #00F0FF;
    }

    .transaction-status {
        font-size: 0.65rem;
        padding: 0.2rem 0.5rem;
        font-weight: 600;
        display: inline-block;
        margin-top: 0.25rem;
        border-radius: 0px;
    }

    .status-pending, .status-pending_auth {
        background: rgba(255, 193, 7, 0.15);
        color: #FFC107;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }
    .status-completed {
        background: rgba(0, 240, 255, 0.15);
        color: #00F0FF;
        border: 1px solid rgba(0, 240, 255, 0.3);
    }
    .status-failed {
        background: rgba(255, 48, 48, 0.15);
        color: #FF6060;
        border: 1px solid rgba(255, 48, 48, 0.3);
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

    /* REPORT */
    .report-container {
        display: none;
    }
    .report-container.visible { display: block; }

    .fee-breakdown {
        background: rgba(0, 240, 255, 0.05);
        padding: 1rem;
        margin-top: 1rem;
        border: 1px solid rgba(0, 240, 255, 0.1);
        border-radius: 0px;
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

    @media (max-width: 640px) {
        body { padding: 1rem; }
        .form-row { grid-template-columns: 1fr; }
        .user-info { flex-direction: column; align-items: flex-start; }
        .swap-card, .transactions-card { padding: 1.25rem; }
    }
</style>
</head>
<body>

<div class="cursor"></div>
<div class="cursor-follower"></div>

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

    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endif; ?>

    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endif; ?>

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
                        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($institutions as $i): ?>
                            <option value="<?= htmlspecialchars($i['provider_code'] ?: $i['name']) ?>">
                                <?= participantIcon($i) ?> <?= htmlspecialchars($i['name']) ?>
                            </option>
                        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
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
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($institutions as $i): ?>
                        <option value="<?= htmlspecialchars($i['provider_code'] ?: $i['name']) ?>">
                            <?= participantIcon($i) ?> <?= htmlspecialchars($i['name']) ?>
                        </option>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
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
                    <strong>Secure by Design</strong><br>
                    Fee: Cashout 10 BWP · Deposit 6 BWP
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

    <div class="transactions-card">
        <div class="card-title">
            <i class="fas fa-clock"></i>
            <h3>Recent Transactions</h3>
        </div>

        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No transactions yet</p>
                <p style="font-size: 0.7rem; margin-top: 0.5rem;">Start your first swap above</p>
            </div>
        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 else: ?>
            <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($recentTransactions as $tx): ?>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 $statusClass = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($tx['status'] ?? 'unknown')); ?>
                <div class="transaction-item">
                    <div class="transaction-left">
                        <div class="transaction-date">
                            <?= date('d M Y • H:i', strtotime($tx['created_at'])) ?>
                        </div>
                        <div class="transaction-details">
                            <?= htmlspecialchars($tx['source_type'] ?? '?') ?>
                            <i class="fas fa-arrow-right" style="font-size: 0.6rem; margin: 0 0.25rem;"></i>
                            <?= htmlspecialchars($tx['destination_type'] ?? '?') ?>
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
            <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endif; ?>
    </div>

</div>

<script>
const sourceType = document.getElementById('sourceType');
const dynamicContainer = document.getElementById('dynamicContainer');
const destType = document.getElementById('destType');
const destValue = document.getElementById('destValue');
const submitBtn = document.getElementById('submitBtn');
const amountInput = document.getElementById('amount');

// Custom cursor
const cursor = document.querySelector('.cursor');
const follower = document.querySelector('.cursor-follower');
if (cursor && follower) {
    document.addEventListener('mousemove', (e) => {
        cursor.style.left = e.clientX + 'px';
        cursor.style.top = e.clientY + 'px';
        follower.style.left = e.clientX - 16 + 'px';
        follower.style.top = e.clientY - 16 + 'px';
    });
}

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
                        <span style="color: #00F0FF;">${data.amount.toFixed(2)} BWP</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
                        <span style="color: #A0A0B0;">Delivery Mode:</span>
                        <span>${data.delivery_mode.toUpperCase()}</span>
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
                    <span style="color: #FF6060;">-${fee.toFixed(2)} BWP</span>
                </div>
                <div class="fee-row total">
                    <span>Net Amount:</span>
                    <span>${netAmount.toFixed(2)} BWP</span>
                </div>
            </div>
            
            <div class="info-box" style="margin-top: 1.25rem;">
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
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Failed: ' + (data.message || 'Unknown error'));
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
    
    requiredFields.forEach(field => { if (!field.value.trim()) { field.style.borderColor = '#FF3030'; isValid = false; } });
    if (!destValue.value.trim()) { destValue.style.borderColor = '#FF3030'; isValid = false; }
    if (!amountInput.value || parseFloat(amountInput.value) <= 0) { amountInput.style.borderColor = '#FF3030'; isValid = false; }
    
    if (!isValid) { alert('Please fill in all required fields'); return; }
    
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
