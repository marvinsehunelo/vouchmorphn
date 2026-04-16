<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// BANK-COMPLIANT WEEKLY RECONCILIATIONS REPORT

// --- SYSTEM & SESSION SETUP ---
require_once __DIR__ . '/../../../src/Application/utils/session_manager.php';
require_once __DIR__ . '/../../../src/Core/Database/config/DBConnection.php';
require_once __DIR__ . '/../../../src/Domain/services/LedgerService.php';

use APP_LAYER\utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\LedgerService;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

SessionManager::start();

// Allow multiple roles
$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];
if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    header('Location: ../admin_login.php');
    exit;
}

// --- LOAD CONFIG & DB ---
$country = require __DIR__ . '/../../../src/Core/Config/system_country.php';
$config  = require __DIR__ . '/../../../src/Core/Config/config_' . $country . '.php';
$swap_systemDB = DBConnection::getInstance($config['db']['swap']);
$ledgerService = new LedgerService($swap_systemDB);

// --- FETCH WEEKLY LEDGER RECONCILIATIONS (last 7 days) ---
$startDate = date('Y-m-d', strtotime('-6 days'));
$endDate   = date('Y-m-d');

$reconciliations = method_exists($ledgerService, 'getReconciliationsByRange')
    ? $ledgerService->getReconciliationsByRange($startDate, $endDate)
    : [];

// --- FETCH WEEKLY SWAPS SUMMARY ---
$stmt = $swap_systemDB->prepare("
    SELECT 
        COUNT(*) AS total_swaps,
        COALESCE(SUM(amount), 0) AS total_amount,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_swaps
    FROM swap_transactions
    WHERE created_at >= :start_date
      AND created_at < DATE(:end_date) + INTERVAL '1 day'
");
$stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
$swapsSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// --- AUDIT LOG ---
$logFile = __DIR__ . '/../../../src/Application/logs/weekly_reconciliations.log';
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Weekly reconciliations run\n", FILE_APPEND);

// --- CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="weekly_reconciliation_' . date('Y-m-d') . '.csv"');
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
    fputcsv($output, [$startDate.' to '.$endDate, $swapsSummary['total_swaps'], $swapsSummary['failed_swaps'], $swapsSummary['total_amount']]);
    fclose($output);
    exit;
}
?>
<div class="dashboard-content">
    <h2>Weekly Reconciliations Report</h2>
    <a href="?export=csv" class="btn-download">Download CSV</a>

    <div class="report-table-container">
        <h3>Ledger Reconciliations (Last 7 Days)</h3>
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
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if (!empty($reconciliations)): ?>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($reconciliations as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['total_transactions'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['total_amount'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['status'] ?? '') ?></td>
                        </tr>
                    <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 else: ?>
                    <tr><td colspan="4" style="text-align:center;">No data available</td></tr>
                <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endif; ?>
            </tbody>
        </table>
    </div>

    <div class="report-table-container">
        <h3>Swap Transactions Summary (Last 7 Days)</h3>
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
.dashboard-content { padding:20px; font-family:'Arial',sans-serif; }
.report-table-container { overflow-x:auto; margin-top:20px; }
.report-table { width:100%; border-collapse: collapse; }
.report-table th, .report-table td { border:1px solid #ddd; padding:10px; text-align:center; }
.report-table th { background-color:#f5f5f5; font-weight:bold; }
.report-table tr:nth-child(even){ background-color:#f9f9f9; }
.btn-download { display:inline-block; padding:8px 16px; background:#007bff; color:#fff; text-decoration:none; margin-bottom:10px; border-radius:4px; }
.btn-download:hover{ background:#0056b3; }
</style>

