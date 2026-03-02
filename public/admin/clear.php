<?php
// test_all_countries.php

echo "<pre>";
echo "<h1>Comprehensive Country Configuration Test</h1>";

$supportedCountries = [
    'BW', 'NG', 'KE', 'ZA', 'UG', 'TZ', 'GH', 'ZM', 'ZW', 'NA', 'CM', 'SN',
    'CI', 'ML', 'ET', 'DZ', 'MA', 'EG', 'SD', 'LY', 'TN', 'RW', 'BI', 'MW',
    'LS', 'MR', 'CF', 'CG', 'CD', 'NE', 'BF', 'GM', 'SL', 'LR', 'GN', 'TG',
    'BJ', 'SO', 'DJ', 'ER', 'ST', 'GQ', 'CV'
];

// Base directory for your config
$baseDir = '/opt/lampp/htdocs/vouchmorphn/src/CORE_CONFIG/';
$loaderPath = $baseDir . 'load_country.php';

foreach ($supportedCountries as $code) {
    echo str_repeat("=", 50) . "\n";
    echo "TESTING: **$code**\n";
    echo str_repeat("=", 50) . "\n";

    // UPDATED PATH: Looking into countries/CODE/ folder
    $countryFolder = $baseDir . "countries/" . $code . "/";
    $envFile = $countryFolder . ".env_" . $code;

    if (file_exists($envFile)) {
        echo "✅ Found env file: $envFile\n";
    } else {
        echo "❌ MISSING env file at: $envFile\n";
    }

    // Simulate environment for the loader
    putenv("VM_COUNTRY=$code");
    
    if (file_exists($loaderPath)) {
        $config = include $loaderPath;

        if ($config) {
            echo "✅ Config loaded successfully.\n";
            $dbName = $config['db']['swap']['database'] ?? 'NOT SET';
            echo "   - Database: $dbName\n";
        } else {
            echo "❌ Config loaded but returned no data.\n";
        }
    } else {
        echo "❌ Loader NOT FOUND at: $loaderPath\n";
    }
    echo "\n";
}

echo "</pre>";
