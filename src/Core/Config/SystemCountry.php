<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// CORE_CONFIG/system_country.php

$supportedCountries = [
    'BW', 'NG', 'KE', 'ZA', 'UG', 'TZ', 'GH', 'ZM', 'ZW', 'NA', 'CM', 'SN',
    'CI', 'ML', 'ET', 'DZ', 'MA', 'EG', 'SD', 'LY', 'TN', 'RW', 'BI', 'MW',
    'LS', 'MR', 'CF', 'CG', 'CD', 'NE', 'BF', 'GM', 'SL', 'LR', 'GN', 'TG',
    'BJ', 'SO', 'DJ', 'ER', 'ST', 'GQ', 'CV'
];

/**
 * Prevent "Fatal error: Cannot redeclare loadEnvFile()" by checking
 * if the function was already defined in a previous require/include.
 */
if (!function_exists('loadEnvFile')) {
    function loadEnvFile($filePath) {
        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;
                
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim(trim($value), '"\'');
                    
                    putenv("$key=$value");
                    $_SERVER[$key] = $value;
                    $_ENV[$key] = $value;
                }
            }
            return true;
        }
        return false;
    }
}

$determinedCountry = null;

// 1. Session Priority
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['country_code'])) {
    $sessionCountry = strtoupper($_SESSION['country_code']);
    if (in_array($sessionCountry, $supportedCountries)) $determinedCountry = $sessionCountry;
}

// 2. Env Priority
if (!$determinedCountry && ($env = getenv('VM_COUNTRY'))) {
    $env = strtoupper($env);
    if (in_array($env, $supportedCountries)) $determinedCountry = $env;
}

// 3. Force Constant
if (!$determinedCountry && defined('FORCE_COUNTRY')) {
    $force = strtoupper(FORCE_COUNTRY);
    if (in_array($force, $supportedCountries)) $determinedCountry = $force;
}

// 4. Default
if (!$determinedCountry) $determinedCountry = 'BW';

// LOAD THE .ENV FILE BEFORE SETTING CONSTANT
$envPath = __DIR__ . "/countries/{$determinedCountry}/.env_{$determinedCountry}";
loadEnvFile($envPath);

/**
 * Prevent "Fatal error: Constant SYSTEM_COUNTRY already defined"
 */
if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $determinedCountry);
}

putenv("VM_COUNTRY={$determinedCountry}");

return $determinedCountry;
