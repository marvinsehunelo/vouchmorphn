<?php
// Force override at the very top
putenv('PG_HOST=interchange.proxy.rlwy.net');
putenv('PG_PORT=52371');
putenv('PG_USER=postgres');
putenv('PG_PASS=JcEMxRQuEImysxrAgeJPgJDXYfsnUxSB');
putenv('PG_NAME=railway');
putenv('PG_DB_SWAP=railway'); // Override this too
putenv('PG_DB_CORE=railway');  // And this

echo "=== ENVIRONMENT VARIABLES ===\n";
echo "PG_HOST: " . getenv('PG_HOST') . "\n";
echo "PG_PORT: " . getenv('PG_PORT') . "\n";
echo "PG_USER: " . getenv('PG_USER') . "\n";
echo "PG_PASS: " . (getenv('PG_PASS') ? '[SET]' : '[NOT SET]') . "\n";
echo "PG_NAME: " . getenv('PG_NAME') . "\n";
echo "PG_DB_SWAP: " . getenv('PG_DB_SWAP') . "\n";
echo "PG_DB_CORE: " . getenv('PG_DB_CORE') . "\n\n";

require './src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

// Use reflection to get the private method
echo "=== DATABASE CONFIG ===\n";
$reflection = new ReflectionClass('DATA_PERSISTENCE_LAYER\config\DBConnection');
$method = $reflection->getMethod('getDbConfig');
$method->setAccessible(true);
$config = $method->invoke(null);
print_r($config);

echo "\n=== TEST CONNECTION ===\n";
$result = \DATA_PERSISTENCE_LAYER\config\DBConnection::testConnection();
print_r($result);

// Try a direct PDO connection to see if that works
echo "\n=== DIRECT PDO CONNECTION ===\n";
try {
    $dsn = "pgsql:host=interchange.proxy.rlwy.net;port=52371;dbname=railway;sslmode=require";
    $direct = new PDO($dsn, 'postgres', 'JcEMxRQuEImysxrAgeJPgJDXYfsnUxSB', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✅ DIRECT CONNECTION SUCCESSFUL!\n";
    $dbname = $direct->query("SELECT current_database()")->fetchColumn();
    echo "Connected to: " . $dbname . "\n";
} catch (Exception $e) {
    echo "❌ Direct connection failed: " . $e->getMessage() . "\n";
}
