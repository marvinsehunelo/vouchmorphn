<?php
declare(strict_types=1);

/**
 * GLOBAL SYSTEM BOOTSTRAP
 * Banking-grade, multi-country, regulator-safe
 */

/* ======================================================
 * 0️⃣ AUTOLOADER
 * ====================================================== */

$projectRoot = dirname(__DIR__);

spl_autoload_register(function ($class) use ($projectRoot) {
    $prefixes = [
        'DFSP_ADAPTER_LAYER\\'     => $projectRoot . '/src/DFSP_ADAPTER_LAYER/',
        'BUSINESS_LOGIC_LAYER\\'   => $projectRoot . '/src/BUSINESS_LOGIC_LAYER/',
        'DATA_PERSISTENCE_LAYER\\' => $projectRoot . '/src/DATA_PERSISTENCE_LAYER/',
        'INTEGRATION_LAYER\\'      => $projectRoot . '/src/INTEGRATION_LAYER/',
        'SECURITY_LAYER\\'         => $projectRoot . '/src/SECURITY_LAYER/',
        'CORE_CONFIG\\'            => $projectRoot . '/src/CORE_CONFIG/',
        'APP_LAYER\\'              => $projectRoot . '/src/APP_LAYER/'
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) continue;

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', $projectRoot);

use Dotenv\Dotenv;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\Adapter\PutenvAdapter;
use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\config\DBConnection;
use SECURITY_LAYER\Encryption\KeyVault;
use SECURITY_LAYER\Encryption\TokenEncryptor;
use BUSINESS_LOGIC_LAYER\services\SwapService;

/* ======================================================
 * 1️⃣ RESOLVE COUNTRY
 * ====================================================== */

$country = require APP_ROOT . '/src/CORE_CONFIG/system_country.php';

if (!$country || !defined('SYSTEM_COUNTRY')) {
    throw new RuntimeException('SYSTEM_COUNTRY not resolved');
}

$GLOBALS['country'] = SYSTEM_COUNTRY;
error_log("[BOOTSTRAP] Country → " . SYSTEM_COUNTRY);

/* ======================================================
 * 2️⃣ LOAD ENV (LOCAL ONLY)
 * ====================================================== */

if (!getenv('DATABASE_URL')) {

    $envFile = ".env_" . SYSTEM_COUNTRY;
    $envPath = APP_ROOT . "/src/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/" . $envFile;

    if (!file_exists($envPath)) {
        throw new RuntimeException("Missing env file: {$envPath}");
    }

    $repository = RepositoryBuilder::createWithDefaultAdapters()
        ->addAdapter(PutenvAdapter::class)
        ->make();

    Dotenv::create($repository, dirname($envPath), basename($envPath))->load();

    error_log("[BOOTSTRAP] Loaded local env: {$envPath}");
}

/* ======================================================
 * 3️⃣ LOAD COUNTRY CONFIG + PARTICIPANTS
 * ====================================================== */

$configFile = APP_ROOT . "/src/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/config_" . SYSTEM_COUNTRY . ".php";
$participantsFile = APP_ROOT . "/src/CORE_CONFIG/countries/" . SYSTEM_COUNTRY . "/participants_" . SYSTEM_COUNTRY . ".json";

if (!file_exists($configFile) || !file_exists($participantsFile)) {
    throw new RuntimeException("Missing country configuration files");
}

$countryConfig = require $configFile;

$participantsRaw = json_decode(file_get_contents($participantsFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException("Invalid participants JSON");
}

if (!isset($participantsRaw['participants'])) {
    $participantsRaw = ['participants' => $participantsRaw];
}

$participants = [];
foreach ($participantsRaw['participants'] as $code => $participant) {
    if (($participant['status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
    $participants[strtolower($code)] = $participant;
}

error_log("[BOOTSTRAP] Active participants: " . count($participants));

/* ======================================================
 * 4️⃣ SECURITY INITIALIZATION
 * ====================================================== */

$appKey = getenv('APP_ENCRYPTION_KEY') 
          ?: ($countryConfig['security']['encryption_key'] ?? null);

if (!$appKey || strlen($appKey) < 32) {
    throw new RuntimeException('APP_ENCRYPTION_KEY missing or weak');
}

$keyVault = new KeyVault(['default' => $appKey]);
$tokenEncryptor = new TokenEncryptor($keyVault->getEncryptionKey());

/* ======================================================
 * 5️⃣ SESSION START
 * ====================================================== */

SessionManager::start();

/* ======================================================
 * 6️⃣ DATABASE INITIALIZATION
 * ====================================================== */

// Debug: Check if DATABASE_URL is set
error_log("[DB_DEBUG] DATABASE_URL: " . (getenv('DATABASE_URL') ? 'Set' : 'Not set'));

// Debug: Show first part of DATABASE_URL (safe)
$dbUrl = getenv('DATABASE_URL');
if ($dbUrl) {
    $masked = preg_replace('/:[^@]*@/', ':****@', $dbUrl);
    error_log("[DB_DEBUG] DATABASE_URL value: " . $masked);
}

$pdo = DBConnection::getConnection();

if (!$pdo instanceof PDO) {
    error_log("[DB_DEBUG] DBConnection::getConnection() returned null");
    throw new RuntimeException("Failed to obtain PDO instance");
}
/* ======================================================
 * 7️⃣ LOAD MOJALOOP CONFIG
 * ====================================================== */

$mojaloopConfigPath = APP_ROOT . '/src/CORE_CONFIG/mojaloop.php';
$mojaloopConfig = file_exists($mojaloopConfigPath)
    ? require $mojaloopConfigPath
    : [];

/* ======================================================
 * 8️⃣ INITIALIZE SWAP SERVICE
 * ====================================================== */

$swapServiceConfig = [
    'participants'   => $participants,
    'country_config' => $countryConfig,
    'mojaloop'       => $mojaloopConfig,
    'environment'    => getenv('APP_ENV') ?: 'production'
];

$swapService = new SwapService(
    $primaryDbConnection,
    $mojaloopConfig,
    SYSTEM_COUNTRY,
    $appKey,
    $swapServiceConfig
);

error_log("[BOOTSTRAP] SwapService initialized");

/* ======================================================
 * 9️⃣ GLOBAL CONTEXT (CONTROLLED)
 * ====================================================== */

unset($countryConfig['security']['encryption_key']);

$GLOBALS['config'] = $countryConfig;
$GLOBALS['participants'] = $participants;
$GLOBALS['databases'] = $databases;
$GLOBALS['swapService'] = $swapService;
$GLOBALS['security'] = [
    'encryptor' => $tokenEncryptor,
    'keyVault'  => $keyVault
];

/* ======================================================
 * 🔟 ENSURE LOG DIRECTORY
 * ====================================================== */

$logDir = APP_ROOT . '/src/APP_LAYER/logs';
$logFile = $logDir . '/swap_audit.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

if (!file_exists($logFile)) {
    file_put_contents($logFile, '');
    chmod($logFile, 0644);
}

/* ======================================================
 * 1️⃣1️⃣ CALLBACK URL DETECTION
 * ====================================================== */

function getCallbackUrl(): string
{
    $hosts = [
        'host.docker.internal',
        '172.17.0.1',
        'localhost',
        '127.0.0.1'
    ];

    foreach ($hosts as $host) {
        $url = "http://{$host}:5050/callback/test";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_NOBODY => true
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code > 0 && $code < 500) {
            return "http://{$host}:5050/callback";
        }
    }

    return 'http://localhost:5050/callback';
}

$GLOBALS['CALLBACK_URL'] = getCallbackUrl();

/* ======================================================
 * 1️⃣2️⃣ FINAL VERIFICATION
 * ====================================================== */

error_log("[BOOTSTRAP] System ready for " . SYSTEM_COUNTRY);
error_log("[BOOTSTRAP] Callback URL → " . $GLOBALS['CALLBACK_URL']);




