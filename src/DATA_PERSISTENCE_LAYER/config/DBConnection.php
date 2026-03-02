<?php
namespace DATA_PERSISTENCE_LAYER\config;

use PDO;
use PDOException;
use Exception;

class DBConnection
{
    private static ?PDO $connection = null;
    private static array $instances = [];

    /**
     * Original method - maintains backward compatibility
     */
    public static function getConnection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = getenv('PG_HOST') ?: '127.0.0.1';
        $port = getenv('PG_PORT') ?: 5432;
        $db   = getenv('PG_DB_SWAP') ?: getenv('PG_DB_CORE') ?: 'swap_system_bw';
        $user = getenv('PG_USER') ?: 'vouchmorphn_user';
        $pass = getenv('PG_PASS') ?: '';

        try {
            $dsn = "pgsql:host=$host;port=$port;dbname=$db";
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            // Set search path
            self::$connection->exec("SET search_path TO public");
            
            error_log("[DBConnection] Successfully connected to database: {$db} as user: {$user}");
            return self::$connection;

        } catch (PDOException $e) {
            error_log("[DBConnection] Connection failed: " . $e->getMessage());
            http_response_code(500);
            die("DATABASE_CONNECTION_FAILED: " . $e->getMessage());
        }
    }

    /**
     * New method - matches what USSDController expects
     */
    public static function getInstance(array $dbConfig = []): PDO
    {
        $key = getenv('PG_DB_SWAP') ?: 'default';
        
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::getConnection();
        }
        
        return self::$instances[$key];
    }

    /**
     * Get database configuration
     */
    public function getConfig(): array
    {
        return [
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => getenv('PG_PORT') ?: 5432,
            'name' => getenv('PG_DB_SWAP') ?: getenv('PG_DB_CORE') ?: 'swap_system_bw',
            'user' => getenv('PG_USER') ?: 'vouchmorphn_user',
            'password' => getenv('PG_PASS') ?: '',
        ];
    }

    /**
     * Execute a query and return results
     */
    public static function query(string $sql, array $params = []): array
    {
        try {
            $conn = self::getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'permission denied') !== false) {
                error_log("[DBConnection] Permission denied. Please run: GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO " . self::getConnection()->query("SELECT current_user")->fetchColumn());
            }
            throw $e;
        }
    }

    /**
     * Execute a statement and return row count
     */
    public static function execute(string $sql, array $params = []): int
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Begin a transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Get the last insert ID
     */
    public static function lastInsertId(?string $name = null)
    {
        return self::getConnection()->lastInsertId($name);
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
            'permission_help' => null
        ];
        
        try {
            $conn = self::getConnection();
            $results['connection'] = true;
            $results['user'] = $conn->query("SELECT current_user")->fetchColumn();
            $results['database'] = $conn->query("SELECT current_database()")->fetchColumn();
            
            // Test permissions on key tables
            $tables = ['roles', 'users', 'participants', 'ledger_accounts'];
            foreach ($tables as $table) {
                try {
                    $conn->query("SELECT 1 FROM $table LIMIT 0");
                    $results['permissions'][$table] = 'GRANTED';
                } catch (Exception $e) {
                    $results['permissions'][$table] = 'DENIED: ' . $e->getMessage();
                }
            }
            
            // Provide help for permission issues
            if (in_array('DENIED', array_map(function($p) { 
                return strpos($p, 'DENIED') === 0 ? 'DENIED' : 'GRANTED'; 
            }, $results['permissions']))) {
                $results['permission_help'] = "Run these SQL commands as superuser:\n" .
                    "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO " . $results['user'] . ";\n" .
                    "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO " . $results['user'] . ";\n" .
                    "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO " . $results['user'] . ";";
            }
            
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }

    /**
     * Create a superuser connection for maintenance tasks
     */
    public static function getSuperuserConnection(): ?PDO
    {
        $host = getenv('PG_HOST') ?: '127.0.0.1';
        $port = getenv('PG_PORT') ?: 5432;
        $db   = getenv('PG_DB_SWAP') ?: getenv('PG_DB_CORE') ?: 'swap_system_bw';
        
        // Try common superuser names
        $superUsers = [
            ['user' => 'postgres', 'pass' => getenv('PG_SUPER_PASS') ?: ''],
            ['user' => 'root', 'pass' => ''],
        ];
        
        foreach ($superUsers as $su) {
            try {
                $dsn = "pgsql:host=$host;port=$port;dbname=$db";
                $conn = new PDO($dsn, $su['user'], $su['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return $conn;
            } catch (Exception $e) {
                continue;
            }
        }
        
        return null;
    }

    /**
     * Fix permissions automatically if we have superuser access
     */
    public static function fixPermissions(): bool
    {
        $superConn = self::getSuperuserConnection();
        if (!$superConn) {
            error_log("[DBConnection] Cannot fix permissions - no superuser access");
            return false;
        }
        
        try {
            $user = self::getConnection()->query("SELECT current_user")->fetchColumn();
            
            $superConn->exec("GRANT USAGE ON SCHEMA public TO $user");
            $superConn->exec("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO $user");
            $superConn->exec("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO $user");
            $superConn->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO $user");
            
            error_log("[DBConnection] Successfully granted permissions to $user");
            return true;
            
        } catch (Exception $e) {
            error_log("[DBConnection] Failed to fix permissions: " . $e->getMessage());
            return false;
        }
    }
}
