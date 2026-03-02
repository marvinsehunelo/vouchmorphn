<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/APP_LAYER/utils/session_manager.php';


use APP_LAYER\utils\SessionManager;
use BUSINESS_LOGIC_LAYER\services\LedgerService;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

SessionManager::start();

$user = SessionManager::getUser();
$allowedRoles = ['GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];

if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

$country = require __DIR__ . '/../../../src/CORE_CONFIG/system_country.php';
$config  = require __DIR__ . '/../../../src/CORE_CONFIG/config_' . $country . '.php';

$swap_systemDB = DBConnection::getInstance($config['db']['swap']);
$ledgerService = new LedgerService($swap_systemDB);


// Fetch daily ledger reconciliations
$reconciliations = method_exists($ledgerService, 'getDailyReconciliations')
    ? $ledgerService->getDailyReconciliations()
    : [];

// Fetch daily swaps summary
$stmt = $swap_systemDB->prepare("
    SELECT 
        COUNT(*) AS total_swaps,
        COALESCE(SUM(amount), 0) AS total_amount,
        SUM(
            CASE 
                WHEN status = 'failed' THEN 1 
                ELSE 0 
            END
        ) AS failed_swaps
    FROM swap_transactions
    WHERE created_at >= CURRENT_DATE
      AND created_at < CURRENT_DATE + INTERVAL '1 day'
");
$stmt->execute();
$swapsSummary = $stmt->fetch(PDO::FETCH_ASSOC);


// Audit log
$logFile = __DIR__ . '/../../../src/APP_LAYER/logs/daily_reconciliations.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Daily reconciliations run\n", FILE_APPEND);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_reconciliation_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Total Transactions', 'Total Amount', 'Status']);
    foreach ($reconciliations as $row) {
        fputcsv($output, [
            $row['date'] ?? '',
            $row['total_transactions'] ?? '',
            $row['total_amount'] ?? '',
            $row['status'] ?? ''
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['SWAP SUMMARY', 'Total Swaps', 'Failed Swaps', 'Total Amount']);
    fputcsv($output, [date('Y-m-d'), $swapsSummary['total_swaps'], $swapsSummary['failed_swaps'], $swapsSummary['total_amount']]);
    fclose($output);
    exit;
}
?>
<!-- Dashboard HTML -->
<div class="dashboard-content">
    <h2>Daily Reconciliations Report</h2>

    <a href="?export=csv" class="btn-download">Download CSV</a>

    <div class="report-table-container">
        <h3>Ledger Reconciliations</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Total Transactions</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reconciliations)): ?>
                    <?php foreach ($reconciliations as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['total_transactions'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['total_amount'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['status'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center;">No data available</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="report-table-container">
        <h3>Swap Transactions Summary</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Total Swaps</th>
                    <th>Failed Swaps</th>
                    <th>Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= htmlspecialchars($swapsSummary['total_swaps'] ?? 0) ?></td>
                    <td><?= htmlspecialchars($swapsSummary['failed_swaps'] ?? 0) ?></td>
                    <td><?= htmlspecialchars(number_format($swapsSummary['total_amount'] ?? 0, 2)) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.dashboard-content { padding: 20px; font-family: 'Arial', sans-serif; }
.report-table-container { overflow-x:auto; margin-top:20px; }
.report-table { width: 100%; border-collapse: collapse; }
.report-table th, .report-table td { border:1px solid #ddd; padding:10px; text-align:center; }
.report-table th { background-color:#f5f5f5; font-weight:bold; }
.report-table tr:nth-child(even){ background-color:#f9f9f9; }
.btn-download { display:inline-block; padding:8px 16px; background:#007bff; color:#fff; text-decoration:none; margin-bottom:10px; border-radius:4px; }
.btn-download:hover{ background:#0056b3; }
</style>

