<?php
declare(strict_types=1);

$countryMeta = require __DIR__ . '/SystemCountry.php';

$country = defined('SYSTEM_COUNTRY')
    ? SYSTEM_COUNTRY
    : ($countryMeta['name'] ?? 'Botswana');

$countrySlug = defined('SYSTEM_COUNTRY_SLUG')
    ? SYSTEM_COUNTRY_SLUG
    : ($countryMeta['slug'] ?? strtolower($country));

$configFile       = dirname(__DIR__, 3) . "/src/Core/Config/Countries/{$country}/config.php";
$participantsFile = dirname(__DIR__, 3) . "/config/countries/{$countrySlug}/participants.json";
$feesFile         = dirname(__DIR__, 3) . "/config/countries/{$countrySlug}/fees.json";

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

$participantsConfig = json_decode((string) file_get_contents($participantsFile), true);
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

$feesConfig = json_decode((string) file_get_contents($feesFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("JSON parse error in fees file: " . json_last_error_msg());
}

/* -------------------------------------------------------
   Resolve Fees Safely
------------------------------------------------------- */
if (!function_exists('resolveFees')) {
    function decimal($value): string
    {
        if (!is_numeric($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return number_format((float) $value, 6, '.', '');
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

        foreach (['metadata', 'regulatory', 'limits', 'currency', 'aliases', 'rules'] as $section) {
            if (isset($feeConfig[$section])) {
                $resolved[$section] = $feeConfig[$section];
            }
        }

        return $resolved;
    }
}

$countryConfig['fees'] = resolveFees($feesConfig);

/* -------------------------------------------------------
   Database Configuration
------------------------------------------------------- */
$dbName = getenv('PG_NAME') ?: getenv('PG_DB_CORE') ?: ('swap_system_' . strtolower($country));

$countryConfig['db']['swap'] = [
    'name'     => $dbName,
    'database' => $dbName,
    'host'     => getenv('PG_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('PG_PORT') ?: 5432),
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
