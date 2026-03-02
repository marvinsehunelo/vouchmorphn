<?php

namespace FACTORY_LAYER;

use INTEGRATION_LAYER\CLIENTS\CommunicationClients\CommunicationClient;
use INTEGRATION_LAYER\INTERFACES\CommunicationProviderInterface;
use Exception;

class CommunicationFactory
{
    /**
     * Create communication provider (SMS, USSD, etc)
     * Uses config_{country}.php ONLY
     */
    public static function create(string $provider): CommunicationProviderInterface
    {
        // Load active country
        $country = require __DIR__ . '/../CORE_CONFIG/system_country.php';

        // Load country PHP config (NOT JSON)
        $configFile = __DIR__ . "/../CORE_CONFIG/config_{$country}.php";
        if (!file_exists($configFile)) {
            throw new Exception("Config file not found: config_{$country}.php");
        }

        $config = require $configFile;

        $providerKey = strtolower($provider);

        if (!isset($config['db'][$providerKey])) {
            throw new Exception("Communication provider '{$provider}' not configured for {$country}");
        }

        // Delegate creation to CommunicationClient
        return CommunicationClient::create([
            'provider' => $providerKey,
            'db'       => $config['db'][$providerKey],
        ]);
    }
}

