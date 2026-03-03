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
    'host' => getenv('PG_HOST') ?: 'localhost',
    'port' => getenv('PG_PORT') ?: 5432,
    'dbname' => getenv('PG_NAME') ?: (getenv('PG_DB_SWAP') ?: (getenv('PG_DB_CORE') ?: 'swap_system_bw')),
    'user' => getenv('PG_USER') ?: 'postgres',
    'password' => getenv('PG_PASS') ?: '',
    'sslmode' => getenv('PG_SSL_MODE') ?: 'prefer'
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
        
        // Fallback to individual environment variables
        return [
            'host' => getenv('PG_HOST') ?: 'localhost',
            'port' => getenv('PG_PORT') ?: 5432,
            'dbname' => getenv('PG_DB_SWAP') ?: getenv('PG_DB_CORE') ?: 'swap_system_bw',
            'user' => getenv('PG_USER') ?: 'postgres',
            'password' => getenv('PG_PASS') ?: '',
            'sslmode' => getenv('PG_SSL_MODE') ?: 'prefer' // Default to prefer for local
        ];
    }

    /**
     * Original method - maintains backward compatibility
     * Now returns null on failure instead of dying
     */
    public static function getConnection(): ?PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        // Prevent multiple connection attempts
        if (self::$connectionAttempted) {
            return null;
        }

        self::$connectionAttempted = true;
        $config = self::getDbConfig();

        try {
            // Build DSN with SSL parameters
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
            
            // Add SSL mode if specified and not default
            if (isset($config['sslmode']) && $config['sslmode'] !== 'prefer') {
                $dsn .= ";sslmode={$config['sslmode']}";
            }
            
            // For Railway, we need to handle SSL certificates
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false
            ];
            
            // Add SSL specific options for Railway
            if (getenv('DATABASE_URL') || (isset($config['sslmode']) && $config['sslmode'] === 'require')) {
                // For Railway's self-signed certificates, we need to allow self-signed certs
                // This is equivalent to ?sslmode=require in the connection string
                $options[PDO::PGSQL_ATTR_SSL_MODE] = 'require';
            }
            
            self::$connection = new PDO($dsn, $config['user'], $config['password'], $options);
            
            // Set search path
            self::$connection->exec("SET search_path TO public");
            
            error_log("[DBConnection] Successfully connected to database: {$config['dbname']} on {$config['host']}");
            return self::$connection;

        } catch (PDOException $e) {
            error_log("[DBConnection] Connection failed: " . $e->getMessage());
            error_log("[DBConnection] DSN used: " . $dsn);
            self::$connection = null;
            return null; // Return null instead of dying
        }
    }

    /**
     * New method - matches what USSDController expects
     * Returns null on failure instead of throwing
     */
    public static function getInstance(array $dbConfig = []): ?PDO
    {
        try {
            $conn = self::getConnection();
            return $conn;
        } catch (\Throwable $e) {
            error_log("[DBConnection] getInstance failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get database configuration
     */
    public function getConfig(): array
    {
        $config = self::getDbConfig();
        
        return [
            'host' => $config['host'],
            'port' => $config['port'],
            'name' => $config['dbname'],
            'user' => $config['user'],
            'password' => $config['password'],
            'driver' => 'pgsql',
            'sslmode' => $config['sslmode'] ?? 'prefer'
        ];
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
     * Get connection for a specific database (for multi-db setups)
     */
    public static function getDatabaseConnection(string $dbName): ?PDO
    {
        $config = self::getDbConfig();
        $config['dbname'] = $dbName;
        
        $key = $dbName;
        
        if (!isset(self::$instances[$key])) {
            try {
                // Build DSN with SSL parameters
                $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
                
                // Add SSL mode if specified and not default
                if (isset($config['sslmode']) && $config['sslmode'] !== 'prefer') {
                    $dsn .= ";sslmode={$config['sslmode']}";
                }
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                
                // Add SSL specific options for Railway
                if (getenv('DATABASE_URL') || (isset($config['sslmode']) && $config['sslmode'] === 'require')) {
                    $options[PDO::PGSQL_ATTR_SSL_MODE] = 'require';
                }
                
                self::$instances[$key] = new PDO($dsn, $config['user'], $config['password'], $options);
                
                error_log("[DBConnection] Connected to database: {$dbName}");
                
            } catch (PDOException $e) {
                error_log("[DBConnection] Failed to connect to {$dbName}: " . $e->getMessage());
                return null;
            }
        }
        
        return self::$instances[$key] ?? null;
    }

    /**
     * Execute a query and return results (safe version)
     */
    public static function query(string $sql, array $params = []): array
    {
        $conn = self::getConnection();
        if (!$conn) {
            error_log("[DBConnection] No database connection for query");
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
     * Execute a statement and return row count (safe version)
     */
    public static function execute(string $sql, array $params = []): int
    {
        $conn = self::getConnection();
        if (!$conn) {
            error_log("[DBConnection] No database connection for execute");
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
     * Begin a transaction
     */
    public static function beginTransaction(): bool
    {
        $conn = self::getConnection();
        return $conn ? $conn->beginTransaction() : false;
    }

    /**
     * Commit a transaction
     */
    public static function commit(): bool
    {
        $conn = self::getConnection();
        return $conn ? $conn->commit() : false;
    }

    /**
     * Rollback a transaction
     */
    public static function rollback(): bool
    {
        $conn = self::getConnection();
        return $conn ? $conn->rollBack() : false;
    }

    /**
     * Get the last insert ID
     */
    public static function lastInsertId(?string $name = null)
    {
        $conn = self::getConnection();
        return $conn ? $conn->lastInsertId($name) : null;
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
            'permissions' => [],
            'railway_url_detected' => getenv('DATABASE_URL') ? true : false,
            'ssl_mode' => null
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
            
            // Check SSL status
            $sslStatus = $conn->query("SHOW ssl")->fetchColumn();
            $results['ssl_mode'] = $sslStatus;
            
            // Test permissions on key tables
            $tables = ['roles', 'users', 'participants', 'ledger_accounts', 'admins'];
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
     * Fix permissions - In Railway, this is handled automatically
     */
    public static function fixPermissions(): bool
    {
        error_log("[DBConnection] Permissions are managed by Railway - no action needed");
        return true;
    }

    /**
     * Get connection status with detailed info
     */
    public static function getConnectionStatus(): array
    {
        $config = self::getDbConfig();
        $status = [
            'connected' => self::isConnected(),
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $config['dbname'],
            'user' => $config['user'],
            'ssl_mode' => $config['sslmode'] ?? 'prefer',
            'using_railway' => getenv('DATABASE_URL') ? true : false
        ];
        
        if (self::$connection) {
            try {
                $status['server_version'] = self::$connection->getAttribute(PDO::ATTR_SERVER_VERSION);
                $status['client_version'] = self::$connection->getAttribute(PDO::ATTR_CLIENT_VERSION);
                $status['connection_status'] = self::$connection->getAttribute(PDO::ATTR_CONNECTION_STATUS);
            } catch (\Throwable $e) {
                $status['error'] = $e->getMessage();
            }
        }
        
        return $status;
    }
}
