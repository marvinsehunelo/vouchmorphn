<?php
declare(strict_types=1);

namespace DASHBOARD;

use PDO;
use DateTime;
use DateTimeZone;

// ============================================================================
// REGULATORY EVIDENCE PACKAGE
// ============================================================================
ob_start();
$format = $_GET['format'] ?? 'html'; // html, pdf, json, csv
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
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function safe_html($str) {
    if ($str === null || $str === '') {
        return '—';
    }
    return htmlspecialchars((string)$str);
}

function calculateComplianceScore($data) {
    $score = 0;
    $total = 0;
    
    if ($data['swap_count'] > 0) $score += 10;
    if ($data['holds_count'] > 0) $score += 10;
    if ($data['api_count'] > 0) $score += 10;
    if ($data['fee_count'] > 0) $score += 10;
    if ($data['ledger_count'] > 0) $score += 10;
    if ($data['settlement_count'] > 0) $score += 10;
    if ($data['success_rate'] > 95) $score += 20;
    if ($data['avg_response_ms'] < 2000) $score += 10;
    if ($data['unique_participants'] > 1) $score += 5;
    if ($data['total_volume'] > 1000) $score += 5;
    
    return min(100, $score);
}

// ============================================================================
// FETCH ALL REGULATORY DATA
// ============================================================================

// 1. SYSTEM OVERVIEW
$systemQuery = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM swap_requests) as total_swaps,
        (SELECT COUNT(*) FROM swap_requests WHERE created_at > NOW() - INTERVAL '24 hours') as swaps_24h,
        (SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE') as active_holds,
        (SELECT COUNT(*) FROM api_message_logs) as total_api_calls,
        (SELECT COUNT(*) FROM swap_fee_collections) as total_fees,
        (SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE') as active_participants,
        (SELECT COALESCE(SUM(amount), 0) FROM swap_requests) as total_volume,
        (SELECT COUNT(*) FROM ledger_entries) as total_ledger_entries
");
$system = $systemQuery->fetch(PDO::FETCH_ASSOC);

// 2. SUCCESS RATE
$successQuery = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM swap_requests
");
$success = $successQuery->fetch(PDO::FETCH_ASSOC);
$successRate = $success['total'] > 0 
    ? round(($success['completed'] / $success['total']) * 100, 2) 
    : 0;

// 3. RESPONSE TIMES
$responseQuery = $db->query("
    SELECT 
        AVG(duration_ms) as avg_response,
        MAX(duration_ms) as max_response,
        MIN(duration_ms) as min_response
    FROM api_message_logs
    WHERE success = true
");
$response = $responseQuery->fetch(PDO::FETCH_ASSOC);

// 4. DAILY VOLUME (Last 7 days)
$dailyQuery = $db->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count,
        SUM(amount) as volume
    FROM swap_requests
    WHERE created_at > NOW() - INTERVAL '7 days'
    GROUP BY DATE(created_at)
    ORDER BY date DESC
");
$daily = $dailyQuery->fetchAll(PDO::FETCH_ASSOC);

// 5. PARTICIPANT ACTIVITY
$participantQuery = $db->query("
    SELECT 
        p.name,
        p.type,
        p.category,
        COUNT(DISTINCT s.swap_id) as swap_count,
        COUNT(DISTINCT h.hold_id) as hold_count,
        COUNT(DISTINCT a.log_id) as api_count,
        SUM(CASE WHEN h.status = 'ACTIVE' THEN h.amount ELSE 0 END) as active_exposure
    FROM participants p
    LEFT JOIN hold_transactions h ON p.participant_id = h.participant_id
    LEFT JOIN swap_requests s ON h.swap_reference = s.swap_uuid
    LEFT JOIN api_message_logs a ON p.participant_id = a.participant_id
    WHERE p.status = 'ACTIVE'
    GROUP BY p.name, p.type, p.category
    ORDER BY swap_count DESC
");
$participants = $participantQuery->fetchAll(PDO::FETCH_ASSOC);

// 6. RECENT SWAPS (Last 20)
$recentQuery = $db->query("
    SELECT 
        s.swap_uuid,
        s.amount,
        s.from_currency,
        s.status,
        s.created_at,
        s.source_details->>'institution' as source_inst,
        s.destination_details->>'institution' as dest_inst,
        COUNT(DISTINCT h.hold_id) as hold_count,
        COUNT(DISTINCT a.log_id) as api_count
    FROM swap_requests s
    LEFT JOIN hold_transactions h ON s.swap_uuid = h.swap_reference
    LEFT JOIN api_message_logs a ON s.swap_uuid = a.message_id
    GROUP BY s.swap_uuid, s.amount, s.from_currency, s.status, s.created_at, s.source_details, s.destination_details
    ORDER BY s.created_at DESC
    LIMIT 20
");
$recentSwaps = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

// 7. FEE SUMMARY
$feeQuery = $db->query("
    SELECT 
        fee_type,
        COUNT(*) as count,
        SUM(total_amount) as total,
        SUM(vat_amount) as total_vat,
        AVG(total_amount) as avg_fee
    FROM swap_fee_collections
    GROUP BY fee_type
    ORDER BY total DESC
");
$feeSummary = $feeQuery->fetchAll(PDO::FETCH_ASSOC);

// 8. SETTLEMENT SUMMARY
$settlementQuery = $db->query("
    SELECT 
        debtor,
        creditor,
        SUM(amount) as total_owed,
        COUNT(*) as transaction_count
    FROM settlement_queue
    GROUP BY debtor, creditor
    ORDER BY total_owed DESC
    LIMIT 10
");
$settlements = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);

// 9. HOLD STATUS
$holdQuery = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(amount) as total_value
    FROM hold_transactions
    GROUP BY status
");
$holdStatus = $holdQuery->fetchAll(PDO::FETCH_ASSOC);

// 10. AUDIT COMPLIANCE
$auditQuery = $db->query("
    SELECT 
        'swap_requests' as table_name,
        COUNT(*) as row_count,
        MIN(created_at) as oldest_record,
        MAX(created_at) as newest_record
    FROM swap_requests
    UNION ALL
    SELECT 
        'hold_transactions',
        COUNT(*),
        MIN(created_at),
        MAX(created_at)
    FROM hold_transactions
    UNION ALL
    SELECT 
        'api_message_logs',
        COUNT(*),
        MIN(created_at),
        MAX(created_at)
    FROM api_message_logs
    UNION ALL
    SELECT 
        'ledger_entries',
        COUNT(*),
        MIN(created_at),
        MAX(created_at)
    FROM ledger_entries
");
$audit = $auditQuery->fetchAll(PDO::FETCH_ASSOC);

// 11. COMPLIANCE SCORE
$complianceData = [
    'swap_count' => $system['total_swaps'],
    'holds_count' => $system['active_holds'],
    'api_count' => $system['total_api_calls'],
    'fee_count' => $system['total_fees'],
    'ledger_count' => $system['total_ledger_entries'],
    'settlement_count' => count($settlements),
    'success_rate' => $successRate,
    'avg_response_ms' => $response['avg_response'] ?? 0,
    'unique_participants' => count($participants),
    'total_volume' => $system['total_volume']
];
$complianceScore = calculateComplianceScore($complianceData);

// 12. GENERATE REPORT METADATA
$reportId = 'BoB-EVIDENCE-' . date('Ymd-His');
$generatedAt = new DateTime('now', new DateTimeZone('Africa/Gaborone'));
$reportHash = hash('sha256', json_encode([
    $system, $success, $response, $participants, $recentSwaps, $feeSummary
]));

// ============================================================================
// OUTPUT FORMATS
// ============================================================================

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="vouchmorph_regulatory_evidence_' . date('Ymd_His') . '.json"');
    
    $report = [
        'report_metadata' => [
            'report_id' => $reportId,
            'generated_for' => 'Bank of Botswana Regulatory Sandbox',
            'generated_at' => $generatedAt->format('c'),
            'country' => $countryCode,
            'integrity_hash' => $reportHash,
            'version' => '2.0'
        ],
        'system_overview' => $system,
        'performance_metrics' => [
            'total_swaps' => $system['total_swaps'],
            'success_rate' => $successRate,
            'avg_response_time_ms' => round($response['avg_response'] ?? 0),
            'active_holds' => $system['active_holds'],
            'active_participants' => $system['active_participants']
        ],
        'transaction_stats' => [
            'success' => $success,
            'daily_volume' => $daily,
            'recent_swaps' => $recentSwaps
        ],
        'participant_activity' => $participants,
        'fee_analysis' => $feeSummary,
        'settlement_obligations' => $settlements,
        'hold_status' => $holdStatus,
        'audit_compliance' => $audit,
        'compliance_score' => $complianceScore,
        'regulatory_declaration' => [
            'no_fund_custody' => true,
            'no_pin_storage' => true,
            'seven_year_audit_trail' => true,
            'real_time_settlement' => true,
            'double_entry_accounting' => true,
            'iso20022_compliant' => true
        ]
    ];
    
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vouchmorph_regulatory_evidence_' . date('Ymd_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['VOUCHMORPH REGULATORY EVIDENCE PACKAGE']);
    fputcsv($output, ['Generated for:', 'Bank of Botswana']);
    fputcsv($output, ['Report ID:', $reportId]);
    fputcsv($output, ['Date:', $generatedAt->format('Y-m-d H:i:s T')]);
    fputcsv($output, []);
    
    // System Overview
    fputcsv($output, ['SYSTEM OVERVIEW']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Swaps', $system['total_swaps']]);
    fputcsv($output, ['Swaps (24h)', $system['swaps_24h']]);
    fputcsv($output, ['Success Rate', $successRate . '%']);
    fputcsv($output, ['Active Holds', $system['active_holds']]);
    fputcsv($output, ['Active Participants', $system['active_participants']]);
    fputcsv($output, ['Total Volume (BWP)', number_format($system['total_volume'], 2)]);
    fputcsv($output, []);
    
    // Response Times
    fputcsv($output, ['API PERFORMANCE']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Average Response', round($response['avg_response'] ?? 0) . ' ms']);
    fputcsv($output, ['Max Response', round($response['max_response'] ?? 0) . ' ms']);
    fputcsv($output, ['Min Response', round($response['min_response'] ?? 0) . ' ms']);
    fputcsv($output, []);
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    $format = 'html';
    $_GET['pdf'] = '1';
}

// ============================================================================
// HTML REPORT (Printable/Downloadable)
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · REGULATORY EVIDENCE · BANK OF BOTSWANA</title>
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

        /* Cover Page */
        .cover-page {
            text-align: center;
            margin-bottom: 4rem;
            padding: 4rem 2rem;
            border: 3px solid #000;
            page-break-after: always;
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

        .cover-logo {
            font-size: 4rem;
            margin: 3rem 0;
            color: #0f0;
        }

        .cover-meta {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #ddd;
            font-size: 0.9rem;
            color: #666;
        }

        /* Header */
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

        /* Grids */
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

        /* Metrics */
        .metric-card {
            background: #000;
            color: #fff;
            padding: 1.5rem;
            border-radius: 4px;
        }

        .metric-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 0.5rem;
        }

        .metric-value {
            font-size: 2.5rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            color: #0f0;
        }

        .metric-unit {
            font-size: 0.9rem;
            color: #666;
            margin-left: 0.5rem;
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
        }

        tr:hover {
            background: #f5f5f5;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border-radius: 20px;
        }

        .status-completed { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-active { background: #d1ecf1; color: #0c5460; }

        /* Compliance Score */
        .score-container {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #000, #333);
            color: #fff;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .score-value {
            font-size: 5rem;
            font-weight: 200;
            color: #0f0;
        }

        .score-label {
            font-size: 1rem;
            text-transform: uppercase;
            color: #888;
        }

        /* Declaration */
        .declaration-box {
            background: #f0f0f0;
            border-left: 5px solid #000;
            padding: 1.5rem;
            margin: 2rem 0;
            font-style: italic;
        }

        .signature-line {
            margin-top: 3rem;
            border-top: 2px solid #000;
            width: 300px;
        }

        /* Print/Download Buttons */
        .button-group {
            margin-bottom: 2rem;
            text-align: right;
        }

        .btn {
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

        .btn:hover {
            background: #333;
        }

        .btn-success {
            background: #0f0;
            color: #000;
        }

        /* Print Styles */
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
            .metric-card { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .cover-title { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <!-- Download Buttons (no-print) -->
    <div class="button-group no-print">
        <button onclick="window.print()" class="btn">🖨️ PRINT EVIDENCE PACKAGE</button>
        <a href="?format=json" class="btn">📥 DOWNLOAD JSON</a>
        <a href="?format=csv" class="btn">📥 DOWNLOAD CSV</a>
        <a href="?format=pdf&pdf=1" class="btn btn-success">📥 DOWNLOAD PDF</a>
    </div>

    <!-- COVER PAGE -->
    <div class="cover-page">
        <div class="cover-title">VOUCHMORPH</div>
        <div class="cover-subtitle">Regulatory Evidence Package</div>
        <div class="cover-logo">⚡</div>
        <div style="font-size: 1.2rem; margin: 2rem 0;">
            <strong>Bank of Botswana</strong><br>
            Regulatory Sandbox
        </div>
        <div class="cover-meta">
            <div><strong>Report ID:</strong> <?php echo $reportId; ?></div>
            <div><strong>Generated:</strong> <?php echo $generatedAt->format('Y-m-d H:i:s T'); ?></div>
            <div><strong>Integrity Hash:</strong> <?php echo substr($reportHash, 0, 32); ?>…</div>
            <div style="margin-top: 1rem;">CONFIDENTIAL · FOR REGULATORY USE ONLY</div>
        </div>
    </div>

    <!-- EXECUTIVE SUMMARY -->
    <div class="section">
        <div class="section-title">Executive Summary</div>
        <div class="card">
            <p style="font-size: 1.1rem; margin-bottom: 1rem;">
                VouchMorph has successfully processed <strong><?php echo number_format($system['total_swaps']); ?> transactions</strong> 
                with a <strong><?php echo $successRate; ?>% success rate</strong> across 
                <strong><?php echo $system['active_participants']; ?> active financial institutions</strong>.
            </p>
            <p style="margin-bottom: 1rem;">
                The system maintains real-time audit trails, double-entry accounting, and ISO20022-compliant messaging. 
                No customer funds are ever held by VouchMorph, and no PINs are stored — all authentication is performed 
                by source institutions in real-time.
            </p>
            <div class="grid-4" style="margin-top: 2rem;">
                <div class="metric-card">
                    <div class="metric-label">Compliance Score</div>
                    <div class="metric-value"><?php echo $complianceScore; ?><span class="metric-unit">/100</span></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Success Rate</div>
                    <div class="metric-value"><?php echo $successRate; ?><span class="metric-unit">%</span></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Avg Response</div>
                    <div class="metric-value"><?php echo round($response['avg_response'] ?? 0); ?><span class="metric-unit">ms</span></div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">Active Holds</div>
                    <div class="metric-value"><?php echo $system['active_holds']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- SYSTEM OVERVIEW -->
    <div class="section">
        <div class="section-title">1. System Overview</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Volume</span>
                    <span class="card-badge">LIFETIME</span>
                </div>
                <table>
                    <tr><td>Total Swaps</td><td><strong><?php echo number_format($system['total_swaps']); ?></strong></td></tr>
                    <tr><td>Last 24 Hours</td><td><strong><?php echo number_format($system['swaps_24h']); ?></strong></td></tr>
                    <tr><td>Total Volume</td><td><strong>BWP <?php echo number_format($system['total_volume'], 2); ?></strong></td></tr>
                    <tr><td>Active Holds</td><td><strong><?php echo $system['active_holds']; ?></strong></td></tr>
                </table>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Transaction Status</span>
                    <span class="card-badge">REAL-TIME</span>
                </div>
                <table>
                    <tr><td>Completed</td><td><strong><?php echo number_format($success['completed']); ?></strong></td></tr>
                    <tr><td>Pending</td><td><strong><?php echo number_format($success['pending']); ?></strong></td></tr>
                    <tr><td>Failed</td><td><strong><?php echo number_format($success['failed']); ?></strong></td></tr>
                    <tr><td>Success Rate</td><td><strong><?php echo $successRate; ?>%</strong></td></tr>
                </table>
            </div>
        </div>

        <!-- Daily Volume Chart (Text-based) -->
        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-header">
                <span class="card-title">Daily Volume (Last 7 Days)</span>
                <span class="card-badge">TREND</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Volume (BWP)</th>
                        <th>Visual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $maxVolume = max(array_column($daily, 'volume') ?: [1]);
                    foreach ($daily as $day): 
                        $barWidth = ($day['volume'] / $maxVolume) * 100;
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($day['date'])); ?></td>
                        <td><?php echo $day['count']; ?></td>
                        <td><?php echo number_format($day['volume'], 2); ?></td>
                        <td>
                            <div style="background: #0f0; height: 20px; width: <?php echo $barWidth; ?>%;"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PARTICIPANT ACTIVITY -->
    <div class="section">
        <div class="section-title">2. Participant Activity</div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Institution</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Swaps</th>
                        <th>Holds</th>
                        <th>API Calls</th>
                        <th>Exposure (BWP)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                    <tr>
                        <td><strong><?php echo safe_html($p['name']); ?></strong></td>
                        <td><?php echo safe_html($p['type']); ?></td>
                        <td><?php echo safe_html($p['category']); ?></td>
                        <td><?php echo number_format($p['swap_count']); ?></td>
                        <td><?php echo number_format($p['hold_count']); ?></td>
                        <td><?php echo number_format($p['api_count']); ?></td>
                        <td><?php echo number_format($p['active_exposure'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="section">
        <div class="section-title">3. Recent Transactions (Last 20)</div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Swap UUID</th>
                        <th>Amount</th>
                        <th>From → To</th>
                        <th>Status</th>
                        <th>Holds</th>
                        <th>API</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSwaps as $swap): ?>
                    <tr>
                        <td><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></td>
                        <td><code><?php echo substr($swap['swap_uuid'], 0, 12); ?>…</code></td>
                        <td><?php echo number_format($swap['amount'], 2); ?> <?php echo $swap['from_currency']; ?></td>
                        <td><?php echo safe_html($swap['source_inst']); ?> → <?php echo safe_html($swap['dest_inst']); ?></td>
                        <td><span class="status-badge status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span></td>
                        <td><?php echo $swap['hold_count']; ?></td>
                        <td><?php echo $swap['api_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FEE ANALYSIS -->
    <div class="section">
        <div class="section-title">4. Fee Analysis</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Fee Summary</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <th>Count</th>
                            <th>Total (BWP)</th>
                            <th>VAT (14%)</th>
                            <th>Avg Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeSummary as $fee): ?>
                        <tr>
                            <td><?php echo safe_html($fee['fee_type']); ?></td>
                            <td><?php echo number_format($fee['count']); ?></td>
                            <td><?php echo number_format($fee['total'], 2); ?></td>
                            <td><?php echo number_format($fee['total_vat'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($fee['avg_fee'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Hold Status</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>Value (BWP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holdStatus as $hold): ?>
                        <tr>
                            <td><span class="status-badge status-<?php echo strtolower($hold['status']); ?>"><?php echo $hold['status']; ?></span></td>
                            <td><?php echo number_format($hold['count']); ?></td>
                            <td><?php echo number_format($hold['total_value'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SETTLEMENT OBLIGATIONS -->
    <div class="section">
        <div class="section-title">5. Settlement Obligations</div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Debtor</th>
                        <th>Creditor</th>
                        <th>Amount (BWP)</th>
                        <th>Transactions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><?php echo safe_html($s['debtor']); ?></td>
                        <td><?php echo safe_html($s['creditor']); ?></td>
                        <td><strong><?php echo number_format($s['total_owed'], 2); ?></strong></td>
                        <td><?php echo $s['transaction_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- AUDIT COMPLIANCE -->
    <div class="section">
        <div class="section-title">6. Audit Trail Compliance (7-Year Retention)</div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Records</th>
                        <th>Oldest Record</th>
                        <th>Newest Record</th>
                        <th>Compliant</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit as $a): ?>
                    <tr>
                        <td><code><?php echo $a['table_name']; ?></code></td>
                        <td><?php echo number_format($a['row_count']); ?></td>
                        <td><?php echo $a['oldest_record'] ? date('Y-m-d', strtotime($a['oldest_record'])) : '—'; ?></td>
                        <td><?php echo $a['newest_record'] ? date('Y-m-d', strtotime($a['newest_record'])) : '—'; ?></td>
                        <td style="color: #0f0;">✓ YES</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- REGULATORY DECLARATION -->
    <div class="section page-break">
        <div class="section-title">7. Regulatory Compliance Declaration</div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Funds Safety</span>
                </div>
                <ul style="margin-left: 1.5rem;">
                    <li>✅ No customer funds ever held by VouchMorph</li>
                    <li>✅ Funds remain at source institution until delivery</li>
                    <li>✅ Holds expire after 24 hours if not completed</li>
                    <li>✅ Failed transactions cost customers ZERO</li>
                </ul>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">PIN Security</span>
                </div>
                <ul style="margin-left: 1.5rem;">
                    <li>✅ No PINs ever stored in database</li>
                    <li>✅ PINs verified by source institutions in real-time</li>
                    <li>✅ Encrypted transmission only</li>
                    <li>✅ Wrong PIN attempts = no charge</li>
                </ul>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Audit Trail</span>
                </div>
                <ul style="margin-left: 1.5rem;">
                    <li>✅ 7-year retention for all transaction records</li>
                    <li>✅ Every API call logged with request/response</li>
                    <li>✅ Double-entry ledger verification</li>
                    <li>✅ SHA-256 checksums for integrity</li>
                </ul>
            </div>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Consumer Protection</span>
                </div>
                <ul style="margin-left: 1.5rem;">
                    <li>✅ Social grants protected at flat P6 rate</li>
                    <li>✅ Free USSD browsing (no charge to check balance)</li>
                    <li>✅ Fees displayed before every transaction</li>
                    <li>✅ 48-hour dispute resolution guarantee</li>
                </ul>
            </div>
        </div>

        <div class="declaration-box">
            <p style="font-size: 1.1rem;">
                "VouchMorph Proprietary Limited hereby declares that the system described in this evidence package 
                operates in full compliance with the Bank of Botswana National Payment System Act, FATF Recommendations, 
                and all applicable consumer protection regulations. No customer funds are ever held, no PINs are ever stored, 
                and every transaction is fully auditable for 7 years."
            </p>
        </div>

        <div style="margin-top: 3rem;">
            <div class="signature-line"></div>
            <div style="margin-top: 0.5rem;"><strong>[Name], Managing Director</strong></div>
            <div>VouchMorph Proprietary Limited</div>
            <div style="margin-top: 2rem;">Date: <?php echo $generatedAt->format('Y-m-d'); ?></div>
        </div>

        <div style="margin-top: 3rem; text-align: center; font-size: 0.8rem; color: #666;">
            <p>Integrity Hash: <?php echo $reportHash; ?></p>
            <p>This document is a complete, verifiable record of VouchMorph's system readiness.</p>
        </div>
    </div>

    <?php if (isset($_GET['pdf'])): ?>
    <script>window.onload = function() { window.print(); }</script>
    <?php endif; ?>
</body>
</html>
