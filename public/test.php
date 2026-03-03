<?php
header('Content-Type: text/plain');
echo "=== VOUCHMORPHN SYSTEM DIAGNOSIS ===\n";

// 1. Check PHP Version & Environment
echo "[1] PHP Version: " . PHP_VERSION . "\n";
echo "[2] Working Directory: " . getcwd() . "\n";

// 2. Test Path Logic (Crucial for your APP_ROOT)
$root = realpath(__DIR__ . '/../');
echo "[3] Detected Root: " . ($root ?: "FAILED TO RESOLVE") . "\n";

// 3. Check for Critical Files
$files = [
    'Autoloader' => $root . '/vendor/autoload.php',
    'Config'     => $root . '/src/CORE_CONFIG/countries/BW/config_BW.php',
    'Public User'=> $root . '/public/user/regulationdemo.php'
];

foreach ($files as $name => $path) {
    echo "[4] Searching for $name: " . (file_exists($path) ? "✅ FOUND" : "❌ MISSING") . " ($path)\n";
}

// 4. Test Composer Autoloading
if (file_exists($files['Autoloader'])) {
    require $files['Autoloader'];
    $className = '\DATA_PERSISTENCE_LAYER\config\DBConnection';
    echo "[5] DB Class exists: " . (class_exists($className) ? "✅ YES" : "❌ NO") . "\n";
}

// 5. Test Database Connection (The most likely crash point)
try {
    echo "[6] Testing DB Connection... ";
    // We use getenv to see if Railway injected the variables
    $db_host = getenv('PGHOST') ?: 'Not Set';
    echo "Target: $db_host\n";
    
    // If your DBConnection class exists, try to call it
    if (class_exists('\DATA_PERSISTENCE_LAYER\config\DBConnection')) {
        $conn = \DATA_PERSISTENCE_LAYER\config\DBConnection::getConnection();
        echo "    Result: ✅ DATABASE CONNECTED\n";
    }
} catch (\Throwable $e) {
    echo "    Result: ❌ CRASHED: " . $e->getMessage() . "\n";
}

echo "=== DIAGNOSIS COMPLETE ===\n";
