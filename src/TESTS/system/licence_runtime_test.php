<?php
require_once __DIR__ . '/../../bootstrap.php';

use APP_LAYER\Utils\SessionManager;

try {

    // 🔐 Ensure session exists
    SessionManager::start();

    $user = SessionManager::getUser();

    if (!$user) {
        throw new Exception("No active session.");
    }

    // 🌍 Country comes from admin identity, NOT hardcoded
    $country = $user['country'] ?? null;

    if (!$country) {
        throw new Exception("Admin country not set in session.");
    }

    // 🔑 Licence enforcement (country-aware)
    LicenceEnforcementService::assertCountryLicenceActive($country);

    // 🏦 Bank enforcement (example: bank must exist in that country)
    // You can make this dynamic later
    LicenceEnforcementService::assertBankActive($country, 'GTB');

    // 👮 Role enforcement
    LicenceEnforcementService::assertRole('ADMIN');

    echo "LICENCE CHECK PASSED FOR COUNTRY: {$country}\n";

} catch (Exception $e) {
    echo "LICENCE BLOCKED: " . $e->getMessage() . "\n";
}

