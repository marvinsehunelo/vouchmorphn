<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/../../src/APP_LAYER/utils/SessionManager.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
$config = require __DIR__ . '/../../src/CORE_CONFIG/load_country.php';

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: user_dashboard.php');
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
$dbConfig       = $config['db']['swap'] ?? null;
$countryConfig  = $config['country_settings'][$systemCountry] ?? [];

// Dynamic country phone settings
$countryDialCode   = $countryConfig['dial_code'] ?? '+267';
$localLength       = (int)($countryConfig['local_phone_length'] ?? 8);
$phonePlaceholder  = $countryConfig['phone_placeholder'] ?? str_repeat('0', $localLength);
$countryName       = $countryConfig['name'] ?? $systemCountry;

// Regex pattern for frontend validation
$phonePattern = '[0-9]{' . $localLength . '}';

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
// 4️⃣ Helpers
// --------------------------------------------------
function normalizePhone(string $phoneInput, string $dialCode): string
{
    $phoneInput = preg_replace('/[^\d+]/', '', trim($phoneInput));

    if ($phoneInput === '') {
        return '';
    }

    // Already in international format
    if (str_starts_with($phoneInput, '+')) {
        return $phoneInput;
    }

    // Convert local number to international using country config
    return $dialCode . ltrim($phoneInput, '0');
}

function getLocalPhonePart(string $fullPhone, string $dialCode): string
{
    if (str_starts_with($fullPhone, $dialCode)) {
        return substr($fullPhone, strlen($dialCode));
    }

    return ltrim($fullPhone, '0');
}

// -------------------------------------------------
// 5️⃣ Handle Login POST
// -------------------------------------------------
$error = '';
$phone = '';
$formattedPhone = '';
$loginMethod = $_POST['login_method'] ?? 'phone';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phoneInput = trim($_POST['phone'] ?? '');
    $formattedPhone = normalizePhone($phoneInput, $countryDialCode);
    $phone = getLocalPhonePart($formattedPhone, $countryDialCode);

    if ($loginMethod === 'pin') {
        // PIN LOGIN
        $pin = trim($_POST['pin'] ?? '');

        if ($phoneInput === '' || $pin === '') {
            $error = "Phone number and PIN are required.";
        } else {
            try {
                $stmt = $db->prepare(
    "SELECT user_id, phone, username, password_hash, verified, created_at
     FROM users
     WHERE phone = :phone
     LIMIT 1"
);
$stmt->execute([':phone' => $formattedPhone]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$user || (int)$user['verified'] !== 1) {
    $error = "Invalid login credentials.";
} elseif (!password_verify($pin, $user['password_hash'])) {
    $error = "Invalid PIN.";
    error_log("PIN LOGIN FAILED: {$formattedPhone}");
} else {
    session_regenerate_id(true);

    SessionManager::setUser([
        'user_id'    => $user['user_id'],
        'username'   => $user['username'] ?? '',
        'phone'      => $user['phone'],
        'role'       => 'USER',
        'country'    => $systemCountry,
        'created_at' => $user['created_at'] ?? null
    ]);

    error_log("PIN LOGIN SUCCESS: {$formattedPhone}");
    header('Location: virtual_atmswap_dashboard.php');
    exit();
}
            } catch (\Throwable $e) {
                error_log("USER LOGIN QUERY ERROR [{$systemCountry}]: " . $e->getMessage());
                $error = "System error. Please try again.";
            }
        }
    } else {
        // PHONE LOGIN
        if ($phoneInput === '') {
            $error = "Phone number is required.";
        } else {
            try {
                $stmt = $db->prepare(
                    "SELECT user_id, phone, username, created_at, verified, pin_enabled
                     FROM users
                     WHERE phone = :phone
                     LIMIT 1"
                );
                $stmt->execute([':phone' => $formattedPhone]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$user || (int)$user['verified'] !== 1) {
                    $error = "Invalid login credentials.";
                } else {
                    session_regenerate_id(true);

                    SessionManager::setUser([
                        'user_id'     => $user['user_id'],
                        'username'    => $user['username'] ?? '',
                        'phone'       => $user['phone'],
                        'role'        => 'USER',
                        'country'     => $systemCountry,
                        'created_at'  => $user['created_at'] ?? null,
                        'pin_enabled' => (int)($user['pin_enabled'] ?? 0) === 1
                    ]);

                    error_log("PHONE LOGIN SUCCESS: {$formattedPhone}");
                    header('Location: virtual_atmswap_dashboard.php');
                    exit();
                }
            } catch (\Throwable $e) {
                error_log("USER LOGIN QUERY ERROR [{$systemCountry}]: " . $e->getMessage());
                $error = "System error. Please try again.";
            }
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
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .login-container {
        background: white;
        border-radius: 24px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        width: 100%;
        max-width: 480px;
        overflow: hidden;
    }

    .login-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 32px;
        text-align: center;
    }

    .login-header h1 {
        font-size: 28px;
        margin-bottom: 8px;
    }

    .subtitle {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 16px;
    }

    .system-badge {
        background: rgba(255,255,255,0.2);
        border-radius: 20px;
        padding: 6px 12px;
        font-size: 12px;
        display: inline-block;
    }

    .login-tabs {
        display: flex;
        border-bottom: 2px solid #e5e7eb;
        background: #f9fafb;
    }

    .tab-btn {
        flex: 1;
        padding: 16px;
        background: none;
        border: none;
        font-size: 16px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        color: #6b7280;
    }

    .tab-btn.active {
        color: #667eea;
        border-bottom: 2px solid #667eea;
        background: white;
    }

    .login-form {
        padding: 32px;
    }

    .tab-pane {
        display: none;
    }

    .tab-pane.active {
        display: block;
    }

    .form-group {
        margin-bottom: 24px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #374151;
    }

    .phone-input-container {
        display: flex;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s;
    }

    .phone-input-container:focus-within {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
    }

    .phone-prefix {
        background: #f3f4f6;
        padding: 12px 16px;
        font-weight: 500;
        color: #374151;
    }

    .form-control {
        flex: 1;
        border: none;
        padding: 12px 16px;
        font-size: 16px;
        outline: none;
    }

    .pin-input {
        font-size: 24px;
        letter-spacing: 8px;
        text-align: center;
    }

    .login-btn {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s;
    }

    .login-btn:hover {
        transform: translateY(-2px);
    }

    .error-message {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 14px;
    }

    .security-notice {
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid #e5e7eb;
        font-size: 12px;
        color: #6b7280;
        text-align: center;
    }

    .login-footer {
        background: #f9fafb;
        padding: 16px 32px;
        text-align: center;
    }

    .login-links {
        display: flex;
        justify-content: center;
        gap: 24px;
        flex-wrap: wrap;
    }

    .login-links a {
        color: #6b7280;
        text-decoration: none;
        font-size: 14px;
    }

    .login-links a:hover {
        color: #667eea;
    }

    @media (max-width: 640px) {
        .login-header h1 {
            font-size: 24px;
        }

        .login-form {
            padding: 24px;
        }
    }
</style>
</head>
<body>
<div class="login-container">
    <div class="login-header">
        <h1>VouchMorph</h1>
        <div class="subtitle">Interoperability Platform</div>
        <div class="system-badge">
            <?= htmlspecialchars(strtoupper($countryName)) ?> • Secure Login
        </div>
    </div>

    <div class="login-tabs">
        <button type="button" class="tab-btn active" data-tab="phone">📱 Phone Login</button>
        <button type="button" class="tab-btn" data-tab="pin">🔐 PIN Login</button>
    </div>

    <div class="login-form">
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="phone-tab" class="tab-pane active">
            <form method="POST" novalidate>
                <input type="hidden" name="login_method" value="phone">
                <div class="form-group">
                    <label>Mobile Number</label>
                    <div class="phone-input-container">
                        <span class="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            required
                            value="<?= htmlspecialchars($phone) ?>"
                            placeholder="<?= htmlspecialchars($phonePlaceholder) ?>"
                            pattern="<?= htmlspecialchars($phonePattern) ?>"
                            inputmode="numeric"
                            autocomplete="tel-national"
                        >
                    </div>
                </div>
                <button type="submit" class="login-btn">Access Platform</button>
            </form>
        </div>

        <div id="pin-tab" class="tab-pane">
            <form method="POST" novalidate>
                <input type="hidden" name="login_method" value="pin">
                <div class="form-group">
                    <label>Mobile Number</label>
                    <div class="phone-input-container">
                        <span class="phone-prefix"><?= htmlspecialchars($countryDialCode) ?></span>
                        <input
                            type="tel"
                            name="phone"
                            class="form-control"
                            required
                            value="<?= htmlspecialchars($phone) ?>"
                            placeholder="<?= htmlspecialchars($phonePlaceholder) ?>"
                            pattern="<?= htmlspecialchars($phonePattern) ?>"
                            inputmode="numeric"
                            autocomplete="tel-national"
                        >
                    </div>
                </div>
                <div class="form-group">
                    <label>PIN Code</label>
                    <input
                        type="password"
                        name="pin"
                        class="form-control pin-input"
                        required
                        maxlength="6"
                        placeholder="••••••"
                        style="letter-spacing: 8px;"
                        inputmode="numeric"
                        autocomplete="current-password"
                    >
                </div>
                <button type="submit" class="login-btn">Login with PIN</button>
            </form>
            <div class="security-notice">
                <strong>💡 Forgot PIN?</strong> Use phone login to recover.
            </div>
        </div>
    </div>

    <div class="login-footer">
        <div class="login-links">
            <a href="register.php">Register</a>
            <a href="forgot.php">Recover Account</a>
            <a href="support.php">Support</a>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(button => {
    button.addEventListener('click', function () {
        const tab = this.dataset.tab;

        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('active');
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        document.getElementById(tab + '-tab').classList.add('active');
        this.classList.add('active');
    });
});
</script>
</body>
</html>
