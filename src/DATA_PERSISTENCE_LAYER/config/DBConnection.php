<?php
namespace DATA_PERSISTENCE_LAYER\config;

use PDO;
use PDOException;
use Exception;

class DBConnection
{
    private static ?PDO $connection = null;
    private static array $instances = [];
    private static bool $connectionAttempted = false;

    /**
     * Parse Railway DATABASE_URL
     */
    private static function parseRailwayUrl(): ?array
    {
        $database_url = getenv('DATABASE_URL');
        
        if (!$database_url) {
            return null;
        }
        
        $db = parse_url($database_url);
        
        // Parse query string for SSL parameters
        $params = [];
        if (isset($db['query'])) {
            parse_str($db['query'], $params);
        }
        
        return [
            'host' => $db['host'] ?? 'localhost',
            'port' => $db['port'] ?? '5432',
            'dbname' => ltrim($db['path'] ?? '', '/'),
            'user' => $db['user'] ?? 'postgres',
            'password' => $db['pass'] ?? '',
            'sslmode' => $params['sslmode'] ?? 'require'
        ];
    }

    /**
     * Get database configuration from environment
     */
    private static function getDbConfig(): array
    {
        // First try Railway DATABASE_URL
        $railwayConfig = self::parseRailwayUrl();
        if ($railwayConfig) {
            return $railwayConfig;
        }
        
        // Get host to detect Railway
        $host = getenv('PG_HOST') ?: 'localhost';
        $isRailway = (strpos($host, 'railway') !== false || 
                      strpos($host, 'rlwy.net') !== false ||
                      $host === 'interchange.proxy.rlwy.net');
        
        // For Railway, force database name to 'railway'
        if ($isRailway) {
            return [
                'host' => $host,
                'port' => getenv('PG_PORT') ?: 5432,
                'dbname' => 'railway', // FORCE railway database name
                'user' => getenv('PG_USER') ?: 'postgres',
                'password' => getenv('PG_PASS') ?: '',
                'sslmode' => 'require'
            ];
        }
        
        // Local development - use environment or default
        $dbname = getenv('PG_NAME') ?: (getenv('PG_DB_SWAP') ?: (getenv('PG_DB_CORE') ?: 'swap_system_bw'));
        
        return [
            'host' => $host,
            'port' => getenv('PG_PORT') ?: 5432,
            'dbname' => $dbname,
            'user' => getenv('PG_USER') ?: 'postgres',
            'password' => getenv('PG_PASS') ?: '',
            'sslmode' => getenv('PG_SSL_MODE') ?: 'prefer'
        ];
    }

    /**
     * Get database connection
     */
    public static function getConnection(): ?PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (self::$connectionAttempted) {
            return null;
        }

        self::$connectionAttempted = true;
        $config = self::getDbConfig();

        try {
            // Build DSN
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
            
            // Add SSL mode if required - this is the correct way to enable SSL
            if (isset($config['sslmode']) && $config['sslmode'] === 'require') {
                $dsn .= ";sslmode=require";
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5
            ];
            
            self::$connection = new PDO($dsn, $config['user'], $config['password'], $options);
            
            // Set search path
            self::$connection->exec("SET search_path TO public");
            
            return self::$connection;

        } catch (PDOException $e) {
            error_log("[DBConnection] Connection failed: " . $e->getMessage());
            self::$connection = null;
            return null;
        }
    }

    /**
     * Get database connection instance
     */
    public static function getInstance(array $dbConfig = []): ?PDO
    {
        return self::getConnection();
    }

    /**
     * Get database configuration
     */
    public function getConfig(): array
    {
        return self::getDbConfig();
    }

    /**
     * Check if database is connected
     */
    public static function isConnected(): bool
    {
        try {
            $conn = self::getConnection();
            if (!$conn) {
                return false;
            }
            $conn->query("SELECT 1")->fetch();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get connection for a specific database
     */
    public static function getDatabaseConnection(string $dbName): ?PDO
    {
        $config = self::getDbConfig();
        $config['dbname'] = $dbName;
        
        $key = $dbName;
        
        if (!isset(self::$instances[$key])) {
            try {
                $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
                
                if (isset($config['sslmode']) && $config['sslmode'] === 'require') {
                    $dsn .= ";sslmode=require";
                }
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                
                self::$instances[$key] = new PDO($dsn, $config['user'], $config['password'], $options);
                
            } catch (PDOException $e) {
                error_log("[DBConnection] Failed to connect to {$dbName}: " . $e->getMessage());
                return null;
            }
        }
        
        return self::$instances[$key] ?? null;
    }

    /**
     * Execute a query and return results
     */
    public static function query(string $sql, array $params = []): array
    {
        $conn = self::getConnection();
        if (!$conn) {
            return [];
        }
        
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[DBConnection] Query failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Execute a statement and return row count
     */
    public static function execute(string $sql, array $params = []): int
    {
        $conn = self::getConnection();
        if (!$conn) {
            return 0;
        }
        
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("[DBConnection] Execute failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Test connection and permissions
     */
    public static function testConnection(): array
    {
        $results = [
            'connection' => false,
            'user' => null,
            'database' => null,
            'permissions' => []
        ];
        
        $conn = self::getConnection();
        if (!$conn) {
            $results['error'] = 'No database connection';
            return $results;
        }
        
        try {
            $results['connection'] = true;
            $results['user'] = $conn->query("SELECT current_user")->fetchColumn();
            $results['database'] = $conn->query("SELECT current_database()")->fetchColumn();
            
            // Test permissions on key tables
            $tables = ['admins', 'participants', 'ledger_accounts', 'kyc_documents'];
            foreach ($tables as $table) {
                try {
                    $conn->query("SELECT 1 FROM $table LIMIT 0");
                    $results['permissions'][$table] = 'GRANTED';
                } catch (Exception $e) {
                    $results['permissions'][$table] = 'DENIED';
                }
            }
            
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Get connection status
     */
    public static function getConnectionStatus(): array
    {
        $config = self::getDbConfig();
        return [
            'connected' => self::isConnected(),
            'config' => $config
        ];
    }
}}
