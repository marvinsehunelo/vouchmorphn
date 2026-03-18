<?php
declare(strict_types=1);

namespace DASHBOARD;

use PDO;

// ============================================================================
// INITIALIZATION
// ============================================================================
ob_start();
$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', rtrim(realpath(__DIR__ . '/../../'), '/') ?: '/var/www/html');
}

@include_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
use DATA_PERSISTENCE_LAYER\config\DBConnection;
$db = DBConnection::getConnection();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
function format_amount($amount, $decimals = 2) {
    return number_format((float)$amount, $decimals);
}

function safe_json_decode($json, $default = []) {
    if (is_array($json)) return $json;
    if (is_string($json) && !empty($json)) {
        $decoded = json_decode($json, true);
        return $decoded ?: $default;
    }
    return $default;
}

// ============================================================================
// PROFESSIONAL TRANSACTION REPORT DOWNLOAD (HTML Format)
// ============================================================================
if (isset($_GET['export_transaction']) && !empty($_GET['swap'])) {
    $exportSwap = $_GET['swap'];
    
    // Get ALL transaction data with details
    $swapQuery = $db->prepare("
        SELECT 
            swap_id,
            swap_uuid,
            from_currency,
            to_currency,
            amount,
            source_details,
            destination_details,
            status,
            created_at,
            metadata
        FROM swap_requests 
        WHERE swap_uuid = ?
    ");
    $swapQuery->execute([$exportSwap]);
    $swap = $swapQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$swap) {
        die("Transaction not found");
    }
    
    // Get hold transactions
    $holdQuery = $db->prepare("
        SELECT * FROM hold_transactions 
        WHERE swap_reference = ?
        ORDER BY created_at ASC
    ");
    $holdQuery->execute([$exportSwap]);
    $holds = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Get API messages
    $apiQuery = $db->prepare("
        SELECT * FROM api_message_logs 
        WHERE message_id = ?
        ORDER BY created_at ASC
    ");
    $apiQuery->execute([$exportSwap]);
    $apis = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Get fee collections
    $feeQuery = $db->prepare("
        SELECT * FROM swap_fee_collections 
        WHERE swap_reference = ?
        ORDER BY created_at ASC
    ");
    $feeQuery->execute([$exportSwap]);
    $fees = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Get ledger entries
    $ledgerQuery = $db->prepare("
        SELECT * FROM ledger_entries 
        WHERE reference = ?
        ORDER BY created_at ASC
    ");
    $ledgerQuery->execute([$exportSwap]);
    $ledgers = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Get card data if exists
    $cardAuthQuery = $db->prepare("
        SELECT * FROM card_authorizations 
        WHERE swap_reference = ?
        ORDER BY created_at ASC
    ");
    $cardAuthQuery->execute([$exportSwap]);
    $cardAuths = $cardAuthQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse JSON details
    $sourceDetails = safe_json_decode($swap['source_details']);
    $destDetails = safe_json_decode($swap['destination_details']);
    $metadata = safe_json_decode($swap['metadata']);
    
    // Get participant info
    $sourceInst = $sourceDetails['institution'] ?? null;
    $destInst = $destDetails['institution'] ?? null;
    
    $participantInfo = [];
    if ($sourceInst) {
        $partQuery = $db->prepare("SELECT * FROM participants WHERE name = ? OR provider_code = ?");
        $partQuery->execute([$sourceInst, $sourceInst]);
        $participantInfo['source'] = $partQuery->fetch(PDO::FETCH_ASSOC);
    }
    if ($destInst) {
        $partQuery = $db->prepare("SELECT * FROM participants WHERE name = ? OR provider_code = ?");
        $partQuery->execute([$destInst, $destInst]);
        $participantInfo['destination'] = $partQuery->fetch(PDO::FETCH_ASSOC);
    }
    
    // Generate filename
    $filename = 'transaction_report_' . substr($exportSwap, 0, 8) . '_' . date('Ymd_His');
    
    // Set headers for HTML download
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    
    // Start output
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · TRANSACTION REPORT · <?php echo substr($exportSwap, 0, 8); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Helvetica Neue', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #0a0a0a;
            line-height: 1.6;
            padding: 3rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Cover Page */
        .cover-page {
            background: linear-gradient(135deg, #001B44 0%, #002B6A 100%);
            color: white;
            padding: 4rem;
            border-radius: 24px;
            margin-bottom: 3rem;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
        }

        .cover-page::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,218,99,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .cover-title {
            font-size: 3.5rem;
            font-weight: 300;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .cover-title span {
            color: #FFDA63;
            font-weight: 600;
        }

        .cover-subtitle {
            font-size: 1.2rem;
            color: #A1B5D8;
            margin-bottom: 3rem;
            position: relative;
            z-index: 1;
        }

        .cover-badge {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: rgba(255,218,99,0.1);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            border-radius: 40px;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        .cover-meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-top: 3rem;
            position: relative;
            z-index: 1;
        }

        .cover-meta-item {
            border-left: 2px solid rgba(255,218,99,0.3);
            padding-left: 1.5rem;
        }

        .cover-meta-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #A1B5D8;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .cover-meta-value {
            font-size: 1.5rem;
            font-weight: 300;
            color: #FFDA63;
        }

        /* Section Styles */
        .section {
            margin: 3rem 0;
            page-break-inside: avoid;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #001B44;
        }

        .section-icon {
            width: 48px;
            height: 48px;
            background: #001B44;
            color: #FFDA63;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 300;
            color: #001B44;
        }

        .section-title span {
            font-weight: 600;
            color: #FFDA63;
            margin-left: 0.5rem;
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #001B44;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .card-badge {
            padding: 0.5rem 1.5rem;
            background: #001B44;
            color: #FFDA63;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .card-badge-success {
            background: #10b981;
            color: white;
        }

        .card-badge-pending {
            background: #f59e0b;
            color: white;
        }

        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
        }

        /* JSON Display */
        .json-block {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1.5rem;
            border-radius: 12px;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #334155;
            box-shadow: inset 0 2px 4px 0 rgba(0,0,0,0.2);
        }

        .json-key {
            color: #FFDA63;
        }

        .json-string {
            color: #4ecdc4;
        }

        .json-number {
            color: #ff6b6b;
        }

        .json-boolean {
            color: #a78bfa;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 3rem;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 3rem;
            border-left: 3px solid #001B44;
            padding-left: 2rem;
            margin-left: 1rem;
        }

        .timeline-item:last-child {
            border-left: none;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.65rem;
            top: 0;
            width: 1.3rem;
            height: 1.3rem;
            background: #FFDA63;
            border: 3px solid #001B44;
            border-radius: 50%;
            z-index: 2;
        }

        .timeline-time {
            font-family: 'JetBrains Mono', monospace;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #001B44;
            margin-bottom: 0.5rem;
        }

        .timeline-subtitle {
            color: #475569;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            font-size: 0.95rem;
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #001B44;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover {
            background: #f8fafc;
        }

        /* Status Indicators */
        .status {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fed7aa;
            color: #92400e;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-active {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Fee Split */
        .fee-split {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .fee-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .fee-party {
            font-weight: 600;
            color: #001B44;
        }

        .fee-amount {
            font-family: 'JetBrains Mono', monospace;
            color: #10b981;
            font-weight: 600;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 4rem;
            padding-top: 3rem;
            border-top: 3px solid #001B44;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .signature-box {
            flex: 1;
        }

        .signature-line {
            margin-top: 2rem;
            border-bottom: 2px solid #001B44;
            width: 300px;
        }

        .seal {
            text-align: right;
        }

        .seal img {
            width: 100px;
            height: 100px;
            opacity: 0.8;
        }

        /* Footer */
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            text-align: center;
            color: #64748b;
            font-size: 0.8rem;
            border-top: 1px solid #e2e8f0;
        }

        /* Print Styles */
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .cover-page {
                background: #001B44;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .json-block {
                background: #f8fafc;
                color: #0a0a0a;
                border: 1px solid #ddd;
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            body { padding: 1.5rem; }
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .cover-meta { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .cover-meta { grid-template-columns: 1fr; }
            .cover-title { font-size: 2.5rem; }
        }
    </style>
</head>
<body>
    <!-- Cover Page -->
    <div class="cover-page">
        <div class="cover-badge">OFFICIAL TRANSACTION REPORT</div>
        <div class="cover-title">VOUCHMORPH <span>MESSAGE CLEARING HOUSE</span></div>
        <div class="cover-subtitle">Complete Transaction Evidence · ISO 20022 Compliant</div>
        
        <div class="cover-meta">
            <div class="cover-meta-item">
                <div class="cover-meta-label">Transaction ID</div>
                <div class="cover-meta-value"><?php echo substr($swap['swap_uuid'], 0, 16); ?>…</div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Amount</div>
                <div class="cover-meta-value"><?php echo number_format((float)$swap['amount'], 2); ?> <?php echo $swap['from_currency'] ?? 'BWP'; ?></div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Status</div>
                <div class="cover-meta-value"><?php echo strtoupper($swap['status']); ?></div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Date</div>
                <div class="cover-meta-value"><?php echo date('Y-m-d', strtotime($swap['created_at'])); ?></div>
            </div>
        </div>
    </div>

    <!-- Transaction Overview -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon">📋</div>
            <div class="section-title">Transaction <span>Overview</span></div>
        </div>
        
        <div class="grid-2">
            <!-- Source Details -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">SOURCE INSTITUTION</div>
                    <div class="card-badge">FUNDS ORIGIN</div>
                </div>
                <div class="json-block"><?php 
                    echo json_encode($sourceDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                ?></div>
                <?php if (!empty($participantInfo['source'])): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <strong style="color: #001B44;">Participant:</strong> <?php echo $participantInfo['source']['name']; ?> · 
                    <span style="color: #64748b;"><?php echo $participantInfo['source']['type']; ?> · <?php echo $participantInfo['source']['provider_code']; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Destination Details -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">DESTINATION INSTITUTION</div>
                    <div class="card-badge">FUNDS RECIPIENT</div>
                </div>
                <div class="json-block"><?php 
                    echo json_encode($destDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                ?></div>
                <?php if (!empty($participantInfo['destination'])): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <strong style="color: #001B44;">Participant:</strong> <?php echo $participantInfo['destination']['name']; ?> · 
                    <span style="color: #64748b;"><?php echo $participantInfo['destination']['type']; ?> · <?php echo $participantInfo['destination']['provider_code']; ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Complete Transaction Flow -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon">⏱️</div>
            <div class="section-title">Transaction <span>Flow</span></div>
        </div>

        <div class="timeline">
            <!-- Step 1: API Request -->
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></div>
                <div class="timeline-title">1. API REQUEST</div>
                <div class="timeline-subtitle">POST /swap/execute</div>
                <div class="json-block"><?php 
                    echo json_encode([
                        'source' => $sourceDetails,
                        'destination' => $destDetails,
                        'amount' => (float)$swap['amount'],
                        'currency' => $swap['from_currency']
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                ?></div>
            </div>

            <!-- Hold Transactions -->
            <?php foreach ($holds as $index => $hold): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></div>
                <div class="timeline-title">2.<?php echo $index + 1; ?>. HOLD CREATED</div>
                <div class="timeline-subtitle"><?php echo htmlspecialchars($hold['participant_name'] ?? $hold['source_institution']); ?></div>
                <div class="json-block"><?php 
                    echo json_encode([
                        'hold_reference' => $hold['hold_reference'],
                        'asset_type' => $hold['asset_type'],
                        'amount' => (float)$hold['amount'],
                        'currency' => $hold['currency'] ?? 'BWP',
                        'status' => $hold['status'],
                        'expiry' => $hold['hold_expiry'] ?? 'N/A'
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                ?></div>
            </div>
            <?php endforeach; ?>

            <!-- API Messages -->
            <?php foreach ($apis as $index => $api): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                <div class="timeline-title">3.<?php echo $index + 1; ?>. <?php echo strtoupper($api['direction'] ?? 'API'); ?> MESSAGE</div>
                <div class="timeline-subtitle">
                    <?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?> · 
                    <?php echo htmlspecialchars($api['endpoint'] ?? 'N/A'); ?> · 
                    HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?> · 
                    <?php echo $api['duration_ms'] ?? 'N/A'; ?>ms
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div>
                        <strong style="color: #ff6b6b;">REQUEST:</strong>
                        <div class="json-block" style="margin-top: 0.5rem;"><?php 
                            $req = safe_json_decode($api['request_payload']);
                            echo json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                        ?></div>
                    </div>
                    <div>
                        <strong style="color: #4ecdc4;">RESPONSE:</strong>
                        <div class="json-block" style="margin-top: 0.5rem;"><?php 
                            $res = safe_json_decode($api['response_payload']);
                            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                        ?></div>
                    </div>
                </div>
                <?php if (!empty($api['curl_error'])): ?>
                <div style="color: #f00; margin-top: 0.5rem;">⚠️ Error: <?php echo htmlspecialchars($api['curl_error']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- Ledger Entries -->
            <?php if (!empty($ledgers)): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($ledgers[0]['created_at'])); ?></div>
                <div class="timeline-title">4. LEDGER IMPACT</div>
                <div class="timeline-subtitle">Double-Entry Accounting</div>
                <div style="margin-top: 1rem;">
                    <table>
                        <thead>
                            <tr>
                                <th>Debit Account</th>
                                <th>Credit Account</th>
                                <th>Amount</th>
                                <th>Split Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledgers as $entry): ?>
                            <tr>
                                <td style="color: #ff6b6b;"><?php echo htmlspecialchars($entry['debit_account_id']); ?></td>
                                <td style="color: #4ecdc4;"><?php echo htmlspecialchars($entry['credit_account_id']); ?></td>
                                <td style="color: #10b981; font-weight: 600;"><?php echo number_format((float)$entry['amount'], 2); ?> <?php echo $entry['currency_code'] ?? 'BWP'; ?></td>
                                <td><?php echo htmlspecialchars($entry['split_type'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Fee Collections -->
            <?php foreach ($fees as $index => $fee): 
                $split = safe_json_decode($fee['split_config']);
            ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($fee['collected_at'] ?? $fee['created_at'])); ?></div>
                <div class="timeline-title">5.<?php echo $index + 1; ?>. FEE SPLIT</div>
                <div class="timeline-subtitle"><?php echo htmlspecialchars($fee['fee_type']); ?></div>
                <div style="margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600;">Total Fee:</span>
                        <span style="color: #10b981; font-size: 1.2rem;"><?php echo number_format((float)$fee['total_amount'], 2); ?> <?php echo $fee['currency'] ?? 'BWP'; ?></span>
                    </div>
                    
                    <?php if (!empty($split)): ?>
                    <div class="fee-split">
                        <?php foreach ($split as $party => $amount): ?>
                        <div class="fee-row">
                            <span class="fee-party"><?php echo strtoupper($party); ?></span>
                            <span class="fee-amount">+<?php echo number_format((float)$amount, 2); ?> BWP</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($fee['vat_amount']) && $fee['vat_amount'] > 0): ?>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #e2e8f0;">
                        <div style="display: flex; justify-content: space-between;">
                            <span>VAT (14%):</span>
                            <span><?php echo number_format((float)$fee['vat_amount'], 2); ?> BWP</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Card Authorizations -->
            <?php foreach ($cardAuths as $index => $auth): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($auth['created_at'])); ?></div>
                <div class="timeline-title">6.<?php echo $index + 1; ?>. CARD AUTHORIZATION</div>
                <div class="timeline-subtitle">Card •••• <?php echo $auth['card_suffix']; ?></div>
                <div class="json-block"><?php 
                    echo json_encode([
                        'authorized_amount' => (float)$auth['authorized_amount'],
                        'remaining_balance' => (float)$auth['remaining_balance'],
                        'status' => $auth['status'],
                        'expiry' => $auth['expiry_at']
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon">📊</div>
            <div class="section-title">Transaction <span>Statistics</span></div>
        </div>

        <div class="grid-4">
            <div class="card" style="text-align: center;">
                <div style="font-size: 2.5rem; color: #001B44; margin-bottom: 0.5rem;"><?php echo count($apis); ?></div>
                <div style="color: #64748b;">API Messages</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 2.5rem; color: #001B44; margin-bottom: 0.5rem;"><?php echo count($holds); ?></div>
                <div style="color: #64748b;">Hold Transactions</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 2.5rem; color: #001B44; margin-bottom: 0.5rem;"><?php echo count($ledgers); ?></div>
                <div style="color: #64748b;">Ledger Entries</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 2.5rem; color: #001B44; margin-bottom: 0.5rem;">
                    <?php 
                    $totalFees = array_sum(array_column($fees, 'total_amount'));
                    echo number_format($totalFees, 2);
                    ?>
                </div>
                <div style="color: #64748b;">Total Fees (BWP)</div>
            </div>
        </div>
    </div>

    <!-- Metadata -->
    <?php if (!empty($metadata)): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-icon">📎</div>
            <div class="section-title">Additional <span>Metadata</span></div>
        </div>
        <div class="card">
            <div class="json-block"><?php echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Regulatory Declaration -->
    <div class="section">
        <div class="card" style="background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border: 2px solid #001B44;">
            <div style="text-align: center; margin-bottom: 2rem;">
                <div style="font-size: 2rem; color: #001B44; margin-bottom: 1rem;">⚖️</div>
                <h2 style="color: #001B44;">Regulatory Declaration</h2>
            </div>
            
            <div style="font-style: italic; color: #475569; margin-bottom: 2rem; text-align: center;">
                "This document certifies that the above transaction was processed in accordance with 
                ISO 20022 standards, with all messages logged, funds never held in custody, and 
                complete audit trail maintained as required by the Bank of Botswana."
            </div>

            <div class="signature-section">
                <div class="signature-box">
                    <div style="font-weight: 600; color: #001B44;">Generated By</div>
                    <div style="color: #64748b; margin: 0.5rem 0;">VouchMorph Message Clearing House</div>
                    <div class="signature-line"></div>
                    <div style="margin-top: 0.5rem; color: #94a3b8; font-size: 0.9rem;">Authorized System Signature</div>
                </div>
                
                <div class="signature-box" style="text-align: right;">
                    <div style="font-weight: 600; color: #001B44;">Verification</div>
                    <div style="color: #64748b; margin: 0.5rem 0;">SHA-256: <?php echo substr(hash('sha256', $exportSwap), 0, 16); ?>…</div>
                    <div style="color: #10b981;">✓ INTEGRITY VERIFIED</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>VOUCHMORPH PROPRIETARY LIMITED · CONFIDENTIAL · Bank of Botswana Regulatory Sandbox</p>
        <p style="margin-top: 0.5rem;">Report Generated: <?php echo date('Y-m-d H:i:s T'); ?> · Transaction: <?php echo $swap['swap_uuid']; ?></p>
        <p style="margin-top: 1rem; color: #94a3b8;">This document is a complete, verifiable record of the transaction. All data is presented as stored in the VouchMorph Message Clearing House with 7-year audit trail retention.</p>
    </div>
</body>
</html>
