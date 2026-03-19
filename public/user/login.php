<?php
/**
 * User Login – VouchMorph Interoperability Platform
 * European Banking Style Login Interface
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

use APP_LAYER\utils\SessionManager;  // Fixed namespace (lowercase utils)
use DATA_PERSISTENCE_LAYER\config\DBConnection;
use PDO;  // Added missing PDO import
use Throwable;  // Added for error handling

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: virtual_atmswap_dashboard.php');
    exit();
}

// --------------------------------------------------
// 2️⃣ Load Country & Config
// --------------------------------------------------
$config = require __DIR__ . '/../../src/CORE_CONFIG/load_country.php';  // Removed _once

// Define SYSTEM_COUNTRY if not already defined
if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $config['country'] ?? 'BW');
}
$systemCountry = SYSTEM_COUNTRY; // e.g., 'BW' or 'NG'

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
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
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
    
    // 1. Logic: If user typed "71..." and country is BW, make it "+26771..."
    if ($phoneInput !== '') {
        if ($systemCountry === 'BW' && !str_starts_with($phoneInput, '+267')) {
            $formattedPhone = '+267' . ltrim($phoneInput, '0');
        } elseif ($systemCountry === 'NG' && !str_starts_with($phoneInput, '+234')) {
            $formattedPhone = '+234' . ltrim($phoneInput, '0');
        } else {
            $formattedPhone = $phoneInput;
        }
    }

    $phone = htmlspecialchars($phoneInput, ENT_QUOTES, 'UTF-8');

    if ($phoneInput === '') {
        $error = "Phone number is required.";
    } else {
        try {
            // 2. Use $formattedPhone for database search
            $stmt = $db->prepare(
                "SELECT user_id, phone, username, created_at, verified
                 FROM users
                 WHERE phone = :phone
                 LIMIT 1"
            );

            $stmt->execute([':phone' => $formattedPhone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Check if user exists AND if they are verified
            if (!$user) {
                $error = "Account not found ($formattedPhone). Please register first.";
            } elseif ($user['verified'] != true) {
                $error = "This account is not yet verified.";
            } else {
                // Set session payload
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

        } catch (Throwable $e) {
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
<!-- Typography: European banking style -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ===== EUROPEAN BANKING STYLE ===== */
:root {
    --primary-navy: #0A2463;
    --primary-gold: #B8860B;
    --primary-slate: #2D3748;
    --secondary-steel: #4A5568;
    --light-gray: #F7FAFC;
    --border-gray: #E2E8F0;
    --success-green: #38A169;
    --error-red: #E53E3E;
    --warning-amber: #D69E2E;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
    color: var(--primary-slate);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

/* ===== LOGIN CONTAINER ===== */
.login-container {
    width: 100%;
    max-width: 460px;
    background: white;
    border-radius: 0;
    box-shadow: 
        0 4px 6px -1px rgba(0, 0, 0, 0.05),
        0 10px 15px -3px rgba(0, 0, 0, 0.08),
        0 20px 40px -20px rgba(0, 0, 0, 0.15);
    border: 1px solid var(--border-gray);
    overflow: hidden;
}

/* ===== HEADER ===== */
.login-header {
    background: var(--primary-navy);
    color: white;
    padding: 28px 32px;
    border-bottom: 3px solid var(--primary-gold);
    text-align: center;
}

.login-header h1 {
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -0.3px;
    margin-bottom: 6px;
}

.login-header .subtitle {
    font-size: 13px;
    font-weight: 400;
    opacity: 0.9;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.system-badge {
    display: inline-block;
    background: rgba(255, 255, 255, 0.1);
    padding: 4px 12px;
    border-radius: 2px;
    font-size: 11px;
    font-weight: 500;
    margin-top: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ===== FORM SECTION ===== */
.login-form {
    padding: 40px 32px;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--secondary-steel);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--border-gray);
    border-radius: 0;
    font-size: 15px;
    font-family: 'Inter', sans-serif;
    color: var(--primary-slate);
    background: white;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-navy);
    box-shadow: 0 0 0 2px rgba(10, 36, 99, 0.1);
}

.form-control::placeholder {
    color: #A0AEC0;
    font-weight: 400;
}

.phone-prefix {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--secondary-steel);
    font-weight: 500;
    font-size: 15px;
}

.phone-input-container {
    position: relative;
}

.phone-input-container .form-control {
    padding-left: 50px;
}

/* ===== ERROR MESSAGE ===== */
.error-message {
    background: #FED7D7;
    border: 1px solid var(--error-red);
    color: var(--error-red);
    padding: 14px 16px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 0;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.error-message::before {
    content: "⚠";
    font-size: 16px;
}

/* ===== SUBMIT BUTTON ===== */
.login-btn {
    background: var(--primary-navy);
    color: white;
    border: none;
    padding: 16px 32px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 0;
    cursor: pointer;
    transition: all 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}

.login-btn:hover:not(:disabled) {
    background: #0A1E4D;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(10, 36, 99, 0.2);
}

.login-btn:disabled {
    background: var(--border-gray);
    color: #A0AEC0;
    cursor: not-allowed;
}

.login-btn .spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== FOOTER LINKS ===== */
.login-footer {
    padding: 24px 32px;
    background: var(--light-gray);
    border-top: 1px solid var(--border-gray);
    text-align: center;
}

.login-links {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-bottom: 16px;
}

.login-link {
    color: var(--secondary-steel);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: color 0.2s ease;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.login-link:hover {
    color: var(--primary-navy);
}

.system-info {
    font-size: 11px;
    color: var(--secondary-steel);
    font-weight: 500;
}

.system-info .country {
    color: var(--primary-navy);
    font-weight: 600;
}

/* ===== SECURITY NOTICE ===== */
.security-notice {
    background: var(--light-gray);
    border: 1px solid var(--border-gray);
    padding: 16px;
    margin-top: 24px;
    font-size: 11px;
    color: var(--secondary-steel);
    text-align: center;
}

.security-notice strong {
    color: var(--primary-slate);
    font-weight: 600;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 480px) {
    .login-container {
        max-width: 100%;
        margin: 0;
    }
    
    .login-header {
        padding: 24px 20px;
    }
    
    .login-form {
        padding: 32px 20px;
    }
    
    .login-links {
        flex-direction: column;
        gap: 12px;
    }
    
    .login-footer {
        padding: 20px;
    }
}

/* ===== COUNTRY SPECIFIC STYLING ===== */
<?php if ($systemCountry === 'BW'): ?>
.country-flag {
    color: #75AADB;
}
<?php elseif ($systemCountry === 'NG'): ?>
.country-flag {
    color: #008751;
}
<?php endif; ?>
</style>
</head>
<body>

<div class="login-container">
    <!-- HEADER -->
    <div class="login-header">
        <h1>VouchMorph Interoperability Platform</h1>
        <div class="subtitle">National ATM & Liquidity Coordination System</div>
        <div class="system-badge">
            <span class="country-flag"><?= htmlspecialchars(strtoupper($systemCountry)) ?></span> • Secure Login
        </div>
    </div>

    <!-- ERROR MESSAGE -->
    <?php if ($error): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- LOGIN FORM -->
    <form method="POST" class="login-form" id="loginForm">
        <div class="form-group">
            <label for="phoneInput">Mobile Number</label>
            <div class="phone-input-container">
                <span class="phone-prefix">
                    <?php if ($systemCountry === 'BW'): ?>
                        +267
                    <?php elseif ($systemCountry === 'NG'): ?>
                        +234
                    <?php else: ?>
                        +267
                    <?php endif; ?>
                </span>
                <input 
                    type="tel" 
                    id="phoneInput"
                    name="phone" 
                    class="form-control" 
                    required 
                    placeholder="71123456"
                    value="<?= htmlspecialchars($phone) ?>"
                    pattern="[0-9]{8,10}"
                    title="Enter your mobile number without country code"
                    autocomplete="tel"
                    autofocus
                >
            </div>
            <div style="font-size: 12px; color: var(--secondary-steel); margin-top: 6px;">
                Enter your registered mobile number
            </div>
        </div>

        <button type="submit" class="login-btn" id="loginBtn">
            <span id="loginText">Access Platform</span>
        </button>

        <!-- SECURITY NOTICE -->
        <div class="security-notice">
            <strong>Security Notice:</strong> This system uses bank-grade encryption. Never share your credentials.
        </div>
    </form>

    <!-- FOOTER -->
    <div class="login-footer">
        <div class="login-links">
            <a href="register.php" class="login-link">Register Account</a>
            <a href="forgot.php" class="login-link">Recover Access</a>
            <a href="support.php" class="login-link">Support</a>
        </div>
        <div class="system-info">
            System: <span class="country"><?= htmlspecialchars(strtoupper($systemCountry)) ?></span> • 
            Environment: Production • v1.3.2
        </div>
    </div>
</div>

<script>
// ===== FORM VALIDATION =====
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const phoneInput = document.getElementById('phoneInput');
    const loginBtn = document.getElementById('loginBtn');
    const loginText = document.getElementById('loginText');

    // Phone number validation
    phoneInput.addEventListener('input', function() {
        // Remove non-numeric characters
        this.value = this.value.replace(/[^\d]/g, '');
        
        // Add country-specific validation
        const country = '<?= $systemCountry ?>';
        if (country === 'BW') {
            // Botswana: 8 digits
            if (this.value.length > 8) {
                this.value = this.value.substring(0, 8);
            }
        } else if (country === 'NG') {
            // Nigeria: 10 digits
            if (this.value.length > 10) {
                this.value = this.value.substring(0, 10);
            }
        }
    });

    // Form submission
    loginForm.addEventListener('submit', function(e) {
        const phone = phoneInput.value.trim();
        
        // Basic validation
        if (!phone) {
            e.preventDefault();
            showError('Phone number is required');
            phoneInput.focus();
            return;
        }

        // Country-specific validation
        const country = '<?= $systemCountry ?>';
        let isValid = false;
        let errorMessage = '';
        
        if (country === 'BW') {
            // Botswana: 8 digits
            isValid = /^\d{8}$/.test(phone);
            errorMessage = 'Please enter a valid 8-digit Botswana mobile number';
        } else if (country === 'NG') {
            // Nigeria: 10 digits
            isValid = /^\d{10}$/.test(phone);
            errorMessage = 'Please enter a valid 10-digit Nigeria mobile number';
        } else {
            // Default: at least 8 digits
            isValid = /^\d{8,}$/.test(phone);
            errorMessage = 'Please enter a valid mobile number';
        }

        if (!isValid) {
            e.preventDefault();
            showError(errorMessage);
            phoneInput.focus();
            return;
        }

        // Show loading state
        loginBtn.disabled = true;
        loginText.innerHTML = '<span class="spinner"></span> Authenticating...';
    });

    // Error display function
    function showError(message) {
        // Remove existing error
        const existingError = document.querySelector('.error-message');
        if (existingError) {
            existingError.remove();
        }

        // Create new error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.innerHTML = '⚠ ' + message;
        
        // Insert after header
        const header = document.querySelector('.login-header');
        header.parentNode.insertBefore(errorDiv, header.nextSibling);
        
        // Scroll to error
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Focus phone input on page load
    phoneInput.focus();
});

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function(e) {
    // Ctrl+Enter to submit
    if (e.ctrlKey && e.key === 'Enter') {
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.submit();
        }
    }
    
    // Escape to clear field
    if (e.key === 'Escape') {
        const phoneInput = document.getElementById('phoneInput');
        if (phoneInput && document.activeElement === phoneInput) {
            phoneInput.value = '';
        }
    }
});
</script>
</body>
</html>
