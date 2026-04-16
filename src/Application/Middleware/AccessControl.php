<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace APP_LAYER\utils;

final class AccessControl
{
    /**
     * Can the system operate for this country?
     * Validates master license, payment status, and expiry.
     */
    public static function canOperateSystem(string $country): bool
    {
        $registryFile = __DIR__ . '/../../CORE_CONFIG/licences/global_licence_registry.json';

        if (!file_exists($registryFile)) {
            return false;
        }

        $licenceRegistry = json_decode(file_get_contents($registryFile), true);
        $country = strtoupper($country);

        if (!isset($licenceRegistry[$country])) {
            return false;
        }

        $licence = $licenceRegistry[$country];

        // 1. Master Status check
        $statusOk = ($licence['status'] ?? '') === 'ACTIVE';

        // 2. Fee & Grace Period check
        $feesOk = false;
        $feeStatus = $licence['fees']['status'] ?? '';
        $graceUntil = $licence['fees']['grace_until'] ?? null;

        if ($feeStatus === 'PAID') {
            $feesOk = true;
        } elseif ($graceUntil && strtotime($graceUntil) > time()) {
            $feesOk = true;
        }

        // 3. Expiry check
        $expiryOk = isset($licence['expiry']) && strtotime($licence['expiry']) > time();

        return $statusOk && $feesOk && $expiryOk;
    }

    /**
     * Can a bank participate based on Licensor/Middleman rules?
     * This checks if the Middleman has explicitly blacklisted a bank code.
     */
    public static function canAccessBank(string $bankCode, string $country, string $middleman = 'OWNER'): bool
    {
        $registryFile = __DIR__ . '/../../CORE_CONFIG/licences/global_licence_registry.json';

        if (!file_exists($registryFile)) {
            return false;
        }

        $licenceRegistry = json_decode(file_get_contents($registryFile), true);
        $country = strtoupper($country);
        $middleman = strtoupper($middleman);
        $bankCode = strtoupper($bankCode);

        // Check if country exists in registry
        if (!isset($licenceRegistry[$country])) {
            return false;
        }

        // Check Middleman existence and active status
        $middlemanData = $licenceRegistry['middlemen'][$country][$middleman] ?? null;
        if (!$middlemanData || ($middlemanData['status'] ?? '') !== 'ACTIVE') {
            return false;
        }

        // Check for Middleman Suspensions (The Kill-Switch)
        // If the bankCode is in the suspended_banks array, access is denied.
        $suspendedBanks = array_map('strtoupper', $middlemanData['suspended_banks'] ?? []);
        if (in_array($bankCode, $suspendedBanks, true)) {
            return false;
        }

        return true; 
    }
}
