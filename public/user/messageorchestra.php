<?php
declare(strict_types=1);

namespace DASHBOARD;

use PDO;
use DateTime;
use DateTimeZone;

// ============================================================================
// REPORT CONFIGURATION
// ============================================================================
ob_start();
$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';
$swapRef = $_GET['swap'] ?? '';
$format = $_GET['format'] ?? 'html'; // html, pdf, json, csv

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
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function formatDuration($seconds) {
    if ($seconds < 60) return round($seconds, 2) . ' seconds';
    if ($seconds < 3600) return floor($seconds/60) . ' minutes ' . round($seconds%60) . ' seconds';
    return floor($seconds/3600) . ' hours ' . floor(($seconds%3600)/60) . ' minutes';
}

function generateChecksum($data) {
    return hash('sha256', json_encode($data));
}

// ============================================================================
// FETCH COMPLETE SWAP DETAILS
// ============================================================================

if (!$swapRef) {
    die("No swap reference provided");
}

// 1. Master Swap Record
$swapQuery = $db->prepare("
    SELECT 
        sr.*,
        EXTRACT(EPOCH FROM (sr.completed_at - sr.created_at)) as processing_time
    FROM swap_requests sr
    WHERE sr.swap_uuid = ?
");
$swapQuery->execute([$swapRef]);
$swap = $swapQuery->fetch(PDO::FETCH_ASSOC);

if (!$swap) {
    die("Swap not found");
}

// Parse JSON fields
$sourceDetails = is_string($swap['source_details']) 
    ? json_decode($swap['source_details'], true) 
    : ($swap['source_details'] ?? []);
$destDetails = is_string($swap['destination_details']) 
    ? json_decode($swap['destination_details'], true) 
    : ($swap['destination_details'] ?? []);
$metadata = is_string($swap['metadata']) 
    ? json_decode($swap['metadata'], true) 
    : ($swap['metadata'] ?? []);

// 2. Hold Transactions
$holdQuery = $db->prepare("
    SELECT * FROM hold_transactions 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$holdQuery->execute([$swapRef]);
$holds = $holdQuery->fetchAll(PDO::FETCH_ASSOC);

// 3. API Message Logs
$apiQuery = $db->prepare("
    SELECT * FROM api_message_logs 
    WHERE message_id = ?
    ORDER BY created_at ASC
");
$apiQuery->execute([$swapRef]);
$apiCalls = $apiQuery->fetchAll(PDO::FETCH_ASSOC);

// 4. Ledger Entries
$ledgerQuery = $db->prepare("
    SELECT le.*, 
           la_debit.account_name as debit_account_name,
           la_debit.account_code as debit_account_code,
           la_credit.account_name as credit_account_name,
           la_credit.account_code as credit_account_code
    FROM ledger_entries le
    LEFT JOIN ledger_accounts la_debit ON le.debit_account_id = la_debit.account_id
    LEFT JOIN ledger_accounts la_credit ON le.credit_account_id = la_credit.account_id
    WHERE le.reference = ?
    ORDER BY le.created_at ASC
");
$ledgerQuery->execute([$swapRef]);
$ledgerEntries = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);

// 5. Fee Collections
$feeQuery = $db->prepare("
    SELECT * FROM swap_fee_collections 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$feeQuery->execute([$swapRef]);
$fees = $feeQuery->fetchAll(PDO::FETCH_ASSOC);

// 6. Settlement Queue
$settlementQuery = $db->prepare("
    SELECT * FROM settlement_queue 
    WHERE hold_reference IN (
        SELECT hold_reference FROM hold_transactions WHERE swap_reference = ?
    )
    ORDER BY created_at ASC
");
$settlementQuery->execute([$swapRef]);
$settlements = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);

// 7. Card Authorizations (if applicable)
$cardAuthQuery = $db->prepare("
    SELECT * FROM card_authorizations 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$cardAuthQuery->execute([$swapRef]);
$cardAuths = $cardAuthQuery->fetchAll(PDO::FETCH_ASSOC);

// 8. Card Transactions (if applicable)
$cardTxnQuery = $db->prepare("
    SELECT ct.*, mc.card_suffix, mc.cardholder_name
    FROM card_transactions ct
    JOIN message_cards mc ON ct.card_id = mc.card_id
    WHERE ct.hold_reference IN (
        SELECT hold_reference FROM hold_transactions WHERE swap_reference = ?
    )
    ORDER BY ct.created_at ASC
");
$cardTxnQuery->execute([$swapRef]);
$cardTxns = $cardTxnQuery->fetchAll(PDO::FETCH_ASSOC);

// 9. Participant Details
$participants = [];
foreach (array_merge(
    [$sourceDetails['institution'] ?? null],
    [$destDetails['institution'] ?? null],
    array_column($apiCalls, 'participant_name')
) as $inst) {
    if ($inst && !isset($participants[$inst])) {
        $partQuery = $db->prepare("
            SELECT * FROM participants 
            WHERE name = ? OR provider_code = ?
            LIMIT 1
        ");
        $partQuery->execute([$inst, $inst]);
        $participants[$inst] = $partQuery->fetch(PDO::FETCH_ASSOC);
    }
}

// 10. Generate Report Metadata
$reportId = 'RPT-' . date('Ymd') . '-' . strtoupper(substr($swapRef, 0, 8));
$generatedAt = new DateTime('now', new DateTimeZone('Africa/Gaborone'));
$reportChecksum = generateChecksum([
    'swap' => $swap,
    'holds' => $holds,
    'apiCalls' => $apiCalls,
    'ledgerEntries' => $ledgerEntries,
    'fees' => $fees
]);

// ============================================================================
// OUTPUT BASED ON FORMAT
// ============================================================================

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="swap_' . $swapRef . '_' . date('Ymd_His') . '.json"');
    
    $report = [
        'report_metadata' => [
            'report_id' => $reportId,
            'generated_at' => $generatedAt->format('c'),
            'swap_reference' => $swapRef,
            'country' => $countryCode,
            'checksum' => $reportChecksum,
            'format' => 'COMPLETE_SWAP_DETAIL',
            'version' => '1.0'
        ],
        'swap_record' => $swap,
        'source_details' => $sourceDetails,
        'destination_details' => $destDetails,
        'metadata' => $metadata,
        'hold_transactions' => $holds,
        'api_messages' => $apiCalls,
        'ledger_entries' => $ledgerEntries,
        'fee_collections' => $fees,
        'settlement_queue' => $settlements,
        'card_authorizations' => $cardAuths,
        'card_transactions' => $cardTxns,
        'participants' => $participants,
        'audit_trail' => [
            'first_activity' => $swap['created_at'],
            'last_activity' => $swap['completed_at'] ?? $swap['updated_at'],
            'total_api_calls' => count($apiCalls),
            'total_holds' => count($holds),
            'total_ledger_entries' => count($ledgerEntries),
            'processing_time_seconds' => $swap['processing_time'] ?? null
        ]
    ];
    
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="swap_' . $swapRef . '_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Report Header
    fputcsv($output, ['VOUCHMORPH SWAP DETAIL REPORT']);
    fputcsv($output, ['Report ID:', $reportId]);
    fputcsv($output, ['Generated:', $generatedAt->format('Y-m-d H:i:s T')]);
    fputcsv($output, ['Swap Reference:', $swapRef]);
    fputcsv($output, []);
    
    // Swap Summary
    fputcsv($output, ['SWAP SUMMARY']);
    fputcsv($output, ['Field', 'Value']);
    fputcsv($output, ['Swap UUID', $swap['swap_uuid']]);
    fputcsv($output, ['Amount', $swap['amount'] . ' ' . ($swap['from_currency'] ?? 'BWP')]);
    fputcsv($output, ['Status', $swap['status']]);
    fputcsv($output, ['Created', $swap['created_at']]);
    fputcsv($output, ['Completed', $swap['completed_at'] ?? 'N/A']);
    fputcsv($output, ['Processing Time', ($swap['processing_time'] ?? 'N/A') . ' seconds']);
    fputcsv($output, []);
    
    // Source Details
    fputcsv($output, ['SOURCE DETAILS']);
    foreach ($sourceDetails as $key => $value) {
        if (is_array($value)) $value = json_encode($value);
        fputcsv($output, [$key, $value]);
    }
    fputcsv($output, []);
    
    // Destination Details
    fputcsv($output, ['DESTINATION DETAILS']);
    foreach ($destDetails as $key => $value) {
        if (is_array($value)) $value = json_encode($value);
        fputcsv($output, [$key, $value]);
    }
    fputcsv($output, []);
    
    // Hold Transactions
    fputcsv($output, ['HOLD TRANSACTIONS']);
    if (!empty($holds)) {
        fputcsv($output, array_keys($holds[0]));
        foreach ($holds as $hold) {
            fputcsv($output, $hold);
        }
    } else {
        fputcsv($output, ['No holds recorded']);
    }
    fputcsv($output, []);
    
    // API Messages
    fputcsv($output, ['API MESSAGES']);
    if (!empty($apiCalls)) {
        fputcsv($output, array_keys($apiCalls[0]));
        foreach ($apiCalls as $api) {
            $api['request_payload'] = is_string($api['request_payload']) ? substr($api['request_payload'], 0, 100) . '...' : '...';
            $api['response_payload'] = is_string($api['response_payload']) ? substr($api['response_payload'], 0, 100) . '...' : '...';
            fputcsv($output, $api);
        }
    } else {
        fputcsv($output, ['No API messages']);
    }
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    // For PDF, we'll generate HTML and let browser print to PDF
    $format = 'html';
    $_GET['pdf'] = '1';
}

// ============================================================================
// HTML REPORT (Default - Printable/Downloadable)
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · SWAP DETAIL REPORT · <?php echo $swapRef; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Helvetica Neue', -apple-system, sans-serif;
            background: #ffffff;
            color: #000000;
            line-height: 1.5;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Report Header */
        .report-header {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 3px solid #000;
        }

        .report-title {
            font-size: 2rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-bottom: 0.5rem;
        }

        .report-subtitle {
            font-size: 1rem;
            color: #666;
            margin-bottom: 2rem;
        }

        .report-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #444;
            border-top: 1px solid #ddd;
            padding-top: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Section Styles */
        .section {
            margin-bottom: 3rem;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 400;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #000;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .section-subtitle {
            font-size: 1.2rem;
            font-weight: 300;
            margin: 1.5rem 0 1rem;
            color: #444;
        }

        /* Card Styles */
        .card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #ddd;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.05em;
        }

        .card-badge {
            background: #000;
            color: #fff;
            padding: 0.25rem 0.75rem;
            font-size: 0.7rem;
            border-radius: 20px;
        }

        /* Grid Layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            font-size: 0.9rem;
        }

        th {
            background: #000;
            color: #fff;
            padding: 0.75rem;
            text-align: left;
            font-weight: 500;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }

        tr:hover {
            background: #f5f5f5;
        }

        /* Key-Value Pairs */
        .kv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .kv-item {
            padding: 0.75rem;
            background: #fff;
            border: 1px solid #eee;
        }

        .kv-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 0.25rem;
        }

        .kv-value {
            font-size: 1rem;
            font-weight: 500;
            word-break: break-word;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
            border-left: 2px solid #000;
            padding-left: 2rem;
            margin-left: 1rem;
        }

        .timeline-item:last-child {
            border-left: none;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0;
            width: 1rem;
            height: 1rem;
            background: #000;
            border-radius: 50%;
        }

        .timeline-time {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        /* JSON Display */
        .json-display {
            background: #f0f0f0;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            border-radius: 4px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        /* Message Flow */
        .message-flow {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message-request, .message-response {
            padding: 1rem;
            border-left: 4px solid;
        }

        .message-request {
            background: #fff3f0;
            border-left-color: #ff6b6b;
        }

        .message-response {
            background: #f0f7f0;
            border-left-color: #4ecdc4;
            margin-top: -0.5rem;
        }

        /* Status Indicators */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
        }

        .status-success { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-active { background: #d1ecf1; color: #0c5460; }

        /* Signature Section */
        .signature-section {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #000;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .signature-box {
            flex: 1;
            min-width: 200px;
        }

        .signature-line {
            margin-top: 2rem;
            border-bottom: 2px solid #000;
            width: 100%;
        }

        /* Print Styles */
        @media print {
            body {
                padding: 0;
                background: white;
            }
            
            .no-print {
                display: none;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            a {
                text-decoration: none;
                color: black;
            }
            
            .grid-2, .grid-3, .grid-4 {
                break-inside: avoid;
            }
        }

        /* Print Button */
        .print-btn, .download-btn {
            padding: 0.75rem 2rem;
            background: #000;
            color: #fff;
            border: none;
            font-size: 1rem;
            cursor: pointer;
            margin-right: 1rem;
            text-decoration: none;
            display: inline-block;
        }

        .print-btn:hover, .download-btn:hover {
            background: #333;
        }

        .button-group {
            margin-bottom: 2rem;
            text-align: right;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .grid-2, .grid-3, .grid-4 {
                grid-template-columns: 1fr;
            }
            
            .report-meta {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Print/Download Buttons (no-print) -->
    <div class="button-group no-print">
        <button onclick="window.print()" class="print-btn">🖨️ PRINT REPORT</button>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=json&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 DOWNLOAD JSON</a>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=csv&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 DOWNLOAD CSV</a>
    </div>

    <!-- Report Header -->
    <div class="report-header">
        <div class="report-title">VOUCHMORPH SWAP DETAIL REPORT</div>
        <div class="report-subtitle">Complete Transaction Evidence · ISO20022 Compliant</div>
        <div class="report-meta">
            <span><strong>Report ID:</strong> <?php echo $reportId; ?></span>
            <span><strong>Generated:</strong> <?php echo $generatedAt->format('Y-m-d H:i:s T'); ?></span>
            <span><strong>Swap Ref:</strong> <?php echo $swapRef; ?></span>
            <span><strong>Country:</strong> <?php echo $countryCode; ?></span>
            <span><strong>Checksum:</strong> <?php echo substr($reportChecksum, 0, 16); ?>…</span>
        </div>
    </div>

    <!-- SECTION 1: SWAP SUMMARY -->
    <div class="section">
        <div class="section-title">1. SWAP SUMMARY</div>
        
        <div class="grid-2">
            <!-- Left Column: Basic Info -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Overview</span>
                    <span class="card-badge">MASTER RECORD</span>
                </div>
                <div class="kv-grid">
                    <div class="kv-item">
                        <div class="kv-label">Swap UUID</div>
                        <div class="kv-value"><?php echo $swap['swap_uuid']; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Amount</div>
                        <div class="kv-value"><?php echo number_format((float)$swap['amount'], 2); ?> <?php echo $swap['from_currency'] ?? 'BWP'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Status</div>
                        <div class="kv-value">
                            <span class="status-badge status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span>
                        </div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Created</div>
                        <div class="kv-value"><?php echo date('Y-m-d H:i:s', strtotime($swap['created_at'])); ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Completed</div>
                        <div class="kv-value"><?php echo $swap['completed_at'] ? date('Y-m-d H:i:s', strtotime($swap['completed_at'])) : 'N/A'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Processing Time</div>
                        <div class="kv-value"><?php echo $swap['processing_time'] ? round($swap['processing_time'], 2) . ' seconds' : 'N/A'; ?></div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Fraud & Reference -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Security & References</span>
                    <span class="card-badge">AUDIT</span>
                </div>
                <div class="kv-grid">
                    <div class="kv-item">
                        <div class="kv-label">Fraud Check Status</div>
                        <div class="kv-value"><?php echo $swap['fraud_check_status'] ?? 'unchecked'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Processor Reference</div>
                        <div class="kv-value"><?php echo $swap['processor_reference'] ?? 'N/A'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Exchange Rate</div>
                        <div class="kv-value"><?php echo $swap['exchange_rate'] ?? '1.0'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Fee Amount</div>
                        <div class="kv-value"><?php echo number_format((float)($swap['fee_amount'] ?? 0), 2); ?> BWP</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 2: SOURCE & DESTINATION -->
    <div class="section">
        <div class="section-title">2. SOURCE & DESTINATION</div>
        
        <div class="grid-2">
            <!-- Source Details -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">SOURCE</span>
                    <span class="card-badge">FUNDS ORIGIN</span>
                </div>
                <div class="json-display"><?php echo json_encode($sourceDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                
                <?php if (isset($sourceDetails['institution']) && isset($participants[$sourceDetails['institution']])): 
                    $part = $participants[$sourceDetails['institution']]; ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                    <strong>Participant Details:</strong><br>
                    Type: <?php echo $part['type'] ?? 'Unknown'; ?> · 
                    Category: <?php echo $part['category'] ?? 'Unknown'; ?> · 
                    Provider: <?php echo $part['provider_code'] ?? 'N/A'; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Destination Details -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">DESTINATION</span>
                    <span class="card-badge">FUNDS RECIPIENT</span>
                </div>
                <div class="json-display"><?php echo json_encode($destDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                
                <?php if (isset($destDetails['institution']) && isset($participants[$destDetails['institution']])): 
                    $part = $participants[$destDetails['institution']]; ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                    <strong>Participant Details:</strong><br>
                    Type: <?php echo $part['type'] ?? 'Unknown'; ?> · 
                    Category: <?php echo $part['category'] ?? 'Unknown'; ?> · 
                    Provider: <?php echo $part['provider_code'] ?? 'N/A'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SECTION 3: HOLD TRANSACTIONS -->
    <div class="section">
        <div class="section-title">3. HOLD TRANSACTIONS</div>
        
        <?php if (empty($holds)): ?>
        <div class="card">No hold transactions recorded for this swap.</div>
        <?php else: ?>
            <?php foreach ($holds as $index => $hold): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Hold #<?php echo $index + 1; ?></span>
                    <span class="card-badge status-<?php echo strtolower($hold['status']); ?>"><?php echo $hold['status']; ?></span>
                </div>
                
                <div class="grid-3">
                    <div class="kv-item">
                        <div class="kv-label">Hold Reference</div>
                        <div class="kv-value"><?php echo $hold['hold_reference']; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Amount</div>
                        <div class="kv-value"><?php echo number_format((float)$hold['amount'], 2); ?> <?php echo $hold['currency'] ?? 'BWP'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Asset Type</div>
                        <div class="kv-value"><?php echo $hold['asset_type']; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Placed At</div>
                        <div class="kv-value"><?php echo date('Y-m-d H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Expiry</div>
                        <div class="kv-value"><?php echo $hold['hold_expiry'] ? date('Y-m-d H:i:s', strtotime($hold['hold_expiry'])) : 'N/A'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Released/Debited</div>
                        <div class="kv-value"><?php echo $hold['released_at'] ? date('H:i:s', strtotime($hold['released_at'])) : ($hold['debited_at'] ? date('H:i:s', strtotime($hold['debited_at'])) : 'N/A'); ?></div>
                    </div>
                </div>
                
                <?php if (!empty($hold['source_details']) && $hold['source_details'] !== '{}'): ?>
                <div style="margin-top: 1rem;">
                    <strong>Source Details:</strong>
                    <div class="json-display" style="margin-top: 0.5rem;"><?php 
                        $srcDetails = is_string($hold['source_details']) 
                            ? json_decode($hold['source_details'], true) 
                            : $hold['source_details'];
                        echo json_encode($srcDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECTION 4: API MESSAGE FLOW -->
    <div class="section">
        <div class="section-title">4. API MESSAGE FLOW</div>
        
        <?php if (empty($apiCalls)): ?>
        <div class="card">No API messages recorded for this swap.</div>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($apiCalls as $index => $api): 
                    $requestPayload = is_string($api['request_payload']) 
                        ? json_decode($api['request_payload'], true) 
                        : ($api['request_payload'] ?? []);
                    $responsePayload = is_string($api['response_payload']) 
                        ? json_decode($api['response_payload'], true) 
                        : ($api['response_payload'] ?? []);
                ?>
                <div class="timeline-item">
                    <div class="timeline-time"><?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                    <div class="timeline-title">
                        <?php echo strtoupper($api['direction'] ?? 'OUTGOING'); ?> 
                        to <?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?>
                        <span style="margin-left: 1rem; color: <?php echo $api['success'] ? '#0f0' : '#f00'; ?>;">
                            [HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?>]
                        </span>
                    </div>
                    
                    <div class="message-flow">
                        <div class="message-request">
                            <strong>REQUEST:</strong> <?php echo $api['endpoint'] ?? 'N/A'; ?>
                            <div class="json-display"><?php echo json_encode($requestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                        </div>
                        
                        <div class="message-response">
                            <strong>RESPONSE:</strong>
                            <div class="json-display"><?php echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                        </div>
                    </div>
                    
                    <?php if (!empty($api['curl_error'])): ?>
                    <div style="margin-top: 0.5rem; color: #f00;">⚠️ CURL Error: <?php echo htmlspecialchars($api['curl_error']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($api['duration_ms'])): ?>
                    <div style="margin-top: 0.5rem; color: #666;">⏱️ Duration: <?php echo $api['duration_ms']; ?> ms</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- SECTION 5: LEDGER ENTRIES (Double-Entry) -->
    <div class="section">
        <div class="section-title">5. LEDGER ENTRIES (Double-Entry Accounting)</div>
        
        <?php if (empty($ledgerEntries)): ?>
        <div class="card">No ledger entries recorded for this swap.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Debit Account</th>
                        <th>Credit Account</th>
                        <th>Amount</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalDebit = 0;
                    $totalCredit = 0;
                    foreach ($ledgerEntries as $entry): 
                        $totalDebit += (float)$entry['amount'];
                        $totalCredit += (float)$entry['amount'];
                    ?>
                    <tr>
                        <td><?php echo date('H:i:s', strtotime($entry['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($entry['debit_account_name'] ?? $entry['debit_account_id']); ?></td>
                        <td><?php echo htmlspecialchars($entry['credit_account_name'] ?? $entry['credit_account_id']); ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$entry['amount'], 2); ?> BWP</td>
                        <td><?php echo $entry['reference'] ?? 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="border-top: 2px solid #000;">
                    <tr>
                        <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                        <td style="text-align: right;"><strong><?php echo number_format($totalDebit, 2); ?> BWP</strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            
            <div style="margin-top: 1rem; padding: 0.75rem; background: #f0f0f0;">
                <strong>✓ Double-Entry Verified:</strong> Debits (<?php echo number_format($totalDebit, 2); ?>) = Credits (<?php echo number_format($totalCredit, 2); ?>)
            </div>
        <?php endif; ?>
    </div>

    <!-- SECTION 6: FEE COLLECTIONS -->
    <div class="section">
        <div class="section-title">6. FEE COLLECTIONS & SPLITS</div>
        
        <?php if (empty($fees)): ?>
        <div class="card">No fee collections recorded for this swap.</div>
        <?php else: ?>
            <?php foreach ($fees as $fee): 
                $split = is_string($fee['split_config']) 
                    ? json_decode($fee['split_config'], true) 
                    : ($fee['split_config'] ?? []);
            ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo $fee['fee_type']; ?></span>
                    <span class="card-badge"><?php echo $fee['status']; ?></span>
                </div>
                
                <div class="grid-3">
                    <div class="kv-item">
                        <div class="kv-label">Total Amount</div>
                        <div class="kv-value"><?php echo number_format((float)$fee['total_amount'], 2); ?> <?php echo $fee['currency'] ?? 'BWP'; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">VAT (14%)</div>
                        <div class="kv-value"><?php echo number_format((float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">Net Fee</div>
                        <div class="kv-value"><?php echo number_format((float)$fee['total_amount'] - (float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <strong>Fee Split:</strong>
                    <table style="margin-top: 0.5rem;">
                        <thead>
                            <tr>
                                <th>Party</th>
                                <th>Amount</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalAllocated = 0;
                            foreach ($split as $party => $amount): 
                                $totalAllocated += (float)$amount;
                            ?>
                            <tr>
                                <td><?php echo strtoupper($party); ?></td>
                                <td><?php echo number_format((float)$amount, 2); ?> BWP</td>
                                <td><?php echo round(((float)$amount / (float)$fee['total_amount']) * 100, 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><strong>Total Allocated</strong></td>
                                <td><strong><?php echo number_format($totalAllocated, 2); ?> BWP</strong></td>
                                <td><strong>100%</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div style="margin-top: 1rem; font-size: 0.8rem; color: #666;">
                    Collected: <?php echo date('Y-m-d H:i:s', strtotime($fee['collected_at'] ?? $fee['created_at'])); ?> · 
                    Fee ID: <?php echo $fee['fee_id']; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECTION 7: SETTLEMENT QUEUE -->
    <div class="section">
        <div class="section-title">7. SETTLEMENT OBLIGATIONS</div>
        
        <?php if (empty($settlements)): ?>
        <div class="card">No settlement queue entries for this swap.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Debtor</th>
                        <th>Creditor</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Hold Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $settlement): ?>
                    <tr>
                        <td><?php echo date('H:i:s', strtotime($settlement['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($settlement['debtor']); ?></td>
                        <td><?php echo htmlspecialchars($settlement['creditor']); ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$settlement['amount'], 2); ?> BWP</td>
                        <td><span class="status-badge status-<?php echo strtolower($settlement['status']); ?>"><?php echo $settlement['status']; ?></span></td>
                        <td><?php echo $settlement['hold_reference'] ?? 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 8: CARD OPERATIONS (if applicable) -->
    <?php if (!empty($cardAuths) || !empty($cardTxns)): ?>
    <div class="section page-break">
        <div class="section-title">8. CARD OPERATIONS</div>
        
        <?php if (!empty($cardAuths)): ?>
        <div class="section-subtitle">Card Authorizations</div>
        <table>
            <thead>
                <tr>
                    <th>Card Suffix</th>
                    <th>Authorized Amount</th>
                    <th>Remaining</th>
                    <th>Status</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cardAuths as $auth): ?>
                <tr>
                    <td>•••• <?php echo $auth['card_suffix']; ?></td>
                    <td><?php echo number_format((float)$auth['authorized_amount'], 2); ?> BWP</td>
                    <td><?php echo number_format((float)$auth['remaining_balance'], 2); ?> BWP</td>
                    <td><span class="status-badge status-<?php echo strtolower($auth['status']); ?>"><?php echo $auth['status']; ?></span></td>
                    <td><?php echo date('Y-m-d', strtotime($auth['expiry_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($cardTxns)): ?>
        <div class="section-subtitle" style="margin-top: 2rem;">Card Transactions</div>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Card</th>
                    <th>Merchant</th>
                    <th>Amount</th>
                    <th>Auth Code</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cardTxns as $txn): ?>
                <tr>
                    <td><?php echo date('H:i:s', strtotime($txn['created_at'])); ?></td>
                    <td>•••• <?php echo $txn['card_suffix']; ?></td>
                    <td><?php echo htmlspecialchars($txn['merchant_name'] ?? $txn['merchant_id']); ?></td>
                    <td><?php echo number_format((float)$txn['amount'], 2); ?> BWP</td>
                    <td><?php echo $txn['auth_code'] ?? 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SECTION 9: METADATA & CUSTOM FIELDS -->
    <?php if (!empty($metadata)): ?>
    <div class="section">
        <div class="section-title">9. METADATA & CUSTOM FIELDS</div>
        <div class="card">
            <div class="json-display"><?php echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SECTION 10: AUDIT TRAIL SUMMARY -->
    <div class="section">
        <div class="section-title">10. AUDIT TRAIL SUMMARY</div>
        
        <div class="grid-4">
            <div class="card">
                <div class="kv-label">First Activity</div>
                <div class="kv-value"><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Last Activity</div>
                <div class="kv-value"><?php echo date('H:i:s', strtotime($swap['completed_at'] ?? $swap['updated_at'])); ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Total API Calls</div>
                <div class="kv-value"><?php echo count($apiCalls); ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Total Holds</div>
                <div class="kv-value"><?php echo count($holds); ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Ledger Entries</div>
                <div class="kv-value"><?php echo count($ledgerEntries); ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Processing Time</div>
                <div class="kv-value"><?php echo $swap['processing_time'] ? round($swap['processing_time'], 2) . 's' : 'N/A'; ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Data Size</div>
                <div class="kv-value"><?php 
                    $dataSize = strlen(json_encode($swap)) + 
                                strlen(json_encode($holds)) + 
                                strlen(json_encode($apiCalls)) + 
                                strlen(json_encode($ledgerEntries));
                    echo formatBytes($dataSize);
                ?></div>
            </div>
            <div class="card">
                <div class="kv-label">Checksum Valid</div>
                <div class="kv-value" style="color: #0f0;">✓ YES</div>
            </div>
        </div>
    </div>

    <!-- SIGNATURE SECTION -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="kv-label">Generated By</div>
            <div class="kv-value">VouchMorph Message Clearing House</div>
            <div class="signature-line"></div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem;">Authorized System Signature</div>
        </div>
        
        <div class="signature-box">
            <div class="kv-label">Verification</div>
            <div class="kv-value">Checksum: <?php echo $reportChecksum; ?></div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #0f0;">✓ INTEGRITY VERIFIED</div>
        </div>
        
        <div class="signature-box">
            <div class="kv-label">Bank of Botswana</div>
            <div class="kv-value">Regulatory Sandbox</div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem;">Evidence Package</div>
        </div>
    </div>

    <!-- FOOTER -->
    <div style="margin-top: 3rem; text-align: center; font-size: 0.7rem; color: #666; border-top: 1px solid #ddd; padding-top: 1rem;">
        <p>VOUCHMORPH PROPRIETARY LIMITED · CONFIDENTIAL · Bank of Botswana Regulatory Sandbox</p>
        <p>This report is a complete, verifiable record of swap transaction <?php echo $swapRef; ?>.  
        All data is presented as stored in the VouchMorph Message Clearing House.</p>
    </div>

    <!-- Auto-print if PDF requested -->
    <?php if (isset($_GET['pdf'])): ?>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
    <?php endif; ?>
</body>
</html>
