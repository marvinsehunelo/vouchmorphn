<?php
// Force opcache reset
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ Opcache reset successfully\n";
} else {
    echo "❌ Opcache not enabled\n";
}

// Clear realpath cache
clearstatcache(true);
echo "✅ Realpath cache cleared\n";

// Show file path and check if it exists
$filePath = __DIR__ . '/../../../../src/BUSINESS_LOGIC_LAYER/services/SwapService.php';
echo "Loading from: " . $filePath . "\n";
echo "File exists: " . (file_exists($filePath) ? 'YES' : 'NO') . "\n";

// Try to load the file
require_once $filePath;
echo "✅ SwapService loaded\n";

// Check if the class exists
echo "SwapService class exists: " . (class_exists('BUSINESS_LOGIC_LAYER\services\SwapService') ? 'YES' : 'NO') . "\n";

// List all methods in the class
if (class_exists('BUSINESS_LOGIC_LAYER\services\SwapService')) {
    $methods = get_class_methods('BUSINESS_LOGIC_LAYER\services\SwapService');
    echo "Methods found: " . implode(', ', $methods) . "\n";
    echo "verifySourceAsset exists in list: " . (in_array('verifySourceAsset', $methods) ? 'YES' : 'NO') . "\n";
}
