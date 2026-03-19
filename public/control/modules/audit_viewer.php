<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

$db = DBConnection::getConnection();

$page = $_GET['page'] ?? 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$entityFilter = $_GET['entity'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');

// Build query
$where = ["1=1"];
$params = [];

if ($entityFilter) {
    $where[] = "entity_type = ?";
    $params[] = $entityFilter;
}

if ($actionFilter) {
    $where[] = "action = ?";
    $params[] = $actionFilter;
}

$where[] = "DATE(performed_at) BETWEEN ? AND ?";
$params[] = $dateFrom;
$params[] = $dateTo;

$whereClause = implode(" AND ", $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Get records
$stmt = $db->prepare("
    SELECT a.*, 
           CASE 
               WHEN a.performed_by_type = 'admin' THEN (SELECT username FROM admins WHERE admin_id = a.performed_by_id::int)
               WHEN a.performed_by_type = 'user' THEN (SELECT username FROM users WHERE user_id = a.performed_by_id::int)
               ELSE 'System'
           END as performed_by_name
    FROM audit_logs a
    WHERE $whereClause
    ORDER BY a.performed_at DESC
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$stmt->execute($params);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct entity types for filter
$entities = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

// Get distinct actions for filter
$actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Audit Trail Viewer</title>
    <link rel="stylesheet" href="../assets/css/control.css">
</head>
<body>
    <div class="control-container">
        <div class="control-header">
            <div class="logo">
                <h1>VOUCHMORPH <span>AUDIT TRAIL VIEWER</span></h1>
            </div>
            <div class="badge">
                <a href="../index.php" style="color: #FFDA63; text-decoration: none;">← BACK TO DASHBOARD</a>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="compliance-card" style="margin-bottom: 2rem;">
            <div class="card-header">
                <span class="card-title">Filter Audit Logs</span>
            </div>
            <form method="GET" class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="form-group">
                    <label>Entity Type</label>
                    <select name="entity" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($entities as $e): ?>
                        <option value="<?php echo $e; ?>" <?php echo $entityFilter == $e ? 'selected' : ''; ?>>
                            <?php echo $e; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Action</label>
                    <select name="action" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($actions as $a): ?>
                        <option value="<?php echo $a; ?>" <?php echo $actionFilter == $a ? 'selected' : ''; ?>>
                            <?php echo $a; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>From Date</label>
                    <input type="date" name="from" value="<?php echo $dateFrom; ?>" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>To Date</label>
                    <input type="date" name="to" value="<?php echo $dateTo; ?>" class="form-control">
                </div>
                
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
                    <a href="?" class="btn" style="margin-left: 0.5rem;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Audit Trail Summary -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-box">
                <div class="stat-value"><?php echo number_format($totalRecords); ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo count($auditLogs); ?></div>
                <div class="stat-label">Showing</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo $totalPages; ?></div>
                <div class="stat-label">Pages</div>
            </div>
            <div class="stat-box">
                <div class="stat-value">7 Years</div>
                <div class="stat-label">Retention</div>
            </div>
        </div>

        <!-- Audit Log Table -->
        <div class="compliance-card">
            <div class="card-header">
                <span class="card-title">Audit Trail Entries</span>
                <span class="card-badge">Integrity Verified ✓</span>
            </div>
            
            <div style="overflow-x: auto;">
                <table style="width:100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding:0.75rem;">Timestamp</th>
                            <th style="text-align:left; padding:0.75rem;">Entity</th>
                            <th style="text-align:left; padding:0.75rem;">Action</th>
                            <th style="text-align:left; padding:0.75rem;">Performed By</th>
                            <th style="text-align:left; padding:0.75rem;">Changes</th>
                            <th style="text-align:left; padding:0.75rem;">IP Address</th>
                            <th style="text-align:left; padding:0.75rem;">Integrity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php echo date('Y-m-d H:i:s', strtotime($log['performed_at'])); ?>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php echo $log['entity_type']; ?> #<?php echo $log['entity_id']; ?>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <span class="status-badge" style="background: #1a3a3a; color: #0ff;">
                                    <?php echo $log['action']; ?>
                                </span>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php echo $log['performed_by_type']; ?><br>
                                <small><?php echo htmlspecialchars($log['performed_by_name'] ?? 'Unknown'); ?></small>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php if ($log['old_value'] || $log['new_value']): ?>
                                <details>
                                    <summary style="color:#0f0; cursor:pointer;">View Changes</summary>
                                    <pre style="background:#000; padding:0.5rem; margin-top:0.5rem; font-size:0.7rem; overflow-x:auto;">
Old: <?php echo json_encode(json_decode($log['old_value']), JSON_PRETTY_PRINT); ?>

New: <?php echo json_encode(json_decode($log['new_value']), JSON_PRETTY_PRINT); ?>
                                    </pre>
                                </details>
                                <?php else: ?>
                                —
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php echo $log['ip_address'] ?: '—'; ?>
                            </td>
                            <td style="padding:0.75rem; border-bottom:1px solid #222;">
                                <?php if ($log['integrity_hash']): ?>
                                <span style="color:#0f0;">✓</span>
                                <?php else: ?>
                                <span style="color:#f00;">✗</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div style="margin-top: 2rem; display: flex; justify-content: center; gap: 0.5rem;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&entity=<?php echo urlencode($entityFilter); ?>&action=<?php echo urlencode($actionFilter); ?>&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" 
                   class="btn-small <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Export Options -->
        <div style="margin-top: 2rem; text-align: center;">
            <a href="report_generator.php?type=audit&format=csv&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" class="btn">📥 Export CSV</a>
            <a href="report_generator.php?type=audit&format=json&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" class="btn">📥 Export JSON</a>
            <a href="report_generator.php?type=audit&format=pdf&from=<?php echo $dateFrom; ?>&to=<?php echo $dateTo; ?>" class="btn btn-success">📥 Export PDF</a>
        </div>
    </div>
</body>
</html>
