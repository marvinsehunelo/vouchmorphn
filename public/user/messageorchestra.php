<?php
declare(strict_types=1);

namespace DASHBOARD;

use PDO; // ADD THIS for PDO::FETCH_ASSOC

// Same bootstrap code as regulationdemo.php
ob_start();
$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', rtrim(realpath(__DIR__ . '/../../'), '/') ?: '/var/www/html');
}

@include_once APP_ROOT . '/vendor/autoload.php';

// Database connection
require_once APP_ROOT . '/src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
use DATA_PERSISTENCE_LAYER\config\DBConnection;
$db = DBConnection::getConnection();

// ============================================================================
// HELPER FUNCTION FOR SAFE NUMBER FORMATTING
// ============================================================================
function format_amount($amount, $decimals = 2) {
    return number_format((float)$amount, $decimals);
}

// ============================================================================
// FETCH MESSAGE CLEARING DATA
// ============================================================================

$selectedSwap = $_GET['swap'] ?? null;
$timeframe = $_GET['timeframe'] ?? 'today'; // today, week, month
$view = $_GET['clearing_view'] ?? 'overview'; // overview, message_flow, settlement, liquidity

// Get date range based on timeframe
$dateRange = match($timeframe) {
    'today' => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')],
    'week' => ['start' => date('Y-m-d 00:00:00', strtotime('-7 days')), 'end' => date('Y-m-d 23:59:59')],
    'month' => ['start' => date('Y-m-d 00:00:00', strtotime('-30 days')), 'end' => date('Y-m-d 23:59:59')],
    default => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')]
};

// ============================================================================
// 1. GLOBAL SYSTEM STATUS
// ============================================================================

// Transaction throughput (TPS)
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

// Liquidity (total in escrow)
$liquidityQuery = $db->query("
    SELECT SUM(balance) as total_liquidity
    FROM ledger_accounts
    WHERE account_type IN ('escrow', 'settlement')
");
$liquidity = $liquidityQuery->fetchColumn() ?: 0;

// Active institutions
$instQuery = $db->query("SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE'");
$activeInstitutions = $instQuery->fetchColumn();

// Pending messages
$pendingQuery = $db->query("
    SELECT COUNT(*) FROM settlement_messages 
    WHERE status = 'PENDING'
");
$pendingMessages = $pendingQuery->fetchColumn();

// ============================================================================
// 2. LIVE SWAP STREAM (Last 20 swaps)
// ============================================================================

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

// ============================================================================
// 3. DETAILED SWAP VIEW (if one is selected)
// ============================================================================

$swapDetails = null;
$messageFlow = [];
$ledgerEntries = [];
$feeDetails = [];
$apiCalls = [];

if ($selectedSwap) {
    // Get swap details - FIXED: removed metadata column
    $swapDetailQuery = $db->prepare("
        SELECT 
            sr.*,
            sr.source_details,
            sr.destination_details
        FROM swap_requests sr
        WHERE sr.swap_uuid = ?
    ");
    $swapDetailQuery->execute([$selectedSwap]);
    $swapDetails = $swapDetailQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($swapDetails) {
        // Get hold transactions
        $holdQuery = $db->prepare("
            SELECT * FROM hold_transactions 
            WHERE swap_reference = ?
        ");
        $holdQuery->execute([$selectedSwap]);
        $messageFlow['hold'] = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get API message logs
        $apiQuery = $db->prepare("
            SELECT * FROM api_message_logs 
            WHERE message_id = ?
            ORDER BY created_at ASC
        ");
        $apiQuery->execute([$selectedSwap]);
        $apiCalls = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get ledger entries
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
        
        // Get fee collections
        $feeQuery = $db->prepare("
            SELECT * FROM swap_fee_collections 
            WHERE swap_reference = ?
        ");
        $feeQuery->execute([$selectedSwap]);
        $feeDetails = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
        
        // Get settlement queue entry
        $sourceInstitution = is_array($swapDetails['source_details']) 
            ? ($swapDetails['source_details']['institution'] ?? '') 
            : (json_decode($swapDetails['source_details'], true)['institution'] ?? '');
        
        $destInstitution = is_array($swapDetails['destination_details']) 
            ? ($swapDetails['destination_details']['institution'] ?? '') 
            : (json_decode($swapDetails['destination_details'], true)['institution'] ?? '');
        
        $settlementQuery = $db->prepare("
            SELECT * FROM settlement_queue 
            WHERE debtor = ? OR creditor = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $settlementQuery->execute([$sourceInstitution, $destInstitution]);
        $messageFlow['settlement'] = $settlementQuery->fetch(PDO::FETCH_ASSOC);
    }
}

// ============================================================================
// 4. INTER-INSTITUTION NET POSITIONS
// ============================================================================

$netPositionsQuery = $db->query("
    SELECT 
        debtor,
        creditor,
        SUM(amount) as net_amount
    FROM settlement_queue
    GROUP BY debtor, creditor
    ORDER BY net_amount DESC
    LIMIT 10
");
$netPositions = $netPositionsQuery->fetchAll(PDO::FETCH_ASSOC);

// Calculate net for each institution
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

// ============================================================================
// 5. MESSAGE CLEARANCE METRICS
// ============================================================================

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

// ============================================================================
// 6. LIQUIDITY EXPOSURE
// ============================================================================

$liquidityQuery = $db->query("
    SELECT 
        p.name as institution,
        la.account_name,
        la.balance,
        la.account_type
    FROM ledger_accounts la
    JOIN participants p ON la.participant_id = p.participant_id
    WHERE la.account_type IN ('escrow', 'settlement')
    ORDER BY la.balance DESC
");
$liquidityExposure = $liquidityQuery->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// 7. SETTLEMENT MATRIX (For visualization)
// ============================================================================

$institutions = array_keys($institutionNets);
$settlementMatrix = [];
foreach ($institutions as $debtor) {
    foreach ($institutions as $creditor) {
        if ($debtor !== $creditor) {
            $query = $db->prepare("
                SELECT SUM(amount) as amount
                FROM settlement_queue
                WHERE debtor = ? AND creditor = ?
            ");
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

// ============================================================================
// RENDER THE DASHBOARD
// ============================================================================

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>VOUCHMORPH · MESSAGE CLEARING HOUSE · <?php echo $countryCode; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0a;
            font-family: 'Inter', 'Helvetica Neue', -apple-system, sans-serif;
            color: #ffffff;
            line-height: 1.4;
            font-weight: 300;
            overflow-x: hidden;
            width: 100%;
            position: relative;
        }

        .container {
            max-width: 2000px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .logo {
            font-size: clamp(1rem, 4vw, 1.4rem);
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }

        .logo span {
            color: #888;
            font-size: clamp(0.6rem, 2vw, 0.8rem);
            margin-left: 1rem;
        }

        .status-badge {
            padding: 0.5rem 1.5rem;
            background: #111;
            border: 1px solid #0f0;
            color: #0f0;
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            text-transform: uppercase;
            white-space: nowrap;
        }

        /* Clearing-specific styles */
        .clearing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .clearing-title {
            font-size: clamp(1.5rem, 5vw, 2rem);
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
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            text-transform: uppercase;
            white-space: nowrap;
        }

        .timeframe-btn.active {
            background: #0f0;
            color: #000;
            border-color: #0f0;
        }

        /* Zone 1: Global System Status */
        .global-status {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .status-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem 1rem;
            position: relative;
            overflow: hidden;
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
            font-size: clamp(0.6rem, 1.5vw, 0.7rem);
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
            white-space: nowrap;
        }

        .status-value {
            font-size: clamp(1.5rem, 4vw, 2.5rem);
            font-weight: 200;
            font-family: 'Courier New', monospace;
            color: #0f0;
            line-height: 1.2;
            word-break: break-word;
        }

        .status-unit {
            font-size: clamp(0.6rem, 1.5vw, 0.8rem);
            color: #444;
            margin-left: 0.25rem;
        }

        /* Zone 2: Main Clearing Area */
        .clearing-main {
            display: grid;
            grid-template-columns: minmax(280px, 350px) 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Live Swap Feed */
        .swap-feed {
            background: #111;
            border: 2px solid #222;
            height: 600px;
            overflow-y: auto;
        }

        .feed-header {
            padding: 1rem;
            border-bottom: 2px solid #222;
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            text-transform: uppercase;
            color: #888;
            position: sticky;
            top: 0;
            background: #111;
            z-index: 10;
        }

        .swap-item {
            padding: 1rem;
            border-bottom: 1px solid #222;
            cursor: pointer;
            transition: all 0.2s;
        }

        .swap-item:hover {
            background: #1a1a1a;
            border-left: 3px solid #0f0;
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
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            flex-wrap: wrap;
        }

        .swap-source, .swap-dest {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .swap-source {
            color: #ff6b6b;
        }

        .swap-arrow {
            color: #444;
            flex-shrink: 0;
        }

        .swap-dest {
            color: #4ecdc4;
        }

        .swap-meta {
            display: flex;
            justify-content: space-between;
            font-size: clamp(0.7rem, 1.8vw, 0.8rem);
            color: #666;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .swap-amount {
            color: #0f0;
        }

        .swap-status {
            padding: 0.2rem 0.5rem;
            border: 1px solid;
            font-size: 0.6rem;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-completed { border-color: #0f0; color: #0f0; }
        .status-pending { border-color: #ff0; color: #ff0; }
        .status-failed { border-color: #f00; color: #f00; }

        /* Message Clearing Visualizer */
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

        .selected-swap-info {
            font-size: clamp(1rem, 3vw, 1.2rem);
        }

        .selected-swap-ref {
            color: #0f0;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }

        /* Message Flow Timeline */
        .message-timeline {
            margin: 2rem 0;
            min-width: 300px;
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
            min-width: 200px;
            overflow-x: auto;
        }

        .timeline-title {
            font-size: clamp(0.9rem, 2.5vw, 1rem);
            text-transform: uppercase;
            color: #0f0;
            margin-bottom: 0.5rem;
        }

        .timeline-subtitle {
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            color: #888;
            margin-bottom: 1rem;
            word-break: break-word;
        }

        .timeline-details {
            background: #0a0a0a;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: clamp(0.7rem, 1.8vw, 0.8rem);
            overflow-x: auto;
        }

        .timeline-details pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #ccc;
        }

        /* Zone 3: Net Positions */
        .net-positions {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
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
            font-size: clamp(0.9rem, 2.5vw, 1rem);
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
            font-size: clamp(0.6rem, 1.8vw, 0.7rem);
            text-transform: uppercase;
            white-space: nowrap;
        }

        .positions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
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
            word-break: break-word;
        }

        .position-net {
            font-size: clamp(1rem, 2.5vw, 1.2rem);
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }

        .net-positive { color: #0f0; }
        .net-negative { color: #f00; }

        /* Zone 4: Settlement Matrix */
        .settlement-matrix {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .matrix-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
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
        .edge-arrow { color: #444; }
        .edge-amount { color: #0f0; font-weight: 400; white-space: nowrap; }

        /* Zone 5: Message Clearance Monitor */
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
            min-width: 500px;
        }

        .clearance-table th {
            text-align: left;
            padding: 1rem;
            background: #000;
            color: #888;
            font-size: clamp(0.6rem, 1.8vw, 0.7rem);
            text-transform: uppercase;
        }

        .clearance-table td {
            padding: 1rem;
            border-bottom: 1px solid #222;
            font-family: 'Courier New', monospace;
            font-size: clamp(0.7rem, 2vw, 0.8rem);
        }

        .success-rate {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #1a3a1a;
            color: #0f0;
            border-radius: 3px;
            white-space: nowrap;
        }

        /* Zone 6: Raw Message Console */
        .message-console {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            overflow-x: auto;
        }

        .console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .console-content {
            background: #000;
            padding: 1.5rem;
            font-family: 'Courier New', monospace;
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            overflow-x: auto;
            border: 2px solid #222;
            margin-bottom: 1rem;
        }

        .console-request {
            color: #ff6b6b;
        }

        .console-response {
            color: #4ecdc4;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #333;
        }

        .console-content pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #ccc;
        }

        /* Replay Button */
        .replay-btn {
            padding: 0.5rem 1.5rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: clamp(0.7rem, 2vw, 0.8rem);
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .replay-btn:hover {
            background: #0f0;
            color: #000;
        }

        .replay-animation {
            animation: pulse 1s ease-in-out;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #444;
            font-size: clamp(0.6rem, 2vw, 0.7rem);
        }

        /* ============================================ */
        /* RESPONSIVE BREAKPOINTS */
        /* ============================================ */

        /* Large Tablets (max-width: 1200px) */
        @media screen and (max-width: 1200px) {
            .container {
                padding: 1.5rem;
            }
            
            .global-status {
                gap: 1rem;
            }
            
            .status-card {
                padding: 1.25rem 0.75rem;
            }
        }

        /* Tablets (max-width: 992px) */
        @media screen and (max-width: 992px) {
            .clearing-main {
                grid-template-columns: 1fr;
            }
            
            .swap-feed {
                height: 400px;
            }
            
            .global-status {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .positions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Mobile Landscape (max-width: 768px) */
        @media screen and (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .status-badge {
                align-self: flex-start;
            }
            
            .clearing-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .global-status {
                gap: 0.75rem;
            }
            
            .status-card {
                padding: 1rem 0.75rem;
            }
            
            .positions-grid {
                grid-template-columns: 1fr;
            }
            
            .matrix-grid {
                grid-template-columns: 1fr;
            }
            
            .timeline-step {
                flex-direction: column;
            }
            
            .timeline-step::before {
                display: none;
            }
            
            .timeline-icon {
                margin-bottom: 1rem;
            }
            
            .visualizer-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .replay-btn {
                width: 100%;
            }
        }

        /* Mobile Portrait (max-width: 480px) */
        @media screen and (max-width: 480px) {
            .global-status {
                grid-template-columns: 1fr;
            }
            
            .timeframe-selector {
                width: 100%;
            }
            
            .timeframe-btn {
                flex: 1;
                text-align: center;
                padding: 0.5rem 0.25rem;
            }
            
            .swap-path {
                font-size: 0.8rem;
            }
            
            .swap-meta {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .position-header {
                flex-direction: column;
            }
            
            .edge-path {
                width: 100%;
            }
            
            .console-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Small Mobile (max-width: 360px) */
        @media screen and (max-width: 360px) {
            .container {
                padding: 0.75rem;
            }
            
            .status-card {
                padding: 0.75rem 0.5rem;
            }
            
            .status-value {
                font-size: 1.2rem;
            }
            
            .swap-source, .swap-dest {
                max-width: 80px;
            }
            
            .edge-from, .edge-to {
                max-width: 70px;
            }
        }

        /* Large Screens (min-width: 2000px) */
        @media screen and (min-width: 2000px) {
            .container {
                max-width: 90%;
            }
            
            .global-status {
                gap: 2.5rem;
            }
            
            .status-card {
                padding: 2rem;
            }
            
            .status-value {
                font-size: 3rem;
            }
            
            .positions-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .swap-item {
                padding: 1.2rem;
            }
            
            .timeframe-btn, .replay-btn {
                padding: 0.75rem 1.5rem;
            }
            
            .swap-item:hover {
                background: #111;
            }
        }

        /* Print styles */
        @media print {
            body {
                background: white;
                color: black;
            }
            
            .status-card, .swap-feed, .clearing-visualizer, 
            .net-positions, .settlement-matrix, .clearance-monitor {
                break-inside: avoid;
                background: white;
                color: black;
                border: 1px solid #ccc;
            }
            
            .replay-btn, .timeframe-selector {
                display: none;
            }
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

        <!-- Clearing Header -->
        <div class="clearing-header">
            <div class="clearing-title">MESSAGE CLEARING SYSTEM</div>
            <div class="timeframe-selector">
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=today&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'today' ? 'active' : ''; ?>">TODAY</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=week&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'week' ? 'active' : ''; ?>">WEEK</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=month&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'month' ? 'active' : ''; ?>">MONTH</a>
            </div>
        </div>

        <!-- ZONE 1: GLOBAL SYSTEM STATUS -->
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

        <!-- ZONE 2: MAIN CLEARING AREA -->
        <div class="clearing-main">
            <!-- LEFT: LIVE SWAP FEED -->
            <div class="swap-feed">
                <div class="feed-header">LIVE SWAP STREAM · REAL-TIME</div>
                <?php foreach ($liveSwaps as $swap): ?>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=<?php echo $timeframe; ?>&swap=<?php echo $swap['swap_uuid']; ?>" style="text-decoration: none;">
                    <div class="swap-item <?php echo $selectedSwap === $swap['swap_uuid'] ? 'selected' : ''; ?>">
                        <div class="swap-path">
                            <span class="swap-source" title="<?php echo htmlspecialchars($swap['source_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['source_institution'] ?? 'UNKNOWN', 0, 10)); ?></span>
                            <span class="swap-arrow">→</span>
                            <span class="swap-dest" title="<?php echo htmlspecialchars($swap['dest_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['dest_institution'] ?? 'UNKNOWN', 0, 10)); ?></span>
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
                <?php endforeach; ?>
            </div>

            <!-- RIGHT: MESSAGE CLEARING VISUALIZER -->
            <div class="clearing-visualizer">
                <?php if ($selectedSwap && $swapDetails): ?>
                <div class="visualizer-header">
                    <div class="selected-swap-info">
                        Clearing Transaction: <span class="selected-swap-ref"><?php echo substr($selectedSwap, 0, 16); ?>…</span>
                    </div>
                    <button class="replay-btn" onclick="replaySwap()">⟲ REPLAY SWAP</button>
                </div>

                <!-- Message Flow Timeline -->
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
                            <div class="timeline-subtitle">Participant: <?php echo htmlspecialchars($hold['participant_name'] ?? 'Unknown'); ?> · <?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></div>
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

                    <!-- Step 3: API Messages to Institutions -->
                    <?php foreach ($apiCalls as $api): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">3</div>
                        <div class="timeline-content">
                            <div class="timeline-title"><?php echo strtoupper($api['direction'] ?? 'OUTGOING'); ?> API MESSAGE</div>
                            <div class="timeline-subtitle"><?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?> · <?php echo htmlspecialchars($api['endpoint'] ?? 'N/A'); ?> · <?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                            <div class="timeline-details">
                                <div class="console-request">
                                    <strong>REQUEST:</strong>
                                    <pre><?php 
                                    $requestPayload = is_string($api['request_payload']) 
                                        ? json_decode($api['request_payload'], true) 
                                        : $api['request_payload'];
                                    echo json_encode($requestPayload, JSON_PRETTY_PRINT); 
                                    ?></pre>
                                </div>
                                <div class="console-response">
                                    <strong>RESPONSE (<?php echo $api['http_status_code'] ?? 'N/A'; ?>):</strong>
                                    <pre><?php 
                                    $responsePayload = is_string($api['response_payload']) 
                                        ? json_decode($api['response_payload'], true) 
                                        : $api['response_payload'];
                                    echo json_encode($responsePayload, JSON_PRETTY_PRINT); 
                                    ?></pre>
                                </div>
                                <?php if (!empty($api['duration_ms'])): ?>
                                <div style="margin-top: 0.5rem; color: #888;">⏱️ <?php echo $api['duration_ms']; ?>ms</div>
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
                            <div class="timeline-subtitle">Double-Entry Accounting · <?php echo date('H:i:s', strtotime($ledgerEntries[0]['created_at'])); ?></div>
                            <div class="timeline-details">
                                <?php foreach ($ledgerEntries as $entry): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding: 0.5rem; background: #0a0a0a; flex-wrap: wrap; gap: 0.5rem;">
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
                            <div class="timeline-subtitle"><?php echo htmlspecialchars($fee['fee_type'] ?? 'Fee'); ?> · <?php echo date('H:i:s', strtotime($fee['collected_at'] ?? $fee['created_at'])); ?></div>
                            <div class="timeline-details">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                                    <span>Total Fee:</span>
                                    <span class="positive"><?php echo format_amount($fee['total_amount']); ?> BWP</span>
                                </div>
                                <?php 
                                $split = is_string($fee['split_config']) 
                                    ? json_decode($fee['split_config'], true) 
                                    : ($fee['split_config'] ?? []);
                                if (is_array($split)):
                                foreach ($split as $party => $amount): 
                                ?>
                                <div style="display: flex; justify-content: space-between; margin-left: 1rem; color: #888; flex-wrap: wrap; gap: 0.5rem;">
                                    <span><?php echo strtoupper($party); ?>:</span>
                                    <span class="positive">+<?php echo format_amount($amount); ?> BWP</span>
                                </div>
                                <?php endforeach; endif; ?>
                                <?php if (!empty($fee['vat_amount']) && $fee['vat_amount'] > 0): ?>
                                <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #333; flex-wrap: wrap; gap: 0.5rem;">
                                    <span>VAT (14%):</span>
                                    <span><?php echo format_amount($fee['vat_amount']); ?> BWP</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>

                    <!-- Step 6: Settlement Queue -->
                    <?php if (!empty($messageFlow['settlement'])): ?>
                    <div class="timeline-step">
                        <div class="timeline-icon">6</div>
                        <div class="timeline-content">
                            <div class="timeline-title">SETTLEMENT OBLIGATION</div>
                            <div class="timeline-subtitle">Queued for Net Settlement</div>
                            <div class="timeline-details">
                                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                    <span>Debtor: <?php echo htmlspecialchars($messageFlow['settlement']['debtor'] ?? 'N/A'); ?></span>
                                    <span>→</span>
                                    <span>Creditor: <?php echo htmlspecialchars($messageFlow['settlement']['creditor'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #333; flex-wrap: wrap; gap: 0.5rem;">
                                    <span>Amount:</span>
                                    <span class="positive"><?php echo format_amount($messageFlow['settlement']['amount'] ?? 0); ?> BWP</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 4rem 1rem; color: #444;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">↖️</div>
                    <div style="font-size: 1.2rem;">Select a swap from the live feed</div>
                    <div style="margin-top: 1rem; font-size: 0.8rem;">View the complete message clearing flow</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ZONE 3: INTER-INSTITUTION NET POSITIONS -->
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
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666; flex-wrap: wrap; gap: 0.5rem;">
                        <span>Receivable: <?php echo format_amount($nets['credit']); ?></span>
                        <span>Payable: <?php echo format_amount($nets['debit']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ZONE 4: SETTLEMENT MATRIX -->
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
                    <span class="edge-amount"><?php echo format_amount($edge['amount']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ZONE 5: MESSAGE CLEARANCE MONITOR -->
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

        <!-- ZONE 6: RAW MESSAGE CONSOLE (shown when swap selected) -->
        <?php if ($selectedSwap && $swapDetails && !empty($apiCalls)): ?>
        <div class="message-console">
            <div class="console-header">
                <div class="card-title">RAW MESSAGE CONSOLE</div>
                <div class="card-badge">POSTMAN STYLE</div>
            </div>
            <?php foreach ($apiCalls as $api): ?>
            <div class="console-content">
                <div class="console-request">
                    <strong>➡️ <?php echo strtoupper($api['direction'] ?? 'OUTGOING'); ?> REQUEST to <?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?></strong>
                    <pre><?php 
                    $requestPayload = is_string($api['request_payload']) 
                        ? json_decode($api['request_payload'], true) 
                        : ($api['request_payload'] ?? []);
                    echo json_encode($requestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></pre>
                </div>
                <div class="console-response">
                    <strong>⬅️ RESPONSE (HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?>)</strong>
                    <pre><?php 
                    $responsePayload = is_string($api['response_payload']) 
                        ? json_decode($api['response_payload'], true) 
                        : ($api['response_payload'] ?? []);
                    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); 
                    ?></pre>
                </div>
                <?php if (!empty($api['curl_error'])): ?>
                <div style="color: #f00; margin-top: 0.5rem;">⚠️ CURL Error: <?php echo htmlspecialchars($api['curl_error']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
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
        // Replay animation function
        function replaySwap() {
            const timeline = document.getElementById('timeline');
            timeline.classList.add('replay-animation');
            
            // Step through each timeline item with delay
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

        // Optional: Handle touch events for mobile
        document.addEventListener('touchstart', function() {}, {passive: true});
    </script>
</body>
</html>
