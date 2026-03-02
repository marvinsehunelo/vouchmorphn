<?php
declare(strict_types=1);

namespace CORE_CONFIG;

require_once __DIR__ . '/system_country.php';
require_once __DIR__ . '/load_country.php'; // sets SYSTEM_COUNTRY and $countryConfig

class CountryBankRegistry
{
    protected static array $cache = [];
    protected static ?array $participants = null;

    /**
     * Load participants from JSON file if not already loaded
     */
    protected static function loadParticipants(): void
    {
        if (self::$participants !== null) return;

        $country = SYSTEM_COUNTRY;
        $filePath = __DIR__ . "/countries/{$country}/participants_{$country}.json";

        if (!file_exists($filePath)) {
            throw new \Exception("Participants file missing for country {$country}");
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON parse error in participants file: " . json_last_error_msg());
        }

        // Ensure 'participants' key exists
        self::$participants = $data['participants'] ?? [];
    }

    /**
     * Get a specific bank/participant
     */
    public static function get(string $bankCode): array
    {
        $bankCode = strtoupper($bankCode);

        if (isset(self::$cache[$bankCode])) {
            return self::$cache[$bankCode];
        }

        self::loadParticipants();

        if (!isset(self::$participants[$bankCode])) {
            throw new \Exception("Bank {$bankCode} not registered in country " . SYSTEM_COUNTRY);
        }

        self::$cache[$bankCode] = self::$participants[$bankCode];
        return self::$cache[$bankCode];
    }

    /**
     * Get all participants
     */
    public static function all(): array
    {
        self::loadParticipants();
        return self::$participants;
    }
}
