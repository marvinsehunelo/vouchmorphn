<?php
// ADMIN_LAYER/reports/audit_trails.php

require_once __DIR__ . '/../../../src/APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../../src/BUSINESS_LOGIC_LAYER/services/AuditTrailService.php';

use APP_LAYER\utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\AuditTrailService;

SessionManager::start();

// Allow multiple roles
$user = SessionManager::getUser();
$allowedRoles = ['admin', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'AUDITOR'];
if (!$user || !in_array($user['role'] ?? '', $allowedRoles)) {
    http_response_code(403);
    echo "<p style='text-align:center;color:red;font-weight:bold;'>Access denied</p>";
    exit;
}

// --- FETCH AUDIT LOGS ---
$auditService = new AuditTrailService();
$logs = $auditService->getAuditLogs();

// --- CSV Export ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_trails_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Log ID', 'Admin/User', 'Action', 'Date/Time', 'IP']);
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'] ?? '',
            $log['username'] ?? '',
            $log['action'] ?? '',
            $log['timestamp'] ?? '',
            $log['ip_address'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}
?>

<div class="dashboard-content">
    <h2>Audit Trails</h2>
    <a href="?export=csv" class="btn-download">Download CSV</a>

    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Log ID</th>
                    <th>Admin/User</th>
                    <th>Action</th>
                    <th>Date/Time</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['id'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['username'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['action'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['timestamp'] ?? '') ?></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center;">No audit logs available</td></tr>
                <?php endif; ?>
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

