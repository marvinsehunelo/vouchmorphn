<?php
declare(strict_types=1);

namespace DASHBOARD;

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
}];

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
$tps = $tpsData['duration_seconds'] > 0 
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
    // Get swap details
    $swapDetailQuery = $db->prepare("
        SELECT 
            sr.*,
            sr.source_details,
            sr.destination_details,
            sr.metadata
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
        $settlementQuery = $db->prepare("
            SELECT * FROM settlement_queue 
            WHERE debtor = ? OR creditor = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $settlementQuery->execute([
            $swapDetails['source_details']['institution'] ?? '',
            $swapDetails['destination_details']['institution'] ?? ''
        ]);
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
    $institutionNets[$pos['debtor']]['debit'] += $pos['net_amount'];
    $institutionNets[$pos['creditor']]['credit'] += $pos['net_amount'];
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
                    'amount' => $amount
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
            font-family: 'Inter', 'Helvetica Neue', -apple-system, sans-serif;
            color: #ffffff;
            line-height: 1.4;
            font-weight: 300;
        }

        .container {
            max-width: 2000px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header Styles (same as your existing) */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 2px solid #222;
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }

        .logo span {
            color: #888;
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

        /* Clearing-specific styles */
        .clearing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .clearing-title {
            font-size: 2rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }

        .timeframe-selector {
            display: flex;
            gap: 1rem;
        }

        .timeframe-btn {
            padding: 0.5rem 1.5rem;
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

        /* Zone 1: Global System Status */
        .global-status {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .status-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
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
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .status-value {
            font-size: 2.5rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            color: #0f0;
        }

        .status-unit {
            font-size: 0.8rem;
            color: #444;
            margin-left: 0.5rem;
        }

        /* Zone 2: Main Clearing Area */
        .clearing-main {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
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
            font-size: 0.8rem;
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
            font-size: 0.9rem;
        }

        .swap-source {
            color: #ff6b6b;
        }

        .swap-arrow {
            color: #444;
        }

        .swap-dest {
            color: #4ecdc4;
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

        /* Message Clearing Visualizer */
        .clearing-visualizer {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .visualizer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #222;
        }

        .selected-swap-info {
            font-size: 1.2rem;
        }

        .selected-swap-ref {
            color: #0f0;
            font-family: 'Courier New', monospace;
        }

        /* Message Flow Timeline */
        .message-timeline {
            margin: 2rem 0;
        }

        .timeline-step {
            display: flex;
            margin-bottom: 1.5rem;
            position: relative;
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
        }

        .timeline-content {
            flex: 1;
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
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

        /* Zone 3: Net Positions */
        .net-positions {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .positions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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

        /* Zone 4: Settlement Matrix */
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
            margin-top: 1.5rem;
        }

        .matrix-edge {
            background: #000;
            border: 2px solid #222;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .edge-path {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .edge-from { color: #ff6b6b; }
        .edge-to { color: #4ecdc4; }
        .edge-arrow { color: #444; }
        .edge-amount { color: #0f0; font-weight: 400; }

        /* Zone 5: Message Clearance Monitor */
        .clearance-monitor {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .clearance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
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
        }

        .success-rate {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #1a3a1a;
            color: #0f0;
            border-radius: 3px;
        }

        /* Zone 6: Raw Message Console */
        .message-console {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .console-content {
            background: #000;
            padding: 1.5rem;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            border: 2px solid #222;
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

        /* Replay Button */
        .replay-btn {
            padding: 0.5rem 1.5rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
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
            font-size: 0.7rem;
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
                <div class="status-value"><?php echo number_format($tps, 2); ?><span class="status-unit">TPS</span></div>
            </div>
            <div class="status-card">
                <div class="status-label">TOTAL LIQUIDITY</div>
                <div class="status-value"><?php echo number_format($liquidity / 1000000, 2); ?><span class="status-unit">M BWP</span></div>
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
                            <span class="swap-source"><?php echo substr($swap['source_institution'] ?? 'UNKNOWN', 0, 10); ?></span>
                            <span class="swap-arrow">→</span>
                            <span class="swap-dest"><?php echo substr($swap['dest_institution'] ?? 'UNKNOWN', 0, 10); ?></span>
                        </div>
                        <div class="swap-meta">
                            <span><?php echo substr($swap['source_type'] ?? '', 0, 8); ?> → <?php echo substr($swap['dest_type'] ?? '', 0, 8); ?></span>
                            <span class="swap-amount"><?php echo number_format($swap['amount'], 2); ?> BWP</span>
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
                                echo json_encode([
                                    'source' => $swapDetails['source_details'],
                                    'destination' => $swapDetails['destination_details'],
                                    'amount' => $swapDetails['amount'],
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
                            <div class="timeline-subtitle">Participant: <?php echo $hold['participant_name']; ?> · <?php echo date('H:i:s', strtotime($hold['placed_at'])); ?></div>
                            <div class="timeline-details">
                                <pre><?php 
                                echo json_encode([
                                    'hold_reference' => $hold['hold_reference'],
                                    'asset_type' => $hold['asset_type'],
                                    'amount' => $hold['amount'],
                                    'status' => $hold['status'],
                                    'expiry' => $hold['hold_expiry']
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
                            <div class="timeline-title"><?php echo strtoupper($api['direction']); ?> API MESSAGE</div>
                            <div class="timeline-subtitle"><?php echo $api['participant_name']; ?> · <?php echo $api['endpoint']; ?> · <?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                            <div class="timeline-details">
                                <div class="console-request">
                                    <strong>REQUEST:</strong>
                                    <pre><?php echo json_encode($api['request_payload'], JSON_PRETTY_PRINT); ?></pre>
                                </div>
                                <div class="console-response">
                                    <strong>RESPONSE (<?php echo $api['http_status_code']; ?>):</strong>
                                    <pre><?php echo json_encode($api['response_payload'], JSON_PRETTY_PRINT); ?></pre>
                                </div>
                                <?php if ($api['duration_ms']): ?>
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
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; padding: 0.5rem; background: #0a0a0a;">
                                    <span style="color: #ff6b6b;">DEBIT: <?php echo $entry['debit_account_name'] ?? $entry['debit_account_id']; ?></span>
                                    <span style="color: #4ecdc4;">CREDIT: <?php echo $entry['credit_account_name'] ?? $entry['credit_account_id']; ?></span>
                                    <span style="color: #0f0;"><?php echo number_format($entry['amount'], 2); ?> BWP</span>
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
                            <div class="timeline-subtitle"><?php echo $fee['fee_type']; ?> · <?php echo date('H:i:s', strtotime($fee['collected_at'])); ?></div>
                            <div class="timeline-details">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Total Fee:</span>
                                    <span class="positive"><?php echo number_format($fee['total_amount'], 2); ?> BWP</span>
                                </div>
                                <?php 
                                $split = json_decode($fee['split_config'], true);
                                foreach ($split as $party => $amount): 
                                ?>
                                <div style="display: flex; justify-content: space-between; margin-left: 1rem; color: #888;">
                                    <span><?php echo strtoupper($party); ?>:</span>
                                    <span class="positive">+<?php echo number_format($amount, 2); ?> BWP</span>
                                </div>
                                <?php endforeach; ?>
                                <?php if ($fee['vat_amount'] > 0): ?>
                                <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #333;">
                                    <span>VAT (14%):</span>
                                    <span><?php echo number_format($fee['vat_amount'], 2); ?> BWP</span>
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
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Debtor: <?php echo $messageFlow['settlement']['debtor']; ?></span>
                                    <span>→</span>
                                    <span>Creditor: <?php echo $messageFlow['settlement']['creditor']; ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #333;">
                                    <span>Amount:</span>
                                    <span class="positive"><?php echo number_format($messageFlow['settlement']['amount'], 2); ?> BWP</span>
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
                <?php foreach ($institutionNets as $institution => $nets): 
                    $netPosition = $nets['credit'] - $nets['debit'];
                ?>
                <div class="position-card">
                    <div class="position-header">
                        <span class="position-institution"><?php echo $institution; ?></span>
                        <span class="position-net <?php echo $netPosition >= 0 ? 'net-positive' : 'net-negative'; ?>">
                            <?php echo $netPosition >= 0 ? '+' : ''; ?><?php echo number_format($netPosition, 2); ?> BWP
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666;">
                        <span>Receivable: <?php echo number_format($nets['credit'], 2); ?></span>
                        <span>Payable: <?php echo number_format($nets['debit'], 2); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
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
                        <span class="edge-from"><?php echo substr($edge['from'], 0, 8); ?></span>
                        <span class="edge-arrow">→</span>
                        <span class="edge-to"><?php echo substr($edge['to'], 0, 8); ?></span>
                    </div>
                    <span class="edge-amount"><?php echo number_format($edge['amount'], 2); ?></span>
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
                    <?php foreach ($clearanceMetrics as $metric): ?>
                    <tr>
                        <td><?php echo $metric['participant_name']; ?></td>
                        <td><?php echo $metric['total_messages']; ?></td>
                        <td>
                            <span class="success-rate">
                                <?php echo number_format(($metric['successful'] / $metric['total_messages']) * 100, 1); ?>%
                            </span>
                        </td>
                        <td><?php echo round($metric['avg_response_time']); ?> ms</td>
                    </tr>
                    <?php endforeach; ?>
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
                    <strong>➡️ <?php echo strtoupper($api['direction']); ?> REQUEST to <?php echo $api['participant_name']; ?></strong>
                    <pre><?php echo json_encode($api['request_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>
                </div>
                <div class="console-response">
                    <strong>⬅️ RESPONSE (HTTP <?php echo $api['http_status_code']; ?>)</strong>
                    <pre><?php echo json_encode($api['response_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>
                </div>
                <?php if ($api['curl_error']): ?>
                <div style="color: #f00; margin-top: 0.5rem;">⚠️ CURL Error: <?php echo $api['curl_error']; ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="footer">
            <p>VOUCHMORPH · MESSAGE CLEARING HOUSE · DOUBLE-ENTRY VERIFIED · ISO20022 COMPLIANT</p>
            <p style="margin-top: 0.5rem;">CLEARED: <?php echo count($liveSwaps); ?> SWAPS · NET EXPOSURE: <?php echo number_format(array_sum(array_column($institutionNets, 'credit')) - array_sum(array_column($institutionNets, 'debit')), 2); ?> BWP</p>
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

        // Auto-refresh live feed every 10 seconds
        setTimeout(() => {
            location.reload();
        }, 10000);
    </script>
</body>
</html>

