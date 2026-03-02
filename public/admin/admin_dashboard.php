<?php
// --- 0. AUTOLOAD & SESSION ---
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/LedgerService.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/TransactionService.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/AdminService.php';

use APP_LAYER\Utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\AdminService;
use BUSINESS_LOGIC_LAYER\services\LedgerService;
use BUSINESS_LOGIC_LAYER\services\NotificationService;
use BUSINESS_LOGIC_LAYER\services\ExpiredSwapsService;
use BUSINESS_LOGIC_LAYER\services\LicenceEnforcementService;
use BUSINESS_LOGIC_LAYER\controllers\DashboardController;
use BUSINESS_LOGIC_LAYER\controllers\TransactionController;

SessionManager::start();

// --- 1. AUTHENTICATION ---
if (!SessionManager::isLoggedIn()) {
    header('Location: admin_login.php');
    exit;
}

$user = SessionManager::getUser();

if (isset($user['admin_id'])) {
    $user['id'] = $user['admin_id'];
    $_SESSION['user_id'] = $user['admin_id']; 
}

// Roles aligned with your PostgreSQL seeded roles
$validRoles = ['admin', 'compliance', 'auditor', 'GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN'];

if (!isset($user['role']) || !in_array($user['role'], $validRoles)) {
    SessionManager::destroy();
    header('Location: admin_login.php?error=unauthorized');
    exit;
}

// --- 1.6 DYNAMIC LICENCE ENFORCEMENT ---
$currentCountry = $user['country'] ?? 'BW';

try {
    LicenceEnforcementService::assertCountryLicenceActive($currentCountry);
} catch (\Exception $e) {
    error_log("Licence Failure for {$user['username']} ({$currentCountry}): " . $e->getMessage());
    http_response_code(403);
    die("SYSTEM SUSPENDED: " . $e->getMessage());
}

// --- 1.7 RBAC DEFINITIONS ---
$role = $user['role'];
$can_manage_admins   = in_array($role, ['GLOBAL_OWNER', 'admin']);
$can_edit_config     = in_array($role, ['GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN']);
$can_broadcast       = in_array($role, ['GLOBAL_OWNER', 'admin']);
$can_trigger_cron    = in_array($role, ['GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'admin']);
$can_view_reports    = true; 
$is_read_only        = in_array($role, ['auditor', 'SUPPORT']);

// --- 2. DATABASE CONNECTIONS ---
// Path Fixed: Point to the new country-specific subdirectory
$countryCode = require __DIR__ . '/../../src/CORE_CONFIG/system_country.php';
$configPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/config_{$countryCode}.php";

if (!file_exists($configPath)) {
    die("Configuration Error: Config not found for {$countryCode}");
}
$config = require $configPath;

$swapDB = null;
$dbError = null;
try {
    // Uses the PostgreSQL 'swap' credentials from config_BW.php
    $swapDB = DBConnection::getInstance($config['db']['swap']);
} catch (\Throwable $e) {
    error_log("Swap Database connection failed: " . $e->getMessage());
    $dbError = "Swap Database connection failed.";
}

// Load bank connections (MySQL/MariaDB)
$banks = [];
foreach ($config['db'] as $bankName => $dbConfig) {
    if ($bankName === 'swap') continue;
    try {
        $banks[$bankName] = DBConnection::getInstance($dbConfig);
    } catch (\Throwable $e) {
        error_log("Bank connection failed: " . $bankName . " - " . $e->getMessage());
    }
}

// Load participants config
// Path Fixed: Point to countries/BW/participants_BW.json
$participantsPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/participants_{$countryCode}.json";
$participants = [];
try {
    if (!file_exists($participantsPath)) {
        throw new \Exception("Participants config not found at {$participantsPath}");
    }
    $jsonContent = file_get_contents($participantsPath);
    $participantsData = json_decode($jsonContent, true);
    
    // Support both direct array or wrapped 'participants' key
    $participants = $participantsData['participants'] ?? $participantsData;
} catch (\Throwable $e) {
    error_log("Participants configuration error: " . $e->getMessage());
}

// --- 3. TOOL AND ACTION HANDLER ---
$active_tool = $_GET['tool'] ?? 'dashboard';
$main_title = match ($active_tool) {
    'transactions'  => 'TRANSACTION LOGS OVERVIEW',
    'user_access'   => 'ADMIN AND USER ACCESS MANAGEMENT',
    'broadcaster'   => 'GLOBAL NOTIFICATION BROADCASTER',
    'config_editor' => 'SYSTEM CONFIGURATION EDITOR',
    default         => 'EXECUTIVE DASHBOARD OVERVIEW',
};
$tool_message = '';
$message = '';
$transactions = [];
$newAdminMsg = ''; 

$dashboardController = isset($swapDB) ? new DashboardController($swapDB) : null;
$adminService = isset($swapDB) ? new AdminService($swapDB) : null;

// Session Validation against DB
if ($adminService && isset($user['id'])) {
    $dbCheck = $swapDB->prepare("SELECT admin_id FROM admins WHERE admin_id = ?");
    $dbCheck->execute([$user['id']]);
    if (!$dbCheck->fetch()) {
        SessionManager::destroy();
        header('Location: admin_login.php?error=session_invalid');
        exit;
    }
}

// ... [Tool Handlers for 'broadcaster', 'transactions' remain same as previous logic] ...

if ($active_tool === 'config_editor') {
    // Path Fixed: Config Editor now targets the nested file
    $configPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/config_{$countryCode}.php";
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'save_config') {
        try {
            $dataToSave = $_POST;
            unset($dataToSave['action']);
            file_put_contents($configPath, '<?php return ' . var_export($dataToSave, true) . ';');
            $config = require $configPath;
            $message = "Configuration updated successfully.";
        } catch (\Throwable $e) {
            $message = "Configuration update failed: " . $e->getMessage();
        }
    }
}

// ... [AJAX Handlers and ExpiredSwaps logic remain same] ...

// --- 5. FETCH ADMIN DATA & METRICS ---
$admins = [];
if ($adminService) {
    try {
        $admins = $adminService->getAllAdmins();
    } catch (\Throwable $e) {
        error_log("Admin Fetch Error: " . $e->getMessage());
    }
}

$rawMetrics = ['total_users' => 0, 'total_transactions' => 0, 'total_wallet_value' => 0.00, 'total_vouchers' => 0];
if ($dashboardController) {
    try {
        $rawMetrics['total_users'] = $dashboardController->getTotalUsers();
        $rawMetrics['total_transactions'] = $dashboardController->getTotalTransactions();
        $rawMetrics['total_wallet_value'] = $dashboardController->getTotalWalletValue();
        $rawMetrics['total_vouchers'] = $dashboardController->getTotalVouchers();
    } catch (\Throwable $e) {
        error_log("Dashboard Metrics Fetch Error: " . $e->getMessage());
    }
}

$metrics = [
    'TOTAL_USERS' => number_format($rawMetrics['total_users']),
    'TOTAL_TRANSACTIONS' => number_format($rawMetrics['total_transactions']),
    'TOTAL_WALLET_VALUE' => '$' . number_format($rawMetrics['total_wallet_value'], 2),
    'TOTAL_VOUCHERS' => number_format($rawMetrics['total_vouchers']),
];

$primaryMetricsJson = json_encode($metrics);

// Bank Balances Logic
$banksOnly = array_filter($participants, fn($p) => ($p['type'] ?? '') === 'bank');
$bankBalances = [];
foreach ($banksOnly as $name => $p) {
    $balances = $p['balances'] ?? ['middleman_escrow' => 0, 'partner_bank_settlement' => 0, 'middleman_revenue' => 0];
    $bankBalances[strtoupper($name)] = $balances;
}
$bankBalancesJson = json_encode($bankBalances);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - <?php echo htmlspecialchars($main_title); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ----------------------------------------------------------------------------------
 * CORE FINTECH STYLE: IBM Plex Mono, Navy/White (High Contrast, High Authority)
 * ---------------------------------------------------------------------------------- */
body {
    font-family: 'IBM Plex Mono', monospace;
    background: #f7f9fc;
    color: #001B44;
    margin:0;
    padding:0;
    display:flex;
    flex-direction:column;
    height:100%;
}
.cb-header, .cb-footer {
    background:#001B44;
    color:#fff;
    padding:15px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    /* Bold separation */
    border-bottom: 5px solid #FFDA63;
}
.cb-header h1 {
    font-size:1.2rem;
    letter-spacing:2px; /* Increased for stronger presence */
    font-weight: 700;
}
.cb-header nav a {
    color:#A1B5D8;
    text-decoration:none;
    margin-left:20px;
    font-weight:500;
    transition:color 0.2s;
}
.cb-header nav a:hover, .cb-header nav a.active {
    color:#fff;
    /* Add underline on active for visual strength */
    border-bottom: 2px solid #FFDA63;
}
.cb-dashboard {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
    gap:20px;
    padding:30px;
}
.cb-card {
    background:#fff;
    border:2px solid #001B44; /* Thicker border for weight */
    border-radius:0;
    padding:20px;
    box-shadow:4px 4px 0 #A1B5D8; /* Subtler shadow color */
    transition:0.3s;
}
.cb-card:hover {
    box-shadow:6px 6px 0 #A1B5D8; /* Deeper shadow on hover */
}
.cb-card h2 {
    font-size:1.1rem;
    color:#001B44;
    margin-bottom:10px;
    border-bottom:3px solid #001B44; /* Thicker underline for title */
    padding-bottom:5px;
    font-weight: 700;
}
.cb-card p {
    font-size:1.1rem;
    font-weight:600;
    margin-top:10px;
}
.cb-card.full {
    grid-column: span 1;
}
.cb-footer {
    text-align:center;
    font-size:0.9rem;
    margin-top:auto;
}
.cb-container {
    min-height: calc(100vh - 78px - 45px);
    display:flex;
    flex-direction:column;
}
.cb-content {
    padding:30px;
    flex-grow:1;
}
.cb-content h2 {
    font-size:1.5rem;
    color:#001B44;
    border-bottom:4px solid #001B44;
    padding-bottom:10px;
    margin-bottom:20px;
    font-weight: 700;
}
.cb-message {
    padding:10px;
    margin-bottom:20px;
    border:2px solid;
    font-weight:bold;
}
.cb-message.success {
    background:#D4EDDA; /* Light success color */
    border-color:#155724;
    color:#155724;
}
.cb-message.error {
    background:#F8D7DA; /* Light error color */
    border-color:#721c24;
    color:#721c24;
}
.cb-table {
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
    font-size:0.9rem;
}
.cb-table th, .cb-table td {
    border:1px solid #ccc;
    padding:10px;
    text-align:left;
}
.cb-table th {
    background-color:#001B44;
    color:#fff;
    font-weight:600;
}
.cb-table tr:nth-child(even) {
    background-color:#f0f0f0;
}
/* Input styles consolidated and strengthened */
.cb-form textarea,
.cb-form input[type="text"],
.cb-form input[type="email"],
.cb-form input[type="password"],
.cb-form .cb-select {
    width: 100%;
    padding: 10px;
    box-sizing: border-box;
    border: 2px solid #001B44; /* Thicker border */
    font-family: 'IBM Plex Mono', monospace;
    margin-bottom: 15px;
}
.cb-form .cb-label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #001B44;
}
.cb-form button {
    background-color:#001B44;
    color:#fff;
    border:none;
    padding:12px 25px; /* Slightly larger button */
    cursor:pointer;
    font-family:'IBM Plex Mono', monospace;
    font-weight:600;
    transition:background-color 0.2s, color 0.2s;
}
.cb-form button:hover {
    background-color:#FFDA63; /* Accent color on hover */
    color:#001B44;
}
.config-table {
    width:100%;
    border-collapse:collapse;
}
.config-table td {
    padding:10px;
    border:1px solid #ccc;
}
.config-table input[type="text"] {
    width:95%;
    padding:5px;
    border:1px solid #001B44;
    font-family:'IBM Plex Mono', monospace;
}

/* --- SMALL MODAL STYLES (for forms like Add Admin) --- */
/* Kept the original .cb-modal for small forms */
.cb-modal {
    display:none;
    position:fixed;
    z-index:1000;
    padding-top:100px;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
}
.cb-modal-content {
    background:#fff;
    margin:auto;
    padding:30px; /* Increased padding */
    width:40%;
    border:3px solid #001B44;
    box-shadow:4px 4px 0 #001B44;
}
.cb-modal-content .close {
    float:right;
    font-size:30px;
    cursor:pointer;
    font-weight: bold;
}

/* --- DASHBOARD COMPONENT STYLES --- */
.dropdown-card-container { display: flex; flex-direction: column; gap: 10px; min-height: 250px; }
.metric-select, .bank-balance-select, #reportSelector {
    padding: 10px;
    border: 2px solid #001B44; /* Strong border */
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1rem;
    background-color: #fff;
}
#metricDisplay, #bankBalanceDisplay, #expiredSwapsResult {
    background: #fff;
    border: 2px solid #A1B5D8; /* Subtler border for inner display */
    padding: 20px;
    min-height: 80px;
}
#metricDisplay p {
    font-size: 2.5rem; /* Larger metric numbers */
    font-weight: 700;
    color: #001B44;
}

/* ----------------------------------------------------------------------------------
 * FULL-PAGE MODAL STYLES (for Reports)
 * ---------------------------------------------------------------------------------- */
#adminModal, #reportModal {
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100vw;
    height:100vh;
    overflow-y:auto;
    background-color: rgba(0,0,0,0.95); /* Deeper overlay for focus */
    z-index:9999;
    padding:0; /* Padding is on content */
    box-sizing:border-box;
}

#reportModalContent {
    background:#fff;
    width:95%; /* Wider content area */
    max-width:1400px; /* Maximize space for reports */
    min-height:95vh;
    margin:2vh auto; /* Center with a small offset */
    padding:30px;
    box-sizing:border-box;
    border:5px solid #FFDA63; /* Accent border on the full report */
    box-shadow:0 0 30px rgba(255, 218, 99, 0.5); /* Glowing shadow */
    overflow-y:auto;
    position:relative;
}

/* Close button */
#reportModal .close {
    position:fixed; /* Fixed position relative to the viewport */
    top:20px;
    right:30px;
    font-size:40px; /* Large, easy to hit */
    font-weight:bold;
    color:#FFDA63; /* Accent color */
    cursor:pointer;
    z-index:10000;
    text-shadow: 1px 1px 2px #001B44;
    transition: color 0.2s;
}
#reportModal .close:hover {
    color: #fff;
}

/* Responsive for mobile */
@media (max-width:768px){
    #reportModalContent {
        width:98%;
        margin:0.5vh auto;
        padding:15px;
    }
}
</style>

</head>
<body>

<header class="cb-header">
    <h1>ADMIN PANEL | COUNTRY: <?php echo strtoupper($currentCountry); ?></h1>
    <nav>
        <a href="?tool=dashboard" class="<?php echo $active_tool==='dashboard'?'active':''; ?>">Dashboard</a>
        <a href="?tool=transactions" class="<?php echo $active_tool==='transactions'?'active':''; ?>">Transactions</a>
        <a href="?tool=user_access" class="<?php echo $active_tool==='user_access'?'active':''; ?>">Admins</a>
        <a href="?tool=broadcaster" class="<?php echo $active_tool==='broadcaster'?'active':''; ?>">Broadcaster</a>
        <a href="?tool=config_editor" class="<?php echo $active_tool==='config_editor'?'active':''; ?>">Config</a>
        <a href="admin_logout.php">Logout</a>
    </nav>
</header>

<div class="cb-container">
<main class="cb-content">
<h2><?php echo htmlspecialchars($main_title); ?></h2>

<?php if($dbError): ?>
<div class="cb-message error"><strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?></div>
<?php endif; ?>

<?php if(!empty($message)): ?>
<div class="cb-message success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if(!empty($tool_message)):
    $isSuccess = strpos($tool_message, 'successfully') !== false;
    $class = $isSuccess ? 'success' : 'error';
?>
<div class="cb-message <?php echo $class; ?>"><?php echo htmlspecialchars($tool_message); ?></div>
<?php endif; ?>

<?php if($active_tool==='dashboard'): ?>
<div class="cb-dashboard">

<div id="metricDisplay">
    <?php 
    // DYNAMIC LICENSE CHECK
    $registryPath = __DIR__ . '/../../src/CORE_CONFIG/licences/global_licence_registry.json';
    $registry = file_exists($registryPath) ? json_decode(file_get_contents($registryPath), true) : [];
    
    // Using $currentCountry here ensures the license check matches the header
    $isLicenseActive = (isset($registry[$currentCountry]) && $registry[$currentCountry]['status'] === 'ACTIVE');

    if ($isLicenseActive): ?>
        <h2>TOTAL USERS</h2>
        <p><?php echo htmlspecialchars($metrics['TOTAL_USERS']); ?></p>
    <?php else: ?>
        <h2 style="color:red;">SYSTEM SUSPENDED</h2>
        <p style="font-size:1rem;">License inactive for <?php echo htmlspecialchars($currentCountry); ?></p>
    <?php endif; ?>
</div>

<div class="cb-card dropdown-card-container">
    <h2>REPORTS</h2>
    <select id="reportSelector">
    <option value="">-- Select a report --</option>
    <option value="daily_reconciliations.php">Daily Reconciliations</option>
    <option value="weekly_reconciliations.php">Weekly Reconciliations</option>
    <option value="monthly_reconciliations.php">Monthly Reconciliations</option>
    <option value="audit_trails.php">Audit Trails</option>
    <option value="suspicious_activity_report.php">Suspicious Activity</option>
</select>

<p>Select a report to open it in a full-screen viewer.</p>

</div>


    <div class="cb-card dropdown-card-container">
        <h2>SYSTEM OVERVIEW METRICS</h2>
        <select id="metricSelector" class="metric-select">
            <option value="TOTAL_USERS">Total Users</option>
            <option value="TOTAL_TRANSACTIONS">Total Transactions</option>
            <option value="TOTAL_WALLET_VALUE">Total Wallet Value</option>
            <option value="TOTAL_VOUCHERS">Total Vouchers</option>
        </select>
       <div id="metricDisplay">
    <?php 
// Dynamic license check based on user's country
$registryPath = __DIR__ . '/../../src/CORE_CONFIG/licences/global_licence_registry.json';
$registry = json_decode(file_get_contents($registryPath), true);
$userCountry = $user['country'] ?? $country; // Use user's assigned country
$isLicenseActive = (isset($registry[$userCountry]) && $registry[$userCountry]['status'] === 'ACTIVE');

if ($isLicenseActive): ?>
    <h2>TOTAL USERS</h2>
    <p><?php echo htmlspecialchars($metrics['TOTAL_USERS']); ?></p>
<?php else: ?>
    <h2 style="color:red;">SYSTEM SUSPENDED</h2>
    <p style="font-size:1rem;">License inactive for <?php echo htmlspecialchars($userCountry); ?></p>
<?php endif; ?>
</div>
    </div>


    <div class="cb-card dropdown-card-container">
        <h2>BANK PARTICIPANT BALANCES</h2>
        <select id="bankSelector" class="bank-balance-select">
            <option value="">-- Select a Bank --</option>
            <?php foreach($bankBalances as $bankName => $balances): ?>
                <option value="<?php echo htmlspecialchars($bankName); ?>"><?php echo htmlspecialchars($bankName); ?></option>
            <?php endforeach; ?>
        </select>
        <div id="bankBalanceDisplay">
            <p>Select a bank to view its current balances.</p>
        </div>
    </div>


    <div class="cb-card dropdown-card-container">
        <h2>CRON JOB: EXPIRED SWAPS</h2>
        <p>Manually trigger the expired swaps processing service (Requires all bank connections).</p>
        <button id="processExpiredSwaps">Process Expired Swaps</button>
        <div id="expiredSwapsResult"></div>
    </div>
</div>

<?php elseif($active_tool==='transactions'): ?>
<?php if(!empty($transactions)): ?>
<table class="cb-table">
<thead>
<tr><th>ID</th><th>User ID</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr>
</thead>
<tbody>
<?php foreach($transactions as $t): ?>
<tr>
<td><?php echo htmlspecialchars($t['id']??'N/A'); ?></td>
<td><?php echo htmlspecialchars($t['user_id']??'N/A'); ?></td>
<td><?php echo htmlspecialchars($t['type']??'N/A'); ?></td>
<td><?php echo htmlspecialchars($t['amount']??'N/A'); ?></td>
<td><?php echo htmlspecialchars($t['status']??'N/A'); ?></td>
<td><?php echo htmlspecialchars($t['created_at']??'N/A'); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p>No transactions found or database connection failed.</p>
<?php endif; ?>

<?php elseif($active_tool==='user_access'): ?>

<div class="cb-dashboard">

    <div class="cb-card">
        <h2>Existing Admins</h2>
        <?php if(!empty($admins)): ?>
        <table class="cb-table" id="adminTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($admins as $a): ?>
                <tr data-id="<?php echo htmlspecialchars($a['id']??'N/A'); ?>">
                    <td><?php echo htmlspecialchars($a['id']??'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($a['username']??'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($a['email']??'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($a['role']??'N/A'); ?></td>
                    <td>
                        <button class="editAdminBtn"
                            data-id="<?php echo htmlspecialchars($a['id']??''); ?>"
                            data-username="<?php echo htmlspecialchars($a['username']??''); ?>"
                            data-email="<?php echo htmlspecialchars($a['email']??''); ?>"
                            data-role="<?php echo htmlspecialchars($a['role']??''); ?>">
                            Edit
                        </button>
                        <button class="resetPwdBtn" data-id="<?php echo htmlspecialchars($a['id']??''); ?>">Reset</button>
                        <button class="deleteAdminBtn" data-id="<?php echo htmlspecialchars($a['id']??''); ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="adminPagination" style="margin-top: 10px;"></div>
        <?php else: ?>
        <p>No admin data available.</p>
        <?php endif; ?>
    </div>

    <div class="cb-card">
        <h2>Add New Admin</h2>
        <form method="post" class="cb-form">
            <input type="hidden" name="action" value="add_admin">

            <label class="cb-label">Username:</label>
            <input type="text" name="username" class="cb-input" required>

            <label class="cb-label">Email:</label>
            <input type="email" name="email" class="cb-input" required>

            <label class="cb-label">Password:</label>
            <input type="password" name="password" class="cb-input" required>

            <label class="cb-label">Role:</label>

            <label class="cb-label">Role:</label>
<select name="role" class="cb-select" required>
    <option value="GLOBAL_OWNER">Global Owner</option>
    <option value="COUNTRY_MIDDLEMAN">Country Middleman</option>
    <option value="BANK_ADMIN">Bank Admin</option>
    <option value="AUDITOR">Auditor</option>
    <option value="SUPPORT">Support</option>
</select>

            <button type="submit" class="cb-btn">Add Admin</button>
        </form>
    </div>
</div>

<div id="adminModal" class="cb-modal">
    <div class="cb-modal-content">
        <span class="close">&times;</span>
        <h2 id="modalTitle">Edit Admin</h2>
        <form id="adminModalForm">
            <input type="hidden" name="admin_id" id="modalAdminId">
            <label class="cb-label">Username:</label>
            <input type="text" name="username" id="modalUsername" class="cb-input" required>
            <label class="cb-label">Email:</label>
            <input type="email" name="email" id="modalEmail" class="cb-input" required>
            <label class="cb-label">Role:</label>
            <select name="role" id="modalRole" class="cb-select" required>
                <?php foreach($roles as $r){
                    echo "<option value=\"".htmlspecialchars($r)."\">".htmlspecialchars(ucfirst($r))."</option>";
                } ?>
            </select>
            <label class="cb-label" id="passwordLabel" style="display:none;">New Password:</label>
            <input type="password" name="new_password" id="modalPassword" class="cb-input" style="display:none;">
            <button type="submit" class="cb-btn" id="modalSubmitBtn">Save Changes</button>
        </form>
    </div>
</div>
<?php elseif($active_tool==='broadcaster'): ?>
<form method="post" class="cb-form">
<textarea name="message" placeholder="Enter broadcast message..."></textarea>
<input type="hidden" name="action" value="broadcast">
<button type="submit">Send Broadcast</button>
</form>

<?php elseif($active_tool==='config_editor'): ?>
<form method="post" class="cb-form">
<input type="hidden" name="action" value="save_config">
<table class="config-table">
<?php foreach($config as $key=>$val): ?>
<tr>
<td><?php echo htmlspecialchars($key); ?></td>
<td>
<input type="text" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars(is_array($val)?json_encode($val):$val); ?>">
</td>
</tr>
<?php endforeach; ?>
</table>
<button type="submit">Save Configuration</button>
</form>
<?php endif; ?>

</main>
</div>

<div id="reportModal">
    <div class="cb-modal-content" id="reportModalContent">
        <span class="close" onclick="document.getElementById('reportModal').style.display='none';">&times;</span>
        <h2 id="reportModalTitle">Report Name</h2>
        <div id="reportModalDisplay">
            <p style="text-align:center;">Select a report to view it.</p>
        </div>
    </div>
</div>
<footer class="cb-footer">
    &copy; <?php echo date('Y'); ?> Admin Dashboard
</footer>

<script>
// ==============================
// --- PRIMARY METRICS ---
// ==============================
const PRIMARY_METRICS = <?php echo $primaryMetricsJson; ?>;
const metricSelector = document.getElementById('metricSelector');
const metricDisplay = document.getElementById('metricDisplay');

function updateMetricDisplay() {
    const metricSelector = document.getElementById('metricSelector');
    if (!metricSelector) {
        // Metric selector not present on this page
        return;
    }
    const key = metricSelector.value;
    const title = key.replace(/_/g, ' ');
    const value = PRIMARY_METRICS[key] ?? 'N/A';
    metricDisplay.innerHTML = `<h2>${title}</h2><p>${value}</p>`;
}

metricSelector?.addEventListener('change', updateMetricDisplay);
// Initial load
updateMetricDisplay();

// ==============================
// --- BANK BALANCES ---
// ==============================

function formatCurrency(amount) {
    const num = parseFloat(amount);
    return isNaN(num)
        ? amount
        : 'BWP ' + num.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
}

async function updateBankBalanceDisplay() {
    const bankSelector = document.getElementById('bankSelector');
    const bankBalanceDisplay = document.getElementById('bankBalanceDisplay');

    // Tool/page guard — prevents null reference crashes
    if (!bankSelector || !bankBalanceDisplay) return;

    const selectedBank = bankSelector.value;

    if (!selectedBank) {
        bankBalanceDisplay.innerHTML =
            '<p>Select a bank to view its current balances.</p>';
        return;
    }

    bankBalanceDisplay.innerHTML = '<p>Loading balances...</p>';

    try {
        const res = await fetch('../../src/ADMIN_LAYER/api/fetch_bank_balance.php');

        if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
        }

        const data = await res.json();

        if (!data || data.status !== 'success' || !data.banks) {
            throw new Error('Invalid API response');
        }

        const balances = data.banks?.[selectedBank.toUpperCase()] ?? {};

        if (Object.keys(balances).length === 0) {
            bankBalanceDisplay.innerHTML =
                `<p>No balances available for ${selectedBank.toUpperCase()}.</p>`;
            return;
        }

        let html = `<h2>${selectedBank.toUpperCase()} BALANCES</h2><ul>`;

        for (const [account, value] of Object.entries(balances)) {
            html += `
                <li>
                    ${account.replace(/_/g, ' ')}:
                    <strong>${formatCurrency(value)}</strong>
                </li>
            `;
        }

        html += '</ul>';
        bankBalanceDisplay.innerHTML = html;

    } catch (err) {
        console.error('Error fetching balances:', err);
        bankBalanceDisplay.innerHTML =
            '<p class="error">Failed to load bank balances.</p>';
    }
}

// Safe event binding
const bankSelector = document.getElementById('bankSelector');
bankSelector?.addEventListener('change', updateBankBalanceDisplay);

// Initial load (safe even if elements don’t exist)
updateBankBalanceDisplay();

// ==============================
// --- EXPIRED SWAPS ---
// ==============================
document.getElementById('processExpiredSwaps')?.addEventListener('click', () => {
    const resultDiv = document.getElementById('expiredSwapsResult');
    resultDiv.innerHTML = 'Processing...';

    fetch('process_expired_swaps.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({expiredSwaps:true})
    })
    .then(res => res.json())
    .then(data => {
        resultDiv.innerHTML = data.status === 'success' ? 'Processed: ' + data.processed : 'Error: ' + (data.message || 'Unknown error');
    })
    .catch(err => resultDiv.innerHTML = 'Request failed: ' + err);
});

// ==============================
// --- ADMIN PANEL ---
// ==============================
if (document.getElementById('adminTable')) {
    const table = document.getElementById('adminTable');
    const pagination = document.getElementById('adminPagination');
    const rows = Array.from(table.tBodies[0].rows);
    const rowsPerPage = 5;
    const pageCount = Math.ceil(rows.length / rowsPerPage);

    function showPage(page){
        rows.forEach((r,i)=> r.style.display = (i >= (page-1)*rowsPerPage && i < page*rowsPerPage) ? '' : 'none');
        renderPagination(page);
    }

    function renderPagination(active){
        pagination.innerHTML = '';
        for(let i=1;i<=pageCount;i++){
            const btn = document.createElement('button');
            btn.textContent = i;
            btn.style.margin = '0 3px';
            // Simple styling for active page
            if(i===active) btn.style.cssText += 'font-weight:bold; background-color:#FFDA63; color:#001B44;';
            else btn.style.cssText += 'background-color:#001B44; color:#fff;';
            
            btn.onclick = () => showPage(i);
            pagination.appendChild(btn);
        }
    }

    showPage(1);

    // MODAL ELEMENTS
    const modal = document.getElementById('adminModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalForm = document.getElementById('adminModalForm');
    const modalAdminId = document.getElementById('modalAdminId');
    const modalUsername = document.getElementById('modalUsername');
    const modalEmail = document.getElementById('modalEmail');
    const modalRole = document.getElementById('modalRole');
    const modalPassword = document.getElementById('modalPassword');
    const passwordLabel = document.getElementById('passwordLabel');
    const modalSubmitBtn = document.getElementById('modalSubmitBtn');

    // Attach click handlers to the small modal's close elements
    document.querySelector('#adminModal .close')?.addEventListener('click', ()=> modal.style.display='none');
    window.addEventListener('click', e => { if(e.target==modal) modal.style.display='none'; });

    // Function to handle opening the admin edit/reset modal (added for functionality)
    function openAdminModal(type, btn = null) {
        modalForm.reset();
        modalPassword.style.display = 'none';
        passwordLabel.style.display = 'none';

        if (type === 'edit') {
            modalTitle.textContent = 'Edit Admin';
            modalAdminId.value = btn.dataset.id;
            modalUsername.value = btn.dataset.username;
            modalEmail.value = btn.dataset.email;
            modalRole.value = btn.dataset.role;
            modalSubmitBtn.textContent = 'Save Changes';
            modalForm.onsubmit = (e) => {
                e.preventDefault();
                const data = {
                    admin_id: modalAdminId.value,
                    username: modalUsername.value,
                    email: modalEmail.value,
                    role: modalRole.value
                };
                sendAdminAjax('update_admin', data);
                modal.style.display = 'none';
            };
        } else if (type === 'reset') {
            modalTitle.textContent = 'Reset Password';
            modalAdminId.value = btn.dataset.id;
            modalUsername.value = ''; 
            modalEmail.value = ''; 
            modalPassword.style.display = 'block';
            passwordLabel.style.display = 'block';
            modalSubmitBtn.textContent = 'Reset Password';
            modalForm.onsubmit = (e) => {
                e.preventDefault();
                const data = {
                    admin_id: modalAdminId.value,
                    new_password: modalPassword.value
                };
                sendAdminAjax('reset_password', data);
                modal.style.display = 'none';
            };
        }
        modal.style.display = 'block';
    }


    function showMsg(msg, success=true){
        const div = document.createElement('div');
        div.className = 'cb-message ' + (success ? 'success':'error');
        div.innerHTML = `<strong>${success?'Success':'Error'}:</strong> ${msg}`;
        // Insert message right after the main page title
        document.querySelector('.cb-content').insertBefore(div, document.querySelector('.cb-content h2').nextSibling);
        setTimeout(()=>div.remove(),4000);
    }

    function sendAdminAjax(action,data){
        data.action=action;
        fetch('',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify(data)
        })
        .then(res=>res.json())
        .then(resp=>{
            if(resp.status==='success'){
                showMsg(resp.message||'Operation successful');
                setTimeout(()=>location.reload(),500);
            } else {
                showMsg(resp.message||'Operation failed',false);
                modal.style.display='none';
            }
        }).catch(err=>{
            showMsg('AJAX Request Failed: '+err.message,false);
            modal.style.display='none';
        });
    }

    document.addEventListener('click', e=>{
        const btn = e.target;
        if(btn.classList.contains('deleteAdminBtn')){
            if(confirm('Delete this admin?')){
                sendAdminAjax('delete_admin',{admin_id:btn.dataset.id});
            }
        } else if(btn.classList.contains('editAdminBtn')){
            openAdminModal('edit',btn);
        } else if(btn.classList.contains('resetPwdBtn')){
            openAdminModal('reset',btn);
        }
    });
}

// ==============================
// --- REPORT SELECTOR (FULL PAGE) ---
// ==============================

const reportSelector = document.getElementById('reportSelector');
const reportModal = document.getElementById('reportModal');
const reportModalTitle = document.getElementById('reportModalTitle');
const reportModalDisplay = document.getElementById('reportModalDisplay');

if (reportSelector && reportModal && reportModalTitle && reportModalDisplay) {

    reportSelector.addEventListener('change', async () => {
        const file = reportSelector.value;
        const reportName =
            reportSelector.options[reportSelector.selectedIndex]?.text || '';

        // Close modal if no report selected
        if (!file) {
            reportModal.style.display = 'none';
            reportModalDisplay.innerHTML = '';
            return;
        }

        // Show modal + loading state
        reportModalTitle.textContent = `Report: ${reportName}`;
        reportModalDisplay.innerHTML =
            '<p style="text-align:center;">Loading report...</p>';
        reportModal.style.display = 'block';

        try {
            // ✅ CORRECT PATH — relative to /public/admin/
            const res = await fetch(`reports/${file}`, {
                credentials: 'same-origin'
            });

            if (!res.ok) {
                let msg = `Failed to load report. Status: ${res.status}.`;
                if (res.status === 404) msg += ' File not found.';
                else if (res.status === 401 || res.status === 403) msg += ' Access denied.';
                else if (res.status >= 500) msg += ' Server error.';
                throw new Error(msg);
            }

            const html = await res.text();
            reportModalDisplay.innerHTML = html;

        } catch (err) {
            console.error('Report fetch error:', err);
            reportModalDisplay.innerHTML = `
                <p style="text-align:center; color:#721c24; font-weight:bold;">
                    Error loading report: ${err.message}
                </p>
            `;
        }
    });

}

</script>

</body>
</html>
