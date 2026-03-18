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

function sanitize_filename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
}

// ============================================================================
// HANDLE DOWNLOAD REQUESTS
// ============================================================================

// Download single swap details
if (isset($_GET['download_swap']) && !empty($_GET['swap'])) {
    $downloadSwap = $_GET['swap'];
    
    // Get swap details with all related data
    $swapQuery = $db->prepare("
        SELECT 
            s.*,
            s.source_details,
            s.destination_details,
            COUNT(DISTINCT h.hold_id) as hold_count,
            COUNT(DISTINCT a.log_id) as api_count,
            SUM(f.total_amount) as total_fees
        FROM swap_requests s
        LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference
        LEFT JOIN api_message_logs a ON s.swap_uuid = a.message_id
        LEFT JOIN swap_fee_collections f ON s.swap_uuid = f.swap_reference
        WHERE s.swap_uuid = ?
        GROUP BY s.swap_id
    ");
    $swapQuery->execute([$downloadSwap]);
    $swap = $swapQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($swap) {
        // Get all related data
        $holdQuery = $db->prepare("SELECT * FROM hold_transactions WHERE swap_reference = ? ORDER BY created_at ASC");
        $holdQuery->execute([$downloadSwap]);
        $holds = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $apiQuery = $db->prepare("SELECT * FROM api_message_logs WHERE message_id = ? ORDER BY created_at ASC");
        $apiQuery->execute([$downloadSwap]);
        $apis = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $feeQuery = $db->prepare("SELECT * FROM swap_fee_collections WHERE swap_reference = ? ORDER BY created_at ASC");
        $feeQuery->execute([$downloadSwap]);
        $fees = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $ledgerQuery = $db->prepare("SELECT * FROM ledger_entries WHERE reference = ? ORDER BY created_at ASC");
        $ledgerQuery->execute([$downloadSwap]);
        $ledgers = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $settlementQuery = $db->prepare("
            SELECT * FROM settlement_queue 
            WHERE debtor IN (SELECT source_institution FROM hold_transactions WHERE swap_reference = ?)
               OR creditor IN (SELECT source_institution FROM hold_transactions WHERE swap_reference = ?)
        ");
        $settlementQuery->execute([$downloadSwap, $downloadSwap]);
        $settlements = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate filename
        $filename = 'swap_' . substr($downloadSwap, 0, 8) . '_' . date('Ymd_His');
        
        // Determine format
        $format = $_GET['format'] ?? 'csv';
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Header
            fputcsv($output, ['VOUCHMORPH SWAP DETAIL REPORT']);
            fputcsv($output, ['Generated:', date('Y-m-d H:i:s T')]);
            fputcsv($output, ['Swap UUID:', $swap['swap_uuid']]);
            fputcsv($output, ['Amount:', $swap['amount'] . ' ' . ($swap['from_currency'] ?? 'BWP')]);
            fputcsv($output, ['Status:', $swap['status']]);
            fputcsv($output, ['Created:', $swap['created_at']]);
            fputcsv($output, []);
            
            // Source Details
            fputcsv($output, ['SOURCE DETAILS']);
            $source = is_string($swap['source_details']) ? json_decode($swap['source_details'], true) : $swap['source_details'];
            if (is_array($source)) {
                foreach ($source as $key => $value) {
                    fputcsv($output, [$key, is_array($value) ? json_encode($value) : $value]);
                }
            }
            fputcsv($output, []);
            
            // Destination Details
            fputcsv($output, ['DESTINATION DETAILS']);
            $dest = is_string($swap['destination_details']) ? json_decode($swap['destination_details'], true) : $swap['destination_details'];
            if (is_array($dest)) {
                foreach ($dest as $key => $value) {
                    fputcsv($output, [$key, is_array($value) ? json_encode($value) : $value]);
                }
            }
            fputcsv($output, []);
            
            // Hold Transactions
            fputcsv($output, ['HOLD TRANSACTIONS']);
            if (!empty($holds)) {
                fputcsv($output, array_keys($holds[0]));
                foreach ($holds as $hold) {
                    $row = [];
                    foreach ($holds[0] as $key => $value) {
                        $row[] = $hold[$key] ?? '';
                    }
                    fputcsv($output, $row);
                }
            }
            fputcsv($output, []);
            
            // API Messages
            fputcsv($output, ['API MESSAGES']);
            if (!empty($apis)) {
                fputcsv($output, ['Time', 'Type', 'Direction', 'Participant', 'Status', 'Duration', 'HTTP Code']);
                foreach ($apis as $api) {
                    fputcsv($output, [
                        date('H:i:s', strtotime($api['created_at'])),
                        $api['message_type'],
                        $api['direction'],
                        $api['participant_name'],
                        $api['success'] ? 'SUCCESS' : 'FAILED',
                        ($api['duration_ms'] ?? 'N/A') . 'ms',
                        $api['http_status_code'] ?? 'N/A'
                    ]);
                }
            }
            fputcsv($output, []);
            
            // Fee Collections
            fputcsv($output, ['FEE COLLECTIONS']);
            if (!empty($fees)) {
                fputcsv($output, ['Type', 'Total', 'Currency', 'VAT', 'Status', 'Split']);
                foreach ($fees as $fee) {
                    fputcsv($output, [
                        $fee['fee_type'],
                        $fee['total_amount'],
                        $fee['currency'] ?? 'BWP',
                        $fee['vat_amount'] ?? 0,
                        $fee['status'],
                        is_string($fee['split_config']) ? $fee['split_config'] : json_encode($fee['split_config'])
                    ]);
                }
            }
            
            fclose($output);
            exit;
        }
        
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            
            $report = [
                'metadata' => [
                    'generated_at' => date('c'),
                    'swap_uuid' => $swap['swap_uuid'],
                    'report_type' => 'COMPLETE_SWAP_DETAIL'
                ],
                'swap' => $swap,
                'source_details' => $source,
                'destination_details' => $dest,
                'holds' => $holds,
                'api_messages' => $apis,
                'fees' => $fees,
                'ledger_entries' => $ledgers,
                'settlements' => $settlements
            ];
            
            echo json_encode($report, JSON_PRETTY_PRINT);
            exit;
        }
    }
}

// Download full system report
if (isset($_GET['download_report'])) {
    $reportType = $_GET['download_report']; // csv or json
    
    // Get comprehensive system data
    $swapsQuery = $db->query("
        SELECT 
            s.swap_uuid,
            s.amount,
            s.from_currency,
            s.to_currency,
            s.status,
            s.created_at,
            s.source_details->>'institution' as source_inst,
            s.destination_details->>'institution' as dest_inst,
            s.source_details->>'asset_type' as source_type,
            s.destination_details->>'asset_type' as dest_type,
            COUNT(DISTINCT h.hold_id) as holds,
            COUNT(DISTINCT a.log_id) as api_calls,
            COALESCE(SUM(f.total_amount), 0) as total_fees
        FROM swap_requests s
        LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference
        LEFT JOIN api_message_logs a ON s.swap_uuid = a.message_id
        LEFT JOIN swap_fee_collections f ON s.swap_uuid = f.swap_reference
        GROUP BY s.swap_uuid
        ORDER BY s.created_at DESC
    ");
    $allSwaps = $swapsQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Participants data
    $participantsQuery = $db->query("
        SELECT 
            p.name,
            p.type,
            p.category,
            p.status,
            p.provider_code,
            COUNT(DISTINCT h.hold_id) as holds,
            COUNT(DISTINCT a.log_id) as messages,
            COALESCE(SUM(h.amount), 0) as total_hold_value
        FROM participants p
        LEFT JOIN hold_transactions h ON p.name = h.source_institution OR p.name = h.destination_institution
        LEFT JOIN api_message_logs a ON p.name = a.participant_name
        WHERE p.status = 'ACTIVE'
        GROUP BY p.participant_id
    ");
    $participants = $participantsQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Settlement data
    $settlementQuery = $db->query("
        SELECT 
            debtor,
            creditor,
            SUM(amount) as total,
            COUNT(*) as count
        FROM settlement_queue
        GROUP BY debtor, creditor
        ORDER BY total DESC
    ");
    $settlements = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Fee summary
    $feeSummaryQuery = $db->query("
        SELECT 
            fee_type,
            COUNT(*) as count,
            SUM(total_amount) as total,
            SUM(vat_amount) as total_vat
        FROM swap_fee_collections
        GROUP BY fee_type
    ");
    $feeSummary = $feeSummaryQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Success rate
    $successQuery = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM swap_requests
    ");
    $success = $successQuery->fetch(PDO::FETCH_ASSOC);
    $successRate = $success['total'] > 0 ? round(($success['completed'] / $success['total']) * 100, 2) : 0;
    
    $filename = 'vouchmorph_full_report_' . date('Ymd_His');
    
    if ($reportType === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // System Overview
        fputcsv($output, ['VOUCHMORPH COMPLETE SYSTEM REPORT']);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s T')]);
        fputcsv($output, ['Total Swaps:', count($allSwaps)]);
        fputcsv($output, ['Active Participants:', count($participants)]);
        fputcsv($output, ['Success Rate:', $successRate . '%']);
        fputcsv($output, []);
        
        // All Swaps
        fputcsv($output, ['ALL SWAPS (' . count($allSwaps) . ' transactions)']);
        fputcsv($output, ['Swap UUID', 'Amount', 'Currency', 'From', 'To', 'From Type', 'To Type', 'Status', 'Date', 'Holds', 'API Calls', 'Fees']);
        foreach ($allSwaps as $swap) {
            fputcsv($output, [
                $swap['swap_uuid'],
                $swap['amount'],
                $swap['from_currency'],
                $swap['source_inst'] ?? 'N/A',
                $swap['dest_inst'] ?? 'N/A',
                $swap['source_type'] ?? 'N/A',
                $swap['dest_type'] ?? 'N/A',
                $swap['status'],
                $swap['created_at'],
                $swap['holds'] ?? 0,
                $swap['api_calls'] ?? 0,
                $swap['total_fees'] ?? 0
            ]);
        }
        fputcsv($output, []);
        
        // Participants
        fputcsv($output, ['PARTICIPANTS (' . count($participants) . ' active)']);
        fputcsv($output, ['Name', 'Type', 'Category', 'Provider Code', 'Status', 'Holds', 'Messages', 'Hold Value']);
        foreach ($participants as $p) {
            fputcsv($output, [
                $p['name'] ?? 'N/A',
                $p['type'] ?? 'N/A',
                $p['category'] ?? 'N/A',
                $p['provider_code'] ?? 'N/A',
                $p['status'] ?? 'N/A',
                $p['holds'] ?? 0,
                $p['messages'] ?? 0,
                $p['total_hold_value'] ?? 0
            ]);
        }
        fputcsv($output, []);
        
        // Settlements
        fputcsv($output, ['SETTLEMENT OBLIGATIONS']);
        fputcsv($output, ['Debtor', 'Creditor', 'Total Amount', 'Transaction Count']);
        foreach ($settlements as $s) {
            fputcsv($output, [
                $s['debtor'] ?? 'N/A',
                $s['creditor'] ?? 'N/A',
                $s['total'] ?? 0,
                $s['count'] ?? 0
            ]);
        }
        fputcsv($output, []);
        
        // Fee Summary
        fputcsv($output, ['FEE SUMMARY']);
        fputcsv($output, ['Fee Type', 'Count', 'Total', 'VAT']);
        foreach ($feeSummary as $fee) {
            fputcsv($output, [
                $fee['fee_type'] ?? 'N/A',
                $fee['count'] ?? 0,
                $fee['total'] ?? 0,
                $fee['total_vat'] ?? 0
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    if ($reportType === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        
        $report = [
            'metadata' => [
                'generated_at' => date('c'),
                'report_type' => 'COMPLETE_SYSTEM_REPORT',
                'version' => '2.0'
            ],
            'summary' => [
                'total_swaps' => count($allSwaps),
                'active_participants' => count($participants),
                'total_settlements' => count($settlements),
                'success_rate' => $successRate,
                'completed' => $success['completed'] ?? 0,
                'failed' => $success['failed'] ?? 0,
                'pending' => $success['pending'] ?? 0
            ],
            'swaps' => $allSwaps,
            'participants' => $participants,
            'settlements' => $settlements,
            'fees' => $feeSummary
        ];
        
        echo json_encode($report, JSON_PRETTY_PRINT);
        exit;
    }
}

// ============================================================================
// FETCH DASHBOARD DATA
// ============================================================================

$selectedSwap = $_GET['swap'] ?? null;
$timeframe = $_GET['timeframe'] ?? 'today';
$view = $_GET['clearing_view'] ?? 'overview';

// Date range
$dateRange = match($timeframe) {
    'today' => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')],
    'week' => ['start' => date('Y-m-d 00:00:00', strtotime('-7 days')), 'end' => date('Y-m-d 23:59:59')],
    'month' => ['start' => date('Y-m-d 00:00:00', strtotime('-30 days')), 'end' => date('Y-m-d 23:59:59')],
    default => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')]
};

// 1. Global System Status
$tpsQuery = $db->prepare("
    SELECT COUNT(*) as tx_count,
           EXTRACT(EPOCH FROM (MAX(created_at) - MIN(created_at))) as duration_seconds
    FROM swap_requests
    WHERE created_at BETWEEN ? AND ?
");
$tpsQuery->execute([$dateRange['start'], $dateRange['end']]);
$tpsData = $tpsQuery->fetch(PDO::FETCH_ASSOC);
$tps = $tpsData && $tpsData['duration_seconds'] > 0 
    ? round($tpsData['tx_count'] / $tpsData['duration_seconds'], 2) 
    : 0;

$liquidityQuery = $db->query("SELECT SUM(balance) as total_liquidity FROM ledger_accounts WHERE account_type IN ('escrow', 'settlement')");
$liquidity = $liquidityQuery->fetchColumn() ?: 0;

$instQuery = $db->query("SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE'");
$activeInstitutions = $instQuery->fetchColumn();

$pendingQuery = $db->query("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
$pendingMessages = $pendingQuery->fetchColumn() ?: 0;

// 2. Live Swap Stream
$liveSwapsQuery = $db->query("
    SELECT 
        sr.swap_uuid,
        sr.amount,
        sr.status,
        sr.created_at,
        sr.source_details->>'institution' as source_institution,
        sr.source_details->>'asset_type' as source_type,
        sr.destination_details->>'institution' as dest_institution,
        sr.destination_details->>'asset_type' as dest_type,
        COALESCE(ht.status, 'NO_HOLD') as hold_status
    FROM swap_requests sr
    LEFT JOIN hold_transactions ht ON sr.swap_uuid = ht.swap_reference
    ORDER BY sr.created_at DESC
    LIMIT 20
");
$liveSwaps = $liveSwapsQuery->fetchAll(PDO::FETCH_ASSOC);

// 3. Detailed Swap View
$swapDetails = null;
$messageFlow = [];
$ledgerEntries = [];
$feeDetails = [];
$apiCalls = [];

if ($selectedSwap) {
    $swapDetailQuery = $db->prepare("SELECT * FROM swap_requests WHERE swap_uuid = ?");
    $swapDetailQuery->execute([$selectedSwap]);
    $swapDetails = $swapDetailQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($swapDetails) {
        $holdQuery = $db->prepare("SELECT * FROM hold_transactions WHERE swap_reference = ?");
        $holdQuery->execute([$selectedSwap]);
        $messageFlow['hold'] = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $apiQuery = $db->prepare("SELECT * FROM api_message_logs WHERE message_id = ? ORDER BY created_at ASC");
        $apiQuery->execute([$selectedSwap]);
        $apiCalls = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $ledgerQuery = $db->prepare("
            SELECT le.*, 
                   la_debit.account_name as debit_account_name,
                   la_credit.account_name as credit_account_name
            FROM ledger_entries le
            LEFT JOIN ledger_accounts la_debit ON le.debit_account_id = la_debit.account_id
            LEFT JOIN ledger_accounts la_credit ON le.credit_account_id = la_credit.account_id
            WHERE le.reference = ?
        ");
        $ledgerQuery->execute([$selectedSwap]);
        $ledgerEntries = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $feeQuery = $db->prepare("SELECT * FROM swap_fee_collections WHERE swap_reference = ?");
        $feeQuery->execute([$selectedSwap]);
        $feeDetails = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $sourceInst = is_array($swapDetails['source_details']) 
            ? ($swapDetails['source_details']['institution'] ?? '') 
            : (json_decode($swapDetails['source_details'], true)['institution'] ?? '');
        
        $destInst = is_array($swapDetails['destination_details']) 
            ? ($swapDetails['destination_details']['institution'] ?? '') 
            : (json_decode($swapDetails['destination_details'], true)['institution'] ?? '');
        
        $settlementQuery = $db->prepare("SELECT * FROM settlement_queue WHERE debtor = ? OR creditor = ? ORDER BY created_at DESC LIMIT 1");
        $settlementQuery->execute([$sourceInst, $destInst]);
        $messageFlow['settlement'] = $settlementQuery->fetch(PDO::FETCH_ASSOC);
    }
}

// 4. Net Positions
$netPositionsQuery = $db->query("
    SELECT debtor, creditor, SUM(amount) as net_amount
    FROM settlement_queue
    GROUP BY debtor, creditor
    ORDER BY net_amount DESC
    LIMIT 10
");
$netPositions = $netPositionsQuery->fetchAll(PDO::FETCH_ASSOC);

$institutionNets = [];
foreach ($netPositions as $pos) {
    if (!isset($institutionNets[$pos['debtor']])) {
        $institutionNets[$pos['debtor']] = ['debit' => 0, 'credit' => 0];
    }
    if (!isset($institutionNets[$pos['creditor']])) {
        $institutionNets[$pos['creditor']] = ['debit' => 0, 'credit' => 0];
    }
    $institutionNets[$pos['debtor']]['debit'] += (float)$pos['net_amount'];
    $institutionNets[$pos['creditor']]['credit'] += (float)$pos['net_amount'];
}

// 5. Clearance Metrics
$clearanceQuery = $db->query("
    SELECT 
        participant_name,
        COUNT(*) as total_messages,
        SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful,
        AVG(duration_ms) as avg_response_time
    FROM api_message_logs
    WHERE created_at BETWEEN '{$dateRange['start']}' AND '{$dateRange['end']}'
    GROUP BY participant_name
    ORDER BY total_messages DESC
");
$clearanceMetrics = $clearanceQuery->fetchAll(PDO::FETCH_ASSOC);

// 6. Settlement Matrix
$institutions = array_keys($institutionNets);
$settlementMatrix = [];
foreach ($institutions as $debtor) {
    foreach ($institutions as $creditor) {
        if ($debtor !== $creditor) {
            $query = $db->prepare("SELECT SUM(amount) as amount FROM settlement_queue WHERE debtor = ? AND creditor = ?");
            $query->execute([$debtor, $creditor]);
            $amount = $query->fetchColumn();
            if ($amount > 0) {
                $settlementMatrix[] = [
                    'from' => $debtor,
                    'to' => $creditor,
                    'amount' => (float)$amount
                ];
            }
        }
    }
}

// 7. Success Rate
$successQuery = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM swap_requests
");
$successStats = $successQuery->fetch(PDO::FETCH_ASSOC);
$successRate = $successStats['total'] > 0 
    ? round(($successStats['completed'] / $successStats['total']) * 100, 2) 
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · MESSAGE CLEARING HOUSE · <?php echo $countryCode; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0a;
            font-family: 'Inter', -apple-system, sans-serif;
            color: #ffffff;
            line-height: 1.5;
            padding: 2rem;
        }

        .container {
            max-width: 1800px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }

        .logo span {
            color: #666;
            font-size: 0.8rem;
            margin-left: 1rem;
        }

        .status-badge {
            padding: 0.5rem 1.5rem;
            background: #111;
            border: 1px solid #0f0;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        /* Download Section */
        .download-section {
            margin: 2rem 0;
            padding: 2rem;
            background: #111;
            border: 2px solid #0f0;
            border-radius: 8px;
        }

        .download-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .download-title {
            color: #0f0;
            font-size: 1.2rem;
            font-weight: 400;
        }

        .download-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: 2px solid;
            color: #fff;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            transition: all 0.2s;
            display: inline-block;
        }

        .btn-green {
            background: #0f0;
            border-color: #0f0;
            color: #000;
        }

        .btn-green:hover {
            background: #0c0;
        }

        .btn-download {
            border-color: #0f0;
            color: #0f0;
        }

        .btn-download:hover {
            background: #0f0;
            color: #000;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .stat-box {
            background: #000;
            padding: 1.5rem;
            border: 1px solid #333;
            border-radius: 4px;
        }

        .stat-value {
            color: #0f0;
            font-size: 2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
        }

        .stat-label {
            color: #666;
            font-size: 0.8rem;
            text-transform: uppercase;
            margin-top: 0.5rem;
        }

        /* Timeframe Selector */
        .clearing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .clearing-title {
            font-size: 1.8rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }

        .timeframe-selector {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .timeframe-btn {
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1px solid #333;
            color: #888;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .timeframe-btn.active {
            background: #0f0;
            color: #000;
            border-color: #0f0;
        }

        /* Global Status Cards */
        .global-status {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .status-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            position: relative;
        }

        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #0f0, transparent);
        }

        .status-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .status-value {
            font-size: 2.2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            color: #0f0;
        }

        .status-unit {
            font-size: 0.8rem;
            color: #444;
            margin-left: 0.25rem;
        }

        /* Main Clearing Area */
        .clearing-main {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Swap Feed */
        .swap-feed {
            background: #111;
            border: 2px solid #222;
            height: 600px;
            overflow-y: auto;
        }

        .feed-header {
            padding: 1rem;
            border-bottom: 2px solid #222;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #888;
            position: sticky;
            top: 0;
            background: #111;
            z-index: 10;
        }

        .swap-item {
            position: relative;
            padding: 1rem;
            border-bottom: 1px solid #222;
            transition: all 0.2s;
        }

        .swap-item:hover {
            background: #1a1a1a;
        }

        .swap-item.selected {
            background: #1a1a1a;
            border-left: 3px solid #0f0;
        }

        .swap-path {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .swap-source {
            color: #ff6b6b;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .swap-arrow {
            color: #444;
        }

        .swap-dest {
            color: #4ecdc4;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .swap-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #666;
        }

        .swap-amount {
            color: #0f0;
        }

        .swap-status {
            padding: 0.2rem 0.5rem;
            border: 1px solid;
            font-size: 0.6rem;
            text-transform: uppercase;
        }

        .status-completed { border-color: #0f0; color: #0f0; }
        .status-pending { border-color: #ff0; color: #ff0; }
        .status-failed { border-color: #f00; color: #f00; }

        .swap-download {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #0f0;
            color: #000;
            padding: 2px 8px;
            text-decoration: none;
            font-size: 0.7rem;
            border-radius: 3px;
        }

        .swap-download:hover {
            background: #0c0;
        }

        /* Clearing Visualizer */
        .clearing-visualizer {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            overflow-x: auto;
        }

        .visualizer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .selected-swap-ref {
            color: #0f0;
            font-family: 'Courier New', monospace;
        }

        .replay-btn {
            padding: 0.5rem 1.5rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            cursor: pointer;
        }

        .replay-btn:hover {
            background: #0f0;
            color: #000;
        }

        /* Timeline */
        .message-timeline {
            margin: 2rem 0;
        }

        .timeline-step {
            display: flex;
            margin-bottom: 1.5rem;
            position: relative;
            flex-wrap: wrap;
        }

        .timeline-step::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 40px;
            bottom: -20px;
            width: 2px;
            background: #333;
        }

        .timeline-step:last-child::before {
            display: none;
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #000;
            border: 2px solid #0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.5rem;
            z-index: 2;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            overflow-x: auto;
        }

        .timeline-title {
            font-size: 1rem;
            text-transform: uppercase;
            color: #0f0;
            margin-bottom: 0.5rem;
        }

        .timeline-subtitle {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 1rem;
        }

        .timeline-details {
            background: #0a0a0a;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
        }

        .timeline-details pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #ccc;
        }

        .console-request { color: #ff6b6b; }
        .console-response { color: #4ecdc4; }
        .positive { color: #0f0; }

        /* Net Positions */
        .net-positions {
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
            flex-wrap: wrap;
            gap: 1rem;
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

        .positions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .position-card {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .position-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .position-institution {
            font-weight: 400;
            color: #fff;
        }

        .position-net {
            font-size: 1.2rem;
            font-family: 'Courier New', monospace;
        }

        .net-positive { color: #0f0; }
        .net-negative { color: #f00; }

        /* Tables */
        .clearance-monitor {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .clearance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
        }

        .clearance-table th {
            text-align: left;
            padding: 1rem;
            background: #000;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        .clearance-table td {
            padding: 1rem;
            border-bottom: 1px solid #222;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
        }

        .success-rate {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #1a3a1a;
            color: #0f0;
            border-radius: 3px;
        }

        /* Settlement Matrix */
        .settlement-matrix {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .matrix-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .matrix-edge {
            background: #000;
            border: 2px solid #222;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .edge-path {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .edge-from, .edge-to {
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .edge-from { color: #ff6b6b; }
        .edge-to { color: #4ecdc4; }
        .edge-amount { color: #0f0; }

        /* Footer */
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #444;
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .global-status { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .clearing-main { grid-template-columns: 1fr; }
            .swap-feed { height: 400px; }
        }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .global-status { grid-template-columns: 1fr; }
            .positions-grid { grid-template-columns: 1fr; }
            .matrix-grid { grid-template-columns: 1fr; }
        }

        @media print {
            body { background: white; color: black; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                VOUCHMORPH <span>MESSAGE CLEARING HOUSE</span>
            </div>
            <div class="status-badge">
                <?php echo $countryCode; ?> · REAL-TIME CLEARING
            </div>
        </div>

        <!-- DOWNLOAD SECTION -->
        <div class="download-section">
            <div class="download-header">
                <div class="download-title">📊 REGULATORY EVIDENCE PACKAGES</div>
                <div class="download-buttons">
                    <a href="?download_report=csv" class="btn btn-green">📥 DOWNLOAD FULL CSV</a>
                    <a href="?download_report=json" class="btn btn-green">📥 DOWNLOAD JSON</a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?php echo count($liveSwaps); ?></div>
                    <div class="stat-label">TOTAL SWAPS DISPLAYED</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $activeInstitutions; ?></div>
                    <div class="stat-label">ACTIVE PARTNERS</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo format_amount($liquidity / 1000000, 2); ?>M</div>
                    <div class="stat-label">TOTAL LIQUIDITY (BWP)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo $successRate; ?>%</div>
                    <div class="stat-label">SUCCESS RATE</div>
                </div>
            </div>
        </div>

        <!-- Clearing Header -->
        <div class="clearing-header">
            <div class="clearing-title">MESSAGE CLEARING SYSTEM</div>
            <div class="timeframe-selector">
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=today&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'today' ? 'active' : ''; ?>">TODAY</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=week&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'week' ? 'active' : ''; ?>">WEEK</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=month&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'month' ? 'active' : ''; ?>">MONTH</a>
            </div>
        </div>

        <!-- Global System Status -->
        <div class="global-status">
            <div class="status-card">
                <div class="status-label">TRANSACTIONS PER SECOND</div>
                <div class="status-value"><?php echo format_amount($tps, 2); ?><span class="status-unit">TPS</span></div>
            </div>
            <div class="status-card">
                <div class="status-label">TOTAL LIQUIDITY</div>
                <div class="status-value"><?php echo format_amount($liquidity / 1000000, 2); ?><span class="status-unit">M BWP</span></div>
            </div>
            <div class="status-card">
                <div class="status-label">ACTIVE INSTITUTIONS</div>
                <div class="status-value"><?php echo $activeInstitutions; ?><span class="status-unit">BANKS</span></div>
            </div>
            <div class="status-card">
                <div class="status-label">PENDING MESSAGES</div>
                <div class="status-value"><?php echo $pendingMessages; ?><span class="status-unit">IN QUEUE</span></div>
            </div>
        </div>

        <!-- Main Clearing Area -->
        <div class="clearing-main">
            <!-- LEFT: LIVE SWAP FEED -->
            <div class="swap-feed">
                <div class="feed-header">LIVE SWAP STREAM · REAL-TIME</div>
                <?php foreach ($liveSwaps as $swap): ?>
                <div style="position: relative;">
                    <a href="?clearing_view=<?php echo $view; ?>&timeframe=<?php echo $timeframe; ?>&swap=<?php echo $swap['swap_uuid']; ?>" style="text-decoration: none;">
                        <div class="swap-item <?php echo $selectedSwap === $swap['swap_uuid'] ? 'selected' : ''; ?>">
                            <div class="swap-path">
                                <span class="swap-source" title="<?php echo htmlspecialchars($swap['source_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['source_institution'] ?? 'UNKNOWN', 0, 12)); ?></span>
                                <span class="swap-arrow">→</span>
                                <span class="swap-dest" title="<?php echo htmlspecialchars($swap['dest_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['dest_institution'] ?? 'UNKNOWN', 0, 12)); ?></span>
                            </div>
                            <div class="swap-meta">
                                <span><?php echo htmlspecialchars(substr($swap['source_type'] ?? '', 0, 8)); ?> → <?php echo htmlspecialchars(substr($swap['dest_type'] ?? '', 0, 8)); ?></span>
                                <span class="swap-amount"><?php echo format_amount($swap['amount']); ?> BWP</span>
                            </div>
                            <div class="swap-meta">
                                <span><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></span>
                                <span class="swap-status status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span>
                            </div>
                        </div>
                    </a>
                    <a href="?download_swap=1&swap=<?php echo urlencode($swap['swap_uuid']); ?>&format=csv" class="swap-download" title="Download this swap details">📥 CSV</a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- RIGHT: MESSAGE CLEARING VISUALIZER -->
            <div class="clearing-visualizer">
                <?php if ($selectedSwap && $swapDetails): ?>
                <div class="visualizer-header">
                    <div class="selected-swap-info">
                        Clearing: <span class="selected-swap-ref"><?php echo substr($selectedSwap, 0, 16); ?>…</span>
                    </div>
                    <button class="replay-btn" onclick="replaySwap()">⟲ REPLAY SWAP</button>
                </div>

                <div class="message-timeline" id="timeline">
                    <!-- Step 1: API Request -->
                    <div class="timeline-step">
                        <div class="timeline-icon">1</div>
                        <div class="timeline-content">
                            <div class="timeline-title">API REQUEST</div>
                            <div class="timeline-subtitle">POST /swap/execute · <?php echo date('H:i:s', strtotime($swapDetails['created_at'])); ?></div>
                            <div class="timeline-details">
                                <pre><?php 
                                $sourceDetails = is_string($swapDetails['source_details']) 
                                    ? json_decode($swapDetails['source_details'], true) 
                                    : $swapDetails['source_details'];
                                $destDetails = is_string($swapDetails['destination_details']) 
                                    ? json_decode($swapDetails['destination_details'], true) 
                                    : $swapDetails['destination_details'];
                                
                                echo json_encode([
                                    'source' => $sourceDetails,
                                    'destination' => $destDetails,
                                    'amount' => (float)$swapDetails['amount'],
                                    'currency' => $swapDetails['from_currency']
                                ], JSON_PRETTY_PRINT); 
                                ?></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Hold Creation -->
                    <?php if (!empty($messageFlow['hold'])): foreach($messageFlow['hold'] as $hold): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">2</div>
                        <div class="timeline-content">
                            <div class="timeline-title">HOLD CREATED</div>
                            <div class="timeline-subtitle"><?php echo htmlspecialchars($hold['participant_name'] ?? 'Unknown'); ?> · <?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></div>
                            <div class="timeline-details">
                                <pre><?php 
                                echo json_encode([
                                    'hold_reference' => $hold['hold_reference'],
                                    'asset_type' => $hold['asset_type'],
                                    'amount' => (float)$hold['amount'],
                                    'status' => $hold['status'],
                                    'expiry' => $hold['hold_expiry'] ?? 'N/A'
                                ], JSON_PRETTY_PRINT); 
                                ?></pre>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>

                    <!-- Step 3: API Messages -->
                    <?php foreach ($apiCalls as $api): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">3</div>
                        <div class="timeline-content">
                            <div class="timeline-title"><?php echo strtoupper($api['direction'] ?? 'OUTGOING'); ?> API MESSAGE</div>
                            <div class="timeline-subtitle"><?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?> · <?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                            <div class="timeline-details">
                                <div class="console-request">
                                    <strong>REQUEST:</strong>
                                    <pre><?php 
                                    $req = is_string($api['request_payload']) ? json_decode($api['request_payload'], true) : $api['request_payload'];
                                    echo json_encode($req, JSON_PRETTY_PRINT); 
                                    ?></pre>
                                </div>
                                <div class="console-response">
                                    <strong>RESPONSE (<?php echo $api['http_status_code'] ?? 'N/A'; ?>):</strong>
                                    <pre><?php 
                                    $res = is_string($api['response_payload']) ? json_decode($api['response_payload'], true) : $api['response_payload'];
                                    echo json_encode($res, JSON_PRETTY_PRINT); 
                                    ?></pre>
                                </div>
                                <?php if (!empty($api['duration_ms'])): ?>
                                <div style="color: #888;">⏱️ <?php echo $api['duration_ms']; ?>ms</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Step 4: Ledger Entries -->
                    <?php if (!empty($ledgerEntries)): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">4</div>
                        <div class="timeline-content">
                            <div class="timeline-title">LEDGER IMPACT</div>
                            <div class="timeline-subtitle">Double-Entry Accounting</div>
                            <div class="timeline-details">
                                <?php foreach ($ledgerEntries as $entry): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding: 0.5rem; background: #0a0a0a;">
                                    <span style="color: #ff6b6b;">DEBIT: <?php echo htmlspecialchars($entry['debit_account_name'] ?? $entry['debit_account_id']); ?></span>
                                    <span style="color: #4ecdc4;">CREDIT: <?php echo htmlspecialchars($entry['credit_account_name'] ?? $entry['credit_account_id']); ?></span>
                                    <span style="color: #0f0;"><?php echo format_amount($entry['amount']); ?> BWP</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Step 5: Fee Split -->
                    <?php if (!empty($feeDetails)): foreach($feeDetails as $fee): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">5</div>
                        <div class="timeline-content">
                            <div class="timeline-title">FEE SPLIT</div>
                            <div class="timeline-subtitle"><?php echo htmlspecialchars($fee['fee_type'] ?? 'Fee'); ?></div>
                            <div class="timeline-details">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Total Fee:</span>
                                    <span class="positive"><?php echo format_amount($fee['total_amount']); ?> BWP</span>
                                </div>
                                <?php 
                                $split = is_string($fee['split_config']) ? json_decode($fee['split_config'], true) : ($fee['split_config'] ?? []);
                                if (is_array($split)):
                                foreach ($split as $party => $amount): 
                                ?>
                                <div style="display: flex; justify-content: space-between; margin-left: 1rem; color: #888;">
                                    <span><?php echo strtoupper($party); ?>:</span>
                                    <span class="positive">+<?php echo format_amount($amount); ?> BWP</span>
                                </div>
                                <?php endforeach; endif; ?>
                                <?php if (!empty($fee['vat_amount']) && $fee['vat_amount'] > 0): ?>
                                <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #333;">
                                    <span>VAT (14%):</span>
                                    <span><?php echo format_amount($fee['vat_amount']); ?> BWP</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>

                    <!-- Step 6: Settlement -->
                    <?php if (!empty($messageFlow['settlement'])): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">6</div>
                        <div class="timeline-content">
                            <div class="timeline-title">SETTLEMENT OBLIGATION</div>
                            <div class="timeline-subtitle">Queued for Net Settlement</div>
                            <div class="timeline-details">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Debtor: <?php echo htmlspecialchars($messageFlow['settlement']['debtor'] ?? 'N/A'); ?></span>
                                    <span>→</span>
                                    <span>Creditor: <?php echo htmlspecialchars($messageFlow['settlement']['creditor'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-top: 0.5rem; border-top: 1px solid #333;">
                                    <span>Amount:</span>
                                    <span class="positive"><?php echo format_amount($messageFlow['settlement']['amount'] ?? 0); ?> BWP</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 4rem; color: #444;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">↖️</div>
                    <div style="font-size: 1.2rem;">Select a swap from the live feed</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Net Positions -->
        <div class="net-positions">
            <div class="card-header">
                <div class="card-title">INTER-INSTITUTION NET POSITIONS</div>
                <div class="card-badge">REAL-TIME SETTLEMENT</div>
            </div>
            <div class="positions-grid">
                <?php if (empty($institutionNets)): ?>
                <div class="position-card" style="grid-column: 1/-1; text-align: center; color: #666;">
                    No net positions yet
                </div>
                <?php else: ?>
                <?php foreach ($institutionNets as $institution => $nets): 
                    $netPosition = (float)($nets['credit'] - $nets['debit']);
                ?>
                <div class="position-card">
                    <div class="position-header">
                        <span class="position-institution"><?php echo htmlspecialchars($institution); ?></span>
                        <span class="position-net <?php echo $netPosition >= 0 ? 'net-positive' : 'net-negative'; ?>">
                            <?php echo $netPosition >= 0 ? '+' : ''; ?><?php echo format_amount($netPosition); ?> BWP
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666;">
                        <span>Receivable: <?php echo format_amount($nets['credit']); ?></span>
                        <span>Payable: <?php echo format_amount($nets['debit']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settlement Matrix -->
        <?php if (!empty($settlementMatrix)): ?>
        <div class="settlement-matrix">
            <div class="card-header">
                <div class="card-title">SETTLEMENT MATRIX</div>
                <div class="card-badge">NETTING OPTIMIZATION</div>
            </div>
            <div class="matrix-grid">
                <?php foreach ($settlementMatrix as $edge): ?>
                <div class="matrix-edge">
                    <div class="edge-path">
                        <span class="edge-from" title="<?php echo htmlspecialchars($edge['from']); ?>"><?php echo htmlspecialchars(substr($edge['from'], 0, 8)); ?></span>
                        <span class="edge-arrow">→</span>
                        <span class="edge-to" title="<?php echo htmlspecialchars($edge['to']); ?>"><?php echo htmlspecialchars(substr($edge['to'], 0, 8)); ?></span>
                    </div>
                    <span class="edge-amount"><?php echo format_amount($edge['amount']); ?> BWP</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Message Clearance Monitor -->
        <div class="clearance-monitor">
            <div class="card-header">
                <div class="card-title">MESSAGE CLEARANCE MONITOR</div>
                <div class="card-badge">API RELIABILITY</div>
            </div>
            <table class="clearance-table">
                <thead>
                    <tr>
                        <th>PARTICIPANT</th>
                        <th>TOTAL MESSAGES</th>
                        <th>SUCCESS RATE</th>
                        <th>AVG RESPONSE TIME</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clearanceMetrics)): ?>
                    <tr><td colspan="4" style="text-align: center; color: #666;">No message data for this period</td></tr>
                    <?php else: ?>
                    <?php foreach ($clearanceMetrics as $metric): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($metric['participant_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo (int)($metric['total_messages'] ?? 0); ?></td>
                        <td>
                            <span class="success-rate">
                                <?php 
                                $successRate = ((float)($metric['successful'] ?? 0) / max(1, (float)($metric['total_messages'] ?? 1))) * 100;
                                echo format_amount($successRate, 1); ?>%
                            </span>
                        </td>
                        <td><?php echo round((float)($metric['avg_response_time'] ?? 0)); ?> ms</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>VOUCHMORPH · MESSAGE CLEARING HOUSE · DOUBLE-ENTRY VERIFIED · ISO20022 COMPLIANT</p>
            <p style="margin-top: 0.5rem;">
                CLEARED: <?php echo count($liveSwaps); ?> SWAPS · 
                NET EXPOSURE: <?php 
                $totalCredit = array_sum(array_column($institutionNets, 'credit'));
                $totalDebit = array_sum(array_column($institutionNets, 'debit'));
                echo format_amount($totalCredit - $totalDebit); 
                ?> BWP
            </p>
        </div>
    </div>

    <script>
        function replaySwap() {
            const timeline = document.getElementById('timeline');
            if (!timeline) return;
            
            timeline.classList.add('replay-animation');
            const steps = document.querySelectorAll('.timeline-step');
            
            steps.forEach((step, index) => {
                step.style.opacity = '0';
                step.style.transform = 'translateX(-20px)';
                step.style.transition = 'all 0.5s ease';
                
                setTimeout(() => {
                    step.style.opacity = '1';
                    step.style.transform = 'translateX(0)';
                }, index * 300);
            });
            
            setTimeout(() => {
                timeline.classList.remove('replay-animation');
            }, steps.length * 300 + 500);
        }
    </script>
</body>
</html>
