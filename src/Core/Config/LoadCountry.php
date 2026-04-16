<?php
declare(strict_types=1);

// CORE_CONFIG/load_country.php

if (!defined('SYSTEM_COUNTRY')) {
    require_once __DIR__ . '/system_country.php';
}

$country = SYSTEM_COUNTRY;

/* -------------------------------------------------------
   Paths - FIXED to match actual file locations
------------------------------------------------------- */
$countryLower = strtolower($country);
$configFile       = __DIR__ . "/../../../config/countries/{$countryLower}/config.php";
$participantsFile = __DIR__ . "/../../../config/countries/{$countryLower}/participants.json";
$feesFile         = __DIR__ . "/../../../config/countries/{$countryLower}/fees.json";

/* -------------------------------------------------------
   Load Base Config
------------------------------------------------------- */
$countryConfig = [];
if (file_exists($configFile)) {
    $countryConfig = require $configFile;
}

/* -------------------------------------------------------
   Load Participants
------------------------------------------------------- */
if (!file_exists($participantsFile)) {
    die("Participants file error: Missing {$participantsFile}");
}

$participantsConfig = json_decode(file_get_contents($participantsFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("JSON parse error in participants file: " . json_last_error_msg());
}

$countryConfig['participants'] = $participantsConfig['participants'] ?? [];
$countryConfig['api_keys']     = $participantsConfig['api_keys'] ?? [];

/* -------------------------------------------------------
   Load Fees
------------------------------------------------------- */
if (!file_exists($feesFile)) {
    die("Fees file missing: {$feesFile}");
}

$feesConfig = json_decode(file_get_contents($feesFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("JSON parse error in fees file: " . json_last_error_msg());
}

/* -------------------------------------------------------
   Resolve Fees Safely (NO FLOAT CORRUPTION & TYPE SAFE)
------------------------------------------------------- */
if (!function_exists('resolveFees')) {
    function decimal($value): string
    {
        if (!is_numeric($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        return number_format((float)$value, 6, '.', '');
    }

    function resolveFees(array $feeConfig): array
    {
        $resolved = [];
        if (isset($feeConfig['fees'])) {
            foreach ($feeConfig['fees'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        if (is_numeric($subValue) && !is_string($subValue)) {
                            $value[$subKey] = decimal($subValue);
                        }
                    }
                    $resolved[$key] = $value;
                } else {
                    $resolved[$key] = is_numeric($value) ? decimal($value) : $value;
                }
            }
        }
        foreach (['metadata','regulatory','limits','currency','aliases','rules'] as $section) {
            if (isset($feeConfig[$section])) {
                $resolved[$section] = $feeConfig[$section];
            }
        }
        return $resolved;
    }
}

$countryConfig['fees'] = resolveFees($feesConfig);

/* -------------------------------------------------------
   Database Configuration (RAILWAY OPTIMIZED)
------------------------------------------------------- */
$dbName = getenv('PG_NAME') ?: getenv('PG_DB_CORE') ?: ("swap_system_" . strtolower($country));

$countryConfig['db']['swap'] = [
    'name'     => $dbName,
    'database' => $dbName,
    'host'     => getenv('PG_HOST') ?: '127.0.0.1',
    'port'     => (int)(getenv('PG_PORT') ?: 5432),
    'user'     => getenv('PG_USER') ?: 'postgres',
    'password' => getenv('PG_PASS') ?: '',
];

/* -------------------------------------------------------
   Financial Settings Safety
------------------------------------------------------- */
if (isset($countryConfig['settings']['swap_fee'])) {
    $countryConfig['settings']['swap_fee'] = decimal($countryConfig['settings']['swap_fee']);
}

if (isset($countryConfig['fees']['regulatory']['vat_rate'])) {
    $countryConfig['fees']['regulatory']['vat_rate'] = decimal($countryConfig['fees']['regulatory']['vat_rate']);
}

/* -------------------------------------------------------
   Global Access
------------------------------------------------------- */
$GLOBALS['country_config'] = $countryConfig;

if (!defined('COUNTRY_CONFIG')) {
    define('COUNTRY_CONFIG', json_encode($countryConfig, JSON_UNESCAPED_SLASHES));
}

return $countryConfig;
