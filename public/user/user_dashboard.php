<?php
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

function normalizeDestinationInstitution(string $destinationType): string
{
    return match (strtolower($destinationType)) {
        'cashout' => 'CASHOUT',
        'wallet'  => 'WALLET',
        'bank'    => 'BANK',
        'card'    => 'CARD',
        default   => strtoupper($destinationType),
    };
}

function institutionAuthUrl(array $participant): ?string
{
    $resourceEndpoints = safeJsonDecode($participant['resource_endpoints'] ?? null);
    $baseUrl = rtrim((string)($participant['base_url'] ?? ''), '/');

    if (!empty($resourceEndpoints['initiate_auth'])) {
        $path = $resourceEndpoints['initiate_auth'];
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if ($baseUrl !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }
    }

    if ($baseUrl !== '') {
        return $baseUrl . '/initiate-auth';
    }

    return null;
}

/* =========================
   LOAD DATA
========================= */

/*
 participants table has:
 participant_id, name, type, category, provider_code, auth_type, base_url, ... status, resource_endpoints, metadata
*/
$stmt = $db->prepare("
    SELECT participant_id, name, type, category, provider_code, auth_type, base_url, resource_endpoints
    FROM participants
    WHERE status = 'ACTIVE'
    ORDER BY name
");
$stmt->execute();
$institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 swap_requests table has:
 swap_id, swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata

 So recent user transactions are reconstructed by filtering swap_requests using metadata->user_id
 and falling back to phone inside source_details / destination_details JSON.
*/
$stmt = $db->prepare("
    SELECT swap_id, swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata
    FROM swap_requests
    ORDER BY created_at DESC
    LIMIT 50
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
        ((string)($sourceDetails['source_reference'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['phone'] ?? '') === (string)$userPhone) ||
        ((string)($destinationDetails['destination_value'] ?? '') === (string)$userPhone);

    if ($matchesUser) {
        $row['source_type'] = $sourceDetails['source_type'] ?? ($sourceDetails['asset_type'] ?? 'SOURCE');
        $row['source_institution'] = $sourceDetails['institution'] ?? ($sourceDetails['source_institution'] ?? '');
        $row['destination_type'] = $destinationDetails['destination_type'] ?? 'DESTINATION';
        $row['destination_value'] = $destinationDetails['destination_value'] ?? ($destinationDetails['phone'] ?? '');
        $recentTransactions[] = $row;
    }

    if (count($recentTransactions) >= 10) {
        break;
    }
}

/* =========================
   HANDLE SWAP
========================= */
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap') {
    $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
    $institution = trim($_POST['source_institution'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $destinationType = strtolower(trim($_POST['destination_type'] ?? ''));
    $destinationValue = trim($_POST['destination_value'] ?? '');

    $sourceReference = null;

    if ($sourceType === 'WALLET') {
        $sourceReference = $userPhone;
    } elseif ($sourceType === 'ACCOUNT') {
        $sourceReference = trim($_POST['account_number'] ?? '');
    } elseif ($sourceType === 'CARD') {
        $sourceReference = trim($_POST['card_number'] ?? '');
    } elseif ($sourceType === 'VOUCHER') {
        $sourceReference = trim($_POST['voucher_number'] ?? '');
    }

    if ($institution === '' || $amount <= 0 || $destinationValue === '') {
        $error = "Please complete all required fields.";
    } elseif ($sourceReference === null || $sourceReference === '') {
        $error = "Source reference is required.";
    } else {
        $swapRef = 'SWP-' . strtoupper(bin2hex(random_bytes(6)));

        $sourceDetails = [
            'source_type' => $sourceType,
            'institution' => $institution,
            'source_reference' => $sourceReference,
            'phone' => $sourceType === 'WALLET' ? $userPhone : null
        ];

        $destinationDetails = [
            'destination_type' => $destinationType,
            'destination_value' => $destinationValue,
            'destination_institution' => normalizeDestinationInstitution($destinationType)
        ];

        $metadata = [
            'user_id' => $userId,
            'user_phone' => $userPhone,
            'channel' => 'user_dashboard',
            'system_country' => $systemCountry
        ];

        try {
            /*
             swap_requests supports:
             swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at, metadata
            */
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
                ':source_details' => json_encode($sourceDetails, JSON_UNESCAPED_UNICODE),
                ':destination_details' => json_encode($destinationDetails, JSON_UNESCAPED_UNICODE),
                ':status' => 'pending_auth',
                ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
            ]);

            /*
             participants supports:
             name, provider_code, auth_type, base_url, resource_endpoints
            */
            $stmt = $db->prepare("
                SELECT participant_id, name, provider_code, auth_type, base_url, resource_endpoints
                FROM participants
                WHERE name = :institution OR provider_code = :institution
                LIMIT 1
            ");
            $stmt->execute([':institution' => $institution]);
            $inst = $stmt->fetch(PDO::FETCH_ASSOC);

            $authUrl = $inst ? institutionAuthUrl($inst) : null;

            if (!$inst || !$authUrl) {
                $success = "Swap request created successfully. Institution authentication endpoint is not yet configured.";
            } else {
                $payload = [
                    'swap_ref' => $swapRef,
                    'amount' => $amount,
                    'source_reference' => $sourceReference,
                    'destination' => $destinationValue,
                    'source_type' => $sourceType,
                    'destination_type' => $destinationType,
                    'callback_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/swap_callback.php'
                ];

                $ch = curl_init($authUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    $error = "Connection error: " . $curlError;
                } elseif ($httpCode < 200 || $httpCode >= 300) {
                    $error = "Authentication service error (HTTP {$httpCode})";
                } else {
                    $auth = json_decode($response, true);

                    if (!is_array($auth)) {
                        $success = "Swap request created. Waiting for institution response.";
                    } elseif (($auth['auth_type'] ?? '') === 'redirect' && !empty($auth['auth_url'])) {
                        header("Location: " . $auth['auth_url']);
                        exit();
                    } elseif (($auth['auth_type'] ?? '') === 'push') {
                        $success = "Push notification sent to your phone. Please approve the transaction.";
                    } else {
                        $success = $auth['message'] ?? "Authentication initiated. Follow instructions from your provider.";
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("USER DASHBOARD SWAP ERROR: " . $e->getMessage());
            $error = "Unable to create swap request.";
        }
    }
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
    
    /* Main Container */
    .main-container {
        max-width: 700px;
        margin: 0 auto;
    }
    
    /* Header Card */
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
    
    /* Alert Messages */
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
    
    /* Swap Card */
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
    
    /* Form Elements */
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
    
    /* Dynamic Fields */
    .dynamic-fields {
        margin-top: 16px;
        margin-bottom: 16px;
        padding: 16px;
        background: #f9fafb;
        border-radius: 20px;
        border: 1px solid #e5e7eb;
    }
    
    /* Info Box */
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
    
    /* Transactions Card */
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
    
    /* Phone Display */
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
    
    /* Responsive */
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
    
    /* Loading Animation */
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    
    .loading {
        animation: pulse 1.5s ease-in-out infinite;
    }
</style>
</head>
<body>

<div class="main-container">

    <!-- Header Card -->
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
                    <i class="fas fa-globe"></i> <?= strtoupper($systemCountry) ?>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Exit
                </a>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>

    <?php if($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle" style="font-size: 20px;"></i>
        <div><?= htmlspecialchars($success) ?></div>
    </div>
    <?php endif; ?>

    <!-- Swap Card -->
    <div class="swap-card">
        <div class="card-title">
            <i class="fas fa-bolt"></i>
            <h3>New Swap</h3>
        </div>

        <form method="POST" id="swapForm">
            <input type="hidden" name="action" value="swap">

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
                <label><i class="fas fa-building"></i> Institution</label>
                <select name="source_institution" class="form-select" required>
                    <option value="">Select institution</option>
                    <?php foreach($institutions as $i): ?>
                    <option value="<?= htmlspecialchars($i['name']) ?>">
                        <?= htmlspecialchars($i['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Fields Container -->
            <div id="dynamicContainer"></div>

            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-money-bill-wave"></i> Amount (BWP)</label>
                    <input type="number" name="amount" class="form-control" step="0.01" 
                           placeholder="0.00" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Destination Type</label>
                    <select name="destination_type" id="destType" class="form-select" required>
                        <option value="cashout">💰 Cashout (Agent/ATM)</option>
                        <option value="card">💳 Load Virtual Card</option>
                        <option value="bank">🏦 Bank Account</option>
                        <option value="wallet">📱 Mobile Money</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user-check"></i> Destination Details</label>
                <input type="text" name="destination_value" id="destValue" class="form-control" 
                       placeholder="Phone number or account number" required>
            </div>

            <button type="submit" class="swap-btn" id="submitBtn">
                <i class="fas fa-arrow-right"></i>
                Continue to Institution Authentication
            </button>

            <div class="info-box">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <strong>Secure by Design</strong><br>
                    You'll authenticate directly with your institution. We never see or store your PIN.
                </div>
            </div>
        </form>
    </div>

    <!-- Recent Transactions -->
    <div class="transactions-card">
        <div class="card-title">
            <i class="fas fa-clock"></i>
            <h3>Recent Transactions</h3>
        </div>

        <?php if(empty($recentTransactions)): ?>
        <div class="empty-state">
            <i class="fas fa-history"></i>
            <p>No transactions yet</p>
            <p style="font-size: 12px; margin-top: 8px;">Start your first swap above</p>
        </div>
        <?php else: ?>
            <?php foreach($recentTransactions as $tx): ?>
            <div class="transaction-item">
                <div class="transaction-left">
                    <div class="transaction-date">
                        <?= date('d M Y • H:i', strtotime($tx['created_at'])) ?>
                    </div>
                    <div class="transaction-details">
                        <?= htmlspecialchars($tx['source_type'] ?? '?') ?> 
                        <i class="fas fa-arrow-right" style="font-size: 10px; margin: 0 4px;"></i> 
                        <?= htmlspecialchars($tx['destination_type'] ?? '?') ?>
                    </div>
                </div>
                <div class="transaction-right">
                    <div class="transaction-amount">
                        <?= number_format($tx['amount'], 2) ?> BWP
                    </div>
                    <span class="transaction-status status-<?= $tx['status'] ?>">
                        <?= str_replace('_', ' ', strtoupper($tx['status'])) ?>
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
    
    // Update destination placeholder
    function updateDestinationPlaceholder() {
        const type = destType.value;
        const placeholders = {
            'cashout': 'Phone number for cash pickup (e.g., 71123456)',
            'card': 'Last 4 digits of your card (e.g., 1234)',
            'bank': 'Bank account number',
            'wallet': 'Mobile money number (e.g., 71123456)'
        };
        destValue.placeholder = placeholders[type] || 'Enter destination';
    }
    
    // Update dynamic fields based on source type
    function updateDynamicFields() {
        const type = sourceType.value;
        
        if (type === 'WALLET') {
            dynamicContainer.innerHTML = `
                <div class="dynamic-fields">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 20px;"></i>
                        <div>
                            <strong style="color: #1f2937;">Using your registered phone number</strong><br>
                            <span style="font-size: 13px; color: #6b7280;">Source: ${document.querySelector('.phone-highlight')?.innerText || 'Your phone'}</span>
                        </div>
                    </div>
                </div>
            `;
        } 
        else if (type === 'ACCOUNT') {
            dynamicContainer.innerHTML = `
                <div class="dynamic-fields">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Account Number</label>
                        <input type="text" name="account_number" class="form-control" 
                               placeholder="Enter your account number" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Account PIN</label>
                        <input type="password" name="account_pin" class="form-control" 
                               placeholder="Enter your PIN" required>
                        <small style="color: #6b7280; display: block; margin-top: 5px;">
                            <i class="fas fa-shield-alt"></i> Your PIN is sent directly to your bank - never stored
                        </small>
                    </div>
                </div>
            `;
        }
        else if (type === 'CARD') {
            dynamicContainer.innerHTML = `
                <div class="dynamic-fields">
                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> Card Number</label>
                        <input type="text" name="card_number" class="form-control" 
                               placeholder="16-digit card number" maxlength="19" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Card PIN</label>
                        <input type="password" name="card_pin" class="form-control" 
                               placeholder="Enter your PIN" maxlength="6" required>
                        <small style="color: #6b7280; display: block; margin-top: 5px;">
                            <i class="fas fa-shield-alt"></i> Your PIN is sent directly to your card issuer
                        </small>
                    </div>
                </div>
            `;
        }
        else if (type === 'VOUCHER') {
            dynamicContainer.innerHTML = `
                <div class="dynamic-fields">
                    <div class="form-group">
                        <label><i class="fas fa-ticket"></i> Voucher Number</label>
                        <input type="text" name="voucher_number" class="form-control" 
                               placeholder="Enter voucher number" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Voucher PIN</label>
                        <input type="password" name="voucher_pin" class="form-control" 
                               placeholder="Enter voucher PIN" required>
                    </div>
                </div>
            `;
        }
    }
    
    // Form validation and loading state
    document.getElementById('swapForm').addEventListener('submit', function(e) {
        const type = sourceType.value;
        
        // Validate required fields based on type
        if (type !== 'WALLET') {
            const requiredFields = dynamicContainer.querySelectorAll('[required]');
            for (let field of requiredFields) {
                if (!field.value.trim()) {
                    e.preventDefault();
                    field.style.borderColor = '#ef4444';
                    field.focus();
                    alert('Please fill in all required fields');
                    return false;
                }
            }
        }
        
        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
    });
    
    // Reset border color on focus
    document.addEventListener('focusin', function(e) {
        if (e.target.classList && e.target.classList.contains('form-control')) {
            e.target.style.borderColor = '';
        }
    });
    
    // Event listeners
    sourceType.addEventListener('change', updateDynamicFields);
    destType.addEventListener('change', updateDestinationPlaceholder);
    
    // Initialize
    updateDynamicFields();
    updateDestinationPlaceholder();
    
    // Auto-select institution if only one
    const instSelect = document.querySelector('select[name="source_institution"]');
    if (instSelect && instSelect.options.length === 2) {
        instSelect.selectedIndex = 1;
    }
</script>

</body>
</html>
