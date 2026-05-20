<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use Domain\Services\FeeService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ForexService;

// Session management
session_start();

$allowedRoles = ['GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR', 'ADMIN'];

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

// Load country configuration
$countryCode = $_SESSION['user']['country_code'] ?? 'BW';
$countryConfig = require_once __DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/config.php";

// Database connection
$swapDB = require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

// Initialize services
$feeConfig = json_decode(file_get_contents(__DIR__ . "/../../../config/countries/" . strtolower($countryCode) . "/fees.json"), true);
$feeService = new FeeService($feeConfig, 'BWP');
$settlement = new HybridSettlementStrategy($swapDB);
$forexService = new ForexService($swapDB, $countryConfig, []);

// Date range parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$institutionFilter = $_GET['institution'] ?? null;
$currencyFilter = $_GET['currency'] ?? null;

// ============================================
// 1. FETCH SWAP TRANSACTIONS
// ============================================

$query = "
    SELECT 
        sr.swap_uuid,
        sr.status,
        sr.amount,
        sr.source_currency,
        sr.destination_currency,
        sr.exchange_rate,
        sr.source_details,
        sr.destination_details,
        sr.created_at,
        sr.updated_at,
        sr.retry_count,
        sr.original_swap_ref,
        sr.fee_breakdown,
        sr.total_cashout_fee,
        sr.forex_fee_percent,
        sr.forex_fee_amount,
        sr.metadata,
        ht.hold_reference,
        ht.status as hold_status,
        ht.created_at as hold_created_at,
        ht.debited_at,
        ht.released_at,
        sfc.total_amount as collected_fee,
        sfc.fee_type,
        sfc.vat_amount
    FROM swap_requests sr
    LEFT JOIN hold_transactions ht ON sr.swap_uuid = ht.swap_reference
    LEFT JOIN swap_fee_collections sfc ON sr.swap_uuid = sfc.swap_reference
    WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
";

$params = [
    ':date_from' => $dateFrom,
    ':date_to' => $dateTo
];

if ($institutionFilter) {
    $query .= " AND (sr.source_details->>'institution' = :institution OR sr.destination_details->>'institution' = :institution)";
    $params[':institution'] = $institutionFilter;
}

if ($currencyFilter) {
    $query .= " AND (sr.source_currency = :currency OR sr.destination_currency = :currency)";
    $params[':currency'] = $currencyFilter;
}

$query .= " ORDER BY sr.created_at DESC";

$stmt = $swapDB->prepare($query);
$stmt->execute($params);
$swaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 2. FETCH CASHOUT RETRY STATISTICS
// ============================================

$retryStmt = $swapDB->prepare("
    SELECT 
        COUNT(*) as total_retries,
        SUM(CASE WHEN free_retry_used THEN 1 ELSE 0 END) as free_retries_used,
        SUM(CASE WHEN free_retry_used = FALSE THEN 1 ELSE 0 END) as paid_retries,
        AVG(retry_count) as avg_retry_count
    FROM cashout_retry_tracking
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
");
$retryStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$retryStats = $retryStmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// 3. FETCH FEE BREAKDOWN
// ============================================

$feeBreakdownStmt = $swapDB->prepare("
    SELECT 
        fee_type,
        COUNT(*) as count,
        SUM(total_amount) as total_amount,
        SUM(vat_amount) as total_vat,
        currency
    FROM swap_fee_collections sfc
    JOIN swap_requests sr ON sfc.swap_reference = sr.swap_uuid
    WHERE DATE(sr.created_at) BETWEEN :date_from AND :date_to
    GROUP BY fee_type, currency
");
$feeBreakdownStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$feeBreakdown = $feeBreakdownStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 4. FETCH CROSS-BORDER ACTIVITY
// ============================================

$crossBorderStmt = $swapDB->prepare("
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as transaction_count,
        SUM(source_amount) as total_source_amount,
        SUM(converted_amount) as total_destination_amount,
        SUM(corridor_fee) as total_corridor_fees,
        source_currency,
        destination_currency,
        AVG(exchange_rate) as avg_exchange_rate
    FROM cross_border_ledger
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
    GROUP BY source_country, destination_country, source_currency, destination_currency
");
$crossBorderStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$crossBorderActivity = $crossBorderStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 5. FETCH CORRIDOR ACTIVITY
// ============================================

$corridorStmt = $swapDB->prepare("
    SELECT 
        source_country,
        destination_country,
        COUNT(*) as settlement_count,
        SUM(source_amount) as total_settled,
        SUM(corridor_fee) as total_fees
    FROM corridor_settlement_ledger
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
    GROUP BY source_country, destination_country
");
$corridorStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$corridorActivity = $corridorStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 6. FETCH HOOK ACTIVITY
// ============================================

$hookStmt = $swapDB->prepare("
    SELECT 
        COUNT(*) as total_hooks,
        COUNT(DISTINCT user_identifier) as unique_users,
        SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active_hooks
    FROM user_hooks
    WHERE DATE(created_at) BETWEEN :date_from AND :date_to
");
$hookStmt->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$hookStats = $hookStmt->fetch(PDO::FETCH_ASSOC);

// ============================================
// 7. CALCULATE TOTALS
// ============================================

$totalVolume = array_sum(array_column($swaps, 'amount'));
$totalSwaps = count($swaps);
$successfulSwaps = count(array_filter($swaps, fn($s) => $s['status'] === 'completed'));
$failedSwaps = count(array_filter($swaps, fn($s) => $s['status'] === 'failed'));
$pendingSwaps = count(array_filter($swaps, fn($s) => $s['status'] === 'pending'));
$cancelledSwaps = count(array_filter($swaps, fn($s) => $s['status'] === 'cancelled'));

$totalFeesCollected = array_sum(array_column($feeBreakdown, 'total_amount'));
$totalVatCollected = array_sum(array_column($feeBreakdown, 'total_vat'));

// Calculate fee breakdown by type
$feeByType = [];
foreach ($feeBreakdown as $fee) {
    $feeByType[$fee['fee_type']] = ($feeByType[$fee['fee_type']] ?? 0) + $fee['total_amount'];
}

// Calculate FX activity
$fxTransactions = array_filter($swaps, fn($s) => $s['source_currency'] !== $s['destination_currency']);
$totalFxVolume = array_sum(array_column($fxTransactions, 'amount'));
$totalForexFees = array_sum(array_column($fxTransactions, 'forex_fee_amount'));

// ============================================
// 8. CSV EXPORT
// ============================================

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_reconciliation_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['VOUCHMORPH DAILY RECONCILIATION REPORT']);
    fputcsv($output, ['Date Range', $dateFrom, 'to', $dateTo]);
    fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Swaps', $totalSwaps]);
    fputcsv($output, ['Successful Swaps', $successfulSwaps]);
    fputcsv($output, ['Failed Swaps', $failedSwaps]);
    fputcsv($output, ['Pending Swaps', $pendingSwaps]);
    fputcsv($output, ['Cancelled Swaps', $cancelledSwaps]);
    fputcsv($output, ['Total Volume', number_format($totalVolume, 2)]);
    fputcsv($output, ['Total Fees Collected', number_format($totalFeesCollected, 2)]);
    fputcsv($output, ['Total VAT', number_format($totalVatCollected, 2)]);
    fputcsv($output, []);
    
    // Fee Breakdown
    fputcsv($output, ['FEE BREAKDOWN']);
    fputcsv($output, ['Fee Type', 'Total Amount', 'Currency']);
    foreach ($feeBreakdown as $fee) {
        fputcsv($output, [$fee['fee_type'], number_format($fee['total_amount'], 2), $fee['currency']]);
    }
    fputcsv($output, []);
    
    // Cross Border Activity
    fputcsv($output, ['CROSS-BORDER ACTIVITY']);
    fputcsv($output, ['From', 'To', 'Count', 'Volume', 'Corridor Fees']);
    foreach ($crossBorderActivity as $cb) {
        fputcsv($output, [
            $cb['source_country'], 
            $cb['destination_country'], 
            $cb['transaction_count'],
            number_format($cb['total_source_amount'], 2),
            number_format($cb['total_corridor_fees'] ?? 0, 2)
        ]);
    }
    fputcsv($output, []);
    
    // Transactions Detail
    fputcsv($output, ['TRANSACTION DETAILS']);
    fputcsv($output, ['Swap Ref', 'Status', 'Amount', 'Source Currency', 'Dest Currency', 'Fee', 'Created At']);
    foreach ($swaps as $swap) {
        fputcsv($output, [
            $swap['swap_uuid'],
            $swap['status'],
            number_format($swap['amount'], 2),
            $swap['source_currency'],
            $swap['destination_currency'],
            number_format($swap['collected_fee'] ?? 0, 2),
            $swap['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

// ============================================
// 9. GET INSTITUTIONS LIST FOR FILTER
// ============================================

$institutionStmt = $swapDB->prepare("
    SELECT DISTINCT 
        source_details->>'institution' as institution
    FROM swap_requests
    WHERE source_details->>'institution' IS NOT NULL
    UNION
    SELECT DISTINCT 
        destination_details->>'institution' as institution
    FROM swap_requests
    WHERE destination_details->>'institution' IS NOT NULL
");
$institutionStmt->execute();
$institutions = $institutionStmt->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reconciliation Report - VouchMorph</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #333;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dashboard-header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .dashboard-header p {
            opacity: 0.8;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
        }
        
        .filter-group input, .filter-group select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #1a1a2e;
        }
        
        .stat-card .sub {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        
        .section {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
        }
        
        .section-content {
            padding: 20px;
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-bar {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .filter-group input, .filter-group select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div>
                <h1>📊 Daily Reconciliation Report</h1>
                <p>VouchMorph Settlement & Transaction Reconciliation</p>
            </div>
            <div class="export-buttons">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">📥 Export CSV</a>
                <a href="daily_reconciliations.php" class="btn btn-secondary">🔄 Reset</a>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-group">
                <label>Institution</label>
                <select name="institution" id="institution">
                    <option value="">All Institutions</option>
                    <?php foreach ($institutions as $inst): ?>
                        <option value="<?= htmlspecialchars($inst) ?>" <?= $institutionFilter === $inst ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Currency</label>
                <select name="currency" id="currency">
                    <option value="">All Currencies</option>
                    <option value="BWP" <?= $currencyFilter === 'BWP' ? 'selected' : '' ?>>BWP - Pula</option>
                    <option value="ZAR" <?= $currencyFilter === 'ZAR' ? 'selected' : '' ?>>ZAR - Rand</option>
                    <option value="USD" <?= $currencyFilter === 'USD' ? 'selected' : '' ?>>USD - Dollar</option>
                    <option value="EUR" <?= $currencyFilter === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                    <option value="NGN" <?= $currencyFilter === 'NGN' ? 'selected' : '' ?>>NGN - Naira</option>
                    <option value="KES" <?= $currencyFilter === 'KES' ? 'selected' : '' ?>>KES - Shilling</option>
                </select>
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Swaps</h3>
                <div class="value"><?= number_format($totalSwaps) ?></div>
                <div class="sub"><?= $successfulSwaps ?> successful</div>
            </div>
            <div class="stat-card">
                <h3>Total Volume</h3>
                <div class="value"><?= number_format($totalVolume, 2) ?></div>
                <div class="sub">Across all currencies</div>
            </div>
            <div class="stat-card">
                <h3>Fees Collected</h3>
                <div class="value"><?= number_format($totalFeesCollected, 2) ?></div>
                <div class="sub">+ <?= number_format($totalVatCollected, 2) ?> VAT</div>
            </div>
            <div class="stat-card">
                <h3>Cashout Retries</h3>
                <div class="value"><?= number_format($retryStats['total_retries'] ?? 0) ?></div>
                <div class="sub"><?= number_format($retryStats['free_retries_used'] ?? 0) ?> free retries</div>
            </div>
            <div class="stat-card">
                <h3>FX Volume</h3>
                <div class="value"><?= number_format($totalFxVolume, 2) ?></div>
                <div class="sub">Forex Fees: <?= number_format($totalForexFees, 2) ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Hooks</h3>
                <div class="value"><?= number_format($hookStats['active_hooks'] ?? 0) ?></div>
                <div class="sub"><?= number_format($hookStats['unique_users'] ?? 0) ?> users</div>
            </div>
        </div>
        
        <!-- Fee Breakdown Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('feeBreakdown')">
                <h2>💰 Fee Breakdown</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="feeBreakdown">
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th>Count</th>
                            <th>Total Amount</th>
                            <th>VAT</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($feeBreakdown)): ?>
                            <?php foreach ($feeBreakdown as $fee): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fee['fee_type']) ?></td>
                                    <td><?= number_format($fee['count']) ?></td>
                                    <td><strong><?= number_format($fee['total_amount'], 2) ?></strong></td>
                                    <td><?= number_format($fee['total_vat'], 2) ?></td>
                                    <td><?= htmlspecialchars($fee['currency']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No fee data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Cross-Border Activity Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('crossBorder')">
                <h2>🌍 Cross-Border Activity</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="crossBorder">
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Transactions</th>
                            <th>Volume (Source)</th>
                            <th>Volume (Dest)</th>
                            <th>Corridor Fees</th>
                            <th>Avg Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($crossBorderActivity)): ?>
                            <?php foreach ($crossBorderActivity as $cb): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cb['source_country']) ?></td>
                                    <td><?= htmlspecialchars($cb['destination_country']) ?></td>
                                    <td><?= number_format($cb['transaction_count']) ?></td>
                                    <td><?= number_format($cb['total_source_amount'], 2) ?> <?= htmlspecialchars($cb['source_currency']) ?></td>
                                    <td><?= number_format($cb['total_destination_amount'], 2) ?> <?= htmlspecialchars($cb['destination_currency']) ?></td>
                                    <td><?= number_format($cb['total_corridor_fees'] ?? 0, 2) ?></td>
                                    <td><?= number_format($cb['avg_exchange_rate'], 4) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center;">No cross-border activity</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Transactions Table -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('transactions')">
                <h2>📋 Transaction Details</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="transactions">
                <table>
                    <thead>
                        <tr>
                            <th>Swap Reference</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>From</th>
                            <th>To</th>
                            <th>FX Rate</th>
                            <th>Fee</th>
                            <th>Retry</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($swaps)): ?>
                            <?php foreach ($swaps as $swap): 
                                $sourceInst = json_decode($swap['source_details'], true)['institution'] ?? 'N/A';
                                $destInst = json_decode($swap['destination_details'], true)['institution'] ?? 'N/A';
                                $statusClass = match($swap['status']) {
                                    'completed' => 'status-completed',
                                    'failed' => 'status-failed',
                                    'pending' => 'status-pending',
                                    'cancelled' => 'status-cancelled',
                                    default => ''
                                };
                            ?>
                                <tr>
                                    <td><code><?= substr(htmlspecialchars($swap['swap_uuid']), 0, 16) ?>...</code></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($swap['status']) ?></span></td>
                                    <td><strong><?= number_format($swap['amount'], 2) ?></strong> <?= htmlspecialchars($swap['source_currency']) ?></td>
                                    <td><?= htmlspecialchars($sourceInst) ?></td>
                                    <td><?= htmlspecialchars($destInst) ?></td>
                                    <td><?= $swap['exchange_rate'] ? number_format($swap['exchange_rate'], 4) : '-' ?></td>
                                    <td><?= number_format($swap['collected_fee'] ?? 0, 2) ?></td>
                                    <td><?= $swap['retry_count'] ?? 0 ?></td>
                                    <td><?= date('Y-m-d H:i', strtotime($swap['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;">No transactions found for selected period</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Corridor Activity Section -->
        <div class="section">
            <div class="section-header" onclick="toggleSection('corridor')">
                <h2>🔄 Corridor Settlement Activity</h2>
                <span>▼</span>
            </div>
            <div class="section-content" id="corridor">
                <table>
                    <thead>
                        <tr>
                            <th>Source Country</th>
                            <th>Destination Country</th>
                            <th>Settlements</th>
                            <th>Total Settled</th>
                            <th>Corridor Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($corridorActivity)): ?>
                            <?php foreach ($corridorActivity as $corridor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($corridor['source_country']) ?></td>
                                    <td><?= htmlspecialchars($corridor['destination_country']) ?></td>
                                    <td><?= number_format($corridor['settlement_count']) ?></td>
                                    <td><?= number_format($corridor['total_settled'], 2) ?></td>
                                    <td><?= number_format($corridor['total_fees'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No corridor activity</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function applyFilters() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const institution = document.getElementById('institution').value;
            const currency = document.getElementById('currency').value;
            
            const params = new URLSearchParams();
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (institution) params.append('institution', institution);
            if (currency) params.append('currency', currency);
            
            window.location.href = '?' + params.toString();
        }
        
        function toggleSection(sectionId) {
            const section = document.getElementById(sectionId);
            const header = section.previousElementSibling;
            const arrow = header.querySelector('span');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                arrow.textContent = '▼';
            } else {
                section.style.display = 'none';
                arrow.textContent = '▶';
            }
        }
        
        // Set today's date as default if not set
        const dateFromInput = document.getElementById('date_from');
        const dateToInput = document.getElementById('date_to');
        
        if (!dateFromInput.value) {
            const today = new Date().toISOString().split('T')[0];
            dateFromInput.value = today;
            dateToInput.value = today;
        }
        
        // Initialize all sections as visible
        document.querySelectorAll('.section-content').forEach(section => {
            section.style.display = 'block';
        });
    </script>
</body>
</html>

<?php
// Log the report generation
$logFile = __DIR__ . '/../../../storage/logs/reconciliation.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Daily reconciliation report generated for {$dateFrom} to {$dateTo} by {$_SESSION['user']['username'] ?? 'unknown'}\n", FILE_APPEND);
?>
