<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

use Exception;
use RuntimeException;

class CashoutService
{
    private array $denominations = [];
    private string $country;
    private string $currency;
    private array $config;

    public function __construct(string $country, string $currency = 'BWP')
    {
        $this->country = $country;
        $this->currency = $currency;
        $this->loadDenominations();
        $this->loadConfig();
    }

    /**
     * Load ATM denominations from JSON file
     */
    private function loadDenominations(): void
    {
        $filePath = __DIR__ . "/../../../CORE_CONFIG/countries/{$this->country}/atm_notes_{$this->country}.json";
        
        // Try alternative locations
        if (!file_exists($filePath)) {
            $altPaths = [
                __DIR__ . "/../../../CORE_CONFIG/atm_notes_{$this->country}.json",
                __DIR__ . "/../../../atm_notes_{$this->country}.json",
                __DIR__ . "/../../../public/atm_notes_{$this->country}.json"
            ];
            
            foreach ($altPaths as $path) {
                if (file_exists($path)) {
                    $filePath = $path;
                    break;
                }
            }
        }

        if (!file_exists($filePath)) {
            throw new RuntimeException("ATM denominations file not found for country {$this->country}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read ATM denominations file");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in ATM denominations file: " . json_last_error_msg());
        }

        $this->denominations = $data;
        
        // Verify currency exists
        if (!isset($this->denominations[$this->currency])) {
            throw new RuntimeException("Currency {$this->currency} not found in ATM denominations");
        }
    }

    /**
     * Load cashout configuration
     */
    private function loadConfig(): void
    {
        $configPath = __DIR__ . "/../../../CORE_CONFIG/countries/{$this->country}/config_{$this->country}.php";
        
        if (file_exists($configPath)) {
            $config = include $configPath;
            $this->config = $config['cashout'] ?? [
                'min_amount' => 10,
                'max_amount' => 5000,
                'fee_percentage' => 0.02,
                'fee_fixed' => 2.00
            ];
        } else {
            // Default config
            $this->config = [
                'min_amount' => 10,
                'max_amount' => 5000,
                'fee_percentage' => 0.02,
                'fee_fixed' => 2.00
            ];
        }
    }

    /**
     * Get available denominations for currency
     */
    public function getDenominations(): array
    {
        return $this->denominations[$this->currency] ?? [];
    }

    /**
     * Check if amount can be dispensed with available denominations
     */
    public function canDispenseAmount(float $amount): bool
    {
        $denominations = $this->getDenominations();
        if (empty($denominations)) {
            return false;
        }

        // Sort denominations in descending order
        rsort($denominations);
        
        return $this->canMakeAmount($amount, $denominations);
    }

    /**
     * Recursive function to check if amount can be made with denominations
     */
    private function canMakeAmount(float $amount, array $denoms, int $index = 0): bool
    {
        if ($amount == 0) return true;
        if ($amount < 0 || $index >= count($denoms)) return false;

        $denom = $denoms[$index];
        $maxCount = (int)($amount / $denom);

        for ($i = $maxCount; $i >= 0; $i--) {
            if ($this->canMakeAmount($amount - ($i * $denom), $denoms, $index + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get minimum cashout amount
     */
    public function getMinAmount(): float
    {
        return $this->config['min_amount'] ?? 10.00;
    }

    /**
     * Get maximum cashout amount
     */
    public function getMaxAmount(): float
    {
        return $this->config['max_amount'] ?? 5000.00;
    }

    /**
     * Validate cashout amount
     */
    public function validateAmount(float $amount): array
    {
        $errors = [];

        if ($amount < $this->getMinAmount()) {
            $errors[] = "Amount below minimum ({$this->getMinAmount()})";
        }

        if ($amount > $this->getMaxAmount()) {
            $errors[] = "Amount above maximum ({$this->getMaxAmount()})";
        }

        if (!$this->canDispenseAmount($amount)) {
            $errors[] = "Amount cannot be dispensed with available denominations: " . 
                       implode(', ', $this->getDenominations());
        }

        return $errors;
    }

    /**
     * Calculate cashout fee
     */
    public function calculateFee(float $amount): float
    {
        $percentage = $this->config['fee_percentage'] ?? 0.02;
        $fixed = $this->config['fee_fixed'] ?? 2.00;
        
        return round(($amount * $percentage) + $fixed, 2);
    }

    /**
     * Get breakdown of denominations for amount
     */
    public function getDenominationBreakdown(float $amount): array
    {
        if (!$this->canDispenseAmount($amount)) {
            return [];
        }

        $denominations = $this->getDenominations();
        rsort($denominations);
        
        $breakdown = [];
        $remaining = $amount;

        foreach ($denominations as $denom) {
            if ($remaining <= 0) break;
            
            $count = (int)($remaining / $denom);
            if ($count > 0) {
                $breakdown[$denom] = $count;
                $remaining -= $count * $denom;
            }
        }

        return $breakdown;
    }
}
