<?php
namespace BUSINESS_LOGIC_LAYER\services;

use Exception;

class AccessDeniedException extends Exception {}

class LicenceEnforcementService {

    /**
     * Main check for the country status
     */
   public static function assertCountryLicenceActive($countryCode) {
    $path = __DIR__ . '/../../CORE_CONFIG/licences/global_licence_registry.json';
    
    if (!file_exists($path)) {
        throw new \Exception("Licence registry file missing.");
    }

    $registry = json_decode(file_get_contents($path), true);
    $country = strtoupper(trim($countryCode));

    if (!isset($registry[$country])) {
        throw new \Exception("No license found for country: $country");
    }

    $licenceData = $registry[$country];

    // Check if the primary status is ACTIVE
    if (($licenceData['status'] ?? '') !== 'ACTIVE') {
        throw new \Exception("Country license inactive");
    }

    // Check Fees - if status is not PAID and grace period is passed, suspend
    if (isset($licenceData['fees']) && $licenceData['fees']['status'] !== 'PAID') {
        if (time() > strtotime($licenceData['fees']['grace_until'])) {
            throw new \Exception("Country license inactive (Fees Overdue)");
        }
    }

    return true;
}

    /**
     * Check if the specific bank is active within that country
     */
    public static function assertBankActive($countryCode, $bankCode) {
        // If bank_code is null (Global or Country Middleman), we skip bank check
        if (empty($bankCode)) {
            return true;
        }
        
        // Logic to check your banks table in the DB would go here
        return true; 
    }

    /**
     * Verify the role is in the allowed list
     */
    public static function assertRole($role) {
        $allowed = ['GLOBAL_OWNER', 'COUNTRY_MIDDLEMAN', 'BANK_ADMIN', 'AUDITOR', 'SUPPORT'];
        if (!in_array($role, $allowed)) {
            throw new AccessDeniedException("Unauthorized Role: " . $role);
        }
    }
}
