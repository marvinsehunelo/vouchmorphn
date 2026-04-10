<?php
// APP_LAYER/views/register.php — AJAX + page
// STYLED TO MATCH VOUCHMORPH LANDING PAGE

// Disable direct error display to prevent breaking JSON, but log everything
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 1️⃣ Load Country & Config (The New Standard)
 * This automatically handles the /countries/[CODE]/.env_[CODE] logic
 */
try {
    // This replaces system_country.php and the manual config pathing
    $config = require_once __DIR__ . '/../../src/CORE_CONFIG/load_country.php';
    $country = SYSTEM_COUNTRY; 

    // Load Communication Stack in correct dependency order
    require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';
    require_once __DIR__ . '/../../src/INTEGRATION_LAYER/INTERFACES/CommunicationProviderInterface.php';
    require_once __DIR__ . '/../../src/INTEGRATION_LAYER/CLIENTS/CommunicationClients/CommunicationClient.php';
    require_once __DIR__ . '/../../src/FACTORY_LAYER/CommunicationFactory.php';

} catch (Throwable $e) {
    error_log("Bootstrap Error [{$country}]: " . $e->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        die(json_encode(['success' => false, 'message' => 'System configuration error.']));
    }
    die("System initialisation error.");
}

use DATA_PERSISTENCE_LAYER\Config\DBConnection;
use FACTORY_LAYER\CommunicationFactory;

/**
 * 2️⃣ Database Configuration Alignment
 */
$dbConfig = $config['db'] ?? [];

// Identify the source client (Cazacom, etc.) based on country config
// For Nigeria, this might be 'cazacom_ng' or similar in your .env
$sourceKey = $config['db']['source_client_key'] ?? 'cazacom';

if (!isset($dbConfig['swap']) || !isset($dbConfig[$sourceKey])) {
    error_log("DB Config missing keys for {$country}. Available: " . implode(',', array_keys($dbConfig)));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        die(json_encode(['success' => false, 'message' => 'Database mapping error.']));
    }
    die("System initialisation error.");
}

/**
 * 3️⃣ Initialize DB connections
 */
try {
    // Connection to swap_system_bw or swap_system_ng
    $swap_systemDB  = DBConnection::getInstance($dbConfig['swap']);
    // Connection to the source of truth (the telco/partner DB)
    $sourceClientDB = DBConnection::getInstance($dbConfig[$sourceKey]);
} catch (Throwable $e) {
    error_log("DB Connection Failure [{$country}]: " . $e->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        die(json_encode(['success' => false, 'message' => 'Backend connection failed.']));
    }
    die("System initialisation error.");
}

// Identify which Communication Client to use (e.g., CAZACOM_BW or CAZACOM_NG)
$clientPartnerKey = $config['participants'][$sourceKey]['communication_key'] ?? 'CAZACOM';

/**
 * 4️⃣ Handle AJAX POST (Send OTP)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $phone = trim($_POST['phone'] ?? '');
        if ($phone === '') {
            echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
            exit;
        }

        // 🔍 STEP 1: Check phone in Source Client (Telco)
        $stmt = $sourceClientDB->prepare("SELECT id FROM users WHERE phone_number = ? LIMIT 1");
        $stmt->execute([$phone]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => "Phone not found in {$clientPartnerKey} records."]);
            exit;
        }

        // 🔍 STEP 2: Check if already in SWAP (Country specific)
        $stmt = $swap_systemDB->prepare("SELECT user_id FROM users WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Number already registered in SWAP.']);
            exit;
        }

        // ⚡ STEP 3: Generate OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 300);

        // Transactional OTP Update
        $swap_systemDB->prepare("DELETE FROM otp_codes WHERE phone = ?")->execute([$phone]);
        $swap_systemDB->prepare("INSERT INTO otp_codes (phone, code, expires_at) VALUES (?, ?, ?)")
                      ->execute([$phone, $otp, $expiresAt]);

        // 📲 STEP 4: Send SMS via Factory
        $comm = CommunicationFactory::create($clientPartnerKey);
        $msg  = "Your SWAP registration OTP is: {$otp}.";
        $res  = $comm->sendSMS($phone, $msg);

        if (!($res['success'] ?? false)) {
            throw new Exception($res['message'] ?? 'Provider failed to send SMS');
        }

        echo json_encode(['success' => true, 'message' => 'OTP sent successfully.']);
        exit;

    } catch (Throwable $e) {
        error_log("Register Error [{$country}]: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>VouchMorph™ – Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=general-sans@400,500,600&f[]=space-grotesk@400,500,600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #050505;
            font-family: 'Inter', sans-serif;
            color: #FFFFFF;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }

        /* GRID BACKGROUND - SAME AS LANDING PAGE */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
        }

        /* CUSTOM CURSOR - SAME AS LANDING PAGE */
        .cursor {
            width: 8px;
            height: 8px;
            background: #00F0FF;
            position: fixed;
            pointer-events: none;
            z-index: 9999;
            mix-blend-mode: difference;
            transition: transform 0.1s ease;
        }

        .cursor-follower {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(0, 240, 255, 0.5);
            position: fixed;
            pointer-events: none;
            z-index: 9998;
            transition: 0.15s ease;
        }

        @media (max-width: 768px) {
            .cursor, .cursor-follower { display: none; }
        }

        /* REGISTER CARD - SHARP EDGES, 0 BORDER-RADIUS */
        .register-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 480px;
            background: rgba(5, 5, 5, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border-radius: 0px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* HEADER */
        .register-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .register-header h1 {
            font-family: 'Clash Display', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            font-size: 0.875rem;
            color: #A0A0B0;
            margin-bottom: 1rem;
        }

        .system-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(0, 240, 255, 0.1);
            border: 1px solid rgba(0, 240, 255, 0.3);
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-radius: 0px;
            color: #00F0FF;
        }

        /* FORM */
        .register-form {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #C0C0D0;
        }

        .phone-input-container {
            display: flex;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(0, 0, 0, 0.5);
            transition: all 0.2s ease;
            border-radius: 0px;
        }

        .phone-input-container:focus-within {
            border-color: #00F0FF;
            box-shadow: 0 0 0 1px rgba(0, 240, 255, 0.2);
        }

        .phone-prefix {
            padding: 0.875rem 1rem;
            font-family: 'Space Grotesk', monospace;
            font-weight: 500;
            color: #00F0FF;
            background: rgba(0, 240, 255, 0.05);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            letter-spacing: 0.5px;
        }

        .form-control {
            flex: 1;
            border: none;
            padding: 0.875rem 1rem;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            background: transparent;
            color: #FFFFFF;
            outline: none;
        }

        .form-control::placeholder {
            color: #505060;
        }

        .form-control.otp-input {
            font-family: 'Space Grotesk', monospace;
            font-size: 1.25rem;
            letter-spacing: 0.5rem;
            text-align: center;
        }

        /* BUTTONS */
        .btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
            color: #050505;
            border: none;
            font-family: 'General Sans', sans-serif;
            font-weight: 700;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
            border-radius: 0px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4);
        }

        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
            margin-top: 0;
        }

        .btn-secondary:hover {
            border-color: #00F0FF;
            background: rgba(0, 240, 255, 0.05);
            transform: translateY(-2px);
            box-shadow: none;
        }

        /* MESSAGE */
        .message {
            margin-top: 1rem;
            padding: 0.75rem;
            font-size: 0.8125rem;
            text-align: center;
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00F0FF;
            color: #A0A0B0;
            min-height: 50px;
        }

        .message.error {
            background: rgba(255, 48, 48, 0.1);
            border-left-color: #FF3030;
            color: #FF6060;
        }

        .message.success {
            background: rgba(0, 240, 255, 0.1);
            border-left-color: #00F0FF;
            color: #00F0FF;
        }

        /* OTP SECTION */
        .otp-section {
            display: none;
            margin-top: 0;
        }

        /* FOOTER */
        .register-footer {
            padding: 1.25rem 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(10, 10, 20, 0.3);
            text-align: center;
        }

        .register-footer a {
            color: #808090;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .register-footer a:hover {
            color: #00F0FF;
        }

        /* HIDDEN CLASS */
        .hidden {
            display: none;
        }

        /* ANIMATIONS */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.4s ease;
        }

        /* RESPONSIVE */
        @media (max-width: 640px) {
            .register-container {
                margin: 1rem;
            }
            .register-header {
                padding: 1.5rem 1.5rem 1rem;
            }
            .register-header h1 {
                font-size: 1.5rem;
            }
            .register-form {
                padding: 1.5rem;
            }
            .register-footer {
                padding: 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="cursor"></div>
<div class="cursor-follower"></div>

<div class="register-container">
    <div class="register-header">
        <h1>VOUCHMORPH<sup style="font-size: 0.7rem;">™</sup></h1>
        <div class="subtitle">Join the Financial Revolution</div>
        <div class="system-badge">
            <?= htmlspecialchars(strtoupper($country ?? 'BW')) ?> • REGISTER
        </div>
    </div>

    <div class="register-form">
        <div id="register-step">
            <div class="form-group">
                <label>MOBILE NUMBER</label>
                <div class="phone-input-container">
                    <span class="phone-prefix">+267</span>
                    <input type="tel" id="phone" class="form-control" placeholder="71 234 567" autocomplete="off">
                </div>
            </div>
            <button class="btn" onclick="sendOTP()">SEND OTP →</button>
        </div>

        <div id="otp-section" class="otp-section">
            <div class="form-group">
                <label>ENTER 6-DIGIT OTP</label>
                <input type="text" id="otp" class="form-control otp-input" maxlength="6" placeholder="••••••" autocomplete="off">
            </div>
            <button class="btn" onclick="verifyOTP()">VERIFY & REGISTER →</button>
            <button class="btn btn-secondary" onclick="backToPhone()" style="margin-top: 0.75rem;">← BACK</button>
        </div>

        <div id="message" class="message"></div>
    </div>

    <div class="register-footer">
        <a href="login.php">ALREADY REGISTERED? LOGIN →</a>
    </div>
</div>

<script>
// Custom cursor
const cursor = document.querySelector('.cursor');
const follower = document.querySelector('.cursor-follower');

if (cursor && follower) {
    document.addEventListener('mousemove', (e) => {
        cursor.style.left = e.clientX + 'px';
        cursor.style.top = e.clientY + 'px';
        follower.style.left = e.clientX - 16 + 'px';
        follower.style.top = e.clientY - 16 + 'px';
    });
}

function showMessage(text, type) {
    const msgEl = document.getElementById('message');
    msgEl.textContent = text;
    msgEl.className = 'message ' + (type || '');
    // Auto clear after 5 seconds
    setTimeout(() => {
        if (document.getElementById('message').textContent === text) {
            document.getElementById('message').textContent = '';
            document.getElementById('message').className = 'message';
        }
    }, 5000);
}

function sendOTP() {
    const phone = document.getElementById('phone').value.trim();
    const msgEl = document.getElementById('message');
    
    if (!phone) {
        showMessage('Please enter your phone number.', 'error');
        return;
    }

    // Basic phone validation (Botswana format: 8 digits after 267)
    const phoneClean = phone.replace(/\D/g, '');
    if (phoneClean.length !== 8) {
        showMessage('Please enter a valid 8-digit Botswana phone number.', 'error');
        return;
    }

    const fullPhone = '+267' + phoneClean;
    showMessage('Sending OTP...', '');

    const formData = new URLSearchParams();
    formData.append('phone', fullPhone);

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, 'success');
            document.getElementById('register-step').style.display = 'none';
            document.getElementById('otp-section').style.display = 'block';
            document.getElementById('otp-section').classList.add('fade-in');
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Network error. Please try again.', 'error');
    });
}

function verifyOTP() {
    const phone = document.getElementById('phone').value.trim();
    const otp = document.getElementById('otp').value.trim();
    
    if (!otp || otp.length !== 6) {
        showMessage('Please enter the 6-digit OTP.', 'error');
        return;
    }

    const fullPhone = '+267' + phone.replace(/\D/g, '');
    showMessage('Verifying...', '');

    const formData = new URLSearchParams();
    formData.append('phone', fullPhone);
    formData.append('otp', otp);

    fetch('verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('Registration successful! Redirecting to login...', 'success');
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 1500);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage('Verification error. Please try again.', 'error');
    });
}

function backToPhone() {
    document.getElementById('otp-section').style.display = 'none';
    document.getElementById('register-step').style.display = 'block';
    document.getElementById('otp').value = '';
    document.getElementById('message').textContent = '';
    document.getElementById('message').className = 'message';
}

// Enter key support
document.getElementById('phone')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendOTP();
});
document.getElementById('otp')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') verifyOTP();
});
</script>
</body>
</html>
