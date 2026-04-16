<?php
declare(strict_types=1);

namespace Core\Config;

/**
 * LoadCountry - Multi-country configuration loader
 * Loads country-specific configs, participants, fees, and database settings
 * 
 * @package Core\Config
 */
class LoadCountry
{
    private static ?array $config = null;
    private static ?string $country = null;

    /**
     * Get the current system country
     */
    public static function getCountry(): string
    {
        if (self::$country === null) {
            require_once __DIR__ . '/SystemCountry.php';
            self::$country = SYSTEM_COUNTRY;
        }
        return self::$country;
    }

    /**
     * Get the complete country configuration
     */
    public static function getConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $country = self::getCountry();
        
        // Paths for country-specific files
        $basePath = __DIR__ . "/Countries/{$country}";
        $configFile = $basePath . "/config.php";
        $participantsFile = $basePath . "/participants.json";
        $feesFile = $basePath . "/fees.json";

        // Load base config
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Configuration file error: Missing {$configFile}");
        }
        
        $countryConfig = require $configFile;
        
        // Ensure required arrays exist
        if (!isset($countryConfig['db'])) {
            $countryConfig['db'] = [];
        }
        
        if (!isset($countryConfig['settings'])) {
            $countryConfig['settings'] = [];
        }

        // Load participants
        if (!file_exists($participantsFile)) {
            throw new \RuntimeException("Participants file error: Missing {$participantsFile}");
        }

        $participantsContent = file_get_contents($participantsFile);
        if ($participantsContent === false) {
            throw new \RuntimeException("Failed to read participants file: {$participantsFile}");
        }

        $participantsConfig = json_decode($participantsContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("JSON parse error in participants file: " . json_last_error_msg());
        }

        $countryConfig['participants'] = $participantsConfig['participants'] ?? [];
        $countryConfig['api_keys'] = $participantsConfig['api_keys'] ?? [];

        // Load fees
        if (!file_exists($feesFile)) {
            throw new \RuntimeException("Fees file missing: {$feesFile}");
        }

        $feesContent = file_get_contents($feesFile);
        if ($feesContent === false) {
            throw new \RuntimeException("Failed to read fees file: {$feesFile}");
        }

        $feesConfig = json_decode($feesContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("JSON parse error in fees file: " . json_last_error_msg());
        }

        $countryConfig['fees'] = self::resolveFees($feesConfig);

        // Database Configuration (Railway optimized)
        $dbName = getenv('PG_NAME') ?: getenv('PG_DB_CORE') ?: ("swap_system_" . strtolower($country));

        $countryConfig['db']['swap'] = [
            'name' => $dbName,
            'database' => $dbName,
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('PG_PORT') ?: 5432),
            'user' => getenv('PG_USER') ?: 'postgres',
            'password' => getenv('PG_PASS') ?: '',
        ];

        // Financial Settings Safety
        if (isset($countryConfig['settings']['swap_fee'])) {
            $countryConfig['settings']['swap_fee'] = self::decimal($countryConfig['settings']['swap_fee']);
        }

        // Adjust VAT from regulatory section if exists
        if (isset($countryConfig['fees']['regulatory']['vat_rate'])) {
            $countryConfig['fees']['regulatory']['vat_rate'] = self::decimal($countryConfig['fees']['regulatory']['vat_rate']);
        }

        // Country settings for phone formatting
        if (!isset($countryConfig['country_settings'])) {
            $countryConfig['country_settings'] = [];
        }
        
        $countryConfig['country_settings'][$country] = [
            'name' => $country,
            'dial_code' => $countryConfig['dial_code'] ?? ($country === 'BW' ? '+267' : ($country === 'KE' ? '+254' : '+234')),
            'local_phone_length' => $countryConfig['local_phone_length'] ?? 8,
            'phone_placeholder' => $countryConfig['phone_placeholder'] ?? str_repeat('0', $countryConfig['local_phone_length'] ?? 8),
        ];

        self::$config = $countryConfig;
        
        return self::$config;
    }

    /**
     * Format decimal values safely
     */
    private static function decimal($value): string
    {
        if (!is_numeric($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        return number_format((float)$value, 6, '.', '');
    }

    /**
     * Resolve fees configuration with proper decimal formatting
     */
    private static function resolveFees(array $feeConfig): array
    {
        $resolved = [];
        
        if (isset($feeConfig['fees'])) {
            foreach ($feeConfig['fees'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        if (is_numeric($subValue) && !is_string($subValue)) {
                            $value[$subKey] = self::decimal($subValue);
                        }
                    }
                    $resolved[$key] = $value;
                } else {
                    $resolved[$key] = is_numeric($value) ? self::decimal($value) : $value;
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

    /**
     * Get participants configuration
     */
    public static function getParticipants(): array
    {
        $config = self::getConfig();
        return $config['participants'] ?? [];
    }

    /**
     * Get fees configuration
     */
    public static function getFees(): array
    {
        $config = self::getConfig();
        return $config['fees'] ?? [];
    }

    /**
     * Get database configuration
     */
    public static function getDbConfig(string $connection = 'swap'): array
    {
        $config = self::getConfig();
        return $config['db'][$connection] ?? [];
    }

    /**
     * Get country-specific settings
     */
    public static function getCountrySettings(): array
    {
        $config = self::getConfig();
        $country = self::getCountry();
        return $config['country_settings'][$country] ?? [];
    }

    /**
     * Get dial code for current country
     */
    public static function getDialCode(): string
    {
        $settings = self::getCountrySettings();
        return $settings['dial_code'] ?? '+267';
    }

    /**
     * Get local phone length for current country
     */
    public static function getLocalPhoneLength(): int
    {
        $settings = self::getCountrySettings();
        return (int)($settings['local_phone_length'] ?? 8);
    }
}
