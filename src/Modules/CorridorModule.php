<?php
declare(strict_types=1);

namespace Modules;

use Domain\Services\FeeService;
use Domain\Services\Settlement\HybridSettlementStrategy;

/**
 * CorridorModule - Dynamic cross-border corridor resolution
 * 
 * NO MANUAL CORRIDOR CONFIG NEEDED.
 * 
 * Derives everything from source and destination country configs:
 * - VouchMorph account in source country
 * - VouchMorph account in destination country
 * - FX spread (with optional overrides per country)
 * - Settlement time (max of both countries)
 * 
 * Only need optional overrides for special cases (e.g., better rates to neighboring countries)
 */
class CorridorModule
{
    private FeeService $feeService;
    private HybridSettlementStrategy $settlement;
    private array $countryConfigs = [];
    private string $configPath;
    
    public function __construct(
        FeeService $feeService,
        HybridSettlementStrategy $settlement,
        string $configPath = __DIR__ . '/../../config/countries/'
    ) {
        $this->feeService = $feeService;
        $this->settlement = $settlement;
        $this->configPath = rtrim($configPath, '/') . '/';
    }
    
    /**
     * Get corridor information between two countries
     * 
     * Derives everything dynamically - no manual corridor config needed
     */
    public function getCorridor(string $sourceCountry, string $destCountry): array
    {
        $sourceConfig = $this->loadCountryConfig($sourceCountry);
        $destConfig = $this->loadCountryConfig($destCountry);
        
        // Check if cross-border is enabled
        if (!($sourceConfig['cross_border']['enabled'] ?? true)) {
            throw new \RuntimeException("Cross-border not enabled for source country: {$sourceCountry}");
        }
        
        if (!($destConfig['cross_border']['enabled'] ?? true)) {
            throw new \RuntimeException("Cross-border not enabled for destination country: {$destCountry}");
        }
        
        // Get VouchMorph accounts
        $vmSourceAccount = $sourceConfig['vouchmorph']['settlement_account'] ?? "VM-{$sourceCountry}-001";
        $vmDestAccount = $destConfig['vouchmorph']['settlement_account'] ?? "VM-{$destCountry}-001";
        
        // Determine FX spread (check overrides first)
        $fxSpread = $this->feeService->getFxSpread($sourceCountry, $destCountry, $sourceConfig);
        
        // Settlement time - use max of both countries' defaults
        $settlementHours = max(
            $sourceConfig['cross_border']['settlement_hours'] ?? 24,
            $destConfig['cross_border']['settlement_hours'] ?? 24
        );
        
        // Check if corridor requires pre-funding
        $requiresPrefunding = $sourceConfig['cross_border']['requires_prefunding'] ?? false;
        
        return [
            'source_country' => $sourceCountry,
            'destination_country' => $destCountry,
            'source_vm_account' => $vmSourceAccount,
            'destination_vm_account' => $vmDestAccount,
            'fx_spread_percent' => $fxSpread,
            'settlement_time_hours' => $settlementHours,
            'requires_prefunding' => $requiresPrefunding,
            'enabled' => true,
            'resolved_at' => date('c')
        ];
    }
    
    /**
     * Route a settlement through the corridor
     * 
     * Flow: Source Institution → VouchMorph (source) → VouchMorph (dest) → Destination Institution
     */
    public function routeViaCorridor(
        string $swapRef,
        string $sourceInstitution,
        string $destInstitution,
        string $sourceCountry,
        string $destCountry,
        float $amount,
        string $currency
    ): array {
        $corridor = $this->getCorridor($sourceCountry, $destCountry);
        
        $this->logEvent('CORRIDOR_ROUTING', [
            'swap_ref' => $swapRef,
            'source_country' => $sourceCountry,
            'dest_country' => $destCountry,
            'amount' => $amount,
            'corridor' => $corridor
        ]);
        
        // Step 1: Source Institution → VouchMorph Source Account
        $this->settlement->updateNetPosition(
            $sourceInstitution,
            $corridor['source_vm_account'],
            $amount,
            'corridor_inbound',
            $currency
        );
        
        // Step 2: Internal transfer (just tracking - no actual money movement)
        $this->recordInternalTransfer(
            $swapRef,
            $corridor['source_vm_account'],
            $corridor['destination_vm_account'],
            $amount,
            $currency
        );
        
        // Step 3: VouchMorph Destination Account → Destination Institution
        $this->settlement->updateNetPosition(
            $corridor['destination_vm_account'],
            $destInstitution,
            $amount,
            'corridor_outbound',
            $currency
        );
        
        // Step 4: Apply corridor fee
        $corridorFee = $this->feeService->getCorridorFee($amount, $sourceCountry, $destCountry, $this->loadCountryConfig($sourceCountry));
        
        if ($corridorFee > 0) {
            $this->settlement->invoiceFee(
                $swapRef,
                $sourceInstitution,
                0, // participant_id would need lookup
                'CORRIDOR_FEE',
                $corridorFee,
                $currency
            );
        }
        
        // Update swap_requests with corridor info
        $this->updateSwapWithCorridorInfo($swapRef, $corridor, $corridorFee);
        
        return [
            'method' => 'corridor',
            'source_vm_account' => $corridor['source_vm_account'],
            'destination_vm_account' => $corridor['destination_vm_account'],
            'fx_spread_percent' => $corridor['fx_spread_percent'],
            'corridor_fee' => $corridorFee,
            'settlement_time_hours' => $corridor['settlement_time_hours']
        ];
    }
    
    /**
     * Check if two countries need cross-border routing
     */
    public function needsCrossBorder(string $sourceCountry, string $destCountry): bool
    {
        if ($sourceCountry === $destCountry) {
            return false;
        }
        
        try {
            $corridor = $this->getCorridor($sourceCountry, $destCountry);
            return $corridor['enabled'];
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get all available corridors from a source country
     */
    public function getAvailableCorridors(string $sourceCountry): array
    {
        // In production, this would query enabled destination countries
        // For now, return common African countries
        $commonDestinations = ['ZA', 'NG', 'KE', 'TZ', 'UG', 'GH', 'ZW', 'ZM', 'MZ', 'NA'];
        $corridors = [];
        
        foreach ($commonDestinations as $dest) {
            if ($dest !== $sourceCountry) {
                try {
                    $corridors[] = $this->getCorridor($sourceCountry, $dest);
                } catch (\Exception $e) {
                    // Corridor not available, skip
                }
            }
        }
        
        return $corridors;
    }
    
    /**
     * Load country configuration from file
     */
    private function loadCountryConfig(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);
        
        if (isset($this->countryConfigs[$countryCode])) {
            return $this->countryConfigs[$countryCode];
        }
        
        $configFile = $this->configPath . strtolower($countryCode) . '/config.json';
        
        if (!file_exists($configFile)) {
            // Return sensible defaults for any country
            $this->countryConfigs[$countryCode] = $this->getDefaultCountryConfig($countryCode);
        } else {
            $this->countryConfigs[$countryCode] = json_decode(file_get_contents($configFile), true);
        }
        
        return $this->countryConfigs[$countryCode];
    }
    
    /**
     * Default config for any country (ensures system works without config)
     */
    private function getDefaultCountryConfig(string $countryCode): array
    {
        return [
            'country_code' => $countryCode,
            'currency' => $this->guessCurrency($countryCode),
            'vouchmorph' => [
                'settlement_account' => "VM-{$countryCode}-001",
                'cross_border_enabled' => true
            ],
            'fx' => [
                'default_spread' => 0.015,
                'spread_overrides' => []
            ],
            'cross_border' => [
                'enabled' => true,
                'settlement_hours' => 48,
                'requires_prefunding' => false
            ]
        ];
    }
    
    /**
     * Guess currency from country code (fallback)
     */
    private function guessCurrency(string $countryCode): string
    {
        $map = [
            'BW' => 'BWP', 'ZA' => 'ZAR', 'NG' => 'NGN', 'KE' => 'KES',
            'GH' => 'GHS', 'UG' => 'UGX', 'TZ' => 'TZS', 'ZM' => 'ZMW',
            'ZW' => 'ZWL', 'US' => 'USD', 'GB' => 'GBP', 'EU' => 'EUR',
            'CA' => 'CAD', 'AU' => 'AUD', 'CN' => 'CNY', 'JP' => 'JPY',
            'IN' => 'INR', 'BR' => 'BRL', 'MX' => 'MXN'
        ];
        return $map[$countryCode] ?? 'USD';
    }
    
    /**
     * Record internal transfer between VouchMorph accounts
     */
    private function recordInternalTransfer(string $swapRef, string $fromAccount, string $toAccount, float $amount, string $currency): void
    {
        // This is just for audit - no actual money movement
        $this->logEvent('INTERNAL_TRANSFER', [
            'swap_ref' => $swapRef,
            'from' => $fromAccount,
            'to' => $toAccount,
            'amount' => $amount,
            'currency' => $currency
        ]);
    }
    
    /**
     * Update swap_requests with corridor information
     */
    private function updateSwapWithCorridorInfo(string $swapRef, array $corridor, float $corridorFee): void
    {
        // This would be implemented if we have DB access
        // For now, just log
        $this->logEvent('CORRIDOR_APPLIED', [
            'swap_ref' => $swapRef,
            'source_country' => $corridor['source_country'],
            'dest_country' => $corridor['destination_country'],
            'fee' => $corridorFee
        ]);
    }
    
    private function logEvent(string $event, array $data): void
    {
        $log = [
            'timestamp' => date('c'),
            'event' => $event,
            'data' => $data
        ];
        file_put_contents('/tmp/corridor_module.log', json_encode($log) . PHP_EOL, FILE_APPEND);
    }
}
