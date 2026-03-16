<?php
declare(strict_types=1);

// Define project root explicitly
define('PROJECT_ROOT', dirname(__DIR__, 2)); // Goes up 2 levels: /public/admin/ -> /var/www/html/

// Debug: Check paths
error_log("[ADMIN] PROJECT_ROOT: " . PROJECT_ROOT);
error_log("[ADMIN] Bootstrap path: " . PROJECT_ROOT . '/src/bootstrap.php');
error_log("[ADMIN] Bootstrap exists: " . (file_exists(PROJECT_ROOT . '/src/bootstrap.php') ? 'YES' : 'NO'));

// Load bootstrap
require_once PROJECT_ROOT . '/src/bootstrap.php';

// Now use the autoloader - NO require_once for these classes
use ADMIN_LAYER\Auth\AdminAuth;
use ADMIN_LAYER\Middleware\RoleMiddleware;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// Initialize - Use bootstrap's DB connection instead of creating new one
// The bootstrap already created $pdo and set it in $GLOBALS
if (!isset($GLOBALS['databases']['primary']) || !($GLOBALS['databases']['primary'] instanceof PDO)) {
    error_log("[ADMIN] WARNING: Global PDO not available, creating new connection");
    $config = require PROJECT_ROOT . '/src/CORE_CONFIG/load_country.php';
    $db = DBConnection::getInstance($config['db']['swap']);
} else {
    $db = $GLOBALS['databases']['primary'];
    error_log("[ADMIN] Using global PDO connection");
}

$auth = new AdminAuth($db);
$roleMiddleware = new RoleMiddleware();

// Check authentication
$admin = $auth->getCurrentAdmin();
if (!$admin) {
    header('Location: admin_login.php');
    exit;
}

// Get role-based permissions
$role = $admin['role_name'] ?? 'user';
$visibleMetrics = $roleMiddleware->getVisibleMetrics($role);
$hasAccess = function($permission) use ($roleMiddleware, $role) {
    return $roleMiddleware->hasAccess($role, $permission);
};

// Load country-specific data
$countryCode = $admin['country'] ?? SYSTEM_COUNTRY ?? 'BW';
$participantsPath = PROJECT_ROOT . "/src/CORE_CONFIG/countries/{$countryCode}/participants_{$countryCode}.json";
$participants = [];
if (file_exists($participantsPath)) {
    $data = json_decode(file_get_contents($participantsPath), true);
    $participants = $data['participants'] ?? $data;
}

// Get system metrics
$metrics = [];
try {
    // Get today's transaction count
    $stmt = $db->prepare("SELECT COUNT(*) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_transactions'] = $stmt->fetchColumn();
    
    // Get today's volume
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $metrics['today_volume'] = number_format((float)$stmt->fetchColumn(), 2);
    
    // Get active holds
    $stmt = $db->prepare("SELECT COUNT(*) FROM hold_transactions WHERE status = 'ACTIVE'");
    $stmt->execute();
    $metrics['active_holds'] = $stmt->fetchColumn();
    
    // Get pending settlements
    $stmt = $db->prepare("SELECT COUNT(*) FROM settlement_queue WHERE status = 'PENDING'");
    $stmt->execute();
    $metrics['pending_settlements'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("[ADMIN] Error fetching metrics: " . $e->getMessage());
    $metrics = [
        'today_transactions' => 0,
        'today_volume' => '0.00',
        'active_holds' => 0,
        'pending_settlements' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN DASHBOARD</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* HEADER */
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .logo span {
            color: #FFDA63;
            margin-left: 10px;
            font-size: 0.8rem;
        }

        .country-badge {
            padding: 5px 15px;
            background: rgba(255, 218, 99, 0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: #FFDA63;
        }

        .user-role {
            font-size: 0.7rem;
            color: #A1B5D8;
            text-transform: uppercase;
        }

        .logout-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        /* NAVIGATION */
        .admin-nav {
            background: #fff;
            border-bottom: 2px solid #001B44;
            padding: 0 30px;
            display: flex;
            gap: 30px;
        }

        .nav-item {
            padding: 15px 0;
            color: #666;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .nav-item:hover {
            color: #001B44;
        }

        .nav-item.active {
            color: #001B44;
            border-bottom-color: #FFDA63;
        }

        /* MAIN CONTENT */
        .admin-content {
            flex: 1;
            padding: 30px;
        }

        .content-header {
            margin-bottom: 30px;
        }

        .content-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #001B44;
            margin-bottom: 5px;
        }

        .content-header .timestamp {
            color: #666;
            font-size: 0.8rem;
        }

        /* METRICS GRID */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
        }

        .metric-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .metric-value {
            font-size: 2.2rem;
            font-weight: 600;
            color: #001B44;
            line-height: 1.2;
        }

        .metric-change {
            font-size: 0.8rem;
            color: #0f0;
            margin-top: 5px;
        }

        /* GRID LAYOUTS */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        /* CARDS */
        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #001B44;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .card-badge {
            padding: 3px 10px;
            background: #001B44;
            color: #fff;
            font-size: 0.7rem;
        }

        /* TABLES */
        .table-responsive {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            background: #001B44;
            color: #fff;
            padding: 12px;
            font-weight: 600;
            text-align: left;
            position: sticky;
            top: 0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .text-right {
            text-align: right;
        }

        .positive {
            color: #0f0;
        }

        .negative {
            color: #f00;
        }

        /* STATUS BADGES */
        .status {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }

        .status-error {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* ROLE-SPECIFIC STYLES */
        .regulator-view {
            border-left: 5px solid #FFDA63;
        }

        .compliance-view {
            border-left: 5px solid #17a2b8;
        }

        .auditor-view {
            border-left: 5px solid #6c757d;
        }

        /* REPORTS SECTION */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .report-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 0 #FFDA63;
        }

        .report-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .report-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .report-desc {
            font-size: 0.8rem;
            color: #666;
        }

        /* FOOTER */
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
        }

        /* MODAL */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
        }

        .modal-content {
            background: #fff;
            width: 90%;
            max-width: 1200px;
            margin: 50px auto;
            border: 3px solid #001B44;
            padding: 30px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-close {
            float: right;
            font-size: 30px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="header-left">
            <div class="logo">VOUCHMORPH <span>ADMIN</span></div>
            <div class="country-badge"><?php echo htmlspecialchars($countryCode); ?> · SYSTEM</div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($admin['username'] ?? 'Admin'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($role); ?></div>
            </div>
            <a href="admin_logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </header>

    <nav class="admin-nav">
        <a href="?view=dashboard" class="nav-item <?php echo ($_GET['view'] ?? 'dashboard') === 'dashboard' ? 'active' : ''; ?>">DASHBOARD</a>
        
        <?php if ($hasAccess('view_transactions')): ?>
            <a href="?view=transactions" class="nav-item <?php echo ($_GET['view'] ?? '') === 'transactions' ? 'active' : ''; ?>">TRANSACTIONS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('view_audit_logs')): ?>
            <a href="?view=audit" class="nav-item <?php echo ($_GET['view'] ?? '') === 'audit' ? 'active' : ''; ?>">AUDIT LOGS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('generate_reports')): ?>
            <a href="?view=reports" class="nav-item <?php echo ($_GET['view'] ?? '') === 'reports' ? 'active' : ''; ?>">REPORTS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('manage_admins')): ?>
            <a href="?view=admins" class="nav-item <?php echo ($_GET['view'] ?? '') === 'admins' ? 'active' : ''; ?>">ADMINISTRATORS</a>
        <?php endif; ?>
        
        <?php if ($hasAccess('edit_config')): ?>
            <a href="?view=config" class="nav-item <?php echo ($_GET['view'] ?? '') === 'config' ? 'active' : ''; ?>">CONFIGURATION</a>
        <?php endif; ?>
    </nav>

    <main class="admin-content">
        <div class="content-header">
            <h1>EXECUTIVE DASHBOARD</h1>
            <div class="timestamp">Last updated: <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <!-- METRICS GRID -->
        <div class="metrics-grid" id="metrics-data">
            <div class="metric-card">
                <div class="metric-label">TODAY'S TRANSACTIONS</div>
                <div class="metric-value" id="metric-today_transactions"><?php echo $metrics['today_transactions']; ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TODAY'S VOLUME (BWP)</div>
                <div class="metric-value" id="metric-today_volume"><?php echo $metrics['today_volume']; ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE HOLDS</div>
                <div class="metric-value" id="metric-active_holds"><?php echo $metrics['active_holds']; ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PENDING SETTLEMENTS</div>
                <div class="metric-value" id="metric-pending_settlements"><?php echo $metrics['pending_settlements']; ?></div>
            </div>
        </div>

        <!-- QUICK STATS ROW -->
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">PARTICIPANTS OVERVIEW</span>
                    <span class="card-badge"><?php echo count($participants); ?> ACTIVE</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $count = 0;
                            foreach ($participants as $code => $p): 
                                if ($count++ >= 5) break;
                                $type = $p['type'] ?? $p['category'] ?? 'Unknown';
                                $status = $p['status'] ?? 'ACTIVE';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($code); ?></td>
                                <td><?php echo htmlspecialchars($type); ?></td>
                                <td><span class="status status-success"><?php echo htmlspecialchars($status); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">SYSTEM HEALTH</span>
                    <span class="card-badge">LIVE</span>
                </div>
                <div style="padding: 20px;">
                    <p><strong>Country:</strong> <?php echo htmlspecialchars($countryCode); ?></p>
                    <p><strong>Environment:</strong> <?php echo htmlspecialchars(getenv('APP_ENV') ?: 'production'); ?></p>
                    <p><strong>Database:</strong> Connected</p>
                    <p><strong>Last Cron:</strong> <?php echo date('Y-m-d H:i:s', filemtime(PROJECT_ROOT . '/src/APP_LAYER/logs/cron.log') ?: time()); ?></p>
                    <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                </div>
            </div>
        </div>

        <!-- REPORTS SECTION (for regulators/compliance) -->
        <?php if ($hasAccess('generate_reports')): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">REGULATORY REPORTS</span>
                <span class="card-badge">BANK OF BOTSWANA</span>
            </div>
            <div class="reports-grid">
                <div class="report-card" onclick="openReport('reports/daily_settlement.php')">
                    <div class="report-icon">📊</div>
                    <div class="report-title">Daily Settlement Report</div>
                    <div class="report-desc">End-of-day net positions and settlement amounts</div>
                </div>
                <div class="report-card" onclick="openReport('reports/transaction_audit.php')">
                    <div class="report-icon">🔍</div>
                    <div class="report-title">Transaction Audit Log</div>
                    <div class="report-desc">7-year audit trail of all swaps</div>
                </div>
                <div class="report-card" onclick="openReport('reports/fraud_monitoring.php')">
                    <div class="report-icon">⚠️</div>
                    <div class="report-title">Fraud Monitoring</div>
                    <div class="report-desc">Suspicious transaction patterns</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo htmlspecialchars($countryCode); ?> · PRODUCTION SYSTEM</p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>

    <!-- REPORT MODAL -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="document.getElementById('reportModal').style.display='none'">&times;</span>
            <div id="reportContent"></div>
        </div>
    </div>

    <script>
        // Auto-refresh metrics every 30 seconds
        setInterval(() => {
            fetch('api/get_metrics.php')
                .then(res => res.json())
                .then(data => {
                    if (data.today_transactions !== undefined) 
                        document.getElementById('metric-today_transactions').textContent = data.today_transactions;
                    if (data.today_volume !== undefined) 
                        document.getElementById('metric-today_volume').textContent = data.today_volume;
                    if (data.active_holds !== undefined) 
                        document.getElementById('metric-active_holds').textContent = data.active_holds;
                    if (data.pending_settlements !== undefined) 
                        document.getElementById('metric-pending_settlements').textContent = data.pending_settlements;
                })
                .catch(err => console.error('Metrics update failed:', err));
        }, 30000);

        function openReport(url) {
            const modal = document.getElementById('reportModal');
            const content = document.getElementById('reportContent');
            content.innerHTML = '<p>Loading...</p>';
            modal.style.display = 'block';
            
            fetch(url)
                .then(res => res.text())
                .then(html => {
                    content.innerHTML = html;
                })
                .catch(err => {
                    content.innerHTML = '<p>Error loading report: ' + err.message + '</p>';
                });
        }
    </script>
</body>
</html>
