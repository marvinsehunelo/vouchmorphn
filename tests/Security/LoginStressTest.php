<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * Login System Stress Test - Passwordless Login
 * ----------------------------------------------
 * Stress tests login.php endpoint for your Prestaged Swap System.
 *
 * Run:
 *   php login_stress_test.php
 * ----------------------------------------------
 */

date_default_timezone_set('Africa/Gaborone');

// ------------------------
// Test Settings
// ------------------------
$TOTAL_REQUESTS   = 50;   // total login attempts
$MAX_CONCURRENT   = 10;   // simultaneous curls
$LOGIN_ENDPOINT   = "http://localhost/prestagedSWAP/APP_LAYER/pages/login.php";
$VALID_PHONE      = "+26770000000"; // existing user
$INVALID_PHONE    = "+26770000TEST"; // invalid user
$PHONE_LIST       = [$VALID_PHONE, $INVALID_PHONE]; // randomly pick valid/invalid

echo "\n\033[1;36m=== Login System Stress Test ===\033[0m\n";
echo "Total Requests: $TOTAL_REQUESTS | Max Concurrent: $MAX_CONCURRENT\n";
echo "Running stress test...\n";

// ------------------------
// Helper: Initialize CURL
// ------------------------
function initCurl($phone, $endpoint, $cookieFile) {
    $payload = http_build_query(['phone' => $phone]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/x-www-form-urlencoded"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow redirect
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // enable cookies
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    return $ch;
}

// ------------------------
// Prepare all requests
// ------------------------
$multi = curl_multi_init();
$handles = [];
$results = [];
$cookieFile = tempnam(sys_get_temp_dir(), 'curl_cookie_');

for ($i = 0; $i < $TOTAL_REQUESTS; $i++) {
    $phone = $PHONE_LIST[array_rand($PHONE_LIST)];
    $handles[$i] = initCurl($phone, $LOGIN_ENDPOINT, $cookieFile);
}

// ------------------------
// Run stress test in batches
// ------------------------
$completed = 0;

while ($completed < $TOTAL_REQUESTS) {
    $batch = array_slice($handles, $completed, $MAX_CONCURRENT);

    foreach ($batch as $h) curl_multi_add_handle($multi, $h);

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);

    foreach ($batch as $h) {
        $response = curl_multi_getcontent($h);
        $code     = curl_getinfo($h, CURLINFO_HTTP_CODE);

        // login success = redirected to dashboard (HTTP 200 after follow)
        $success = strpos($response, 'Swap System Login') === false; // if login page not returned, login succeeded

        $results[] = [
            'success' => $success,
            'http_code' => $code
        ];

        curl_multi_remove_handle($multi, $h);
        curl_close($h);
    }

    $completed += count($batch);
}

curl_multi_close($multi);

// ------------------------
// Summarize results
// ------------------------
$successCount = count(array_filter($results, fn($r) => $r['success']));
$failCount    = $TOTAL_REQUESTS - $successCount;

echo "\n\033[1;33m===== LOGIN STRESS TEST RESULTS =====\033[0m\n";
echo "Total Requests:      $TOTAL_REQUESTS\n";
echo "Successful (dashboard): \033[1;32m$successCount\033[0m\n";
echo "Failed:              \033[1;31m$failCount\033[0m\n";
echo "====================================\n\n";

unlink($cookieFile); // clean up cookie file
exit;

