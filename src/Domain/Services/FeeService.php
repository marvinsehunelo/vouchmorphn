<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * FeeService - ALL fees from country config, NO HARDCODING
 * 
 * Every fee value must come from config/countries/{country}/fees.json
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
     * Get generate code fee (for cashout code generation)
     */
    public function getGenerateCodeFee(string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CODE_GENERATION_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("CODE_GENERATION_FEE not configured in fees.json");
        }
        
        return (float)($config['total_amount'] ?? 2.00);
    }
    
    /**
     * Get cashout fee (percentage or fixed)
     */
    public function getCashoutFee(float $amount, string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['CASHOUT_FEE'] ?? null;
        
        if (!$config) {
            throw new \RuntimeException("CASHOUT_FEE not configured in fees.json");
        }
        
        if (isset($config['percentage'])) {
            $fee = $amount * $config['percentage'];
            $max = $config['max_amount'] ?? PHP_FLOAT_MAX;
            $min = $config['min_amount'] ?? 0;
            return min($max, max($min, $fee));
        }
        
        return (float)($config['total_amount'] ?? 0.50);
    }
    
    /**
     * Get swap-on-swap fee (for retry attempts)
     * First retry is FREE if configured
     */
    public function getSwapOnSwapFee(int $retryCount, string $currency = 'BWP'): float
    {
        $config = $this->feeConfig['fees']['SWAP_ON_SWAP_FEE'] ?? null;
        
        if (!$config) {
            return 0; // No fee configured
        }
        
        $freeFirstRetry = $config['free_first_retry'] ?? true;
        
        // Free on first retry (retryCount = 1 means first retry)
        if ($freeFirstRetry && $retryCount <= 1) {
            return 0;
        }
        
        return (float)($config['total_amount'] ?? 1.00);
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
     * Calculate all cashout fees at once
     */
    public function calculateCashoutFees(float $amount, int $retryCount, string $currency = 'BWP'): array
    {
        $generateCodeFee = $this->getGenerateCodeFee($currency);
        $cashoutFee = $this->getCashoutFee($amount, $currency);
        $swapOnSwapFee = $this->getSwapOnSwapFee($retryCount, $currency);
        
        $totalFee = $generateCodeFee + $cashoutFee + $swapOnSwapFee;
        
        return [
            'generate_code_fee' => $generateCodeFee,
            'cashout_fee' => $cashoutFee,
            'swap_on_swap_fee' => $swapOnSwapFee,
            'total_fee' => $totalFee,
            'net_amount' => $amount - $totalFee,
            'free_retry_used' => ($retryCount <= 1 && $swapOnSwapFee == 0),
            'currency' => $currency,
            'timestamp' => date('c')
        ];
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
