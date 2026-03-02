<?php
// --- SESSION INIT ---
require_once __DIR__ . '/../../src/APP_LAYER/utils/session_manager.php';
use APP_LAYER\Utils\SessionManager;
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$loggedPhone = htmlspecialchars($user['phone'] ?? '');

// --- DEPENDENCIES ---
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/SwapService.php';

use DATA_PERSISTENCE_LAYER\Config\DBConnection;
use BUSINESS_LOGIC_LAYER\Services\SwapService;

// --- Load Country & Config ---
$config = require_once __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
$country = SYSTEM_COUNTRY; 

try {
    $swapDB = DBConnection::getInstance($config['db']['swap']);
} catch (Exception $e) {
    die("DB connection error: " . $e->getMessage());
}

// Build participants list
$participants = [];
foreach ($config['participants'] ?? [] as $name => $p) {
    if (in_array($p['type'] ?? '', ['bank','preatm'])) {
        $participants[$name] = [
            'name' => $name,
            'wallet_type' => $p['wallet_type'] ?? null, 
            'type' => $p['type'] ?? null,
            'api_url' => $p['api_url'] ?? null,
            'api_key' => $p['api_key'] ?? null,
            'has_swap_to_account' => isset($p['endpoints']['internal_account_transfer']),
            'endpoints' => $p['endpoints'] ?? []
        ];
    }
}

$settings = [
    'swap_enabled' => $config['settings']['swap_enabled'] ?? 1,
    'swap_fee_percentage' => $config['settings']['swap_fee'] ?? 1.5
];

$encryptionKey = $config['encryption']['key'] ?? 'DEFAULT_KEY';

try {
    $swapService = new SwapService($swapDB, $settings, $country, $encryptionKey, $config);
} catch (Exception $e) {
    die("SwapService init failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph | ATM Interoperability Platform</title>
<!-- Typography: European banking style -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ===== EUROPEAN BANKING STYLE ===== */
:root {
    --primary-navy: #0A2463;
    --primary-gold: #B8860B;
    --primary-slate: #2D3748;
    --secondary-steel: #4A5568;
    --light-gray: #F7FAFC;
    --border-gray: #E2E8F0;
    --success-green: #38A169;
    --error-red: #E53E3E;
    --warning-amber: #D69E2E;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
    color: var(--primary-slate);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

/* ===== MAIN CONTAINER ===== */
.dashboard-container {
    width: 100%;
    max-width: 920px;
    background: white;
    border-radius: 0;
    box-shadow: 
        0 4px 6px -1px rgba(0, 0, 0, 0.05),
        0 10px 15px -3px rgba(0, 0, 0, 0.08),
        0 20px 40px -20px rgba(0, 0, 0, 0.15);
    border: 1px solid var(--border-gray);
    overflow: hidden;
}

/* ===== HEADER ===== */
.dashboard-header {
    background: var(--primary-navy);
    color: white;
    padding: 24px 32px;
    border-bottom: 3px solid var(--primary-gold);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left h1 {
    font-size: 20px;
    font-weight: 600;
    letter-spacing: -0.3px;
    margin-bottom: 4px;
}

.header-left .subtitle {
    font-size: 13px;
    font-weight: 400;
    opacity: 0.9;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.user-info {
    font-size: 14px;
    font-weight: 500;
}

.user-info span {
    color: var(--primary-gold);
    font-weight: 600;
}

.logout-btn {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 2px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.logout-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: var(--primary-gold);
}

/* ===== SWAP TYPE SELECTION ===== */
.swap-type-section {
    padding: 28px 32px;
    border-bottom: 1px solid var(--border-gray);
}

.swap-type-section h2 {
    font-size: 15px;
    font-weight: 600;
    color: var(--secondary-steel);
    margin-bottom: 16px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.swap-buttons {
    display: flex;
    gap: 16px;
}

.swap-type-btn {
    flex: 1;
    padding: 18px 24px;
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: 0;
    font-size: 14px;
    font-weight: 500;
    color: var(--primary-slate);
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: center;
    letter-spacing: 0.3px;
}

.swap-type-btn:hover {
    border-color: var(--primary-navy);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(10, 36, 99, 0.1);
}

.swap-type-btn.active {
    background: var(--primary-navy);
    color: white;
    border-color: var(--primary-navy);
}

/* ===== FORM SECTION ===== */
.form-container {
    padding: 32px;
    display: none;
}

.form-container.active {
    display: block;
}

.form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-gray);
}

.form-header h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--primary-slate);
}

.form-header .swap-direction {
    font-size: 13px;
    color: var(--secondary-steel);
    font-weight: 500;
    padding: 4px 12px;
    background: var(--light-gray);
    border-radius: 2px;
}

/* ===== FORM GRID ===== */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 0;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--secondary-steel);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-gray);
    border-radius: 0;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    color: var(--primary-slate);
    background: white;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-navy);
    box-shadow: 0 0 0 2px rgba(10, 36, 99, 0.1);
}

.form-control::placeholder {
    color: #A0AEC0;
    font-weight: 400;
}

/* ===== DYNAMIC FIELDS ===== */
.dynamic-fields {
    margin-top: 8px;
}

/* ===== SUBMIT BUTTON ===== */
.form-actions {
    padding-top: 24px;
    border-top: 1px solid var(--border-gray);
    text-align: right;
}

.execute-btn {
    background: var(--primary-navy);
    color: white;
    border: none;
    padding: 14px 32px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 0;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    min-width: 160px;
}

.execute-btn:hover:not(:disabled) {
    background: #0A1E4D;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(10, 36, 99, 0.2);
}

.execute-btn:disabled {
    background: var(--border-gray);
    color: #A0AEC0;
    cursor: not-allowed;
}

.execute-btn .spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
    margin-right: 8px;
    vertical-align: middle;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== RESULT PANEL ===== */
.result-panel {
    margin-top: 32px;
    border: 1px solid var(--border-gray);
    border-radius: 0;
    overflow: hidden;
    display: none;
}

.result-panel.active {
    display: block;
}

.result-header {
    background: var(--light-gray);
    padding: 16px 24px;
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.result-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-slate);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.result-status {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 2px;
    text-transform: uppercase;
}

.result-status.success {
    background: #C6F6D5;
    color: var(--success-green);
}

.result-status.error {
    background: #FED7D7;
    color: var(--error-red);
}

.result-status.processing {
    background: #FEFCBF;
    color: var(--warning-amber);
}

.result-content {
    padding: 24px;
    font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace;
    font-size: 13px;
    line-height: 1.7;
    background: white;
    max-height: 400px;
    overflow-y: auto;
}

.result-content pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-all;
}

.result-content .transaction-details {
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-gray);
}

.result-content .detail-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.result-content .detail-label {
    color: var(--secondary-steel);
    font-weight: 500;
}

.result-content .detail-value {
    color: var(--primary-slate);
    font-weight: 600;
}

/* ===== FOOTER ===== */
.dashboard-footer {
    padding: 16px 32px;
    background: var(--light-gray);
    border-top: 1px solid var(--border-gray);
    font-size: 12px;
    color: var(--secondary-steel);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.system-info {
    font-weight: 500;
}

.system-info .country {
    color: var(--primary-navy);
    font-weight: 600;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .dashboard-container {
        border-radius: 0;
        margin: 0;
        max-width: 100%;
    }
    
    .dashboard-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
    }
    
    .swap-buttons {
        flex-direction: column;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full-width {
        grid-column: span 1;
    }
    
    .result-content {
        font-size: 12px;
    }
}

/* ===== UTILITY CLASSES ===== */
.text-success {
    color: var(--success-green);
}

.text-error {
    color: var(--error-red);
}

.text-warning {
    color: var(--warning-amber);
}

.bg-light {
    background: var(--light-gray);
}

.border-top {
    border-top: 1px solid var(--border-gray);
}

.mt-24 {
    margin-top: 24px;
}

.mb-16 {
    margin-bottom: 16px;
}

.hidden {
    display: none;
}
</style>
</head>
<body>

<div class="dashboard-container">
    <!-- HEADER -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1>VouchMorph Interoperability Platform</h1>
            <div class="subtitle">National ATM & Liquidity Coordination System</div>
        </div>
        <div class="header-right">
            <div class="user-info">
                User: <span><?= $loggedPhone ?></span>
            </div>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn">Log Out</button>
            </form>
        </div>
    </div>

    <!-- SWAP TYPE SELECTION -->
    <div class="swap-type-section">
        <h2>Select Transaction Type</h2>
        <div class="swap-buttons">
            <button type="button" class="swap-type-btn" onclick="showSwapForm('preatm_to_preatm')">
                <div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">TYPE A</div>
                PreATM → PreATM Transfer
            </button>
            <button type="button" class="swap-type-btn" onclick="showSwapForm('preatm_to_account')">
                <div style="font-size: 12px; opacity: 0.8; margin-bottom: 4px;">TYPE B</div>
                PreATM → Bank Account
            </button>
        </div>
    </div>

    <!-- SWAP FORM -->
    <form id="swapForm" method="POST" 
        action="/vouchmorphn/src/BUSINESS_LOGIC_LAYER/services/swapservice_endpoint.php"
        class="form-container">

        <div class="form-header">
            <h3>Transaction Details</h3>
            <div class="swap-direction" id="swapDirectionLabel"></div>
        </div>

        <input type="hidden" name="token" 
            value="<?= htmlspecialchars($user['id'] ?? $user['token'] ?? $user['phone'] ?? '') ?>">
        <input type="hidden" name="phone" value="<?= $loggedPhone ?>">
        <input type="hidden" name="recipient_phone" value="<?= $loggedPhone ?>">
        <input type="hidden" name="swapType" id="swapTypeInput">

        <div class="form-grid">
            <!-- From Participant -->
            <div class="form-group">
                <label for="fromParticipant">Source Institution</label>
                <select name="fromParticipant" id="fromParticipant" class="form-control" required>
                    <option value="" disabled selected>Select source</option>
                    <?php foreach ($participants as $name => $p): ?>
                        <?php if (in_array($p['wallet_type'] ?? '', ['ewallet', 'voucher'])): ?>
                            <option value="<?= htmlspecialchars($name) ?>"
                                    data-type="<?= htmlspecialchars($p['wallet_type']) ?>">
                                <?= htmlspecialchars(strtoupper($name)) ?> • <?= htmlspecialchars($p['wallet_type']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- To Participant -->
            <div class="form-group">
                <label for="toParticipant">Destination Institution</label>
                <select name="toParticipant" id="toParticipant" class="form-control" required>
                    <option value="" disabled selected>Select destination</option>
                    <?php foreach ($participants as $name => $p): ?>
                        <option value="<?= htmlspecialchars($name) ?>"
                                data-type="<?= htmlspecialchars($p['wallet_type'] ?? 'preatm') ?>"
                                data-has-account="<?= $p['has_swap_to_account'] ? '1' : '0' ?>">
                            <?= htmlspecialchars(strtoupper($name)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Dynamic Fields Container -->
            <div id="dynamicFields" class="form-group full-width"></div>

            <!-- Amount -->
            <div class="form-group full-width">
                <label for="swapAmountInput">Transaction Amount (BWP)</label>
                <input type="number" step="0.01" name="amount" id="swapAmountInput" 
                       class="form-control" required placeholder="0.00" min="20">
                <div style="font-size: 12px; color: var(--secondary-steel); margin-top: 4px;">
                    Minimum amount: BWP 20.00
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="execute-btn" id="executeBtn">
                <span id="executeText">Execute Transaction</span>
            </button>
        </div>
    </form>

    <!-- RESULT PANEL -->
    <div id="swapResult" class="result-panel">
        <div class="result-header">
            <h4>Transaction Result</h4>
            <div class="result-status" id="resultStatus"></div>
        </div>
        <div class="result-content" id="resultContent"></div>
    </div>

    <!-- FOOTER -->
    <div class="dashboard-footer">
        <div class="system-info">
            System: <span class="country"><?= htmlspecialchars(strtoupper($country)) ?></span> • 
            Environment: Production • v1.3.2
        </div>
        <div>VouchMorph™ © 2024</div>
    </div>
</div>

<script>
// ===== GLOBAL VARIABLES =====
const swapForm = document.getElementById('swapForm');
const swapResult = document.getElementById('swapResult');
const resultStatus = document.getElementById('resultStatus');
const resultContent = document.getElementById('resultContent');
const dynamicFields = document.getElementById('dynamicFields');
const swapTypeInput = document.getElementById('swapTypeInput');
const swapDirectionLabel = document.getElementById('swapDirectionLabel');
const fromSelect = document.getElementById('fromParticipant');
const toSelect = document.getElementById('toParticipant');
const executeBtn = document.getElementById('executeBtn');
const executeText = document.getElementById('executeText');

// Participants data
const participants = (function() {
    const map = {};
    document.querySelectorAll('#toParticipant option, #fromParticipant option').forEach(o => {
        if (!o.value) return;
        map[o.value] = {
            wallet_type: o.getAttribute('data-type') || 'preatm',
            has_account: o.getAttribute('data-has-account') === '1'
        };
    });
    return map;
})();

// ===== FORM MANAGEMENT =====
function showSwapForm(type) {
    // Update all swap type buttons
    document.querySelectorAll('.swap-type-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    // Show form container
    swapForm.classList.add('active');
    swapResult.classList.remove('active');
    
    // Set swap type
    swapTypeInput.value = type;
    
    // Update direction label
    const labels = {
        'preatm_to_preatm': 'PreATM → PreATM',
        'preatm_to_account': 'PreATM → Bank Account'
    };
    swapDirectionLabel.textContent = labels[type] || 'Transaction';
    
    // Reset form
    fromSelect.selectedIndex = 0;
    toSelect.selectedIndex = 0;
    dynamicFields.innerHTML = '';
    
    // Rebuild options
    rebuildToOptions();
}

function rebuildToOptions() {
    const swapDirection = swapTypeInput.value;
    const fromName = fromSelect.value;
    
    // Get all options data
    const allOptions = Array.from(toSelect.options).filter(o => o.value).map(o => ({
        value: o.value,
        label: o.textContent.trim(),
        wallet_type: o.getAttribute('data-type') || 'preatm',
        has_account: o.getAttribute('data-has-account') === '1'
    }));
    
    // Filter options based on swap type
    let filtered = allOptions.filter(opt => {
        if (fromName && opt.value === fromName) return false;
        
        if (swapDirection === 'preatm_to_preatm') {
            return ['voucher', 'ewallet', 'preatm'].includes(opt.wallet_type);
        }
        if (swapDirection === 'preatm_to_account') {
            return opt.has_account;
        }
        return false;
    });
    
    // Update dropdown
    toSelect.innerHTML = '<option value="" disabled selected>Select destination</option>';
    filtered.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.setAttribute('data-type', opt.wallet_type);
        option.setAttribute('data-has-account', opt.has_account ? '1' : '0');
        option.textContent = opt.label;
        toSelect.appendChild(option);
    });
    
    updateDynamicFields();
}

function updateDynamicFields() {
    const swapDirection = swapTypeInput.value;
    const fromName = fromSelect.value;
    const fromType = participants[fromName]?.wallet_type || '';
    
    dynamicFields.innerHTML = '';
    
    if (!fromName) return;
    
    let html = '';
    
    // Source-specific fields
    if (fromType === 'voucher') {
        html += `
        <div class="form-grid">
            <div class="form-group">
                <label>Voucher Number</label>
                <input type="text" name="voucher_number" class="form-control" required 
                       placeholder="Enter voucher number">
            </div>
            <div class="form-group">
                <label>PIN Code</label>
                <input type="password" name="pin" class="form-control" required 
                       placeholder="Enter PIN" autocomplete="off">
            </div>
        </div>`;
    } else if (fromType === 'ewallet') {
        html += `
        <div class="form-group full-width">
            <label>eWallet PIN</label>
            <input type="password" name="pin" class="form-control" required 
                   placeholder="Enter eWallet PIN" autocomplete="off">
        </div>`;
    }
    
    // Destination-specific fields
    if (swapDirection === 'preatm_to_account') {
        html += `
        <div class="form-group full-width">
            <label>Destination Account Number</label>
            <input type="text" name="recipient_account" class="form-control" required 
                   placeholder="Enter bank account number">
        </div>`;
    }
    
    dynamicFields.innerHTML = html;
}

// ===== EVENT LISTENERS =====
fromSelect.addEventListener('change', () => {
    toSelect.selectedIndex = 0;
    rebuildToOptions();
});

toSelect.addEventListener('change', updateDynamicFields);

// ===== FORM SUBMISSION =====
swapForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Disable submit button and show loading
    executeBtn.disabled = true;
    executeText.innerHTML = '<span class="spinner"></span> Processing...';
    
    // Show result panel with processing state
    swapResult.classList.add('active');
    resultStatus.textContent = 'Processing';
    resultStatus.className = 'result-status processing';
    resultContent.innerHTML = `
        <div class="transaction-details">
            <div class="detail-item">
                <span class="detail-label">Status:</span>
                <span class="detail-value">Initiating transaction...</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Time:</span>
                <span class="detail-value">${new Date().toLocaleTimeString()}</span>
            </div>
        </div>
        <div>Executing full swap protocol (Steps 1-4)...</div>
    `;
    
    // Prepare payload
    const formData = new FormData(swapForm);
    const swapDirection = swapTypeInput.value;
    const fromName = fromSelect.value;
    const toName = toSelect.value;
    const fromParticipant = participants[fromName];
    const toParticipant = participants[toName];
    
    const payload = {
        fromParticipant: fromName,
        from_type: fromParticipant?.wallet_type || '',
        toParticipant: toName,
        to_type: swapDirection === 'preatm_to_account' ? 'account' : (toParticipant?.wallet_type || ''),
        token: formData.get('token'),
        sender_phone: formData.get('phone'),
        recipient_phone: formData.get('recipient_phone'),
        amount: parseFloat(formData.get('amount')),
        voucher_number: formData.get('voucher_number'),
        pin: formData.get('pin'),
        recipient_account: formData.get('recipient_account')
    };
    
    try {
        const response = await fetch(swapForm.action, {
            method: 'POST',
            body: JSON.stringify(payload),
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });
        
        const result = await response.json();
        
        // Format result for display
        let resultHtml = '';
        let status = 'error';
        let statusClass = 'error';
        let statusText = 'Failed';
        
        if (result.status === 'success') {
            status = 'success';
            statusClass = 'success';
            statusText = 'Completed';
            
            resultHtml = `
                <div class="transaction-details">
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value text-success">✓ Transaction Successful</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reference:</span>
                        <span class="detail-value">${result.swap_reference || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Original Amount:</span>
                        <span class="detail-value">BWP ${result.original_amount?.toFixed(2) || '0.00'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Final Amount:</span>
                        <span class="detail-value">BWP ${result.final_amount?.toFixed(2) || '0.00'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Transaction ID:</span>
                        <span class="detail-value">${result.swap_reference || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Timestamp:</span>
                        <span class="detail-value">${new Date().toLocaleString()}</span>
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <strong>Full Response:</strong>
                    <pre style="margin-top: 8px; padding: 12px; background: var(--light-gray); border: 1px solid var(--border-gray);">${JSON.stringify(result, null, 2)}</pre>
                </div>
            `;
        } else {
            // Transaction failed
            resultHtml = `
                <div class="transaction-details">
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value text-error">✗ Transaction Failed</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Error:</span>
                        <span class="detail-value">${result.message || 'Unknown error'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Reference:</span>
                        <span class="detail-value">${result.swap_reference || 'N/A'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Timestamp:</span>
                        <span class="detail-value">${new Date().toLocaleString()}</span>
                    </div>
                </div>
            `;
            
            // Add step details if available
            if (result.step1 || result.step2 || result.step3 || result.step4) {
                resultHtml += `
                    <div style="margin-top: 16px;">
                        <strong>Step Details:</strong>
                        <pre style="margin-top: 8px; padding: 12px; background: var(--light-gray); border: 1px solid var(--border-gray);">${JSON.stringify({
                            step1: result.step1,
                            step2: result.step2,
                            step3: result.step3,
                            step4: result.step4
                        }, null, 2)}</pre>
                    </div>
                `;
            }
            
            // Add full error details
            resultHtml += `
                <div style="margin-top: 16px;">
                    <strong>Error Details:</strong>
                    <pre style="margin-top: 8px; padding: 12px; background: #FED7D7; border: 1px solid var(--error-red);">${JSON.stringify(result, null, 2)}</pre>
                </div>
            `;
        }
        
        // Update result panel
        resultStatus.textContent = statusText;
        resultStatus.className = `result-status ${statusClass}`;
        resultContent.innerHTML = resultHtml;
        
    } catch (error) {
        // Network or parsing error
        resultStatus.textContent = 'Error';
        resultStatus.className = 'result-status error';
        resultContent.innerHTML = `
            <div class="transaction-details">
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value text-error">✗ System Error</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Error Type:</span>
                    <span class="detail-value">Network/Communication</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Message:</span>
                    <span class="detail-value">${error.message}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Timestamp:</span>
                    <span class="detail-value">${new Date().toLocaleString()}</span>
                </div>
            </div>
            <div style="margin-top: 16px;">
                <strong>Technical Details:</strong>
                <pre style="margin-top: 8px; padding: 12px; background: #FED7D7; border: 1px solid var(--error-red);">${error.stack || 'No stack trace available'}</pre>
            </div>
        `;
    } finally {
        // Re-enable submit button
        executeBtn.disabled = false;
        executeText.textContent = 'Execute Transaction';
        
        // Scroll to result
        swapResult.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', () => {
    // Set default swap type
    showSwapForm('preatm_to_preatm');
    
    // Add input validation
    const amountInput = document.getElementById('swapAmountInput');
    amountInput.addEventListener('change', function() {
        if (this.value < 20) {
            this.value = 20;
            alert('Minimum transaction amount is BWP 20.00');
        }
    });
});
</script>
</body>
</html>
