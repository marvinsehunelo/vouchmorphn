<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

$db = DBConnection::getConnection();

$type = $_GET['type'] ?? 'daily';
$format = $_GET['format'] ?? 'html';
$date = $_GET['date'] ?? date('Y-m-d');
$fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$toDate = $_GET['to'] ?? date('Y-m-d');

// Generate report based on type
switch ($type) {
    case 'daily':
        $report = generateDailyReport($db, $date);
        $filename = "daily_report_{$date}";
        break;
    case 'monthly':
        $report = generateMonthlyReport($db, substr($date, 0, 7));
        $filename = "monthly_report_" . substr($date, 0, 7);
        break;
    case 'compliance':
        $report = generateComplianceReport($db);
        $filename = "compliance_report_" . date('Ymd');
        break;
    case 'audit':
        $report = generateAuditReport($db, $fromDate, $toDate);
        $filename = "audit_report_{$fromDate}_to_{$toDate}";
        break;
    case 'settlement':
        $report = generateSettlementReport($db, $date);
        $filename = "settlement_report_{$date}";
        break;
    default:
        die("Invalid report type");
}

// Store in regulator_reports
$stmt = $db->prepare("
    INSERT INTO regulator_reports 
    (report_id, report_type, report_date, generated_by, report_format, report_data, integrity_hash, created_at)
    VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
");
$reportId = 'RPT-' . date('Ymd') . '-' . uniqid();
$integrityHash = hash('sha256', json_encode($report));
$stmt->execute([
    $reportId,
    strtoupper($type),
    $date,
    $format,
    json_encode($report),
    $integrityHash
]);

// Output based on format
if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Flatten report for CSV
    fputcsv($output, ['Report Type', $type]);
    fputcsv($output, ['Generated', date('c')]);
    fputcsv($output, ['Report ID', $reportId]);
    fputcsv($output, ['Integrity Hash', $integrityHash]);
    fputcsv($output, []);
    
    // Add data based on report type
    foreach ($report as $key => $value) {
        if (is_array($value)) {
            fputcsv($output, [$key, json_encode($value)]);
        } else {
            fputcsv($output, [$key, $value]);
        }
    }
    
    fclose($output);
    exit;
}

// HTML format (default)
header('Content-Type: text/html');
if (isset($_GET['download'])) {
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo ucfirst($type); ?> Report</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #fff;
            color: #000;
            line-height: 1.6;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .report-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #001B44;
        }
        .report-title {
            font-size: 2rem;
            color: #001B44;
            margin-bottom: 0.5rem;
        }
        .report-meta {
            color: #666;
            font-size: 0.9rem;
        }
        .section {
            margin: 2rem 0;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 1.5rem;
            color: #001B44;
            border-bottom: 1px solid #FFDA63;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        th {
            background: #001B44;
            color: #FFDA63;
            padding: 0.75rem;
            text-align: left;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #ddd;
        }
        .signature {
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 2px solid #001B44;
            display: flex;
            justify-content: space-between;
        }
        .footer {
            margin-top: 2rem;
            text-align: center;
            color: #666;
            font-size: 0.8rem;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="report-title">VOUCHMORPH <?php echo strtoupper($type); ?> REPORT</div>
        <div class="report-meta">
            Report ID: <?php echo $reportId; ?> · 
            Generated: <?php echo date('Y-m-d H:i:s'); ?> · 
            Integrity: <?php echo substr($integrityHash, 0, 16); ?>…
        </div>
    </div>

    <?php if ($type === 'daily'): ?>
        <?php echo renderDailyReport($report); ?>
    <?php elseif ($type === 'compliance'): ?>
        <?php echo renderComplianceReport($report); ?>
    <?php elseif ($type === 'audit'): ?>
        <?php echo renderAuditReport($report); ?>
    <?php elseif ($type === 'settlement'): ?>
        <?php echo renderSettlementReport($report); ?>
    <?php endif; ?>

    <div class="signature">
        <div>
            <strong>Generated By:</strong> VouchMorph Control System
        </div>
        <div>
            <strong>Integrity Hash:</strong> <?php echo $integrityHash; ?>
        </div>
    </div>

    <div class="footer no-print">
        <button onclick="window.print()">🖨️ Print Report</button>
        <button onclick="window.close()">✕ Close</button>
    </div>
</body>
</html>
<?php

// ============================================================================
// Report Generation Functions
// ============================================================================

function generateDailyReport($db, $date) {
    // Transactions
    $txQuery = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(amount) as volume,
            AVG(amount) as avg_amount,
            COUNT(DISTINCT source_details->>'institution') as unique_sources,
            COUNT(DISTINCT destination_details->>'institution') as unique_dests
        FROM swap_requests
        WHERE DATE(created_at) = ?
    ");
    $txQuery->execute([$date]);
    $transactions = $txQuery->fetch(PDO::FETCH_ASSOC);
    
    // Status breakdown
    $statusQuery = $db->prepare("
        SELECT status, COUNT(*) as count
        FROM swap_requests
        WHERE DATE(created_at) = ?
        GROUP BY status
    ");
    $statusQuery->execute([$date]);
    $statusBreakdown = $statusQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // By type
    $typeQuery = $db->prepare("
        SELECT 
            destination_details->>'delivery_mode' as type,
            COUNT(*) as count,
            SUM(amount) as volume
        FROM swap_requests
        WHERE DATE(created_at) = ?
        GROUP BY destination_details->>'delivery_mode'
    ");
    $typeQuery->execute([$date]);
    $typeBreakdown = $typeQuery->fetchAll(PDO::FETCH_ASSOC);
    
    // Fees
    $feeQuery = $db->prepare("
        SELECT 
            COUNT(*) as fee_count,
            SUM(total_amount) as total_fees,
            SUM(vat_amount) as total_vat
        FROM swap_fee_collections
        WHERE DATE(created_at) = ?
    ");
    $feeQuery->execute([$date]);
    $fees = $feeQuery->fetch(PDO::FETCH_ASSOC);
    
    // Holds
    $holdQuery = $db->prepare("
        SELECT 
            COUNT(*) as holds_placed,
            SUM(amount) as holds_value
        FROM hold_transactions
        WHERE DATE(placed_at) = ?
    ");
    $holdQuery->execute([$date]);
    $holds = $holdQuery->fetch(PDO::FETCH_ASSOC);
    
    // API Performance
    $apiQuery = $db->prepare("
        SELECT 
            COUNT(*) as api_calls,
            AVG(duration_ms) as avg_response,
            SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful
        FROM api_message_logs
        WHERE DATE(created_at) = ?
    ");
    $apiQuery->execute([$date]);
    $api = $apiQuery->fetch(PDO::FETCH_ASSOC);
    
    return [
        'report_date' => $date,
        'transactions' => $transactions,
        'status_breakdown' => $statusBreakdown,
        'type_breakdown' => $typeBreakdown,
        'fees' => $fees,
        'holds' => $holds,
        'api_performance' => $api,
        'generated_at' => date('c')
    ];
}

function renderDailyReport($report) {
    $html = '<div class="section">';
    $html .= '<div class="section-title">📊 Daily Summary</div>';
    $html .= '<table>';
    $html .= '<tr><th>Metric</th><th>Value</th></tr>';
    $html .= '<tr><td>Total Transactions</td><td>' . number_format($report['transactions']['total']) . '</td></tr>';
    $html .= '<tr><td>Total Volume</td><td>P' . number_format($report['transactions']['volume']) . '</td></tr>';
    $html .= '<tr><td>Average Amount</td><td>P' . number_format($report['transactions']['avg_amount'], 2) . '</td></tr>';
    $html .= '<tr><td>Unique Sources</td><td>' . $report['transactions']['unique_sources'] . '</td></tr>';
    $html .= '<tr><td>Unique Destinations</td><td>' . $report['transactions']['unique_dests'] . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';
    
    $html .= '<div class="section">';
    $html .= '<div class="section-title">📈 Status Breakdown</div>';
    $html .= '<table>';
    $html .= '<tr><th>Status</th><th>Count</th></tr>';
    foreach ($report['status_breakdown'] as $status) {
        $html .= '<tr><td>' . $status['status'] . '</td><td>' . $status['count'] . '</td></tr>';
    }
    $html .= '</table>';
    $html .= '</div>';
    
    $html .= '<div class="section">';
    $html .= '<div class="section-title">💰 Fees Collected</div>';
    $html .= '<table>';
    $html .= '<tr><td>Total Fees</td><td>P' . number_format($report['fees']['total_fees']) . '</td></tr>';
    $html .= '<tr><td>VAT (14%)</td><td>P' . number_format($report['fees']['total_vat']) . '</td></tr>';
    $html .= '<tr><td>Net Fees</td><td>P' . number_format($report['fees']['total_fees'] - $report['fees']['total_vat']) . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';
    
    $html .= '<div class="section">';
    $html .= '<div class="section-title">⚡ API Performance</div>';
    $html .= '<table>';
    $html .= '<tr><td>Total API Calls</td><td>' . number_format($report['api_performance']['api_calls']) . '</td></tr>';
    $html .= '<tr><td>Success Rate</td><td>' . round(($report['api_performance']['successful'] / $report['api_performance']['api_calls']) * 100, 2) . '%</td></tr>';
    $html .= '<tr><td>Avg Response Time</td><td>' . round($report['api_performance']['avg_response']) . ' ms</td></tr>';
    $html .= '</table>';
    $html .= '</div>';
    
    return $html;
}

function generateMonthlyReport($db, $yearMonth) {
    // Similar structure but for month
    return ['month' => $yearMonth];
}

function generateComplianceReport($db) {
    require_once __DIR__ . '/compliance_checker.php';
    return runFullComplianceCheck($db);
}

function renderComplianceReport($report) {
    $html = '<div class="section">';
    $html .= '<div class="section-title">✅ Compliance Score: ' . $report['overall_score'] . '%</div>';
    
    $regs = ['bank_of_botswana', 'fatf', 'psd2', 'pci_dss', 'gdpr', 'sandbox_limits'];
    foreach ($regs as $reg) {
        $html .= '<h3>' . ucfirst(str_replace('_', ' ', $reg)) . ' (' . $report[$reg]['score'] . '%)</h3>';
        $html .= '<table>';
        foreach ($report[$reg]['details'] as $key => $detail) {
            $statusColor = $detail['status'] == 'PASS' ? '#0f0' : '#f00';
            $html .= '<tr>';
            $html .= '<td>' . ucfirst(str_replace('_', ' ', $key)) . '</td>';
            $html .= '<td><span style="color:' . $statusColor . ';">●</span> ' . $detail['status'] . '</td>';
            $html .= '<td>' . $detail['value'] . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    
    if (!empty($report['recommendations'])) {
        $html .= '<h3>📋 Recommendations</h3><ul
