<?php
// Database configuration for Railway
$database_url = getenv('DATABASE_URL');

if ($database_url) {
    // Parse Railway's DATABASE_URL
    $db = parse_url($database_url);
    
    define('DB_HOST', $db['host']);
    define('DB_PORT', $db['port'] ?? '5432');
    define('DB_NAME', ltrim($db['path'], '/'));
    define('DB_USER', $db['user']);
    define('DB_PASSWORD', $db['pass']);
} else {
    // Fallback for local development
    define('DB_HOST', 'localhost');
    define('DB_PORT', '5432');
    define('DB_NAME', 'swap_system_bw');
    define('DB_USER', 'postgres');
    define('DB_PASSWORD', 'StrongPassword!');
}

// Connection function
function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}
?>
