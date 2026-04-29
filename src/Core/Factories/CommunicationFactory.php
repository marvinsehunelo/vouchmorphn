<?php

namespace Core\Factories;

use Infrastructure\SMS\SmsGatewayClient;
use Infrastructure\SMS\Contracts\ProviderInterface;
use Exception;

class CommunicationFactory
{
    /**
     * Create communication provider (SMS, USSD, etc)
     * Uses communication.json from config/countries/{country}/communication.json
     * 
     * @param string $provider The communication provider type (sms, ussd, etc)
     * @return ProviderInterface
     * @throws Exception
     */
    public static function create(string $provider): ProviderInterface
    {
        // Load active country from Core Config
        $systemCountryPath = __DIR__ . '/../Config/SystemCountry.php';
        
        if (!file_exists($systemCountryPath)) {
            throw new Exception("System country configuration not found at: src/Core/Config/SystemCountry.php");
        }
        
        $country = require $systemCountryPath;
        
        // Clean up country value if it's an array
        if (is_array($country)) {
            $country = $country['country'] ?? $country[0] ?? null;
        }
        
        if (!$country) {
            throw new Exception("Active country not configured in SystemCountry.php");
        }

        // Load country communication config from JSON file
        $countryLower = strtolower($country);
        $configFile = dirname(__DIR__, 4) . "/config/countries/{$countryLower}/communication.json";
        
        if (!file_exists($configFile)) {
            throw new Exception("Communication config not found: config/countries/{$countryLower}/communication.json");
        }

        $jsonContent = file_get_contents($configFile);
        $config = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in communication.json: " . json_last_error_msg());
        }

        $providerKey = strtolower($provider);

        if (!isset($config[$providerKey])) {
            throw new Exception("Communication provider '{$provider}' not configured for {$country}");
        }

        $providerConfig = $config[$providerKey];

        // Create the appropriate provider based on type
        return self::instantiateProvider($providerKey, $providerConfig);
    }
    
    /**
     * Instantiate the appropriate communication provider
     * 
     * @param string $type
     * @param array $config
     * @return ProviderInterface
     * @throws Exception
     */
    private static function instantiateProvider(string $type, array $config): ProviderInterface
    {
        switch ($type) {
            case 'sms':
                // Use SmsGatewayClient from Infrastructure
                return new SmsGatewayClient($config);
                
            case 'ussd':
                // USSD provider - you may need to create this class
                // For now, throw an exception with guidance
                throw new Exception("USSD provider not yet implemented. Create INFRASTRUCTURE\USSD\UssdGatewayClient");
                
            default:
                throw new Exception("Unsupported provider type: {$type}");
        }
    }
    
    /**
     * Get available communication providers for current country
     * 
     * @return array List of available providers with their configs
     * @throws Exception
     */
    public static function getAvailableProviders(): array
    {
        // Load active country from Core Config
        $systemCountryPath = __DIR__ . '/../Config/SystemCountry.php';
        
        if (!file_exists($systemCountryPath)) {
            return [];
        }
        
        $country = require $systemCountryPath;
        
        if (is_array($country)) {
            $country = $country['country'] ?? $country[0] ?? null;
        }
        
        if (!$country) {
            return [];
        }

        $countryLower = strtolower($country);
        $configFile = dirname(__DIR__, 4) . "/config/countries/{$countryLower}/communication.json";
        
        if (!file_exists($configFile)) {
            return [];
        }

        $jsonContent = file_get_contents($configFile);
        $config = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }
        
        return $config;
    }
    
    /**
     * Get specific provider configuration without instantiating
     * 
     * @param string $provider
     * @return array|null
     */
    public static function getProviderConfig(string $provider): ?array
    {
        try {
            $providers = self::getAvailableProviders();
            $providerKey = strtolower($provider);
            return $providers[$providerKey] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
