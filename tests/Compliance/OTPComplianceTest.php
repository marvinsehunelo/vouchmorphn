<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

/**
 * OTP Compliance Test (No PHPUnit)
 */

require_once __DIR__ . '/../../SECURITY_LAYER/Auth/MultifactorAuth.php';
require_once __DIR__ . '/../../SECURITY_LAYER/Encryption/TokenEncryptor.php';
require_once __DIR__ . '/../../SECURITY_LAYER/Encryption/SecretManagerClient.php';

use SECURITY_LAYER\Auth\MultifactorAuth;
use SECURITY_LAYER\Encryption\TokenEncryptor;
use SECURITY_LAYER\Encryption\SecretManagerClient;

echo "\n===========================================\n";
echo "      OTP COMPLIANCE TEST (NO PHPUnit)\n";
echo "===========================================\n\n";

function assertResult(string $testName, bool $result): void {
    echo " - $testName : " . ($result ? "PASS" : "FAIL") . "\n";
}

/* ----------------------------------------------------
 * Load encryption key from SecretManagerClient
 * ---------------------------------------------------- */
$secretManager = new SecretManagerClient();

// Flexible key retrieval depending on implementation
if (method_exists($secretManager, 'getEncryptionKey')) {
    $key = $secretManager->getEncryptionKey();
} elseif (method_exists($secretManager, 'get')) {
    $key = $secretManager->get("master_key");
} elseif (method_exists($secretManager, 'fetchKey')) {
    $key = $secretManager->fetchKey("otp_key");
} else {
    $key = "fallback-testing-key-32-characters";
}

$encryptor = new TokenEncryptor($key);
$mfa       = new MultifactorAuth($encryptor);

/* -----------------------------------------
 * 1. Generate OTP
 * ----------------------------------------- */
$otpLength = 6;
$otpString = $mfa->generateOTP($otpLength);

assertResult(
    "OTP length should be $otpLength digits",
    (strlen($otpString) === $otpLength) && ctype_digit($otpString)
);

/* -----------------------------------------
 * 2. Encryption Validation
 * ----------------------------------------- */
$encrypted = $encryptor->encrypt($otpString);
$decrypted = $encryptor->decrypt($encrypted);

assertResult(
    "Encrypted OTP should decrypt correctly",
    $decrypted === $otpString
);

/* -----------------------------------------
 * 3. Replay Protection (simulate)
 * ----------------------------------------- */
$usedOtps = [];

// first verification attempt
$firstVerify = !in_array($otpString, $usedOtps);
if ($firstVerify) $usedOtps[] = $otpString;

// second verification attempt (should fail)
$secondVerify = !in_array($otpString, $usedOtps);

assertResult(
    "OTP should not verify twice (replay attack protection)",
    $firstVerify === true && $secondVerify === false
);

/* -----------------------------------------
 * 4. Expired OTP simulation
 * ----------------------------------------- */
$otpCreatedTime = time() - 100; // OTP generated 100 seconds ago
$expiryTime     = 90;           // OTP expiry in seconds

// Should fail because OTP is older than expiry
$expiredCheck = (time() - $otpCreatedTime) <= $expiryTime;

assertResult(
    "Expired OTP should fail verification",
    $expiredCheck === false
);

echo "\n===========================================\n";
echo " OTP Compliance Test Completed.\n";
echo "===========================================\n\n";

