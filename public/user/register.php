<?php
// APP_LAYER/views/register.php — AJAX + page

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
    die(json_encode(['success' => false, 'message' => 'Database mapping error.']));
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
    die(json_encode(['success' => false, 'message' => 'Backend connection failed.']));
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
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SWAP | Register</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@600&family=Playfair+Display:wght@600&display=swap');
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0a0a0a, #1e1e1e);
            font-family: 'Playfair Display', serif;
            color: #fff;
        }
        .register-container {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.12);
            padding: 3rem;
            width: 380px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        h2 {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            margin-bottom: 1.2rem;
            text-transform: uppercase;
            color: #e5e5e5;
            letter-spacing: 2px;
        }
        input {
            width: 100%;
            padding: .8rem;
            margin: .4rem 0;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            color: #fff;
            font-size: 1rem;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: .9rem;
            background: #d4af37;
            border: none;
            color: #000;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s ease;
        }
        button:hover {
            background: #f5cf57;
        }
        .message {
            margin-top: 1rem;
            color: #d4af37;
            min-height: 20px;
            font-size: 0.9rem;
        }
        .otp-section {
            display: none;
            margin-top: 1rem;
        }
        a {
            color: #aaa;
            display: block;
            margin-top: 15px;
            text-decoration: none;
            font-size: 0.85rem;
        }
        a:hover {
            color: #fff;
        }
    </style>
</head>
<body>

<div class="register-container">
    <h2>Register</h2>
    
    <div id="register-step">
        <input type="text" id="phone" placeholder="Phone Number (+267...)" />
        <button onclick="sendOTP()">Send OTP</button>
    </div>

    <div id="otp-section" class="otp-section">
        <input type="number" id="otp" placeholder="Enter 6-Digit OTP" />
        <button onclick="verifyOTP()">Verify OTP</button>
    </div>

    <div class="message" id="message"></div>
    <a href="login.php">Already registered? Login</a>
</div>

<script>
/**
 * Logic to call the PHP script via AJAX
 */
function sendOTP() {
    const phone = document.getElementById('phone').value.trim();
    const msgEl = document.getElementById('message');
    msgEl.textContent = 'Sending...';

    if (!phone) { 
        msgEl.textContent = 'Please enter your phone number.'; 
        return; 
    }

    const formData = new URLSearchParams();
    formData.append('phone', phone);

    fetch('Register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        msgEl.textContent = data.message;
        if (data.success) {
            document.getElementById('otp-section').style.display = 'block';
            document.getElementById('register-step').style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        msgEl.textContent = 'Network or Server Error. Check console.';
    });
}

function verifyOTP() {
    const phone = document.getElementById('phone').value.trim();
    const otp = document.getElementById('otp').value.trim();
    const msgEl = document.getElementById('message');
    msgEl.textContent = 'Verifying...';

    const formData = new URLSearchParams();
    formData.append('phone', phone);
    formData.append('otp', otp);

    fetch('verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        msgEl.textContent = data.message;
        if (data.success) {
            setTimeout(() => window.location.href = 'login.php', 1500);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        msgEl.textContent = 'Verification Error. Check console.';
    });
}
</script>
</body>
</html>
