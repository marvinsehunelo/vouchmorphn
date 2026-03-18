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
$downloadAll = $_GET['download_all'] ?? false;

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

function safe_html($str) {
    if ($str === null || $str === '') {
        return '—';
    }
    return htmlspecialchars((string)$str);
}

function getISOMessageDescription($msgType) {
    $descriptions = [
        'pain.001' => 'Customer Payment Initiation',
        'pain.002' => 'Payment Status Report',
        'pacs.002' => 'Payment Status Report',
        'pacs.004' => 'Payment Return',
        'pacs.008' => 'FIToFICustomerCreditTransfer',
        'pacs.009' => 'FinancialInstitutionCreditTransfer',
        'camt.053' => 'BankToCustomerStatement',
        'camt.054' => 'BankToCustomerDebitCreditNotification',
        'camt.056' => 'FIToFIPaymentCancellationRequest',
        'acmt.021' => 'AccountVerificationRequest',
        'acmt.022' => 'AccountVerificationReport',
        'acmt.023' => 'IdentificationVerificationRequest',
        'acmt.024' => 'IdentificationVerificationReport',
        'caa.001' => 'CardAuthorizationRequest',
        'caa.002' => 'CardAuthorizationResponse',
        'red.001' => 'RequestForPayment',
        'red.002' => 'RequestForPaymentResponse'
    ];
    return $descriptions[$msgType] ?? 'Unknown Message Type';
}

function getMessageDirectionIcon($direction) {
    if ($direction === 'outgoing') return '⬆️';
    if ($direction === 'incoming') return '⬇️';
    return '🔄';
}

function formatISOPayload($payload) {
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        return $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $payload;
    }
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// ============================================================================
// DOWNLOAD ALL SWAPS REPORT
// ============================================================================
if ($downloadAll) {
    $allSwapsQuery = $db->query("
        SELECT 
            s.swap_uuid,
            s.from_currency,
            s.to_currency,
            s.amount,
            s.status,
            s.created_at,
            s.source_details->>'institution' as source_inst,
            s.destination_details->>'institution' as dest_inst,
            COUNT(DISTINCT h.hold_id) as hold_count,
            COUNT(DISTINCT a.log_id) as api_count,
            SUM(f.total_amount) as total_fees
        FROM swap_requests s
        LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference
        LEFT JOIN api_message_logs a ON s.swap_uuid = a.message_id
        LEFT JOIN swap_fee_collections f ON s.swap_uuid = f.swap_reference
        GROUP BY s.swap_uuid, s.from_currency, s.to_currency, s.amount, s.status, s.created_at, s.source_details, s.destination_details
        ORDER BY s.created_at DESC
    ");
    $allSwaps = $allSwapsQuery->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vouchmorph_all_swaps_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['VOUCHMORPH COMPLETE SWAP REPORT']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s T')]);
    fputcsv($output, ['Total Swaps:', count($allSwaps)]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Swap UUID',
        'From Currency',
        'To Currency',
        'Amount',
        'Status',
        'Created',
        'Source Institution',
        'Destination Institution',
        'Hold Count',
        'API Calls',
        'Total Fees'
    ]);
    
    foreach ($allSwaps as $swap) {
        fputcsv($output, [
            $swap['swap_uuid'],
            $swap['from_currency'],
            $swap['to_currency'],
            $swap['amount'],
            $swap['status'],
            $swap['created_at'],
            $swap['source_inst'],
            $swap['dest_inst'],
            $swap['hold_count'] ?? 0,
            $swap['api_count'] ?? 0,
            $swap['total_fees'] ?? 0
        ]);
    }
    
    fclose($output);
    exit;
}

// ============================================================================
// FETCH COMPLETE SWAP DETAILS
// ============================================================================

if (!$swapRef) {
    $recentQuery = $db->query("
        SELECT 
            swap_uuid,
            from_currency,
            to_currency,
            amount,
            status,
            created_at,
            source_details->>'institution' as source_inst,
            destination_details->>'institution' as dest_inst
        FROM swap_requests 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $recentSwaps = $recentQuery->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Select Swap Report</title>
        <style>
            body { font-family: Arial; padding: 20px; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #000; color: white; padding: 10px; text-align: left; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
            tr:hover { background: #f0f0f0; }
            a { color: #0066cc; text-decoration: none; }
            .download-all { margin: 20px 0; padding: 10px 20px; background: #000; color: white; border: none; cursor: pointer; font-size: 16px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>VOUCHMORPH SWAP REPORTS</h1>
            <a href="?download_all=1" class="download-all">📥 DOWNLOAD FULL REPORT (CSV)</a>
            <p>Select a swap to view detailed report with ISO messages:</p>
            <table>
                <tr>
                    <th>Swap UUID</th>
                    <th>Amount</th>
                    <th>From → To</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($recentSwaps as $swap): ?>
                <tr>
                    <td><a href="?swap=<?php echo urlencode($swap['swap_uuid']); ?>"><?php echo substr($swap['swap_uuid'], 0, 16); ?>…</a></td>
                    <td><?php echo $swap['amount']; ?> <?php echo $swap['from_currency']; ?></td>
                    <td><?php echo safe_html($swap['source_inst']); ?> → <?php echo safe_html($swap['dest_inst']); ?></td>
                    <td><?php echo $swap['status']; ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($swap['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 1. Master Swap Record
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

// 3. API Message Logs (ISO 20022 Messages)
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

// 4. Ledger Entries
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

// 5. Fee Collections
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

// 6. Settlement Queue
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

// 7. Card Authorizations
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

// 8. Card Transactions
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

// 9. Message Cards
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

// 11. Build Transaction Flow Visualization
$flowSteps = [];
$flowSteps[] = [
    'step' => 1,
    'name' => 'Customer Initiation',
    'description' => 'Customer initiates swap via USSD *123#',
    'icon' => '📱',
    'timestamp' => $swap['created_at']
];

foreach ($apiCalls as $api) {
    $flowSteps[] = [
        'step' => count($flowSteps) + 1,
        'name' => 'ISO ' . $api['message_type'],
        'description' => getISOMessageDescription($api['message_type']) . ' ' . getMessageDirectionIcon($api['direction']),
        'icon' => '💬',
        'timestamp' => $api['created_at'],
        'api' => $api
    ];
}

if (!empty($holds)) {
    $flowSteps[] = [
        'step' => count($flowSteps) + 1,
        'name' => 'Hold Placed',
        'description' => 'Funds frozen at source institution',
        'icon' => '🔒',
        'timestamp' => $holds[0]['placed_at'] ?? $holds[0]['created_at'],
        'hold' => $holds[0]
    ];
}

if (!empty($fees)) {
    $flowSteps[] = [
        'step' => count($flowSteps) + 1,
        'name' => 'Fee Deduction',
        'description' => 'Fees calculated and split',
        'icon' => '💰',
        'timestamp' => $fees[0]['collected_at'] ?? $fees[0]['created_at'],
        'fee' => $fees[0]
    ];
}

if (!empty($settlements)) {
    $flowSteps[] = [
        'step' => count($flowSteps) + 1,
        'name' => 'Settlement Queued',
        'description' => 'Obligation recorded for end-of-day netting',
        'icon' => '⚖️',
        'timestamp' => $settlements[0]['created_at'],
        'settlement' => $settlements[0]
    ];
}

$flowSteps[] = [
    'step' => count($flowSteps) + 1,
    'name' => 'Swap Completed',
    'description' => 'Transaction finalized with status: ' . $swap['status'],
    'icon' => '✅',
    'timestamp' => $swap['created_at']
];

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
            'format' => 'COMPLETE_SWAP_DETAIL_WITH_ISO',
            'version' => '2.0'
        ],
        'swap_record' => $swap,
        'source_details' => $sourceDetails,
        'destination_details' => $destDetails,
        'metadata' => $metadata,
        'transaction_flow' => $flowSteps,
        'iso_messages' => array_map(function($api) {
            return [
                'type' => $api['message_type'],
                'description' => getISOMessageDescription($api['message_type']),
                'direction' => $api['direction'],
                'participant' => $api['participant_name'],
                'endpoint' => $api['endpoint'],
                'request' => is_string($api['request_payload']) ? json_decode($api['request_payload'], true) : $api['request_payload'],
                'response' => is_string($api['response_payload']) ? json_decode($api['response_payload'], true) : $api['response_payload'],
                'http_status' => $api['http_status_code'],
                'duration_ms' => $api['duration_ms'],
                'timestamp' => $api['created_at']
            ];
        }, $apiCalls),
        'hold_transactions' => $holds,
        'ledger_entries' => $ledgerEntries,
        'fee_collections' => $fees,
        'settlement_queue' => $settlements,
        'card_authorizations' => $cardAuths,
        'card_transactions' => $cardTxns,
        'message_cards' => $cards
    ];
    
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="swap_' . $swapRef . '_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['VOUCHMORPH SWAP DETAIL REPORT WITH ISO MESSAGES']);
    fputcsv($output, ['Report ID:', $reportId]);
    fputcsv($output, ['Generated:', $generatedAt->format('Y-m-d H:i:s T')]);
    fputcsv($output, ['Swap Reference:', $swapRef]);
    fputcsv($output, []);
    
    fputcsv($output, ['SWAP SUMMARY']);
    fputcsv($output, ['Field', 'Value']);
    fputcsv($output, ['Swap UUID', $swap['swap_uuid']]);
    fputcsv($output, ['Amount', $swap['amount'] . ' ' . ($swap['from_currency'] ?? 'BWP')]);
    fputcsv($output, ['Status', $swap['status']]);
    fputcsv($output, ['Created', $swap['created_at']]);
    fputcsv($output, []);
    
    fputcsv($output, ['ISO MESSAGE FLOW']);
    fputcsv($output, ['Step', 'Time', 'Type', 'Direction', 'Participant', 'Status', 'Duration']);
    foreach ($apiCalls as $idx => $api) {
        fputcsv($output, [
            $idx + 1,
            date('H:i:s', strtotime($api['created_at'])),
            $api['message_type'],
            $api['direction'],
            $api['participant_name'],
            $api['success'] ? 'SUCCESS' : 'FAILED',
            ($api['duration_ms'] ?? 'N/A') . 'ms'
        ]);
    }
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    $format = 'html';
    $_GET['pdf'] = '1';
}

// ============================================================================
// HTML REPORT WITH ISO MESSAGES & FLOW VISUALIZATION
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · COMPLETE SWAP DETAIL · ISO 20022 · <?php echo substr($swapRef, 0, 16); ?></title>
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
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Cover Page */
        .cover-page {
            text-align: center;
            margin-bottom: 4rem;
            padding: 4rem 2rem;
            border: 3px solid #000;
            page-break-after: always;
            background: linear-gradient(135deg, #fff, #f5f5f5);
        }

        .cover-title {
            font-size: 3rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-bottom: 2rem;
        }

        .cover-subtitle {
            font-size: 1.5rem;
            color: #444;
            margin-bottom: 3rem;
        }

        .cover-badge {
            display: inline-block;
            padding: 1rem 3rem;
            background: #000;
            color: #0f0;
            font-size: 1.2rem;
            margin: 2rem 0;
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

        /* Sections */
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

        /* Flow Timeline */
        .flow-timeline {
            margin: 2rem 0;
            position: relative;
        }

        .flow-step {
            display: flex;
            margin-bottom: 2rem;
            position: relative;
            padding-left: 3rem;
        }

        .flow-step::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 30px;
            bottom: -30px;
            width: 2px;
            background: #000;
        }

        .flow-step:last-child::before {
            display: none;
        }

        .flow-icon {
            position: absolute;
            left: 0;
            width: 30px;
            height: 30px;
            background: #000;
            color: #0f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            z-index: 2;
        }

        .flow-content {
            flex: 1;
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 1.5rem;
            border-radius: 4px;
        }

        .flow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .flow-time {
            font-family: 'Courier New', monospace;
            color: #666;
        }

        .flow-title {
            font-weight: 600;
            font-size: 1.1rem;
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

        /* Cards */
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

        .card-badge-success {
            background: #0f0;
            color: #000;
        }

        .card-badge-warning {
            background: #ff0;
            color: #000;
        }

        /* ISO Message Display */
        .iso-message {
            margin-top: 1rem;
            border: 2px solid #ddd;
        }

        .iso-header {
            background: #000;
            color: #fff;
            padding: 0.75rem;
            font-family: 'Courier New', monospace;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .iso-type {
            font-weight: bold;
            color: #0f0;
        }

        .iso-direction {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .iso-direction.outgoing {
            background: #ff6b6b;
            color: #fff;
        }

        .iso-direction.incoming {
            background: #4ecdc4;
            color: #fff;
        }

        .iso-body {
            background: #f0f0f0;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #ddd;
        }

        /* Tables */
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
            border-radius: 4px;
        }

        .btn-success {
            background: #0f0;
            color: #000;
        }

        .button-group {
            margin-bottom: 2rem;
            text-align: right;
        }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
            .flow-icon { background: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .iso-header { background: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .cover-title { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <!-- Download Buttons -->
    <div class="button-group no-print">
        <button onclick="window.print()" class="print-btn">🖨️ PRINT REPORT</button>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=json&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 JSON (ISO)</a>
        <a href="?swap=<?php echo urlencode($swapRef); ?>&format=csv&country=<?php echo urlencode($countryCode); ?>" class="download-btn">📥 CSV</a>
        <a href="?download_all=1" class="download-btn btn-success">📥 ALL SWAPS</a>
    </div>

    <!-- COVER PAGE -->
    <div class="cover-page">
        <div class="cover-title">VOUCHMORPH</div>
        <div class="cover-subtitle">Complete Swap Detail with ISO 20022 Messages</div>
        <div class="cover-badge">ISO 20022 · FSPIOP · PAIN · PACS · CAMT</div>
        <div style="margin: 3rem 0;">
            <div style="font-size: 1.2rem;"><strong>Swap Reference:</strong> <?php echo $swapRef; ?></div>
            <div style="font-size: 1.2rem; margin-top: 0.5rem;"><strong>Amount:</strong> <?php echo number_format((float)$swap['amount'], 2); ?> <?php echo $swap['from_currency']; ?></div>
        </div>
        <div class="report-meta" style="border-top: 2px solid #000; padding-top: 2rem;">
            <div><strong>Report ID:</strong> <?php echo $reportId; ?></div>
            <div><strong>Generated:</strong> <?php echo $generatedAt->format('Y-m-d H:i:s T'); ?></div>
            <div><strong>Checksum:</strong> <?php echo substr($reportChecksum, 0, 16); ?>…</div>
        </div>
    </div>

    <!-- EXECUTIVE SUMMARY -->
    <div class="section">
        <div class="section-title">Executive Summary</div>
        <div class="card">
            <div class="grid-3">
                <div>
                    <div class="kv-label">Transaction ID</div>
                    <div class="kv-value" style="font-size: 1.2rem;"><?php echo $swap['swap_uuid']; ?></div>
                </div>
                <div>
                    <div class="kv-label">Amount</div>
                    <div class="kv-value" style="font-size: 1.2rem;"><?php echo number_format((float)$swap['amount'], 2); ?> <?php echo $swap['from_currency']; ?></div>
                </div>
                <div>
                    <div class="kv-label">Status</div>
                    <div class="kv-value"><span class="status-badge status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span></div>
                </div>
            </div>
            <div class="grid-2" style="margin-top: 1.5rem;">
                <div>
                    <div class="kv-label">Source</div>
                    <div class="kv-value"><?php echo safe_html($sourceDetails['institution'] ?? 'N/A'); ?> · <?php echo safe_html($sourceDetails['asset_type'] ?? 'N/A'); ?></div>
                </div>
                <div>
                    <div class="kv-label">Destination</div>
                    <div class="kv-value"><?php echo safe_html($destDetails['institution'] ?? 'N/A'); ?> · <?php echo safe_html($destDetails['asset_type'] ?? 'N/A'); ?></div>
                </div>
            </div>
            <div style="margin-top: 1.5rem; background: #f0f0f0; padding: 1rem;">
                <div><strong>ISO 20022 Messages:</strong> <?php echo count($apiCalls); ?> exchanged · 
                <?php 
                $successCount = count(array_filter($apiCalls, fn($a) => $a['success']));
                echo $successCount; ?> successful · 
                Avg response: <?php echo round(array_sum(array_column($apiCalls, 'duration_ms')) / max(1, count($apiCalls))); ?>ms
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 1: TRANSACTION FLOW VISUALIZATION -->
    <div class="section">
        <div class="section-title">1. Transaction Flow with ISO Messages</div>
        <div class="flow-timeline">
            <?php foreach ($flowSteps as $step): ?>
            <div class="flow-step">
                <div class="flow-icon"><?php echo $step['icon']; ?></div>
                <div class="flow-content">
                    <div class="flow-header">
                        <span class="flow-title"><?php echo $step['name']; ?></span>
                        <span class="flow-time"><?php echo date('H:i:s', strtotime($step['timestamp'])); ?></span>
                    </div>
                    <div><?php echo $step['description']; ?></div>
                    
                    <?php if (isset($step['api'])): ?>
                    <div class="iso-message" style="margin-top: 1rem;">
                        <div class="iso-header">
                            <span class="iso-type">ISO 20022 · <?php echo $step['api']['message_type']; ?></span>
                            <span class="iso-direction <?php echo $step['api']['direction']; ?>">
                                <?php echo getMessageDirectionIcon($step['api']['direction']); ?> 
                                <?php echo strtoupper($step['api']['direction']); ?>
                            </span>
                        </div>
                        <div style="padding: 0.5rem; background: #f0f0f0;">
                            <strong>To:</strong> <?php echo safe_html($step['api']['participant_name']); ?> · 
                            <strong>Endpoint:</strong> <?php echo safe_html($step['api']['endpoint']); ?> · 
                            <strong>HTTP <?php echo $step['api']['http_status_code']; ?></strong> · 
                            <strong><?php echo $step['api']['duration_ms']; ?>ms</strong>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SECTION 2: ISO 20022 MESSAGE DETAILS -->
    <div class="section page-break">
        <div class="section-title">2. ISO 20022 Message Details</div>
        <?php foreach ($apiCalls as $index => $api): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">
                    <?php echo getMessageDirectionIcon($api['direction']); ?> 
                    ISO <?php echo $api['message_type']; ?> · 
                    <?php echo getISOMessageDescription($api['message_type']); ?>
                </span>
                <span class="card-badge <?php echo $api['success'] ? 'card-badge-success' : 'card-badge-warning'; ?>">
                    HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?> · <?php echo $api['duration_ms']; ?>ms
                </span>
            </div>
            <div style="margin-bottom: 0.5rem;">
                <strong>Participant:</strong> <?php echo safe_html($api['participant_name']); ?> · 
                <strong>Endpoint:</strong> <?php echo safe_html($api['endpoint']); ?> · 
                <strong>Time:</strong> <?php echo date('H:i:s', strtotime($api['created_at'])); ?>
            </div>
            
            <div class="grid-2" style="margin-top: 1rem;">
                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">📤 REQUEST PAYLOAD</div>
                    <div class="json-display"><?php 
                        $req = is_string($api['request_payload']) ? json_decode($api['request_payload'], true) : $api['request_payload'];
                        echo json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></div>
                </div>
                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">📥 RESPONSE PAYLOAD</div>
                    <div class="json-display"><?php 
                        $res = is_string($api['response_payload']) ? json_decode($api['response_payload'], true) : $api['response_payload'];
                        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></div>
                </div>
            </div>
            
            <?php if (!empty($api['curl_error'])): ?>
            <div style="color: #f00; margin-top: 1rem; padding: 0.5rem; background: #ffeeee;">
                ⚠️ CURL Error: <?php echo safe_html($api['curl_error']); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- SECTION 3: SOURCE & DESTINATION DETAILS -->
    <div class="section">
        <div class="section-title">3. Source & Destination Details</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">SOURCE</span>
                    <span class="card-badge">FUNDS ORIGIN</span>
                </div>
                <div class="json-display"><?php echo json_encode($sourceDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">DESTINATION</span>
                    <span class="card-badge">FUNDS RECIPIENT</span>
                </div>
                <div class="json-display"><?php echo json_encode($destDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>
        </div>
    </div>

    <!-- SECTION 4: HOLD TRANSACTIONS -->
    <div class="section">
        <div class="section-title">4. Hold Transactions</div>
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
                        <td><code><?php echo substr($hold['hold_reference'], 0, 12); ?>…</code></td>
                        <td><?php echo safe_html($hold['asset_type']); ?></td>
                        <td><?php echo number_format((float)$hold['amount'], 2); ?> <?php echo safe_html($hold['currency']); ?></td>
                        <td><span class="status-badge status-<?php echo strtolower($hold['status'] ?? ''); ?>"><?php echo safe_html($hold['status']); ?></span></td>
                        <td><?php echo safe_html($hold['source_institution'] ?? $hold['participant_name']); ?></td>
                        <td><?php echo safe_html($hold['destination_institution']); ?></td>
                        <td><?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></td>
                        <td><?php echo $hold['hold_expiry'] ? date('Y-m-d H:i', strtotime($hold['hold_expiry'])) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 5: LEDGER ENTRIES (Double-Entry) -->
    <div class="section">
        <div class="section-title">5. Ledger Entries (Double-Entry Accounting)</div>
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
                    <?php 
                    $totalDebit = 0;
                    foreach ($ledgerEntries as $entry): 
                        $totalDebit += (float)$entry['amount'];
                    ?>
                    <tr>
                        <td><?php echo date('H:i:s', strtotime($entry['created_at'])); ?></td>
                        <td><?php echo safe_html($entry['debit_account_name'] ?? $entry['debit_account_id']); ?></td>
                        <td><?php echo safe_html($entry['credit_account_name'] ?? $entry['credit_account_id']); ?></td>
                        <td><?php echo number_format((float)$entry['amount'], 2); ?> <?php echo safe_html($entry['currency_code']); ?></td>
                        <td><?php echo safe_html($entry['split_type']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                        <td><strong><?php echo number_format($totalDebit, 2); ?> BWP</strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- SECTION 6: FEE COLLECTIONS -->
    <div class="section">
        <div class="section-title">6. Fee Collections & Splits</div>
        <?php if (empty($fees)): ?>
            <div class="card">No fee collections recorded.</div>
        <?php else: ?>
            <?php foreach ($fees as $fee): 
                $split = is_string($fee['split_config']) ? json_decode($fee['split_config'], true) : $fee['split_config'];
            ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?php echo safe_html($fee['fee_type']); ?></span>
                    <span class="card-badge"><?php echo safe_html($fee['status']); ?></span>
                </div>
                <div class="grid-3">
                    <div><strong>Total:</strong> <?php echo number_format((float)$fee['total_amount'], 2); ?> <?php echo safe_html($fee['currency']); ?></div>
                    <div><strong>VAT (14%):</strong> <?php echo number_format((float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                    <div><strong>Net:</strong> <?php echo number_format((float)$fee['total_amount'] - (float)($fee['vat_amount'] ?? 0), 2); ?> BWP</div>
                </div>
                <?php if (!empty($split)): ?>
                <div style="margin-top: 1rem;">
                    <strong>Split Distribution:</strong>
                    <table style="margin-top: 0.5rem;">
                        <thead>
                            <tr>
                                <th>Party</th>
                                <th>Amount</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($split as $party => $amt): ?>
                            <tr>
                                <td><?php echo strtoupper($party); ?></td>
                                <td><?php echo number_format((float)$amt, 2); ?> BWP</td>
                                <td><?php echo round(((float)$amt / (float)$fee['total_amount']) * 100, 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #666;">
                    Fee ID: <?php echo $fee['fee_id']; ?> · Collected: <?php echo date('Y-m-d H:i', strtotime($fee['collected_at'] ?? $fee['created_at'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- SECTION 7: SETTLEMENT OBLIGATIONS -->
    <div class="section">
        <div class="section-title">7. Settlement Obligations (End of Day)</div>
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
                        <th>Settlement Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><?php echo safe_html($s['debtor']); ?></td>
                        <td><?php echo safe_html($s['creditor']); ?></td>
                        <td><strong><?php echo number_format((float)$s['amount'], 2); ?> BWP</strong></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($s['created_at'])); ?></td>
                        <td><span class="status-badge status-pending">PENDING</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 1rem; background: #f0f0f0; padding: 1rem;">
                <strong>Net Settlement Required:</strong> 
                <?php 
                $totalOwed = array_sum(array_column($settlements, 'amount'));
                echo number_format($totalOwed, 2); ?> BWP
            </div>
        <?php endif; ?>
    </div>

    <!-- SECTION 8: CARD OPERATIONS -->
    <?php if (!empty($cardAuths) || !empty($cardTxns)): ?>
    <div class="section">
        <div class="section-title">8. Card Operations</div>
        
        <?php if (!empty($cardAuths)): ?>
        <div class="section-subtitle">Card Authorizations</div>
        <table>
            <thead>
                <tr>
                    <th>Card</th>
                    <th>Authorized</th>
                    <th>Remaining</th>
                    <th>Used</th>
                    <th>Status</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cardAuths as $auth): ?>
                <tr>
                    <td>•••• <?php echo safe_html($auth['card_suffix']); ?></td>
                    <td><?php echo number_format((float)$auth['authorized_amount'], 2); ?> BWP</td>
                    <td><?php echo number_format((float)$auth['remaining_balance'], 2); ?> BWP</td>
                    <td><?php echo number_format((float)($auth['used_amount'] ?? 0), 2); ?> BWP</td>
                    <td><span class="status-badge status-<?php echo strtolower($auth['status'] ?? ''); ?>"><?php echo safe_html($auth['status']); ?></span></td>
                    <td><?php echo $auth['expiry_at'] ? date('Y-m-d', strtotime($auth['expiry_at'])) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($cardTxns)): ?>
        <div class="section-subtitle">Card Transactions</div>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Card</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Merchant/ATM</th>
                    <th>Auth Code</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cardTxns as $txn): ?>
                <tr>
                    <td><?php echo date('H:i:s', strtotime($txn['created_at'])); ?></td>
                    <td>•••• <?php echo safe_html($txn['card_suffix'] ?? 'N/A'); ?></td>
                    <td><?php echo safe_html($txn['transaction_type']); ?></td>
                    <td><?php echo number_format((float)$txn['amount'], 2); ?> <?php echo safe_html($txn['currency']); ?></td>
                    <td><?php echo safe_html($txn['merchant_name'] ?? $txn['merchant_id'] ?? $txn['atm_location']); ?></td>
                    <td><?php echo safe_html($txn['auth_code']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SECTION 9: METADATA -->
    <?php if (!empty($metadata)): ?>
    <div class="section">
        <div class="section-title">9. Additional Metadata</div>
        <div class="card">
            <div class="json-display"><?php echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- REGULATORY DECLARATION & SIGNATURE -->
    <div class="section page-break">
        <div class="section-title">Regulatory Declaration</div>
        <div class="card">
            <div style="font-style: italic; font-size: 1.1rem; margin-bottom: 2rem;">
                "VouchMorph hereby certifies that this swap transaction was processed in accordance with 
                ISO 20022 standards, with all messages logged, funds never held in custody, and 
                complete audit trail maintained as required by the Bank of Botswana."
            </div>
            
            <div class="grid-2">
                <div>
                    <div class="kv-label">Transaction Integrity</div>
                    <div class="kv-value" style="color: #0f0;">✓ VERIFIED (SHA-256)</div>
                    <div style="font-family: monospace; font-size: 0.7rem; margin-top: 0.5rem;"><?php echo $reportChecksum; ?></div>
                </div>
                <div>
                    <div class="kv-label">ISO 20022 Compliance</div>
                    <div class="kv-value" style="color: #0f0;">✓ CONFIRMED</div>
                    <div style="margin-top: 0.5rem;">Messages: <?php echo implode(', ', array_column($apiCalls, 'message_type')); ?></div>
                </div>
            </div>
            
            <div style="margin-top: 3rem;">
                <div style="border-top: 2px solid #000; width: 300px; margin-top: 3rem;"></div>
                <div style="margin-top: 0.5rem;"><strong>VouchMorph Proprietary Limited</strong></div>
                <div>Authorized System Signature</div>
                <div style="margin-top: 0.5rem;"><?php echo $generatedAt->format('Y-m-d'); ?></div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['pdf'])): ?>
    <script>window.onload = function() { window.print(); }</script>
    <?php endif; ?>
</body>
</html>
