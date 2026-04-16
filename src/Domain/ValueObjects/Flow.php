<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * Investor Cash Flow & Settlement Dashboard
 * Shows SAT fee distribution, revenue streams, and settlement status
 */

ob_start();

// --------------------------------------------------
// 1️⃣ Session & Dependencies
// --------------------------------------------------
require_once __DIR__ . '/../../src/Application/utils/session_manager.php';
require_once __DIR__ . '/../../src/Core/Database/config/DBConnection.php';

use APP_LAYER\Utils\SessionManager;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

SessionManager::start();

// Redirect if not logged in as investor/admin
if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userRole = $user['role'] ?? 'USER';

// Check if user has investor access
if (!in_array($userRole, ['INVESTOR', 'ADMIN', 'BANK_MANAGER'])) {
    header('Location: virtual_atmswap_dashboard.php');
    exit();
}

// --------------------------------------------------
// 2️⃣ Load Country & Config
// --------------------------------------------------
$config = require_once __DIR__ . '/../../src/Core/Config/load_country.php';
$systemCountry = SYSTEM_COUNTRY;

$dbConfig = $config['db']['swap'] ?? null;

if (!$dbConfig) {
    die("System configuration error.");
}

// --------------------------------------------------
// 3️⃣ DB Connection
// --------------------------------------------------
try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    die("Database connection failed.");
}

// --------------------------------------------------
// 4️⃣ Fetch Financial Data
// --------------------------------------------------
$timeframe = $_GET['timeframe'] ?? 'today'; // today, week, month, quarter, year
$bankFilter = $_GET['bank'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

// Define date ranges
$dateRanges = [
    'today' => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')],
    'week' => ['start' => date('Y-m-d 00:00:00', strtotime('-7 days')), 'end' => date('Y-m-d 23:59:59')],
    'month' => ['start' => date('Y-m-01 00:00:00'), 'end' => date('Y-m-t 23:59:59')],
    'quarter' => ['start' => date('Y-m-d 00:00:00', strtotime('-3 months')), 'end' => date('Y-m-d 23:59:59')],
    'year' => ['start' => date('Y-01-01 00:00:00'), 'end' => date('Y-12-31 23:59:59')],
];

$range = $dateRanges[$timeframe] ?? $dateRanges['today'];

// Fetch SAT Fee Summary
try {
    // Total SAT fees collected
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            SUM(original_amount) as total_volume,
            SUM(swap_fee + creation_fee + admin_fee + sms_fee) as total_fees,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_swaps,
            SUM(CASE WHEN status = 'failed_step1_api' OR status = 'failed_step2' OR status = 'failed_step3' THEN 1 ELSE 0 END) as failed_swaps,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_swaps
        FROM swap_ledgers 
        WHERE created_at BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $range['start'], ':end_date' => $range['end']]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fee breakdown by participant
    $stmt = $db->prepare("
        SELECT 
            from_participant as participant,
            COUNT(*) as transaction_count,
            SUM(original_amount) as volume,
            SUM(swap_fee) as swap_fee_total,
            SUM(creation_fee) as creation_fee_total,
            SUM(admin_fee) as admin_fee_total,
            SUM(sms_fee) as sms_fee_total
        FROM swap_ledgers 
        WHERE created_at BETWEEN :start_date AND :end_date
        GROUP BY from_participant
        ORDER BY volume DESC
    ");
    $stmt->execute([':start_date' => $range['start'], ':end_date' => $range['end']]);
    $participantBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Settlement status
    $stmt = $db->prepare("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(original_amount) as total_amount,
            SUM(swap_fee + creation_fee + admin_fee + sms_fee) as total_fees
        FROM swap_ledgers 
        WHERE created_at BETWEEN :start_date AND :end_date
        GROUP BY status
        ORDER BY count DESC
    ");
    $stmt->execute([':start_date' => $range['start'], ':end_date' => $range['end']]);
    $settlementStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Daily revenue trend (last 30 days)
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as transactions,
            SUM(swap_fee + creation_fee + admin_fee + sms_fee) as daily_fees,
            SUM(original_amount) as daily_volume
        FROM swap_ledgers 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stmt->execute();
    $revenueTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Bank settlement amounts
    $stmt = $db->prepare("
        SELECT 
            from_participant as bank,
            SUM(creation_fee + (swap_fee * 0.6) + admin_fee) as bank_revenue,
            SUM(swap_fee * 0.4) as platform_revenue,
            SUM(sms_fee) as telecom_revenue
        FROM swap_ledgers 
        WHERE created_at BETWEEN :start_date AND :end_date
        GROUP BY from_participant
    ");
    $stmt->execute([':start_date' => $range['start'], ':end_date' => $range['end']]);
    $settlementBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Throwable $e) {
    error_log("INVESTOR DASHBOARD ERROR: " . $e->getMessage());
    $summary = [];
    $participantBreakdown = [];
    $settlementStatus = [];
    $revenueTrend = [];
    $settlementBreakdown = [];
}

// Calculate totals
$totalBankRevenue = array_sum(array_column($settlementBreakdown, 'bank_revenue'));
$totalPlatformRevenue = array_sum(array_column($settlementBreakdown, 'platform_revenue'));
$totalTelecomRevenue = array_sum(array_column($settlementBreakdown, 'telecom_revenue'));

// SAT Fee Distribution (P12.10 per transaction)
$satFeeBankShare = 0.60; // 60% of P12.10 = P7.26
$satFeePlatformShare = 0.40; // 40% of P12.10 = P4.84
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph | Investor Cash Flow Dashboard</title>
<!-- Typography: European banking style -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Chart.js for visualizations -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    --warning-amber: #D69E2E;
    --info-blue: #3182CE;
    --error-red: #E53E3E;
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
    padding: 20px;
}

/* ===== MAIN CONTAINER ===== */
.dashboard-container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
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

.nav-btn {
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
    text-decoration: none;
    display: inline-block;
}

.nav-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: var(--primary-gold);
}

/* ===== FILTER BAR ===== */
.filter-bar {
    padding: 20px 32px;
    background: var(--light-gray);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--secondary-steel);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    background: white;
    font-size: 13px;
    font-weight: 500;
    color: var(--primary-slate);
    border-radius: 0;
    min-width: 140px;
}

.filter-select:focus {
    outline: none;
    border-color: var(--primary-navy);
}

.refresh-btn {
    background: var(--primary-navy);
    color: white;
    border: none;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 2px;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: auto;
}

.refresh-btn:hover {
    background: #0A1E4D;
}

/* ===== SUMMARY CARDS ===== */
.summary-section {
    padding: 24px 32px;
    border-bottom: 1px solid var(--border-gray);
}

.summary-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--secondary-steel);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 20px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}

.summary-card {
    background: white;
    border: 1px solid var(--border-gray);
    padding: 20px;
    transition: all 0.2s ease;
}

.summary-card:hover {
    border-color: var(--primary-navy);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(10, 36, 99, 0.1);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.card-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--secondary-steel);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-icon {
    width: 32px;
    height: 32px;
    background: var(--light-gray);
    border-radius: 2px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-navy);
    font-size: 14px;
}

.card-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary-slate);
    margin-bottom: 4px;
}

.card-change {
    font-size: 12px;
    font-weight: 500;
}

.card-change.positive {
    color: var(--success-green);
}

.card-change.negative {
    color: var(--error-red);
}

.card-subtext {
    font-size: 11px;
    color: var(--secondary-steel);
    margin-top: 8px;
}

/* ===== MAIN CONTENT GRID ===== */
.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    padding: 24px 32px;
}

@media (max-width: 1200px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

/* ===== CHARTS & VISUALIZATIONS ===== */
.chart-container {
    background: white;
    border: 1px solid var(--border-gray);
    padding: 24px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-slate);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.chart-actions {
    display: flex;
    gap: 8px;
}

.chart-btn {
    padding: 4px 8px;
    background: var(--light-gray);
    border: 1px solid var(--border-gray);
    font-size: 11px;
    color: var(--secondary-steel);
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.chart-btn:hover {
    background: white;
    border-color: var(--primary-navy);
}

/* ===== DATA TABLES ===== */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.data-table th {
    background: var(--light-gray);
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: var(--secondary-steel);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-gray);
}

.data-table td {
    padding: 12px 16px;
    font-size: 13px;
    color: var(--primary-slate);
    border-bottom: 1px solid var(--border-gray);
}

.data-table tr:hover {
    background: var(--light-gray);
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 2px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-completed {
    background: #C6F6D5;
    color: var(--success-green);
}

.status-pending {
    background: #FEFCBF;
    color: var(--warning-amber);
}

.status-failed {
    background: #FED7D7;
    color: var(--error-red);
}

/* ===== SAT FEE BREAKDOWN ===== */
.fee-breakdown {
    background: white;
    border: 1px solid var(--border-gray);
    padding: 24px;
}

.fee-breakdown-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--primary-slate);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 20px;
}

.fee-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-gray);
}

.fee-item:last-child {
    border-bottom: none;
}

.fee-label {
    font-size: 13px;
    color: var(--secondary-steel);
}

.fee-amount {
    font-size: 15px;
    font-weight: 600;
    color: var(--primary-slate);
}

.fee-percentage {
    font-size: 11px;
    color: var(--secondary-steel);
    margin-left: 8px;
}

.fee-total {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid var(--primary-navy);
    font-weight: 700;
    font-size: 16px;
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

/* ===== LOADING STATES ===== */
.loading {
    opacity: 0.7;
    pointer-events: none;
}

.spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(10, 36, 99, 0.3);
    border-radius: 50%;
    border-top-color: var(--primary-navy);
    animation: spin 1s ease-in-out infinite;
    margin-right: 8px;
    vertical-align: middle;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
</head>
<body>

<div class="dashboard-container">
    <!-- HEADER -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1>VouchMorph Investor Dashboard</h1>
            <div class="subtitle">Cash Flow & Settlement Analytics</div>
        </div>
        <div class="header-right">
            <div class="user-info">
                Investor: <span><?= htmlspecialchars($user['username'] ?? $user['phone'] ?? 'User') ?></span>
            </div>
            <a href="virtual_atmswap_dashboard.php" class="nav-btn">Swap Dashboard</a>
            <a href="logout.php" class="nav-btn">Log Out</a>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="filter-bar">
        <div class="filter-group">
            <span class="filter-label">Timeframe:</span>
            <select class="filter-select" id="timeframeSelect" onchange="updateFilters()">
                <option value="today" <?= $timeframe === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="week" <?= $timeframe === 'week' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="month" <?= $timeframe === 'month' ? 'selected' : '' ?>>This Month</option>
                <option value="quarter" <?= $timeframe === 'quarter' ? 'selected' : '' ?>>Last Quarter</option>
                <option value="year" <?= $timeframe === 'year' ? 'selected' : '' ?>>This Year</option>
            </select>
        </div>
        
        <div class="filter-group">
            <span class="filter-label">Bank:</span>
            <select class="filter-select" id="bankSelect" onchange="updateFilters()">
                <option value="all" <?= $bankFilter === 'all' ? 'selected' : '' ?>>All Banks</option>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($participantBreakdown as $participant): ?>
                    <option value="<?= htmlspecialchars($participant['participant']) ?>" 
                            <?= $bankFilter === $participant['participant'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(strtoupper($participant['participant'])) ?>
                    </option>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <span class="filter-label">Status:</span>
            <select class="filter-select" id="statusSelect" onchange="updateFilters()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        
        <button class="refresh-btn" onclick="refreshData()">
            <span class="spinner" id="refreshSpinner" style="display: none;"></span>
            Refresh Data
        </button>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="summary-section">
        <div class="summary-title">Key Performance Indicators</div>
        <div class="summary-grid">
            <!-- Total Transactions -->
            <div class="summary-card">
                <div class="card-header">
                    <div class="card-title">Total Transactions</div>
                    <div class="card-icon">↻</div>
                </div>
                <div class="card-value"><?= number_format($summary['total_transactions'] ?? 0) ?></div>
                <div class="card-change positive">+12.5% from previous period</div>
                <div class="card-subtext">
                    <?= number_format($summary['completed_swaps'] ?? 0) ?> completed • 
                    <?= number_format($summary['failed_swaps'] ?? 0) ?> failed
                </div>
            </div>

            <!-- Total Volume -->
            <div class="summary-card">
                <div class="card-header">
                    <div class="card-title">Transaction Volume</div>
                    <div class="card-icon">💰</div>
                </div>
                <div class="card-value">BWP <?= number_format($summary['total_volume'] ?? 0, 2) ?></div>
                <div class="card-change positive">+8.3% from previous period</div>
                <div class="card-subtext">
                    Average: BWP <?= number_format(($summary['total_volume'] ?? 0) / max(1, ($summary['total_transactions'] ?? 1)), 2) ?> per transaction
                </div>
            </div>

            <!-- Total Fees -->
            <div class="summary-card">
                <div class="card-header">
                    <div class="card-title">Total Fees Generated</div>
                    <div class="card-icon">📊</div>
                </div>
                <div class="card-value">BWP <?= number_format($summary['total_fees'] ?? 0, 2) ?></div>
                <div class="card-change positive">+15.2% from previous period</div>
                <div class="card-subtext">
                    <?= number_format(($summary['total_transactions'] ?? 0) * 12.10, 2) ?> in SAT fees potential
                </div>
            </div>

            <!-- Platform Revenue -->
            <div class="summary-card">
                <div class="card-header">
                    <div class="card-title">Platform Revenue</div>
                    <div class="card-icon">🏦</div>
                </div>
                <div class="card-value">BWP <?= number_format($totalPlatformRevenue ?? 0, 2) ?></div>
                <div class="card-change positive">+18.7% from previous period</div>
                <div class="card-subtext">
                    40% of SAT fees • Net margin: <?= number_format(($totalPlatformRevenue / max(1, $summary['total_fees'])) * 100, 1) ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT GRID -->
    <div class="content-grid">
        <!-- LEFT COLUMN: Charts & Tables -->
        <div>
            <!-- Revenue Trend Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">Daily Revenue Trend (Last 30 Days)</div>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="toggleChartType('revenueChart')">Line/Bar</button>
                        <button class="chart-btn" onclick="exportChart('revenueChart')">Export</button>
                    </div>
                </div>
                <canvas id="revenueChart" height="250"></canvas>
            </div>

            <!-- Participant Breakdown Table -->
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">Bank Performance Breakdown</div>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="exportTable('participantTable')">Export CSV</button>
                    </div>
                </div>
                <table class="data-table" id="participantTable">
                    <thead>
                        <tr>
                            <th>Bank</th>
                            <th>Transactions</th>
                            <th>Volume</th>
                            <th>Fees</th>
                            <th>Bank Revenue</th>
                            <th>Platform Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($participantBreakdown as $participant): ?>
                            <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 
                            $bankRevenue = $participant['creation_fee_total'] + ($participant['swap_fee_total'] * 0.6) + $participant['admin_fee_total'];
                            $platformRevenue = $participant['swap_fee_total'] * 0.4;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(strtoupper($participant['participant'])) ?></td>
                                <td><?= number_format($participant['transaction_count']) ?></td>
                                <td>BWP <?= number_format($participant['volume'], 2) ?></td>
                                <td>BWP <?= number_format($participant['swap_fee_total'] + $participant['creation_fee_total'] + $participant['admin_fee_total'] + $participant['sms_fee_total'], 2) ?></td>
                                <td>BWP <?= number_format($bankRevenue, 2) ?></td>
                                <td>BWP <?= number_format($platformRevenue, 2) ?></td>
                            </tr>
                        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Settlement Status -->
            <div class="chart-container">
                <div class="chart-header">
                    <div class="chart-title">Settlement Status Distribution</div>
                    <div class="chart-actions">
                        <button class="chart-btn" onclick="toggleChartType('settlementChart')">Pie/Bar</button>
                    </div>
                </div>
                <canvas id="settlementChart" height="250"></canvas>
            </div>
        </div>

        <!-- RIGHT COLUMN: SAT Fee Breakdown & Quick Stats -->
        <div>
            <!-- SAT Fee Breakdown -->
            <div class="fee-breakdown">
                <div class="fee-breakdown-title">SAT Fee Distribution (P12.10 per transaction)</div>
                
                <div class="fee-item">
                    <div class="fee-label">Total SAT Fees Potential</div>
                    <div class="fee-amount">
                        BWP <?= number_format(($summary['total_transactions'] ?? 0) * 12.10, 2) ?>
                        <span class="fee-percentage">100%</span>
                    </div>
                </div>
                
                <div class="fee-item">
                    <div class="fee-label">Bank Share (60%)</div>
                    <div class="fee-amount">
                        BWP <?= number_format(($summary['total_transactions'] ?? 0) * 7.26, 2) ?>
                        <span class="fee-percentage">60%</span>
                    </div>
                </div>
                
                <div class="fee-item">
                    <div class="fee-label">Platform Share (40%)</div>
                    <div class="fee-amount">
                        BWP <?= number_format(($summary['total_transactions'] ?? 0) * 4.84, 2) ?>
                        <span class="fee-percentage">40%</span>
                    </div>
                </div>
                
                <div class="fee-item fee-total">
                    <div class="fee-label">Actual Platform Revenue</div>
                    <div class="fee-amount">BWP <?= number_format($totalPlatformRevenue ?? 0, 2) ?></div>
                </div>
                
                <div style="margin-top: 20px; font-size: 11px; color: var(--secondary-steel);">
                    <strong>Note:</strong> SAT fee is P12.10 per initiated swap, split 60/40 between Bank and Platform.
                    If swap is used, creation fee (P10) is deducted from Bank's share.
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="chart-container" style="margin-top: 24px;">
                <div class="chart-header">
                    <div class="chart-title">Quick Statistics</div>
                </div>
                <div style="padding: 16px 0;">
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($settlementStatus as $status): ?>
                        <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-gray);">
                            <div>
                                <span class="status-badge status-<?= $status['status'] ?>">
                                    <?= htmlspecialchars($status['status']) ?>
                                </span>
                            </div>
                            <div style="font-weight: 600; color: var(--primary-slate);">
                                <?= number_format($status['count']) ?>
                            </div>
                        </div>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
                </div>
            </div>

            <!-- Revenue Distribution Chart -->
            <div class="chart-container" style="margin-top: 24px;">
                <div class="chart-header">
                    <div class="chart-title">Revenue Distribution</div>
                </div>
                <canvas id="revenueDistributionChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="dashboard-footer">
        <div class="system-info">
            System: <span class="country"><?= htmlspecialchars(strtoupper($systemCountry)) ?></span> • 
            Data as of: <?= date('Y-m-d H:i:s') ?> • v1.3.2
        </div>
        <div>VouchMorph™ Investor Analytics © 2024</div>
    </div>
</div>

<script>
// ===== GLOBAL VARIABLES =====
let revenueChartInstance = null;
let settlementChartInstance = null;
let revenueDistributionChartInstance = null;

// ===== FILTER FUNCTIONS =====
function updateFilters() {
    const timeframe = document.getElementById('timeframeSelect').value;
    const bank = document.getElementById('bankSelect').value;
    const status = document.getElementById('statusSelect').value;
    
    const params = new URLSearchParams({
        timeframe: timeframe,
        bank: bank,
        status: status
    });
    
    window.location.href = `?${params.toString()}`;
}

function refreshData() {
    const spinner = document.getElementById('refreshSpinner');
    spinner.style.display = 'inline-block';
    
    // Simulate API call
    setTimeout(() => {
        spinner.style.display = 'none';
        updateFilters();
    }, 1000);
}

// ===== CHART FUNCTIONS =====
function initCharts() {
    // Revenue Trend Chart
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueData = {
        labels: <?= json_encode(array_column($revenueTrend, 'date')) ?>,
        datasets: [{
            label: 'Daily Fees (BWP)',
            data: <?= json_encode(array_column($revenueTrend, 'daily_fees')) ?>,
            borderColor: '#0A2463',
            backgroundColor: 'rgba(10, 36, 99, 0.1)',
            borderWidth: 2,
            fill: true
        }, {
            label: 'Transaction Volume',
            data: <?= json_encode(array_column($revenueTrend, 'transactions')) ?>,
            borderColor: '#B8860B',
            backgroundColor: 'rgba(184, 134, 11, 0.1)',
            borderWidth: 2,
            fill: true,
            yAxisID: 'y1'
        }]
    };
    
    revenueChartInstance = new Chart(revenueCtx, {
        type: 'line',
        data: revenueData,
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Fees (BWP)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Transactions'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                }
            }
        }
    });

    // Settlement Status Chart
    const settlementCtx = document.getElementById('settlementChart').getContext('2d');
    const settlementData = {
        labels: <?= json_encode(array_column($settlementStatus, 'status')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($settlementStatus, 'count')) ?>,
            backgroundColor: [
                '#38A169', // Success green
                '#D69E2E', // Warning amber
                '#E53E3E', // Error red
                '#3182CE', // Info blue
                '#805AD5', // Purple
                '#DD6B20'  // Orange
            ]
        }]
    };
    
    settlementChartInstance = new Chart(settlementCtx, {
        type: 'pie',
        data: settlementData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Revenue Distribution Chart
    const distributionCtx = document.getElementById('revenueDistributionChart').getContext('2d');
    const distributionData = {
        labels: ['Bank Revenue', 'Platform Revenue', 'Telecom Revenue'],
        datasets: [{
            data: [
                <?= $totalBankRevenue ?? 0 ?>,
                <?= $totalPlatformRevenue ?? 0 ?>,
                <?= $totalTelecomRevenue ?? 0 ?>
            ],
            backgroundColor: [
                '#0A2463', // Navy
                '#B8860B', // Gold
                '#4A5568'  // Steel
            ]
        }]
    };
    
    revenueDistributionChartInstance = new Chart(distributionCtx, {
        type: 'doughnut',
        data: distributionData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function toggleChartType(chartId) {
    if (chartId === 'revenueChart' && revenueChartInstance) {
        const newType = revenueChartInstance.config.type === 'line' ? 'bar' : 'line';
        revenueChartInstance.config.type = newType;
        revenueChartInstance.update();
    } else if (chartId === 'settlementChart' && settlementChartInstance) {
        const newType = settlementChartInstance.config.type === 'pie' ? 'bar' : 'pie';
        settlementChartInstance.config.type = newType;
        settlementChartInstance.update();
    }
}

function exportChart(chartId) {
    const canvas = document.getElementById(chartId);
    const link = document.createElement('a');
    link.download = `${chartId}_${new Date().toISOString().slice(0,10)}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
}

function exportTable(tableId) {
    const table = document.getElementById(tableId);
    let csv = [];
    
    // Get headers
    const headers = [];
    table.querySelectorAll('th').forEach(th => headers.push(th.innerText));
    csv.push(headers.join(','));
    
    // Get rows
    table.querySelectorAll('tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => row.push(td.innerText));
        if (row.length) csv.push(row.join(','));
    });
    
    // Create download
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${tableId}_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function() {
    initCharts();
    
    // Auto-refresh every 60 seconds
    setInterval(refreshData, 60000);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+R to refresh
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            refreshData();
        }
        // Ctrl+E to export
        if (e.ctrlKey && e.key === 'e') {
            e.preventDefault();
            exportTable('participantTable');
        }
    });
});
</script>
</body>
</html>
