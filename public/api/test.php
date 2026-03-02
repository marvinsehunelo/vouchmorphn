<?php
/**
 * TTK BACKDOOR LOG EXTRACTOR
 * Bypasses the spinning UI to see the actual error.
 */

$ttkAdminUrl = 'http://172.17.0.1:6060/admin/logs?limit=1';

echo "--- [POLLING TTK FOR RECENT ERRORS] ---\n";

$ch = curl_init($ttkAdminUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Don't wait forever
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode !== 200) {
    echo "❌ TTK Admin API is unresponsive (Status: $httpCode).\n";
    echo "ACTION: You need to RESTART your TTK Docker containers. Run: 'docker-compose restart'\n";
    exit;
}

$logs = json_decode($response, true);

if (empty($logs)) {
    echo "✅ No logs found. The TTK might have cleared its buffer.\n";
} else {
    echo "LATEST INTERACTION FOUND:\n";
    $latest = $logs[0];
    echo "Method: " . ($latest['method'] ?? 'N/A') . "\n";
    echo "Path: " . ($latest['path'] ?? 'N/A') . "\n";
    
    if (isset($latest['validationErrors'])) {
        echo "❌ VALIDATION ERROR: " . json_encode($latest['validationErrors'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "No explicit validation error. This usually means a TIMEOUT occurred.\n";
    }
}
