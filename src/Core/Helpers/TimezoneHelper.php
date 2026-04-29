<?php

namespace VouchMorph\Core\Helpers;

/**
 * Multinational Timezone Helper
 * No hardcoded defaults - uses system intelligence
 */
class TimezoneHelper
{
    /**
     * Get valid timezone from environment with intelligent fallback
     * 
     * @param array $envSources Priority order: $_ENV, getenv(), country config
     * @return string Valid timezone identifier
     */
    public static function getValidTimezone(array $envSources = []): string
    {
        // 1. Try to get from environment variables (highest priority)
        $timezone = self::extractFromEnvironment($envSources);
        
        // 2. Try to derive from country code if timezone is invalid/empty
        if (empty($timezone) || !self::isValidTimezone($timezone)) {
            $countryCode = self::getCountryCode();
            $derived = self::deriveFromCountry($countryCode);
            if ($derived && self::isValidTimezone($derived)) {
                $timezone = $derived;
            }
        }
        
        // 3. Try UTC as universal fallback (never hardcodes a country)
        if (empty($timezone) || !self::isValidTimezone($timezone)) {
            $timezone = 'UTC';
        }
        
        return $timezone;
    }
    
    /**
     * Extract timezone from various environment sources
     */
    private static function extractFromEnvironment(array $sources = []): string
    {
        $keys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
        
        foreach ($keys as $key) {
            // Check $_ENV
            if (isset($_ENV[$key]) && !empty($_ENV[$key])) {
                return trim($_ENV[$key]);
            }
            
            // Check getenv()
            $value = getenv($key);
            if ($value !== false && !empty($value)) {
                return trim($value);
            }
        }
        
        return '';
    }
    
    /**
     * Derive timezone from country code intelligently
     */
    private static function deriveFromCountry(string $countryCode): ?string
    {
        if (empty($countryCode)) {
            return null;
        }
        
        // Known country to timezone mappings (uses PHP's built-in data)
        $countryZones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY);
        
        // Try exact match (case-insensitive)
        $countryCodeUpper = strtoupper($countryCode);
        
        if (isset($countryZones[$countryCodeUpper])) {
            $zones = $countryZones[$countryCodeUpper];
            // Return first zone or most common one
            return $zones[0] ?? null;
        }
        
        // Try partial match (for variations like 'bw' vs 'BWA')
        foreach ($countryZones as $code => $zones) {
            if (strcasecmp($code, $countryCode) === 0 || 
                strcasecmp($code, $countryCodeUpper) === 0) {
                return $zones[0] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Get current country code from configuration
     */
    private static function getCountryCode(): string
    {
        // Try environment
        $country = $_ENV['COUNTRY_CODE'] ?? getenv('COUNTRY_CODE');
        if (!empty($country)) {
            return $country;
        }
        
        // Try from server location (IP-based - optional)
        if (function_exists('geoip_record_by_name')) {
            $record = geoip_record_by_name($_SERVER['SERVER_NAME'] ?? '');
            if ($record && isset($record['country_code'])) {
                return $record['country_code'];
            }
        }
        
        return '';
    }
    
    /**
     * Validate timezone string
     */
    private static function isValidTimezone(string $timezone): bool
    {
        if (empty($timezone)) {
            return false;
        }
        
        return in_array($timezone, \DateTimeZone::listIdentifiers());
    }
    
    /**
     * Get list of all valid timezones (for admin interfaces)
     */
    public static function getAllTimezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
    
    /**
     * Get timezones by country (for dropdowns)
     */
    public static function getTimezonesByCountry(string $countryCode): array
    {
        $countryZones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY);
        $code = strtoupper($countryCode);
        
        return $countryZones[$code] ?? [];
    }
}
