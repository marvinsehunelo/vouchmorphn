<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/ADMIN_LAYER/Auth/AdminAuth.php';
require_once __DIR__ . '/../../src/ADMIN_LAYER/Middleware/RoleMiddleware.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use ADMIN_LAYER\Auth\AdminAuth;
use ADMIN_LAYER\Middleware\RoleMiddleware;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// Initialize
$config = require __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
$db = DBConnection::getInstance($config['db']['swap']);
$auth = new AdminAuth($db);
$roleMiddleware = new RoleMiddleware();

// Check authentication
$admin = $auth->getCurrentAdmin();
if (!$admin) {
    header('Location: admin_login.php');
    exit;
}

// Get role-based permissions
$role = $admin['role_name'];
$visibleMetrics = $roleMiddleware->getVisibleMetrics($role);
$hasAccess = function($permission) use ($roleMiddleware, $role) {
    return $roleMiddleware->hasAccess($role, $permission);
};

// Load country-specific data
$countryCode = $admin['country'];
$participantsPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/participants_{$countryCode}.json";
$participants = [];
if (file_exists($participantsPath)) {
    $data = json_decode(file_get_contents($participantsPath), true);
    $participants = $data['participants'] ?? $data;
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
            <div class="country-badge"><?php echo $countryCode; ?> · SYSTEM</div>
        </div>
        <div class="user-info">
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($admin['username']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($admin['role_name']); ?></div>
            </div>
            <a href="logout.php" class="logout-btn">LOGOUT</a>
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
            <h1><?php echo match($_GET['view'] ?? 'dashboard') {
                'dashboard' => 'EXECUTIVE DASHBOARD',
                'transactions' => 'TRANSACTION MONITORING',
                'audit' => 'AUDIT TRAIL',
                'reports' => 'REGULATORY REPORTS',
                'admins' => 'ADMINISTRATOR MANAGEMENT',
                'config' => 'SYSTEM CONFIGURATION',
                default => 'DASHBOARD'
            }; ?></h1>
            <div class="timestamp">Last updated: <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>

        <?php
        $view = $_GET['view'] ?? 'dashboard';
        
        // Role-based view routing
        if ($view === 'dashboard') {
            include 'views/dashboard_view.php';
        } elseif ($view === 'transactions' && $hasAccess('view_transactions')) {
            include 'views/transactions_view.php';
        } elseif ($view === 'audit' && $hasAccess('view_audit_logs')) {
            include 'views/audit_view.php';
        } elseif ($view === 'reports' && $hasAccess('generate_reports')) {
            include 'views/reports_view.php';
        } elseif ($view === 'admins' && $hasAccess('manage_admins')) {
            include 'views/admins_view.php';
        } elseif ($view === 'config' && $hasAccess('edit_config')) {
            include 'views/config_view.php';
        } else {
            echo '<div class="card"><p>You do not have permission to view this section.</p></div>';
        }
        ?>
    </main>

    <footer class="admin-footer">
        <p>VOUCHMORPH · <?php echo $countryCode; ?> · PRODUCTION SYSTEM</p>
        <p style="margin-top: 5px;">Bank of Botswana Regulatory Sandbox Participant</p>
    </footer>

    <script>
        // Auto-refresh metrics every 30 seconds
        setInterval(() => {
            if (document.getElementById('metrics-data')) {
                fetch('api/get_metrics.php')
                    .then(res => res.json())
                    .then(data => updateMetrics(data))
                    .catch(err => console.error('Metrics update failed:', err));
            }
        }, 30000);

        function updateMetrics(data) {
            Object.keys(data).forEach(key => {
                const el = document.getElementById(`metric-${key}`);
                if (el) el.textContent = data[key];
            });
        }

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
                    content.innerHTML = '<p>Error loading report</p>';
                });
        }
    </script>
</body>
</html>
