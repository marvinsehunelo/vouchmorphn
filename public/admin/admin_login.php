<?php
// ADMIN_LAYER/dashboards/admin_login.php
ob_start();

require_once __DIR__ . '/../../src/APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../src/BUSINESS_LOGIC_LAYER/services/AdminService.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use APP_LAYER\Utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\AdminService;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

SessionManager::start();

// --- 1. Resolve Country & Load Configuration ---
/** * We use load_country.php because it:
 * 1. Runs system_country.php
 * 2. Parses the .env file for the specific country
 * 3. Sets the correct DB name (swap_system_bw/ng)
 */
$config = require_once __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
$systemCountry = SYSTEM_COUNTRY; // Defined by the loader above

$dbConfig = $config['db']['swap'] ?? null;

if (!$dbConfig) { 
    die("Swap DB configuration missing for {$systemCountry}."); 
}

// --- 2. Redirect if already logged in ---
if (SessionManager::isLoggedIn()) {
    $user = SessionManager::getUser();
    $userCountry = $user['country'] ?? null;

    if (!$userCountry) {
        SessionManager::destroy();
        header('Location: admin_login.php');
        exit();
    }

    // Roles permitted to access the dashboard
    $validRoles = ['admin', 'compliance', 'auditor', 'GLOBAL_OWNER'];
    
    if (in_array($user['role'] ?? null, $validRoles)) {
        header('Location: admin_dashboard.php');
        exit();
    }
}

$error = '';

try {
    // This will now connect to swap_system_bw or swap_system_ng automatically
    $db = DBConnection::getInstance($dbConfig);
    $adminService = new AdminService($db);
} catch (\Throwable $e) {
    die("System initialization failed. Could not connect to the {$systemCountry} database.");
}

// --- 3. Handle Login POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Both username and password are required.";
    } else {
        try {
            $result = $adminService->login($username, $password);

            if (!$result['success']) {
                $error = $result['message'] ?? "Invalid credentials.";
            } else {
                $user = $result['user'];
                $adminCountry = $user['country_code'] ?? $user['country'] ?? null;

                // Security Check: Does the Admin belong to this country?
                if ($adminCountry !== $systemCountry && $adminCountry !== 'GLOBAL') {
                    SessionManager::destroy();
                    $error = "Admin not authorized for the {$systemCountry} system.";
                } else {
                    SessionManager::setUser([
                        'user_id'  => $user['admin_id'] ?? $user['id'],
                        'username' => $user['username'],
                        'role'     => $user['role_name'] ?? $user['role'], 
                        'country'  => $adminCountry
                    ]);

                    header('Location: admin_dashboard.php');
                    exit();
                }
            }
        } catch (\Throwable $e) {
            $error = "Login service unavailable.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Login - PrestagedSWAP</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body { font-family:'IBM Plex Mono', monospace; background:#f7f9fc; margin:0; }
.cb-container { min-height:100vh; display:flex; align-items:center; justify-content:center; }
.cb-login-card {
    background:#fff;
    border:2px solid #001B44;
    box-shadow:4px 4px 0 #001B44;
    padding:30px;
    max-width:380px;
    width:100%;
}
.cb-login-card h1 {
    text-align:center;
    border-bottom:3px solid #001B44;
    margin-bottom:20px;
}
.cb-form-group { margin-bottom:18px; }
.cb-label { display:block; margin-bottom:6px; font-weight:600; }
.cb-input {
    width:100%;
    padding:10px;
    border:1px solid #001B44;
    font-family:'IBM Plex Mono', monospace;
}
.cb-button {
    width:100%;
    background:#001B44;
    color:#fff;
    border:none;
    padding:12px;
    font-weight:bold;
    cursor:pointer;
}
.cb-message.error {
    background:#ffe6e6;
    border:1px solid red;
    padding:10px;
    margin-bottom:15px;
}
</style>
</head>
<body>

<div class="cb-container">
    <div class="cb-login-card">
        <h1>Admin Login</h1>

        <?php if ($error): ?>
            <div class="cb-message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="cb-form-group">
                <label class="cb-label">Username</label>
                <input type="text" name="username" class="cb-input" required>
            </div>

            <div class="cb-form-group">
                <label class="cb-label">Password</label>
                <input type="password" name="password" class="cb-input" required>
            </div>

            <button class="cb-button">Sign In</button>
        </form>
    </div>
</div>

</body>
</html>

