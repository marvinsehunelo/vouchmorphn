<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// APP_LAYER/api/verify_otp.php — API endpoint to verify OTP

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/**
 * 1️⃣ Load Country & Config (The New Standard)
 * This replaces system_country.php and the manual config pathing.
 * It automatically maps to /countries/[CODE]/.env_[CODE]
 */
try {
    require_once __DIR__ . '/../../src/Core/Database/config/DBConnection.php';
    
    // Load config and set SYSTEM_COUNTRY
    $config = require_once __DIR__ . '/../../src/Core/Config/load_country.php';
    $country = SYSTEM_COUNTRY; 
    
    $dbConfig = $config['db'] ?? [];
    if (empty($dbConfig)) {
        throw new Exception("Database configuration not found for {$country}.");
    }

} catch (Throwable $e) {
    error_log("Verify OTP Bootstrap Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System configuration error.']);
    exit;
}

use DATA_PERSISTENCE_LAYER\Config\DBConnection;

/**
 * 2️⃣ DB Connections
 * Note: Using the dynamic 'source_client_key' to find the partner DB (e.g., Cazacom)
 */
try {
    $swap_systemDB = DBConnection::getInstance($dbConfig['swap']);
    
    $sourceKey = $config['db']['source_client_key'] ?? 'cazacom';
    $sourceClientDB = DBConnection::getInstance($dbConfig[$sourceKey]);
} catch (Throwable $e) {
    error_log("Verify OTP DB Error [{$country}]: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

/**
 * 3️⃣ Input validation
 */
$phone = trim($_POST['phone'] ?? '');
$otp   = trim($_POST['otp'] ?? '');

if ($phone === '' || $otp === '') {
    echo json_encode(['success' => false, 'message' => 'Phone and OTP are required.']);
    exit;
}

// 4️⃣ Verify Logic (OTP check against the country-specific SWAP database)
try {
    $stmt = $swap_systemDB->prepare("
        SELECT id FROM otp_codes 
        WHERE phone = :phone AND code = :code AND expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([':phone' => $phone, ':code' => $otp]);
    
    if ($stmt->fetch()) {
        // Success logic follows... (e.g., marking user as verified)
        echo json_encode(['success' => true, 'message' => 'OTP verified successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
    }
} catch (Throwable $e) {
    error_log("OTP Verification Error [{$country}]: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred during verification.']);
}
try {
    // 6️⃣ Fetch latest OTP
    $stmt = $swap_systemDB->prepare("
        SELECT id, code, expires_at, used
        FROM otp_codes
        WHERE phone = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$phone]);
    $otpRow = $stmt->fetch();

    if (!$otpRow) {
        echo json_encode(['success' => false, 'message' => 'No OTP found for this phone.']);
        exit;
    }

    if ($otpRow['used']) {
        echo json_encode(['success' => false, 'message' => 'OTP has already been used.']);
        exit;
    }

    if ($otpRow['code'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP.']);
        exit;
    }

    if (strtotime($otpRow['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'OTP has expired.']);
        exit;
    }

    // 7️⃣ Mark OTP as used
    $swap_systemDB
        ->prepare("UPDATE otp_codes SET used = TRUE WHERE id = ?")
        ->execute([$otpRow['id']]);

    // 8️⃣ Check SWAP user
    $stmt = $swap_systemDB->prepare("SELECT user_id FROM users WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    $existingUser = $stmt->fetch();

    if (!$existingUser) {
        // 9️⃣ Pull source-client data (CAZACOM)
        $stmt = $cazacomDB->prepare("
            SELECT name, email
            FROM users
            WHERE phone_number = ?
            LIMIT 1
        ");
        $stmt->execute([$phone]);
        $cazaUser = $stmt->fetch();

        $username = $cazaUser['name']  ?? 'User';
        $email    = $cazaUser['email'] ?? null;
        $roleId   = 2; // attendee/user

        $tempPassword = bin2hex(random_bytes(4));
        $passwordHash = password_hash($tempPassword, VM_HASH_ALGO, VM_HASH_OPTIONS);

        $swap_systemDB->prepare("
            INSERT INTO users
            (username, email, phone, verified, role_id, password_hash, created_at, updated_at)
            VALUES (?, ?, ?, TRUE, ?, ?, NOW(), NOW())
        ")->execute([
            $username,
            $email,
            $phone,
            $roleId,
            $passwordHash
        ]);
    } else {
        // Existing user → verify
        $swap_systemDB
            ->prepare("UPDATE users SET verified = TRUE, updated_at = NOW() WHERE phone = ?")
            ->execute([$phone]);
    }

    // 🔔 Confirmation SMS (optional, non-fatal)
    try {
        $comm = CommunicationFactory::create('CAZACOM');
        $comm->sendSMS($phone, 'Your SWAP registration has been successfully verified!');
    } catch (\Throwable $e) {
        error_log('SMS warning: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'OTP verified successfully. User is now registered.'
    ]);

} catch (\Throwable $e) {
    error_log('Verify OTP Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred while verifying OTP.'
    ]);
}

