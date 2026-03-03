<?php
declare(strict_types=1);

/**
 * GLOBAL SYSTEM BOOTSTRAP
 * Banking-grade, multi-country, regulator-safe
 */

/* ======================================================
 * 0️⃣ INTERNAL AUTOLOADER (for layered architecture)
 * ====================================================== */

spl_autoload_register(function ($class) {
    $prefixes = [
        'DFSP_ADAPTER_LAYER\\'     => __DIR__ . '/DFSP_ADAPTER_LAYER/',
        'BUSINESS_LOGIC_LAYER\\'   => __DIR__ . '/BUSINESS_LOGIC_LAYER/',
        'DATA_PERSISTENCE_LAYER\\' => __DIR__ . '/DATA_PERSISTENCE_LAYER/',
        'INTEGRATION_LAYER\\'      => __DIR__ . '/INTEGRATION_LAYER/',
        'SECURITY_LAYER\\'         => __DIR__ . '/SECURITY_LAYER/',
        'CORE_CONFIG\\'            => __DIR__ . '/CORE_CONFIG/',
        'APP_LAYER\\'              => __DIR__ . '/APP_LAYER/'
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) continue;

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// Load Composer autoloader first
require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(__DIR__ . '/../../')); 

use Dotenv\Dotenv;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\Adapter\PutenvAdapter;
use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;
use SECURITY_LAYER\Encryption\KeyVault;
use SECURITY_LAYER\Encryption\TokenEncryptor;
use BUSINESS_LOGIC_LAYER\services\SwapService;

/* ======================================================
 * 1️⃣ RESOLVE SYSTEM COUNTRY (SINGLE SOURCE OF TRUTH)
 * ====================================================== */
$country = require APP_ROOT . '/CORE_CONFIG/system_country.php';

if (!$country || !is_string($country)) {
    throw new RuntimeException('SYSTEM_COUNTRY not resolved');
}

if (!defined('SYSTEM_COUNTRY')) {
    throw new RuntimeException('SYSTEM_COUNTRY not defined. system_country.php must define it.');
}

$GLOBALS['country'] = SYSTEM_COUNTRY;
error_log("[BOOTSTRAP] Country resolved → " . SYSTEM_COUNTRY);

/* ======================================================
 * 2️⃣ LOAD COUNTRY ENV (LOCAL ONLY, RAILWAY USES ENV)
 * ====================================================== */
if (!getenv('DATABASE_URL')) {
    // Local development
    $envFile = ".env_" . SYSTEM_COUNTRY;
    $envPath = APP_ROOT . "/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/" . $envFile;

    if (!file_exists($envPath)) {
        throw new RuntimeException("Missing local env file: {$envPath}");
    }

    $repository = Dotenv\Repository\RepositoryBuilder::createWithDefaultAdapters()
        ->addAdapter(PutenvAdapter::class)
        ->make();

    Dotenv::create($repository, dirname($envFile), basename($envFile))->load();

    error_log("[BOOTSTRAP] Loaded local env: {$envPath}");
} else {
    // Production (Railway) - no file needed
    error_log("[BOOTSTRAP] Using Railway environment variables for " . SYSTEM_COUNTRY);
}

/* ======================================================
 * 3️⃣ LOAD COUNTRY CONFIG
 * ====================================================== */
$configFile = APP_ROOT . "/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/config_" . SYSTEM_COUNTRY . ".php";
$participantsFile = APP_ROOT . "/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/participants_" . SYSTEM_COUNTRY . ".json";

if (!file_exists($configFile)) {
    throw new RuntimeException("Missing config: {$configFile}");
}

if (!file_exists($participantsFile)) {
    throw new RuntimeException("Missing participants file: {$participantsFile}");
}

$countryConfig = require $configFile;

// Load and validate participants JSON with better error handling
$participantsJson = file_get_contents($participantsFile);
if ($participantsJson === false) {
    throw new RuntimeException("Failed to read participants file: {$participantsFile}");
}

$participantsRaw = json_decode($participantsJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException(
        "Invalid participants JSON in {$participantsFile}: " . json_last_error_msg()
    );
}

if (!is_array($participantsRaw)) {
    throw new RuntimeException(
        "Participants data must be an array in {$participantsFile}, got: " . gettype($participantsRaw)
    );
}

// Check if the expected structure exists
if (!isset($participantsRaw['participants'])) {
    error_log("[BOOTSTRAP] WARNING: No 'participants' key found in JSON. Using entire object as participants array.");
    // If 'participants' key doesn't exist, assume the whole object is the participants array
    $participantsRaw = ['participants' => $participantsRaw];
}

error_log("[BOOTSTRAP] Successfully loaded participants JSON from {$participantsFile}");
error_log("[BOOTSTRAP] Found " . count($participantsRaw['participants'] ?? []) . " participants");

/* ======================================================
 * 4️⃣ INITIALIZE SECURITY (CRITICAL)
 * ====================================================== */
$appKey = getenv('APP_ENCRYPTION_KEY');

if (!$appKey || strlen($appKey) < 32) {
    throw new RuntimeException(
        'APP_ENCRYPTION_KEY missing or too weak for ' . SYSTEM_COUNTRY
    );
}

$keyVault = new KeyVault(['default' => $appKey]);
$tokenEncryptor = new TokenEncryptor($keyVault->getEncryptionKey());

/* ======================================================
 * 5️⃣ START SESSION (SAFE)
 * ====================================================== */
require_once APP_ROOT . '/APP_LAYER/utils/session_manager.php';
SessionManager::start();

/* ======================================================
 * 6️⃣ BUILD DATABASE CONFIGURATION ARRAY
 * ====================================================== */
$dbConfig = [
    'host' => getenv('PG_HOST') ?: ($countryConfig['db']['primary']['host'] ?? 'localhost'),
    'port' => getenv('PG_PORT') ?: ($countryConfig['db']['primary']['port'] ?? 5432),
    'name' => getenv('PG_NAME') ?: ($countryConfig['db']['primary']['database'] ?? 'swap_system_bw'),
    'user' => getenv('PG_USER') ?: ($countryConfig['db']['primary']['username'] ?? 'postgres'),
    'password' => getenv('PG_PASS') ?: ($countryConfig['db']['primary']['password'] ?? '')
];

/* ======================================================
 * 7️⃣ INITIALIZE DATABASES (NOW USING CONFIG ARRAY)
 * ====================================================== */
if (empty($countryConfig['db'])) {
    throw new RuntimeException("DB config missing");
}

$databases = [];
foreach ($countryConfig['db'] as $name => $dbConf) {
    // Convert the dbConf array to match what DBConnection expects
    $connectionConfig = [
        'host' => $dbConf['host'] ?? $dbConfig['host'],
        'port' => $dbConf['port'] ?? $dbConfig['port'],
        'name' => $dbConf['database'] ?? $dbConf['name'] ?? $dbConfig['name'],
        'user' => $dbConf['username'] ?? $dbConf['user'] ?? $dbConfig['user'],
        'password' => $dbConf['password'] ?? $dbConfig['password']
    ];
    $databases[$name] = new DBConnection($connectionConfig);
}

// Ensure primary database exists
$primaryDb = $databases['primary'] ?? reset($databases);

/* ======================================================
 * 8️⃣ LOAD ACTIVE PARTICIPANTS ONLY
 * ====================================================== */
$participants = [];
foreach (($participantsRaw['participants'] ?? []) as $code => $participant) {
    if (($participant['status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
    $participants[strtolower($code)] = $participant;
}

/* ======================================================
 * 9️⃣ PREPARE SWAP SERVICE DEPENDENCIES
 * ====================================================== */
// Load Mojaloop config
$mojaloopConfig = [];
$mojaloopConfigPath = APP_ROOT . '/CORE_CONFIG/mojaloop.php';
if (file_exists($mojaloopConfigPath)) {
    $mojaloopConfig = require $mojaloopConfigPath;
}

// Initialize optional services (extend these as needed)
$logger = null; // Initialize your logger if you have one
$cache = null;  // Initialize your cache if you have one
$eventDispatcher = null; // Initialize your event dispatcher if you have one

/* ======================================================
 * 🔟 INITIALIZE SWAP SERVICE WITH CORRECT ARGUMENTS
 * ====================================================== */
// Get the primary database connection object
$primaryDbConnection = $databases['primary'] ?? reset($databases);

// Get the PDO instance from DBConnection
if (method_exists($primaryDbConnection, 'getConnection')) {
    $pdo = $primaryDbConnection->getConnection();
    error_log("[BOOTSTRAP] Got PDO via getConnection() method");
} else {
    // Fallback using reflection
    $reflection = new ReflectionClass($primaryDbConnection);
    if ($reflection->hasProperty('connection')) {
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $pdo = $property->getValue($primaryDbConnection);
        error_log("[BOOTSTRAP] Got PDO via reflection");
    } else {
        throw new RuntimeException("Cannot access PDO from DBConnection");
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException("Primary database must return a PDO instance, got: " . gettype($pdo));
}

error_log("[BOOTSTRAP] PDO connection obtained successfully");

// Get the encryption key from environment (never hardcoded)
$appKey = getenv('APP_ENCRYPTION_KEY');
if (!$appKey) {
    // Try to get from country config as fallback
    $appKey = $countryConfig['security']['encryption_key'] ?? null;
    
    if (!$appKey) {
        error_log("[CRITICAL] APP_ENCRYPTION_KEY not set in environment or config");
        throw new RuntimeException('APP_ENCRYPTION_KEY missing for ' . SYSTEM_COUNTRY);
    }
    
    error_log("[BOOTSTRAP] Using encryption key from country config for " . SYSTEM_COUNTRY);
}

// DEBUG: Log participants structure (without sensitive data)
error_log("[BOOTSTRAP] Participants loaded for " . SYSTEM_COUNTRY . ": " . count($GLOBALS['participants'] ?? []));

// Initialize SwapService with dynamic configuration
$swapServiceConfig = [
    'participants' => $GLOBALS['participants'] ?? [],  // Full participants data
    'country_config' => $countryConfig ?? [],           // Country-specific config
    'mojaloop' => $mojaloopConfig ?? [],                // Mojaloop config if available
    'environment' => getenv('APP_ENV') ?: 'production'   // Current environment
];

$swapService = new SwapService(
    $pdo,                                      // Argument 1: PDO connection
    $mojaloopConfig ?? [],                     // Argument 2: Mojaloop config
    SYSTEM_COUNTRY,                             // Argument 3: Country code
    $appKey,                                    // Argument 4: Encryption key
    $swapServiceConfig                          // Argument 5: Complete config array
);

$GLOBALS['swapService'] = $swapService;

// Log success (without exposing sensitive data)
error_log("[BOOTSTRAP] SwapService initialized for " . SYSTEM_COUNTRY . 
          " with " . count($GLOBALS['participants'] ?? []) . " participants");

// Optional: Log which participants are available (for debugging)
if (getenv('APP_DEBUG') === 'true') {
    error_log("[BOOTSTRAP DEBUG] Available participants: " . 
              json_encode(array_keys($GLOBALS['participants'] ?? [])));
}
/* ======================================================
 * 1️⃣1️⃣ GLOBAL CONTEXT (CONTROLLED EXPOSURE)
 * ====================================================== */
unset($countryConfig['security']['encryption_key']);

$GLOBALS['config'] = $countryConfig;
$GLOBALS['participants'] = $participants;
$GLOBALS['databases'] = $databases;
$GLOBALS['dbConfig'] = $dbConfig;
$GLOBALS['mojaloopConfig'] = $mojaloopConfig;
$GLOBALS['logger'] = $logger;
$GLOBALS['cache'] = $cache;
$GLOBALS['eventDispatcher'] = $eventDispatcher;
$GLOBALS['swapService'] = $swapService;

$GLOBALS['security'] = [
    'encryptor' => $tokenEncryptor,
    'keyVault'  => $keyVault
];

/* ======================================================
 * 1️⃣2️⃣ ENSURE LOG DIRECTORY & FILE PERMISSIONS
 * ====================================================== */
$logDir = APP_ROOT . '/APP_LAYER/logs';
$logFile = $logDir . '/swap_audit.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
    error_log("[BOOTSTRAP] Logs directory created at {$logDir}");
}

if (!file_exists($logFile)) {
    file_put_contents($logFile, "");
    chmod($logFile, 0644);
    error_log("[BOOTSTRAP] swap_audit.log created at {$logFile}");
}

/* ======================================================
 * 1️⃣3️⃣ CALLBACK URL DETECTION
 * ====================================================== */
function getCallbackUrl() {
    // Try different possible Docker host IPs
    $possible_hosts = [
        '172.17.0.1',     // Default Docker bridge
        '172.18.0.1',     // Common Docker network
        'host.docker.internal',
        'localhost',
        '127.0.0.1'
    ];
    
    foreach ($possible_hosts as $host) {
        $url = "http://$host:5050/callback/test";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code > 0 && $code < 500) {
            $baseUrl = "http://$host:5050/callback";
            error_log("[BOOTSTRAP] Callback URL resolved to: $baseUrl (HTTP $code)");
            return $baseUrl;
        }
    }
    
    // Default fallback
    $default = 'http://172.17.0.1:5050/callback';
    error_log("[BOOTSTRAP] Using default callback URL: $default");
    return $default;
}

$GLOBALS['CALLBACK_URL'] = getCallbackUrl();

/* ======================================================
 * 1️⃣4️⃣ VERIFICATION LOGS
 * ====================================================== */
error_log("[BOOTSTRAP] System initialized successfully for " . SYSTEM_COUNTRY);
error_log("[BOOTSTRAP] Callback URL: " . $GLOBALS['CALLBACK_URL']);
error_log("[BOOTSTRAP] SwapService initialized: " . (isset($GLOBALS['swapService']) ? 'YES' : 'NO'));



