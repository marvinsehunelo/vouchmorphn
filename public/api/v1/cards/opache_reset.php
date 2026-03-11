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

// Show that the method exists
require_once '/../../../src/BUSINESS_LOGIC_LAYER/services/SwapService.php';
echo "✅ SwapService reloaded\n";
echo "✅ verifySourceAsset() exists: " . (method_exists('BUSINESS_LOGIC_LAYER\services\SwapService', 'verifySourceAsset') ? 'YES' : 'NO') . "\n";
