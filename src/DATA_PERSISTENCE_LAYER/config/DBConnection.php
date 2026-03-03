<?php
namespace DATA_PERSISTENCE_LAYER\config;

use PDO;
use PDOException;

class DBConnection
{
    private static ?PDO $connection = null;
    
    public static function getConnection(): ?PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }
        
        $databaseUrl = getenv('DATABASE_URL');
        
        if (!$databaseUrl) {
            error_log("[DBConnection] DATABASE_URL not set");
            return null;
        }
        
        try {
            // Parse the URL
            $db = parse_url($databaseUrl);
            $host = $db['host'] ?? 'localhost';
            $port = $db['port'] ?? '5432';
            $dbname = ltrim($db['path'] ?? '', '/');
            $user = $db['user'] ?? 'postgres';
            $password = $db['pass'] ?? '';
            
            // For Railway internal connections, no SSL needed
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            
            error_log("[DBConnection] Connecting to {$host}:{$port}/{$dbname}");
            
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            error_log("[DBConnection] Connected successfully");
            return self::$connection;
            
        } catch (PDOException $e) {
            error_log("[DBConnection] Failed: " . $e->getMessage());
            return null;
        }
    }
}
