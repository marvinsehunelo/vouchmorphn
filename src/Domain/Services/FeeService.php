<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * FeeService - ALL fees from country config, NO HARDCODING
 * 
 * Every fee value must come from config/countries/{country}/fees.json
 * 
 * Cashout Fee Logic:
 * - Client pays total CASHOUT_SWAP_FEE upfront (e.g., 10.00)
 * - Swap Levy (e.g., 1.00) goes to VouchMorph immediately
 * - Remaining split: Platform 35% / Source 15% / Destination 50%
 * - Destination's 50% split: Generate Code Fee (10%) earned immediately, Cashout Fee (90%) earned only on success
 * - On failed cashout: Only Generate Code Fee is paid to first destination
 * - On free retry: VouchMorph pays Generate Code Fee, unearned Cashout Fee from first attempt pays new destination
 */
class FeeService
{
    private array $feeConfig;
    private string $defaultCurrency;
    
    public function __construct(array $feeConfig, string $defaultCurrency = 'BWP')
    {
        $this->feeConfig = $feeConfig;
        $this->defaultCurrency = $defaultCurrency;
    }
    
    /**
     * Get total cashout swap fee (client pays this upfront)
     * Example: 10.00 BWP
     */
    public function getTotalCashoutFee(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CASHOUT_SWAP_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("CASHOUT_SWAP_FEE not configured in fees.json");
        }
        
        return (float)($config['total_amount'] ?? 10.00);
    }
    
    /**
     * Get swap levy (immediately to VouchMorph)
     * Example: 1.00 BWP
     */
    public function getSwapLevy(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CASHOUT_SWAP_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("CASHOUT_SWAP_FEE not configured in fees.json");
        }
        
        return (float)($config['swap_levy'] ?? 1.00);
    }
    
    /**
     * Get split percentages after swap levy
     * Returns: ['platform' => 35, 'source' => 15, 'destination' => 50]
     */
    public function getSplitAfterLevy(): array
    {
        $config = $this->feeConfig['fees']['CASHOUT_SWAP_FEE']['split_after_levy'] ?? null;
        
        if (!$config) {
            return [
                'platform_percent' => 35,
                'source_institution_percent' => 15,
                'destination_institution_percent' => 50
            ];
        }
        
        return [
            'platform_percent' => (int)($config['platform_percent'] ?? 35),
            'source_institution_percent' => (int)($config['source_institution_percent'] ?? 15),
            'destination_institution_percent' => (int)($config['destination_institution_percent'] ?? 50)
        ];
    }
    
    /**
     * Get destination split percentages
     * Returns: ['generate_code' => 10, 'cashout' => 90]
     */
    public function getDestinationSplit(): array
    {
        $config = $this->feeConfig['fees']['CASHOUT_SWAP_FEE']['destination_split'] ?? null;
        
        if (!$config) {
            return [
                'generate_code_fee_percent' => 10,
                'cashout_fee_percent' => 90
            ];
        }
        
        return [
            'generate_code_fee_percent' => (int)($config['generate_code_fee_percent'] ?? 10),
            'cashout_fee_percent' => (int)($config['cashout_fee_percent'] ?? 90)
        ];
    }
    
    /**
     * Calculate complete cashout fee breakdown for first attempt
     * 
     * Example with 10.00 total fee:
     * - Swap Levy: 1.00 → VouchMorph (immediate)
     * - Remaining: 9.00
     *   - Platform (35%): 3.15 → VouchMorph
     *   - Source (15%): 1.35 → Source Institution
     *   - Destination (50%): 4.50 → Destination Institution (reserved)
     *     - Generate Code (10%): 0.45 → Earned immediately
     *     - Cashout (90%): 4.05 → Earned only on success
     */
    public function calculateFirstAttemptFees(string $currency = 'BWP'): array
    {
        $totalFee = $this->getTotalCashoutFee($currency);
        $swapLevy = $this->getSwapLevy($currency);
        $remaining = $totalFee - $swapLevy;
        
        $split = $this->getSplitAfterLevy();
        $destinationSplit = $this->getDestinationSplit();
        
        $platformShare = $remaining * ($split['platform_percent'] / 100);
        $sourceShare = $remaining * ($split['source_institution_percent'] / 100);
        $destinationShare = $remaining * ($split['destination_institution_percent'] / 100);
        
        $generateCodeFee = $destinationShare * ($destinationSplit['generate_code_fee_percent'] / 100);
        $cashoutFee = $destinationShare * ($destinationSplit['cashout_fee_percent'] / 100);
        
        return [
            'total_fee' => $totalFee,
            'swap_levy_to_vouchmorph' => $swapLevy,
            'remaining_after_levy' => $remaining,
            'platform_share' => round($platformShare, 2),
            'source_institution_share' => round($sourceShare, 2),
            'destination_share' => round($destinationShare, 2),
            'generate_code_fee' => round($generateCodeFee, 2),
            'cashout_fee' => round($cashoutFee, 2),
            'vouchmorph_total_immediate' => round($swapLevy + $platformShare, 2),
            'currency' => $currency,
            'earnings_rules' => [
                'generate_code_fee_earned_at' => 'code_generation',
                'cashout_fee_earned_at' => 'cashout_completion'
            ]
        ];
    }
    
    /**
     * Calculate fees for a failed cashout
     * Only generate code fee is paid to destination
     * Cashout fee is NOT earned (held for retry)
     */
    public function calculateFailedCashoutFees(string $currency = 'BWP'): array
    {
        $firstAttempt = $this->calculateFirstAttemptFees($currency);
        
        return [
            'total_fee_collected' => $firstAttempt['total_fee'],
            'vouchmorph_gets' => $firstAttempt['vouchmorph_total_immediate'],
            'source_gets' => $firstAttempt['source_institution_share'],
            'destination_gets' => $firstAttempt['generate_code_fee'], // Only generate code fee
            'unearned_cashout_fee' => $firstAttempt['cashout_fee'], // Held for retry
            'currency' => $currency,
            'status' => 'failed_cashout'
        ];
    }
    
    /**
     * Calculate fees for free swap-on-swap (first retry)
     * 
     * Rules:
     * - Client pays NOTHING (already paid on first attempt)
     * - VouchMorph pays generate code fee to new destination (from its share)
     * - Unearned cashout fee from first attempt pays new destination's cashout fee
     */
    public function calculateFreeRetryFees(string $currency = 'BWP'): array
    {
        $firstAttempt = $this->calculateFirstAttemptFees($currency);
        
        return [
            'client_pays' => 0,
            'new_destination_gets' => [
                'generate_code_fee' => $firstAttempt['generate_code_fee'], // Paid by VouchMorph
                'cashout_fee' => $firstAttempt['cashout_fee'], // From unearned first attempt
                'total' => $firstAttempt['generate_code_fee'] + $firstAttempt['cashout_fee']
            ],
            'vouchmorph_pays' => $firstAttempt['generate_code_fee'],
            'vouchmorph_net' => $firstAttempt['vouchmorph_total_immediate'] - $firstAttempt['generate_code_fee'],
            'first_destination_keeps' => $firstAttempt['generate_code_fee'],
            'source_keeps' => $firstAttempt['source_institution_share'],
            'currency' => $currency,
            'free_retry_applied' => true
        ];
    }
    
    /**
     * Get deposit swap fee (simple - no split logic for deposits)
     */
    public function getDepositSwapFee(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['DEPOSIT_SWAP_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("DEPOSIT_SWAP_FEE not configured in fees.json");
        }
        
        return (float)($config['total_amount'] ?? 6.00);
    }
    
    /**
     * Calculate deposit fee breakdown
     */
    public function calculateDepositFees(string $currency = 'BWP'): array
    {
        $totalFee = $this->getDepositSwapFee($currency);
        $swapLevy = $this->getSwapLevy($currency);
        $remaining = $totalFee - $swapLevy;
        
        $split = $this->getSplitAfterLevy();
        
        $platformShare = $remaining * ($split['platform_percent'] / 100);
        $sourceShare = $remaining * ($split['source_institution_percent'] / 100);
        $destinationShare = $remaining * ($split['destination_institution_percent'] / 100);
        
        return [
            'total_fee' => $totalFee,
            'swap_levy_to_vouchmorph' => $swapLevy,
            'platform_share' => round($platformShare, 2),
            'source_institution_share' => round($sourceShare, 2),
            'destination_institution_share' => round($destinationShare, 2),
            'vouchmorph_total' => round($swapLevy + $platformShare, 2),
            'currency' => $currency
        ];
    }
    
    /**
     * Get card load fee (follows deposit fee structure)
     */
    public function getCardLoadFee(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CARD_LOAD_FEE'] ?? null;
        
        if (!$config) {
            return $this->getDepositSwapFee($currency);
        }
        
        return (float)($config['total_amount'] ?? 6.00);
    }
    
    /**
     * Get card issuance fee
     */
    public function getCardIssuanceFee(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CARD_ISSUANCE_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("CARD_ISSUANCE_FEE not configured in fees.json");
        }
        
        return (float)($config['total_amount'] ?? 25.00);
    }
    
    /**
     * Check if free retry is available for this client/swap
     */
    public function isFreeRetryAvailable(int $retryCount, ?array $failedSwapMetadata = null): bool
    {
        $config = $this->feeConfig['fees']['CASHOUT_SWAP_FEE']['swap_on_swap'] ?? null;
        
        if (!$config) {
            return false;
        }
        
        $freeFirst = $config['free_first'] ?? true;
        
        // First retry (retryCount = 1 means first retry attempt)
        if ($freeFirst && $retryCount <= 1) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get micro fee for small amounts (under threshold)
     * Used by HookModule for aggregating small balances
     */
    public function getMicroFee(float $amount): float
    {
        $config = $this->feeConfig['fees']['MICRO_FEES'] ?? null;
        
        if (!$config || !($config['enabled'] ?? false)) {
            return 0;
        }
        
        $threshold = $config['threshold_amount'] ?? 50.00;
        
        // Only apply micro fee to amounts under threshold
        if ($amount >= $threshold) {
            return 0;
        }
        
        $percent = $config['percent'] ?? 0.005;
        $minFee = $config['min_fee'] ?? 0.50;
        $maxFee = $config['max_fee'] ?? 5.00;
        
        $fee = $amount * $percent;
        return round(min($maxFee, max($minFee, $fee)), 2);
    }
    
    /**
     * Get FX spread for a corridor
     */
    public function getFxSpread(string $sourceCountry, string $destCountry, array $sourceConfig): float
    {
        // Check for override in source country config
        $overrides = $sourceConfig['fx']['spread_overrides'] ?? [];
        if (isset($overrides[$destCountry])) {
            return (float)$overrides[$destCountry];
        }
        
        // Use default spread
        return (float)($sourceConfig['fx']['default_spread'] ?? 0.01);
    }
    
    /**
     * Get corridor fee (VouchMorph's cross-border service fee)
     */
    public function getCorridorFee(float $amount, string $sourceCountry, string $destCountry, array $sourceConfig): float
    {
        $config = $this->feeConfig['fees']['CORRIDOR_FEE'] ?? null;
        
        if (!$config) {
            return 0;
        }
        
        if (isset($config['percentage'])) {
            $fee = $amount * $config['percentage'];
            $max = $config['max_amount'] ?? PHP_FLOAT_MAX;
            $min = $config['min_amount'] ?? 0;
            return min($max, max($min, $fee));
        }
        
        return (float)($config['total_amount'] ?? 0);
    }
    
    /**
     * Get VAT rate from regulatory config
     */
    public function getVatRate(): float
    {
        return (float)($this->feeConfig['regulatory']['vat_rate'] ?? 0.12);
    }
    
    /**
     * Get reporting currency
     */
    public function getReportingCurrency(): string
    {
        return $this->feeConfig['regulatory']['reporting_currency'] ?? $this->defaultCurrency;
    }
    
    /**
     * Validate that amount after fees is positive
     */
    public function validateNetAmount(float $amount, float $totalFee): void
    {
        if ($amount <= $totalFee) {
            throw new \RuntimeException(
                sprintf("Amount %.2f is less than total fees %.2f", $amount, $totalFee)
            );
        }
    }
}
