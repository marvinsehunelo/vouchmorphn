<?php

/**
 * VouchMorph Bootstrap File
 * 
 * This file initializes the application, loads dependencies,
 * creates the Dependency Injection Container, and registers
 * all core services.
 * 
 * All namespaces follow the new structure: VouchMorph\*
 */

// ============================================================================
// 1. DEFINE PATHS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VENDOR_PATH', ROOT_PATH . '/vendor');

// ============================================================================
// 2. LOAD COMPOSER AUTOLOADER
// ============================================================================

$autoloader = VENDOR_PATH . '/autoload.php';
if (!file_exists($autoloader)) {
    die("Composer autoloader not found at: $autoloader. Please run 'composer install'.");
}
require_once $autoloader;

// ============================================================================
// 3. LOAD ENVIRONMENT VARIABLES
// ============================================================================

// Load main .env from root if using phpdotenv
if (class_exists('Dotenv\Dotenv') && file_exists(ROOT_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();
}

// Load country-specific configuration
$countryCode = $_ENV['COUNTRY_CODE'] ?? getenv('COUNTRY_CODE') ?: 'botswana';
$countryConfigPath = CONFIG_PATH . '/countries/' . $countryCode;

// ============================================================================
// 4. LOAD DATABASE CONFIGURATION (PostgreSQL for Railway)
// ============================================================================

// Load country-specific database config
$dbConfigFile = $countryConfigPath . '/database.php';
if (!file_exists($dbConfigFile)) {
    die("Database configuration not found at: $dbConfigFile");
}

$dbConfig = require $dbConfigFile;

// Create PostgreSQL connection using PDO
try {
    // Check if using Railway DATABASE_URL or individual configs
    if (defined('DB_HOST') && defined('DB_NAME')) {
        // Using the constants from database.php
        $host = DB_HOST;
        $port = DB_PORT;
        $database = DB_NAME;
        $username = DB_USER;
        $password = DB_PASSWORD;
        $driver = 'pgsql';
    } else {
        // Fallback to environment variables
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
        $database = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'swap_system_bw';
        $username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'postgres';
        $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';
        $driver = $_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'pgsql';
    }
    
    // Build DSN for PostgreSQL
    $dsn = "pgsql:host=$host;port=$port;dbname=$database";
    
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Set PostgreSQL specific options
    $db->exec("SET NAMES 'UTF8'");
    
    error_log("[Bootstrap] PostgreSQL connection established to database: $database");
    
} catch (PDOException $e) {
    error_log("PostgreSQL connection failed for country {$countryCode}: " . $e->getMessage());
    
    // During healthcheck, don't crash - but log error
    if (PHP_SAPI === 'cli') {
        die("Database connection failed: " . $e->getMessage());
    }
    
    $db = null;
}

// ============================================================================
// 5. LOAD COUNTRY CONFIGURATION
// ============================================================================

$countryConfig = [];
$countryConfigFile = SRC_PATH . '/Core/Config/Countries/' . ucfirst($countryCode) . '/config.php';
if (file_exists($countryConfigFile)) {
    $countryConfig = require_once $countryConfigFile;
}

// ============================================================================
// 6. LOAD COUNTRY-SPECIFIC JSON DATA
// ============================================================================

$banks = [];
$banksFile = $countryConfigPath . '/banks.json';
if (file_exists($banksFile)) {
    $banks = json_decode(file_get_contents($banksFile), true) ?? [];
}

$participants = [];
$participantsFile = $countryConfigPath . '/participants.json';
if (file_exists($participantsFile)) {
    $participants = json_decode(file_get_contents($participantsFile), true) ?? [];
}

$fees = [];
$feesFile = $countryConfigPath . '/fees.json';
if (file_exists($feesFile)) {
    $fees = json_decode(file_get_contents($feesFile), true) ?? [];
}

$cards = [];
$cardsFile = $countryConfigPath . '/cards.json';
if (file_exists($cardsFile)) {
    $cards = json_decode(file_get_contents($cardsFile), true) ?? [];
}

$communication = [];
$commFile = $countryConfigPath . '/communication.json';
if (file_exists($commFile)) {
    $communication = json_decode(file_get_contents($commFile), true) ?? [];
}

$atmNotes = [];
$atmFile = $countryConfigPath . '/atm_notes.json';
if (file_exists($atmFile)) {
    $atmNotes = json_decode(file_get_contents($atmFile), true) ?? [];
}

// ============================================================================
// 7. TIMEZONE HELPER FUNCTION (Inline to avoid dependency issues)
// ============================================================================

/**
 * Get valid timezone from environment with intelligent fallback
 * No hardcoded defaults - uses system intelligence
 * 
 * @return string Valid timezone identifier
 */
function getValidTimezone(): string
{
    // 1. Try environment variables (multiple possible keys)
    $envKeys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
    foreach ($envKeys as $key) {
        // Check $_ENV
        if (isset($_ENV[$key]) && !empty($_ENV[$key]) && is_string($_ENV[$key])) {
            $value = trim($_ENV[$key]);
            if (!empty($value)) {
                // Validate before returning
                if (in_array($value, timezone_identifiers_list())) {
                    return $value;
                }
                error_log("[Timezone] Invalid timezone in \$_ENV['{$key}']: {$value}");
            }
        }
        
        // Check getenv()
        $value = getenv($key);
        if ($value !== false && !empty($value) && is_string($value)) {
            $value = trim($value);
            if (!empty($value)) {
                if (in_array($value, timezone_identifiers_list())) {
                    return $value;
                }
                error_log("[Timezone] Invalid timezone in getenv('{$key}'): {$value}");
            }
        }
    }
    
    // 2. Try to derive from country code
    $countryCode = $_ENV['COUNTRY_CODE'] ?? getenv('COUNTRY_CODE') ?: '';
    if (!empty($countryCode)) {
        // Use PHP's built-in country to timezone mapping
        $countryZones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY);
        $countryUpper = strtoupper($countryCode);
        
        // Try exact match
        if (isset($countryZones[$countryUpper]) && !empty($countryZones[$countryUpper])) {
            $timezone = $countryZones[$countryUpper][0];
            error_log("[Timezone] Derived from country code {$countryCode}: {$timezone}");
            return $timezone;
        }
        
        // Try case-insensitive match
        foreach ($countryZones as $code => $zones) {
            if (strcasecmp($code, $countryCode) === 0 && !empty($zones)) {
                $timezone = $zones[0];
                error_log("[Timezone] Derived from country code {$countryCode}: {$timezone}");
                return $timezone;
            }
        }
    }
    
    // 3. Try to get from system default
    $systemTimezone = ini_get('date.timezone');
    if (!empty($systemTimezone) && in_array($systemTimezone, timezone_identifiers_list())) {
        error_log("[Timezone] Using system default: {$systemTimezone}");
        return $systemTimezone;
    }
    
    // 4. Ultimate fallback to UTC (never fails)
    error_log("[Timezone] No valid timezone found, falling back to UTC");
    return 'UTC';
}

// ============================================================================
// 8. APPLICATION SETTINGS
// ============================================================================

$settings = [
    'app_name' => $_ENV['APP_NAME'] ?? getenv('APP_NAME') ?: 'VouchMorph',
    'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production',
    'app_url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost',
    'timezone' => null, // Will be set after validation
    'country_code' => $countryCode,
    'country_config' => $countryConfig,
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY') ?: '',
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '',
    'db_driver' => 'pgsql',
];

// ============================================================================
// 9. SET TIMEZONE (NO HARDCODING - FULLY DYNAMIC)
// ============================================================================

try {
    $validTimezone = getValidTimezone();
    
    // Set PHP timezone
    date_default_timezone_set($validTimezone);
    $settings['timezone'] = $validTimezone;
    
    // Sync PostgreSQL timezone if database connection exists
    if (isset($db) && $db instanceof PDO) {
        $db->exec("SET timezone = '{$validTimezone}'");
        error_log("[Bootstrap] PostgreSQL timezone set to: {$validTimezone}");
    }
    
    error_log("[Bootstrap] PHP timezone set to: {$validTimezone}");
    
} catch (Exception $e) {
    // Absolute last resort - should never happen
    date_default_timezone_set('UTC');
    $settings['timezone'] = 'UTC';
    error_log("[Bootstrap] CRITICAL: Timezone error - " . $e->getMessage() . " - Forced to UTC");
}

// ============================================================================
// 10. DEPENDENCY INJECTION CONTAINER
// ============================================================================

class Container
{
    private array $instances = [];
    private array $factories = [];
    
    public function set(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }
    
    public function setFactory(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }
    
    public function get(string $id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])($this);
            $this->instances[$id] = $instance;
            return $instance;
        }
        
        throw new \Exception("Service not found in container: " . $id);
    }
    
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }
}

$container = new Container();

// ============================================================================
// 11. REGISTER CORE SERVICES
// ============================================================================

$container->set(PDO::class, $db);
$container->set('settings', $settings);
$container->set('countryConfig', $countryConfig);
$container->set('countryCode', $countryCode);
$container->set('banks', $banks);
$container->set('participants', $participants);
$container->set('fees', $fees);
$container->set('cards', $cards);
$container->set('communication', $communication);
$container->set('atmNotes', $atmNotes);

// ============================================================================
// 12. REGISTER DOMAIN SERVICES
// ============================================================================

// SwapService
$container->setFactory('VouchMorph\Domain\Services\SwapService', function($c) {
    return new \VouchMorph\Domain\Services\SwapService(
        $c->get(PDO::class),
        $c->get('settings'),
        $c->get('countryCode'),
        $c->get('settings')['encryption_key'],
        [
            'banks' => $c->get('banks'),
            'participants' => $c->get('participants'),
            'fees' => $c->get('fees'),
        ]
    );
});

// TransactionService
$container->setFactory('VouchMorph\Domain\Services\TransactionService', function($c) {
    return new \VouchMorph\Domain\Services\TransactionService(
        $c->get(PDO::class)
    );
});

// LedgerService
$container->setFactory('VouchMorph\Domain\Services\LedgerService', function($c) {
    return new \VouchMorph\Domain\Services\LedgerService(
        $c->get(PDO::class)
    );
});

// UserService
$container->setFactory('VouchMorph\Domain\Services\UserService', function($c) {
    return new \VouchMorph\Domain\Services\UserService(
        $c->get(PDO::class)
    );
});

// CardService
$container->setFactory('VouchMorph\Domain\Services\CardService', function($c) {
    return new \VouchMorph\Domain\Services\CardService(
        $c->get(PDO::class),
        $c->get('settings')
    );
});

// CashoutService
$container->setFactory('VouchMorph\Domain\Services\CashoutService', function($c) {
    return new \VouchMorph\Domain\Services\CashoutService(
        $c->get(PDO::class),
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// NotificationService
$container->setFactory('VouchMorph\Domain\Services\NotificationService', function($c) {
    return new \VouchMorph\Domain\Services\NotificationService(
        $c->get(PDO::class),
        $c->get('settings')
    );
});

// ExpiredSwapsService
$container->setFactory('VouchMorph\Domain\Services\ExpiredSwapsService', function($c) {
    return new \VouchMorph\Domain\Services\ExpiredSwapsService(
        $c->get(PDO::class),
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// AdminService
$container->setFactory('VouchMorph\Domain\Services\AdminService', function($c) {
    return new \VouchMorph\Domain\Services\AdminService(
        $c->get(PDO::class)
    );
});

// AuditTrailService
$container->setFactory('VouchMorph\Domain\Services\AuditTrailService', function($c) {
    return new \VouchMorph\Domain\Services\AuditTrailService(
        $c->get(PDO::class)
    );
});

// ============================================================================
// 13. REGISTER INFRASTRUCTURE SERVICES (Mojaloop)
// ============================================================================

// Mojaloop Router
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\Router', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\Router(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// Mojaloop Adapter
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\Adapter', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\Adapter(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// IdempotencyService
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\IdempotencyService', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\IdempotencyService(
        $c->get(PDO::class)
    );
});

// FspiopHeaderValidator
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\FspiopHeaderValidator', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\FspiopHeaderValidator();
});

// RequestParser
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\RequestParser', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\RequestParser();
});

// ResponseBuilder
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\ResponseBuilder', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\ResponseBuilder();
});

// HttpClient
$container->setFactory('VouchMorph\Infrastructure\Mojaloop\HttpClient', function($c) {
    return new \VouchMorph\Infrastructure\Mojaloop\HttpClient();
});

// ============================================================================
// 14. REGISTER APPLICATION CONTROLLERS
// ============================================================================

// USSDController
$container->setFactory('VouchMorph\Application\Controllers\USSDController', function($c) {
    return new \VouchMorph\Application\Controllers\USSDController(
        $c->get('VouchMorph\Domain\Services\SwapService'),
        $c->get('VouchMorph\Domain\Services\UserService')
    );
});

// TransactionController
$container->setFactory('VouchMorph\Application\Controllers\TransactionController', function($c) {
    return new \VouchMorph\Application\Controllers\TransactionController(
        $c->get('VouchMorph\Domain\Services\SwapService'),
        $c->get('VouchMorph\Domain\Services\TransactionService')
    );
});

// AuthController
$container->setFactory('VouchMorph\Application\Controllers\AuthController', function($c) {
    return new \VouchMorph\Application\Controllers\AuthController(
        $c->get('VouchMorph\Domain\Services\UserService')
    );
});

// DashboardController
$container->setFactory('VouchMorph\Application\Controllers\DashboardController', function($c) {
    return new \VouchMorph\Application\Controllers\DashboardController(
        $c->get('VouchMorph\Domain\Services\TransactionService')
    );
});

// ExpiredSwapsController
$container->setFactory('VouchMorph\Application\Controllers\ExpiredSwapsController', function($c) {
    return new \VouchMorph\Application\Controllers\ExpiredSwapsController(
        $c->get('VouchMorph\Domain\Services\ExpiredSwapsService')
    );
});

// ComplianceController
$container->setFactory('VouchMorph\Application\Controllers\ComplianceController', function($c) {
    return new \VouchMorph\Application\Controllers\ComplianceController(
        $c->get(PDO::class)
    );
});

// UserController
$container->setFactory('VouchMorph\Application\Controllers\UserController', function($c) {
    return new \VouchMorph\Application\Controllers\UserController(
        $c->get('VouchMorph\Domain\Services\UserService'),
        $c->get('VouchMorph\Domain\Services\CardService')
    );
});

// AdminController
$container->setFactory('VouchMorph\Application\Controllers\AdminController', function($c) {
    return new \VouchMorph\Application\Controllers\AdminController(
        $c->get('VouchMorph\Domain\Services\AdminService')
    );
});

// ============================================================================
// 15. REGISTER API HANDLERS
// ============================================================================

// TransfersHandler
$container->setFactory('VouchMorph\Application\Handlers\TransfersHandler', function($c) {
    return new \VouchMorph\Application\Handlers\TransfersHandler(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// PartiesHandler
$container->setFactory('VouchMorph\Application\Handlers\PartiesHandler', function($c) {
    return new \VouchMorph\Application\Handlers\PartiesHandler(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// QuotesHandler
$container->setFactory('VouchMorph\Application\Handlers\QuotesHandler', function($c) {
    return new \VouchMorph\Application\Handlers\QuotesHandler(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// ParticipantsHandler
$container->setFactory('VouchMorph\Application\Handlers\ParticipantsHandler', function($c) {
    return new \VouchMorph\Application\Handlers\ParticipantsHandler(
        $c->get('VouchMorph\Domain\Services\SwapService')
    );
});

// ============================================================================
// 16. REGISTER MIDDLEWARE AND UTILITIES
// ============================================================================

// AuditLogger
$container->setFactory('VouchMorph\Application\Utils\AuditLogger', function($c) {
    return new \VouchMorph\Application\Utils\AuditLogger(
        $c->get(PDO::class)
    );
});

// AccessControl
$container->setFactory('VouchMorph\Application\Middleware\AccessControl', function($c) {
    return new \VouchMorph\Application\Middleware\AccessControl();
});

// ============================================================================
// 17. RETURN CONTAINER
// ============================================================================

error_log("[Bootstrap] VouchMorph initialized successfully. Country: {$countryCode}, Timezone: {$settings['timezone']}, Environment: " . ($settings['app_env'] ?? 'unknown'));

return $container;
