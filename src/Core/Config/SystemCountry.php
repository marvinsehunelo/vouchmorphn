<?php
declare(strict_types=1);

$supportedCountries = [
    'BW' => ['name' => 'Botswana', 'slug' => 'botswana', 'code' => 'BW'],
    'NG' => ['name' => 'Nigeria',  'slug' => 'nigeria',  'code' => 'NG'],
    'KE' => ['name' => 'Kenya',    'slug' => 'kenya',    'code' => 'KE'],
    'ZA' => ['name' => 'SouthAfrica', 'slug' => 'southafrica', 'code' => 'ZA'],
    'UG' => ['name' => 'Uganda',   'slug' => 'uganda',   'code' => 'UG'],
    'TZ' => ['name' => 'Tanzania', 'slug' => 'tanzania', 'code' => 'TZ'],
    'GH' => ['name' => 'Ghana',    'slug' => 'ghana',    'code' => 'GH'],
    'ZM' => ['name' => 'Zambia',   'slug' => 'zambia',   'code' => 'ZM'],
    'ZW' => ['name' => 'Zimbabwe', 'slug' => 'zimbabwe', 'code' => 'ZW'],
    'NA' => ['name' => 'Namibia',  'slug' => 'namibia',  'code' => 'NA'],
    'CM' => ['name' => 'Cameroon', 'slug' => 'cameroon', 'code' => 'CM'],
    'SN' => ['name' => 'Senegal',  'slug' => 'senegal',  'code' => 'SN'],
    'CI' => ['name' => 'CotedIvoire', 'slug' => 'cotedivoire', 'code' => 'CI'],
    'ML' => ['name' => 'Mali',     'slug' => 'mali',     'code' => 'ML'],
    'ET' => ['name' => 'Ethiopia', 'slug' => 'ethiopia', 'code' => 'ET'],
    'DZ' => ['name' => 'Algeria',  'slug' => 'algeria',  'code' => 'DZ'],
    'MA' => ['name' => 'Morocco',  'slug' => 'morocco',  'code' => 'MA'],
    'EG' => ['name' => 'Egypt',    'slug' => 'egypt',    'code' => 'EG'],
    'SD' => ['name' => 'Sudan',    'slug' => 'sudan',    'code' => 'SD'],
    'LY' => ['name' => 'Libya',    'slug' => 'libya',    'code' => 'LY'],
    'TN' => ['name' => 'Tunisia',  'slug' => 'tunisia',  'code' => 'TN'],
    'RW' => ['name' => 'Rwanda',   'slug' => 'rwanda',   'code' => 'RW'],
    'BI' => ['name' => 'Burundi',  'slug' => 'burundi',  'code' => 'BI'],
    'MW' => ['name' => 'Malawi',   'slug' => 'malawi',   'code' => 'MW'],
    'LS' => ['name' => 'Lesotho',  'slug' => 'lesotho',  'code' => 'LS'],
    'MR' => ['name' => 'Mauritania', 'slug' => 'mauritania', 'code' => 'MR'],
    'CF' => ['name' => 'CentralAfricanRepublic', 'slug' => 'centralafricanrepublic', 'code' => 'CF'],
    'CG' => ['name' => 'Congo',    'slug' => 'congo',    'code' => 'CG'],
    'CD' => ['name' => 'DRCongo',  'slug' => 'drcongo',  'code' => 'CD'],
    'NE' => ['name' => 'Niger',    'slug' => 'niger',    'code' => 'NE'],
    'BF' => ['name' => 'BurkinaFaso', 'slug' => 'burkinafaso', 'code' => 'BF'],
    'GM' => ['name' => 'Gambia',   'slug' => 'gambia',   'code' => 'GM'],
    'SL' => ['name' => 'SierraLeone', 'slug' => 'sierraleone', 'code' => 'SL'],
    'LR' => ['name' => 'Liberia',  'slug' => 'liberia',  'code' => 'LR'],
    'GN' => ['name' => 'Guinea',   'slug' => 'guinea',   'code' => 'GN'],
    'TG' => ['name' => 'Togo',     'slug' => 'togo',     'code' => 'TG'],
    'BJ' => ['name' => 'Benin',    'slug' => 'benin',    'code' => 'BJ'],
    'SO' => ['name' => 'Somalia',  'slug' => 'somalia',  'code' => 'SO'],
    'DJ' => ['name' => 'Djibouti', 'slug' => 'djibouti', 'code' => 'DJ'],
    'ER' => ['name' => 'Eritrea',  'slug' => 'eritrea',  'code' => 'ER'],
    'ST' => ['name' => 'SaoTomeAndPrincipe', 'slug' => 'saotomeandprincipe', 'code' => 'ST'],
    'GQ' => ['name' => 'EquatorialGuinea', 'slug' => 'equatorialguinea', 'code' => 'GQ'],
    'CV' => ['name' => 'CapeVerde', 'slug' => 'capeverde', 'code' => 'CV'],
];

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim(trim($value), "\"'");

                putenv("$key=$value");
                $_SERVER[$key] = $value;
                $_ENV[$key] = $value;
            }
        }

        return true;
    }
}

$determinedCountry = null;

// 1. Session
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['country_code'])) {
    $sessionCountry = strtoupper(trim((string) $_SESSION['country_code']));
    if (isset($supportedCountries[$sessionCountry])) {
        $determinedCountry = $sessionCountry;
    }
}

// 2. Env
if (!$determinedCountry && ($env = getenv('VM_COUNTRY'))) {
    $env = strtoupper(trim((string) $env));
    if (isset($supportedCountries[$env])) {
        $determinedCountry = $env;
    }
}

// 3. Force constant
if (!$determinedCountry && defined('FORCE_COUNTRY')) {
    $force = strtoupper(trim((string) FORCE_COUNTRY));
    if (isset($supportedCountries[$force])) {
        $determinedCountry = $force;
    }
}

// 4. Default
if (!$determinedCountry) {
    $determinedCountry = 'BW';
}

$resolved = $supportedCountries[$determinedCountry];

// Load env from current file location style
$envCandidates = [
    dirname(__DIR__, 3) . '/config/countries/' . $resolved['slug'] . '/.env',
    __DIR__ . '/Countries/' . $resolved['name'] . '/.env_' . $resolved['name'],
    __DIR__ . '/Countries/' . $resolved['name'] . '/.env_' . $resolved['code'],
];

foreach ($envCandidates as $envPath) {
    if (loadEnvFile($envPath)) {
        break;
    }
}

if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $resolved['name']);
}

if (!defined('SYSTEM_COUNTRY_SLUG')) {
    define('SYSTEM_COUNTRY_SLUG', $resolved['slug']);
}

if (!defined('SYSTEM_COUNTRY_CODE')) {
    define('SYSTEM_COUNTRY_CODE', $resolved['code']);
}

putenv('VM_COUNTRY=' . $resolved['code']);

return $resolved;
