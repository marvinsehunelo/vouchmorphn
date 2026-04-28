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

// Load main .env from root
$mainEnv = ROOT_PATH . '/.env';
if (file_exists($mainEnv)) {
    $lines = file($mainEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            $_SERVER[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Load country-specific .env for Botswana
$botswanaEnv = CONFIG_PATH . '/countries/botswana/.env';
if (file_exists($botswanaEnv)) {
    $lines = file($botswanaEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            $_SERVER[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// ============================================================================
// 4. LOAD DATABASE CONFIGURATION
// ============================================================================

$dbConfig = [];
if (file_exists(CONFIG_PATH . '/database_settings.php')) {
    $dbConfig = require_once CONFIG_PATH . '/database_settings.php';
} elseif (file_exists(CONFIG_PATH . '/database.php')) {
    $dbConfig = require_once CONFIG_PATH . '/database.php';
}

// Create database connection using PDO
try {
    $host = $dbConfig['host'] ?? $_ENV['DB_HOST'] ?? 'localhost';
    $port = $dbConfig['port'] ?? $_ENV['DB_PORT'] ?? '3306';
    $database = $dbConfig['database'] ?? $_ENV['DB_DATABASE'] ?? 'vouchmorph';
    $username = $dbConfig['username'] ?? $_ENV['DB_USERNAME'] ?? 'root';
    $password = $dbConfig['password'] ?? $_ENV['DB_PASSWORD'] ?? '';
    
    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed. Check your database environment variables.");
}

// ============================================================================
// 5. LOAD COUNTRY CONFIGURATION
// ============================================================================

$countryCode = $_ENV['COUNTRY_CODE'] ?? 'botswana';
$countryConfig = [];

$countryConfigPath = SRC_PATH . '/Core/Config/Countries/' . ucfirst($countryCode) . '/config.php';
if (file_exists($countryConfigPath)) {
    $countryConfig = require_once $countryConfigPath;
}

// ============================================================================
// 6. LOAD COUNTRY-SPECIFIC JSON DATA
// ============================================================================

$countryDataPath = CONFIG_PATH . '/countries/' . $countryCode;

$banks = [];
$banksFile = $countryDataPath . '/banks.json';
if (file_exists($banksFile)) {
    $banks = json_decode(file_get_contents($banksFile), true) ?? [];
}

$participants = [];
$participantsFile = $countryDataPath . '/participants.json';
if (file_exists($participantsFile)) {
    $participants = json_decode(file_get_contents($participantsFile), true) ?? [];
}

$fees = [];
$feesFile = $countryDataPath . '/fees.json';
if (file_exists($feesFile)) {
    $fees = json_decode(file_get_contents($feesFile), true) ?? [];
}

$cards = [];
$cardsFile = $countryDataPath . '/cards.json';
if (file_exists($cardsFile)) {
    $cards = json_decode(file_get_contents($cardsFile), true) ?? [];
}

$communication = [];
$commFile = $countryDataPath . '/communication.json';
if (file_exists($commFile)) {
    $communication = json_decode(file_get_contents($commFile), true) ?? [];
}

$atmNotes = [];
$atmFile = $countryDataPath . '/atm_notes.json';
if (file_exists($atmFile)) {
    $atmNotes = json_decode(file_get_contents($atmFile), true) ?? [];
}

// ============================================================================
// 7. APPLICATION SETTINGS
// ============================================================================

$settings = [
    'app_name' => $_ENV['APP_NAME'] ?? 'VouchMorph',
    'app_env' => $_ENV['APP_ENV'] ?? 'production',
    'app_url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Africa/Gaborone',
    'country_code' => $countryCode,
    'country_config' => $countryConfig,
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? '',
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? '',
];

date_default_timezone_set($settings['timezone']);

// ============================================================================
// 8. DEPENDENCY INJECTION CONTAINER
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
// 9. REGISTER CORE SERVICES
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
// 10. REGISTER DOMAIN SERVICES
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
// 11. REGISTER INFRASTRUCTURE SERVICES (Mojaloop)
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
// 12. REGISTER APPLICATION CONTROLLERS
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
// 13. REGISTER API HANDLERS - FIXED NAMESPACE
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
// 14. REGISTER MIDDLEWARE AND UTILITIES
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
// 15. RETURN CONTAINER (NO GLOBAL EAGER LOADING)
// ============================================================================

error_log("[Bootstrap] VouchMorph initialized successfully. Environment: " . ($settings['app_env'] ?? 'unknown'));

return $container;
