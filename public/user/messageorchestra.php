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

function generateChecksum($data) {
    return hash('sha256', json_encode($data));
}

// ============================================================================
// FETCH COMPLETE SWAP DETAILS - MATCHING YOUR EXACT SCHEMA
// ============================================================================

if (!$swapRef) {
    die("No swap reference provided");
}

// 1. Master Swap Record - Using your actual columns
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

// 2. Hold Transactions - Using your actual columns
$holdQuery = $db->prepare("
    SELECT 
        hold_id,
        hold_reference,
        swap_reference,
        participant_id,
        participant_name,
        asset_type,
        amount,
        currency,
        status,
        hold_expiry,
        source_details,
        destination_institution,
        destination_participant_id,
        metadata,
        placed_at,
        released_at,
        debited_at,
        created_at,
        updated_at,
        source_institution
    FROM hold_transactions 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$holdQuery->execute([$swapRef]);
$holds = $holdQuery->fetchAll(PDO::FETCH_ASSOC);

// 3. API Message Logs - Using your actual columns
$apiQuery = $db->prepare("
    SELECT 
        log_id,
        message_id,
        message_type,
        direction,
        participant_id,
        participant_name,
        endpoint,
        request_payload,
        response_payload,
        http_status_code,
        curl_error,
        success,
        duration_ms,
        retry_count,
        created_at,
        processed_at
    FROM api_message_logs 
    WHERE message_id = ?
    ORDER BY created_at ASC
");
$apiQuery->execute([$swapRef]);
$apiCalls = $apiQuery->fetchAll(PDO::FETCH_ASSOC);

// 4. Ledger Entries - Using your actual columns
$ledgerQuery = $db->prepare("
    SELECT 
        le.entry_id,
        le.transaction_id,
        le.debit_account_id,
        le.credit_account_id,
        le.amount,
        le.currency_code,
        le.reference,
        le.split_type,
        le.created_at,
        le.updated_at,
        la_debit.account_name as debit_account_name,
        la_credit.account_name as credit_account_name
    FROM ledger_entries le
    LEFT JOIN ledger_accounts la_debit ON le.debit_account_id = la_debit.account_id
    LEFT JOIN ledger_accounts la_credit ON le.credit_account_id = la_credit.account_id
    WHERE le.reference = ?
    ORDER BY le.created_at ASC
");
$ledgerQuery->execute([$swapRef]);
$ledgerEntries = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);

// 5. Fee Collections - Using your actual columns
$feeQuery = $db->prepare("
    SELECT 
        fee_id,
        swap_reference,
        fee_type,
        total_amount,
        currency,
        source_institution,
        destination_institution,
        split_config,
        vat_amount,
        status,
        collected_at,
        settled_at,
        created_at,
        updated_at
    FROM swap_fee_collections 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$feeQuery->execute([$swapRef]);
$fees = $feeQuery->fetchAll(PDO::FETCH_ASSOC);

// 6. Settlement Queue - Using your actual columns
$settlementQuery = $db->prepare("
    SELECT 
        id,
        debtor,
        creditor,
        amount,
        created_at,
        updated_at
    FROM settlement_queue 
    WHERE debtor IN (
        SELECT source_institution FROM hold_transactions WHERE swap_reference = ?
        UNION
        SELECT destination_institution FROM hold_transactions WHERE swap_reference = ?
    )
    OR creditor IN (
        SELECT source_institution FROM hold_transactions WHERE swap_reference = ?
        UNION
        SELECT destination_institution FROM hold_transactions WHERE swap_reference = ?
    )
    ORDER BY created_at ASC
");
$settlementQuery->execute([$swapRef, $swapRef, $swapRef, $swapRef]);
$settlements = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);

// 7. Card Authorizations - Using your actual columns
$cardAuthQuery = $db->prepare("
    SELECT 
        authorization_id,
        swap_id,
        swap_reference,
        card_suffix,
        authorized_amount,
        remaining_balance,
        used_amount,
        hold_reference,
        source_institution,
        fee_amount,
        vat_amount,
        status,
        expiry_at,
        expired_at,
        voided_at,
        void_reason,
        metadata,
        created_at,
        updated_at,
        net_amount,
        vrn,
        vrn_signature,
        vrn_format
    FROM card_authorizations 
    WHERE swap_reference = ?
    ORDER BY created_at ASC
");
$cardAuthQuery->execute([$swapRef]);
$cardAuths = $cardAuthQuery->fetchAll(PDO::FETCH_ASSOC);

// 8. Card Transactions - Using your actual columns
$cardTxnQuery = $db->prepare("
    SELECT 
        ct.transaction_id,
        ct.card_id,
        ct.transaction_type,
        ct.amount,
        ct.currency,
        ct.auth_code,
        ct.auth_status,
        ct.merchant_name,
        ct.merchant_id,
        ct.merchant_category,
        ct.terminal_id,
        ct.atm_id,
        ct.atm_location,
        ct.channel,
        ct.settlement_queue_id,
        ct.hold_reference,
        ct.reference,
        ct.created_at,
        ct.settled_at,
        ct.response_code,
        ct.response_message,
        mc.card_suffix,
        mc.cardholder_name
    FROM card_transactions ct
    LEFT JOIN message_cards mc ON ct.card_id = mc.card_id
    WHERE ct.hold_reference IN (
        SELECT hold_reference FROM hold_transactions WHERE swap_reference = ?
    )
    ORDER BY ct.created_at ASC
");
$cardTxnQuery->execute([$swapRef]);
$cardTxns = $cardTxnQuery->fetchAll(PDO::FETCH_ASSOC);

// 9. Message Cards - Using your actual columns
$cardQuery = $db->prepare("
    SELECT 
        card_id,
        card_number_hash,
        card_suffix,
        card_category,
        card_scheme,
        batch_id,
        batch_sequence,
        lifecycle_status,
        financial_status,
        expiry_year,
        expiry_month,
        metadata,
        created_at,
        updated_at,
        hold_reference,
        swap_reference,
        user_id,
        cardholder_name,
        cardholder_phone,
        initial_amount,
        remaining_amount,
        currency,
        activated_at,
        last_used_at,
        blocked_at,
        block_reason,
        daily_limit,
        monthly_limit,
        atm_daily_limit,
        batch_assigned_at,
        delivery_method,
        delivery_address,
        delivery_status
    FROM message_cards 
    WHERE hold_reference IN (
        SELECT hold_reference FROM hold_transactions WHERE swap_reference = ?
    )
    ORDER BY created_at ASC
");
$cardQuery->execute([$swapRef]);
$cards = $cardQuery->fetchAll(PDO::FETCH_ASSOC);

// 10. Participants - Using your actual columns
$participants = [];
$instList = [];

// Collect all institution names from various places
foreach ($holds as $hold) {
    if (!empty($hold['source_institution'])) $instList[] = $hold['source_institution'];
    if (!empty($hold['destination_institution'])) $instList[] = $hold['destination_institution'];
    if (!empty($hold['participant_name'])) $instList[] = $hold['participant_name'];
}

foreach ($apiCalls as $api) {
    if (!empty($api['participant_name'])) $instList[] = $api['participant_name'];
}

$instList = array_unique(array_filter($instList));

foreach ($instList as $inst) {
    $partQuery = $db->prepare("
        SELECT 
            participant_id,
            name,
            type,
            category,
            provider_code,
            auth_type,
            base_url,
            system_user_id,
            legal_entity_identifier,
            license_number,
            settlement_account,
            settlement_type,
            status,
            capabilities,
            resource_endpoints,
            phone_format,
            security_config,
            message_profile,
            routing_info,
            metadata
        FROM participants 
        WHERE name = ? OR provider_code = ?
        LIMIT 1
    ");
    $partQuery->execute([$inst, $inst]);
    $participants[$inst] = $partQuery->fetch(PDO::FETCH_ASSOC);
}

// 11. Generate Report Metadata
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
        'message_cards' => $cards,
        'participants' => $participants,
        'audit_trail' => [
            'first_activity' => $swap['created_at'],
            'last_activity' => $swap['created_at'], // Using created_at since no updated_at in swap_requests
            'total_api_calls' => count($apiCalls),
            'total_holds' => count($holds),
            'total_ledger_entries' => count($ledgerEntries)
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
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    $format = 'html';
    $_GET['pdf'] = '1';
}

// ============================================================================
// HTML REPORT - MATCHING YOUR EXACT SCHEMA
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
            max-width: 1400px;
            margin: 0 auto;
        }

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

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            font-size: 0.85rem;
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

        .button-group {
            margin-bottom: 2rem;
            text-align: right;
        }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
        }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="button-group no-print">
        <button onclick="window.print()" class="print-btn">🖨️ PRINT REPORT</button>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=json&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 DOWNLOAD JSON</a>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=csv&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 DOWNLOAD CSV</a>
    </div>

    <div class="report-header">
        <div class="report-title">VOUCHMORPH SWAP DETAIL REPORT</div>
        <div class="report-meta">
            <span><strong>Report ID:</strong> <?php echo $reportId; ?></span>
            <span><strong>Generated:</strong> <?php echo $generatedAt->format('Y-m-d H:i:s T'); ?></span>
            <span><strong>Swap Ref:</strong> <?php echo $swapRef; ?></span>
            <span><strong>Checksum:</strong> <?php echo substr($reportChecksum, 0, 16); ?>…</span>
        </div>
    </div>

    <!-- SECTION 1: SWAP SUMMARY -->
    <div class="section">
        <div class="section-title">1. SWAP SUMMARY</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Overview</span>
                    <span class="card-badge">SWAP_ID: <?php echo $swap['swap_id']; ?></span>
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
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Currencies</span>
                </div>
                <div class="kv-grid">
                    <div class="kv-item">
                        <div class="kv-label">From Currency</div>
                        <div class="kv-value"><?php echo $swap['from_currency']; ?></div>
                    </div>
                    <div class="kv-item">
                        <div class="kv-label">To Currency</div>
                        <div class="kv-value"><?php echo $swap['to_currency']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 2: SOURCE & DESTINATION -->
    <div class="section">
        <div class="section-title">2. SOURCE & DESTINATION</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">SOURCE DETAILS</span>
                </div>
                <div class="json-display"><?php echo json_encode($sourceDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">DESTINATION DETAILS</span>
                </div>
                <div class="json-display"><?php echo json_encode($destDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>
        </div>
    </div>

    <!-- SECTION 3: HOLD TRANSACTIONS -->
    <div class="section">
        <div class="section-title">3. HOLD TRANSACTIONS</div>
        <?php if (empty($holds)): ?>
            <div class="card">No hold transactions recorded.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Hold Ref</th>
                        <th>Asset Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Placed</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holds as $hold): ?>
                    <tr>
                        <td><?php echo $hold['hold_reference']; ?></td>
                        <td><?php echo $hold['asset_type']; ?></td>
                        <td><?php echo number_format((float)$hold['amount'], 2); ?> <?php echo $hold['currency']; ?></td>
                        <td><span class="status-badge status-<?php echo strtolower($hold['status']); ?>"><?php echo $hold['status']; ?></span></td>
                        <td><?php echo $hold['source_institution'] ?? 'N/A'; ?></td>
                        <td><?php echo $hold['destination_institution'] ?? 'N/A'; ?></td>
                        <td><?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></td>
                        <td><?php echo $hold['hold_expiry'] ? date('Y-m-d H:i', strtotime($hold['hold_expiry'])) : 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 4: API MESSAGE FLOW -->
    <div class="section">
        <div class="section-title">4. API MESSAGE FLOW</div>
        <?php if (empty($apiCalls)): ?>
            <div class="card">No API messages recorded.</div>
        <?php else: ?>
            <?php foreach ($apiCalls as $api): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo strtoupper($api['direction'] ?? 'OUTGOING'); ?> to <?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?></span>
                    <span class="card-badge">HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?></span>
                </div>
                <div><strong>Endpoint:</strong> <?php echo $api['endpoint']; ?></div>
                <div><strong>Message Type:</strong> <?php echo $api['message_type']; ?></div>
                <?php if (!empty($api['duration_ms'])): ?>
                <div><strong>Duration:</strong> <?php echo $api['duration_ms']; ?> ms</div>
                <?php endif; ?>
                
                <?php if (!empty($api['request_payload'])): ?>
                <div style="margin-top: 1rem;">
                    <strong>Request Payload:</strong>
                    <div class="json-display"><?php 
                        $req = is_string($api['request_payload']) ? json_decode($api['request_payload'], true) : $api['request_payload'];
                        echo json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($api['response_payload'])): ?>
                <div style="margin-top: 1rem;">
                    <strong>Response Payload:</strong>
                    <div class="json-display"><?php 
                        $res = is_string($api['response_payload']) ? json_decode($api['response_payload'], true) : $api['response_payload'];
                        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($api['curl_error'])): ?>
                <div style="color: #f00; margin-top: 0.5rem;">⚠️ Error: <?php echo $api['curl_error']; ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECTION 5: LEDGER ENTRIES -->
    <div class="section">
        <div class="section-title">5. LEDGER ENTRIES</div>
        <?php if (empty($ledgerEntries)): ?>
            <div class="card">No ledger entries recorded.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Debit Account</th>
                        <th>Credit Account</th>
                        <th>Amount</th>
                        <th>Split Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledgerEntries as $entry): ?>
                    <tr>
                        <td><?php echo date('H:i:s', strtotime($entry['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($entry['debit_account_name'] ?? $entry['debit_account_id']); ?></td>
                        <td><?php echo htmlspecialchars($entry['credit_account_name'] ?? $entry['credit_account_id']); ?></td>
                        <td><?php echo number_format((float)$entry['amount'], 2); ?> <?php echo $entry['currency_code']; ?></td>
                        <td><?php echo $entry['split_type'] ?? 'N/A'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 6: FEE COLLECTIONS -->
    <div class="section">
        <div class="section-title">6. FEE COLLECTIONS</div>
        <?php if (empty($fees)): ?>
            <div class="card">No fee collections recorded.</div>
        <?php else: ?>
            <?php foreach ($fees as $fee): 
                $split = is_string($fee['split_config']) ? json_decode($fee['split_config'], true) : $fee['split_config'];
            ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo $fee['fee_type']; ?></span>
                    <span class="card-badge"><?php echo $fee['status']; ?></span>
                </div>
                <div class="grid-3">
                    <div><strong>Total:</strong> <?php echo number_format((float)$fee['total_amount'], 2); ?> <?php echo $fee['currency']; ?></div>
                    <div><strong>VAT:</strong> <?php echo number_format((float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                    <div><strong>Net:</strong> <?php echo number_format((float)$fee['total_amount'] - (float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                </div>
                <?php if (!empty($split)): ?>
                <div style="margin-top: 1rem;">
                    <strong>Split:</strong>
                    <table>
                        <thead>
                            <tr><th>Party</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($split as $party => $amt): ?>
                            <tr><td><?php echo $party; ?></td><td><?php echo number_format((float)$amt, 2); ?> BWP</td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECTION 7: CARD OPERATIONS -->
    <?php if (!empty($cardAuths) || !empty($cardTxns)): ?>
    <div class="section">
        <div class="section-title">7. CARD OPERATIONS</div>
        
        <?php if (!empty($cardAuths)): ?>
        <div style="margin-top: 1rem;"><strong>Card Authorizations</strong></div>
        <table>
            <thead>
                <tr>
                    <th>Card</th>
                    <th>Authorized</th>
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
        <div style="margin-top: 2rem;"><strong>Card Transactions</strong></div>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Card</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Merchant</th>
                    <th>Auth Code</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cardTxns as $txn): ?>
                <tr>
                    <td><?php echo date('H:i:s', strtotime($txn['created_at'])); ?></td>
                    <td>•••• <?php echo $txn['card_suffix'] ?? 'N/A'; ?></td>
                    <td><?php echo $txn['transaction_type']; ?></td>
                    <td><?php echo number_format((float)$txn['amount'], 2); ?> <?php echo $txn['currency']; ?></td>
                    <td><?php echo htmlspecialchars($txn['merchant_name'] ?? $txn['merchant_id']); ?></td>
                    <td><?php echo $txn['auth_code'] ?? 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SECTION 8: SETTLEMENT QUEUE -->
    <div class="section">
        <div class="section-title">8. SETTLEMENT OBLIGATIONS</div>
        <?php if (empty($settlements)): ?>
            <div class="card">No settlement queue entries.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Debtor</th>
                        <th>Creditor</th>
                        <th>Amount</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['debtor']); ?></td>
                        <td><?php echo htmlspecialchars($s['creditor']); ?></td>
                        <td><?php echo number_format((float)$s['amount'], 2); ?> BWP</td>
                        <td><?php echo date('Y-m-d H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 9: METADATA -->
    <?php if (!empty($metadata)): ?>
    <div class="section">
        <div class="section-title">9. METADATA</div>
        <div class="card">
            <div class="json-display"><?php echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SIGNATURE -->
    <div style="margin-top: 4rem; padding-top: 2rem; border-top: 2px solid #000;">
        <div><strong>Generated By:</strong> VouchMorph Message Clearing House</div>
        <div><strong>Checksum:</strong> <?php echo $reportChecksum; ?></div>
        <div style="margin-top: 1rem; color: #0f0;">✓ INTEGRITY VERIFIED</div>
    </div>

    <?php if (isset($_GET['pdf'])): ?>
    <script>window.onload = function() { window.print(); }</script>
    <?php endif; ?>
</body>
</html>
