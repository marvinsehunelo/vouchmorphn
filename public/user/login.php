<?php
/**
 * User Login – VouchMorph Interoperability Platform
 */

ob_start();

// --------------------------------------------------
// 0️⃣ Error handling
// --------------------------------------------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --------------------------------------------------
// 1️⃣ Session & Dependencies
// --------------------------------------------------
require_once __DIR__ . '/../../src/APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: virtual_atmswap_dashboard.php');
    exit();
}

// --------------------------------------------------
// 2️⃣ Load Country & Config
// --------------------------------------------------
$config = require __DIR__ . '/../../src/CORE_CONFIG/load_country.php';

if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $config['country'] ?? 'BW');
}

$systemCountry = SYSTEM_COUNTRY;
$dbConfig = $config['db']['swap'] ?? null;

if (!$dbConfig) {
    error_log("USER LOGIN: Swap DB config missing for {$systemCountry}");
    die("System initialisation error.");
}

// --------------------------------------------------
// 3️⃣ DB Bootstrap
// --------------------------------------------------
try {
    $db = DBConnection::getInstance($dbConfig);
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("USER LOGIN DB ERROR [{$systemCountry}]: " . $e->getMessage());
    die("System initialisation failed.");
}

// --------------------------------------------------
// 4️⃣ Handle Login POST
// --------------------------------------------------
$error = '';
$phone = '';
$formattedPhone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phoneInput = trim($_POST['phone'] ?? '');

    // Normalize input (remove spaces and non-digits except +)
    $phoneInput = preg_replace('/[^\d+]/', '', $phoneInput);

    if ($phoneInput !== '') {
        if ($systemCountry === 'BW' && !str_starts_with($phoneInput, '+267')) {
            $formattedPhone = '+267' . ltrim($phoneInput, '0');
        } elseif ($systemCountry === 'NG' && !str_starts_with($phoneInput, '+234')) {
            $formattedPhone = '+234' . ltrim($phoneInput, '0');
        } else {
            $formattedPhone = $phoneInput;
        }
    }

    $phone = $phoneInput;

    if ($phoneInput === '') {
        $error = "Phone number is required.";
    } else {
        try {
            $stmt = $db->prepare(
                "SELECT user_id, phone, username, created_at, verified
                 FROM users
                 WHERE phone = :phone
                 LIMIT 1"
            );

            $stmt->execute([':phone' => $formattedPhone]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user || $user['verified'] != true) {
                // Prevent user enumeration
                $error = "Invalid login credentials.";
            } else {

                // 🔐 Regenerate session ID (security)
                session_regenerate_id(true);

                SessionManager::setUser([
                    'user_id'    => $user['user_id'],
                    'username'   => $user['username'] ?? '',
                    'phone'      => $user['phone'],
                    'role'       => 'USER',
                    'country'    => $systemCountry,
                    'created_at' => $user['created_at']
                ]);

                error_log("LOGIN SUCCESS: {$formattedPhone} logged into {$systemCountry}");

                header('Location: virtual_atmswap_dashboard.php');
                exit();
            }

        } catch (\Throwable $e) {
            error_log("USER LOGIN QUERY ERROR [{$systemCountry}]: " . $e->getMessage());
            $error = "System error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph | Platform Login</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
/* (UNCHANGED CSS — your design is already excellent) */
</style>
</head>
<body>

<div class="login-container">

    <div class="login-header">
        <h1>VouchMorph Interoperability Platform</h1>
        <div class="subtitle">National ATM & Liquidity Coordination System</div>
        <div class="system-badge">
            <?= htmlspecialchars(strtoupper($systemCountry)) ?> • Secure Login
        </div>
    </div>

    <div class="login-form">

        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">

            <div class="form-group">
                <label>Mobile Number</label>

                <div class="phone-input-container">
                    <span class="phone-prefix">
                        <?= $systemCountry === 'NG' ? '+234' : '+267' ?>
                    </span>

                    <input 
                        type="tel"
                        name="phone"
                        class="form-control"
                        required
                        value="<?= htmlspecialchars($phone) ?>"
                        placeholder="<?= $systemCountry === 'NG' ? '8012345678' : '71123456' ?>"
                        pattern="<?= $systemCountry === 'NG' ? '[0-9]{10}' : '[0-9]{8}' ?>"
                    >
                </div>
            </div>

            <button type="submit" class="login-btn">
                Access Platform
            </button>

            <div class="security-notice">
                <strong>Security Notice:</strong> Bank-grade encryption in use.
            </div>

        </form>
    </div>

    <div class="login-footer">
        <div class="login-links">
            <a href="register.php">Register</a>
            <a href="forgot.php">Recover</a>
            <a href="support.php">Support</a>
        </div>
    </div>

</div>

</body>
</html>
