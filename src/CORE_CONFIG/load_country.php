<?php
declare(strict_types=1);

// CORE_CONFIG/load_country.php

if (!defined('SYSTEM_COUNTRY')) {
    require_once __DIR__ . '/system_country.php';
}

$country = SYSTEM_COUNTRY;

/* -------------------------------------------------------
   Paths
------------------------------------------------------- */
$configFile       = __DIR__ . "/countries/{$country}/config_{$country}.php";
$participantsFile = __DIR__ . "/countries/{$country}/participants_{$country}.json";
$feesFile         = __DIR__ . "/countries/{$country}/fees_{$country}.json";

/* -------------------------------------------------------
   Load Base Config
------------------------------------------------------- */
if (!file_exists($configFile)) {
    die("Configuration file error: Missing {$configFile}");
}
$countryConfig = require $configFile;

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
   Resolve Fees Safely (NO FLOAT CORRUPTION)
------------------------------------------------------- */
if (!function_exists('resolveFees')) {
    function decimal($value): string
    {
        if (!is_numeric($value)) return $value;
        return number_format((float)$value, 6, '.', '');
    }

    function resolveFees(array $feeConfig): array
    {
        $resolved = [];

        if (isset($feeConfig['fees'])) {
            foreach ($feeConfig['fees'] as $key => $value) {

                if (is_array($value)) {

                    if (isset($value['amount'])) {
                        $value['amount'] = decimal($value['amount']);
                        $resolved[$key] = $value;
                        continue;
                    }

                    foreach ($value as $k => $v) {
                        $value[$k] = decimal($v);
                    }

                    $resolved[$key] = $value;

                } else {
                    $resolved[$key] = decimal($value);
                }
            }
        }

        // preserve metadata
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
   Database Configuration (CRITICAL FIX)
------------------------------------------------------- */
$dbName =
    getenv('PG_DB_CORE') ?:
    getenv('DB_DATABASE') ?:
    ("swap_system_" . strtolower($country));

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
    $countryConfig['settings']['swap_fee'] = number_format(
        (float)$countryConfig['settings']['swap_fee'], 6, '.', ''
    );
}

if (isset($countryConfig['fees']['vat_rate'])) {
    $countryConfig['fees']['vat_rate'] = number_format(
        (float)$countryConfig['fees']['vat_rate'], 6, '.', ''
    );
}

if (isset($countryConfig['fees']['markup_limit'])) {
    $countryConfig['fees']['markup_limit'] = number_format(
        (float)$countryConfig['fees']['markup_limit'], 6, '.', ''
    );
}

/* -------------------------------------------------------
   Global Access
------------------------------------------------------- */
$GLOBALS['country_config'] = $countryConfig;

if (!defined('COUNTRY_CONFIG')) {
    define('COUNTRY_CONFIG', json_encode($countryConfig, JSON_UNESCAPED_SLASHES));
}

return $countryConfig;

