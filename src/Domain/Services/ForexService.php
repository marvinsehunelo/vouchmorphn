<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Infrastructure\Banks\GenericBankClient;

/**
 * ForexService - Multi-currency FX orchestration
 * 
 * Handles:
 * - Currency detection and validation
 * - FX provider selection
 * - Rate fetching with layering
 * - Quote locking
 * - Markup application
 * - INTEGRATION WITH FEE SERVICE FOR FOREX FEES
 */
class ForexService
{
    private PDO $db;
    private array $config;
    private array $participants;
    private array $rateProviders = [];
    private string $defaultCurrency = 'BWP';
    private ?FeeService $feeService = null;
    private ?array $fxContext = null;
    
    // Rate source priority
    private const PRIORITY_LIVE_PARTICIPANT = 1;
    private const PRIORITY_FOREX_SHOP = 2;
    private const PRIORITY_TREASURY = 3;
    private const PRIORITY_CACHED = 4;
    private const PRIORITY_CENTRAL_BANK = 5;
    
    // Default markup percentages by corridor risk
    private array $defaultMarkup = [
        'low_risk' => 0.01,      // 1%
        'medium_risk' => 0.02,   // 2%
        'high_risk' => 0.035,    // 3.5%
        'volatile' => 0.05       // 5%
    ];
    
    public function __construct(PDO $db, array $config, array $participants, ?FeeService $feeService = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->participants = $participants;
        $this->feeService = $feeService;
        $this->ensureFxTablesExist();
    }
    
    /**
     * Ensure FX tables exist
     */
    private function ensureFxTablesExist(): void
    {
        // fx_quotes table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_quotes (
                id BIGSERIAL PRIMARY KEY,
                quote_uuid UUID NOT NULL UNIQUE,
                swap_reference VARCHAR(100),
                source_participant_id BIGINT,
                destination_participant_id BIGINT,
                fx_provider_participant_id BIGINT,
                source_currency CHAR(3) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                source_amount NUMERIC(24,2) NOT NULL,
                rate NUMERIC(24,10) NOT NULL,
                destination_amount NUMERIC(24,2) NOT NULL,
                rate_source VARCHAR(50),
                markup_amount NUMERIC(12,2) DEFAULT 0,
                fee_amount NUMERIC(12,2) DEFAULT 0,
                forex_fee_percent NUMERIC(5,4) DEFAULT 0,
                forex_fee_amount NUMERIC(12,2) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'QUOTED',
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // fx_rates table for caching
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_rates (
                id BIGSERIAL PRIMARY KEY,
                from_currency CHAR(3) NOT NULL,
                to_currency CHAR(3) NOT NULL,
                market_rate NUMERIC(24,10) NOT NULL,
                provider_rate NUMERIC(24,10) NOT NULL,
                your_markup_percent NUMERIC(5,4) DEFAULT 0,
                your_final_rate NUMERIC(24,10) NOT NULL,
                valid_from TIMESTAMP DEFAULT NOW(),
                valid_until TIMESTAMP NOT NULL,
                status VARCHAR(20) DEFAULT 'ACTIVE',
                fx_provider_id BIGINT,
                created_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(from_currency, to_currency, status)
            )
        ");
        
        // Add forex_fee columns to swap_requests if not exists
        $this->db->exec("
            DO $$ 
            BEGIN
                BEGIN
                    ALTER TABLE swap_requests ADD COLUMN forex_fee_percent NUMERIC(5,4) DEFAULT 0;
                EXCEPTION
                    WHEN duplicate_column THEN NULL;
                END;
                BEGIN
                    ALTER TABLE swap_requests ADD COLUMN forex_fee_amount NUMERIC(12,2) DEFAULT 0;
                EXCEPTION
                    WHEN duplicate_column THEN NULL;
                END;
                BEGIN
                    ALTER TABLE swap_requests ADD COLUMN total_forex_fee NUMERIC(12,2) DEFAULT 0;
                EXCEPTION
                    WHEN duplicate_column THEN NULL;
                END;
            END $$;
        ");
    }
    
    /**
     * Prepare FX context for a swap - main entry point
     * 
     * @param string $swapRef Swap reference
     * @param array $sourceParticipant Source participant config
     * @param array $destinationParticipant Destination participant config
     * @param string $sourceCurrency Source currency from verification
     * @param float $sourceAmount Amount in source currency
     * @param array $destination Destination payload
     * @return array FX context with amounts and quote
     */
    public function prepareFxContext(
        string $swapRef,
        array $sourceParticipant,
        array $destinationParticipant,
        string $sourceCurrency,
        float $sourceAmount,
        array $destination
    ): array {
        
        // Step 1: Determine destination currency
        $destinationCurrency = $this->resolveDestinationCurrency($destinationParticipant, $destination);
        
        $this->logFxEvent($swapRef, 'FX_CONTEXT_START', [
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'source_amount' => $sourceAmount
        ]);
        
        // Step 2: Check if FX is needed
        if ($sourceCurrency === $destinationCurrency) {
            $this->logFxEvent($swapRef, 'SAME_CURRENCY_NO_FX', [
                'currency' => $sourceCurrency
            ]);
            
            return [
                'needs_fx' => false,
                'source_currency' => $sourceCurrency,
                'destination_currency' => $destinationCurrency,
                'source_amount' => $sourceAmount,
                'destination_amount' => $sourceAmount,
                'rate' => 1.0,
                'quote_id' => null,
                'quote_uuid' => null,
                'forex_fee_percent' => 0,
                'forex_fee_amount' => 0
            ];
        }
        
        // Step 3: Get best FX quote
        $quote = $this->getBestQuote(
            $swapRef,
            $sourceParticipant,
            $destinationParticipant,
            $sourceCurrency,
            $destinationCurrency,
            $sourceAmount
        );
        
        if (!$quote) {
            throw new \RuntimeException(
                "No FX quote available for {$sourceCurrency} → {$destinationCurrency}"
            );
        }
        
        // Step 4: Calculate forex fee using FeeService
        $forexFeePercent = 0;
        $forexFeeAmount = 0;
        
        if ($this->feeService) {
            // Get forex fee from fees.json configuration
            $forexFeePercent = $this->getForexFeePercent($sourceCurrency, $destinationCurrency);
            $forexFeeAmount = $quote['destination_amount'] * $forexFeePercent;
            
            $this->logFxEvent($swapRef, 'FOREX_FEE_APPLIED', [
                'destination_amount' => $quote['destination_amount'],
                'forex_fee_percent' => $forexFeePercent,
                'forex_fee_amount' => $forexFeeAmount
            ]);
        }
        
        // Step 5: Store forex fee in quote
        $quote['forex_fee_percent'] = $forexFeePercent;
        $quote['forex_fee_amount'] = $forexFeeAmount;
        
        // Store forex fee in swap_requests later when recording
        $this->storeForexFeeInSwap($swapRef, $forexFeePercent, $forexFeeAmount);
        
        $this->logFxEvent($swapRef, 'FX_QUOTE_LOCKED', [
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'source_amount' => $quote['source_amount'],
            'destination_amount' => $quote['destination_amount'],
            'rate' => $quote['rate'],
            'markup' => $quote['markup_amount'],
            'forex_fee_percent' => $forexFeePercent,
            'forex_fee_amount' => $forexFeeAmount,
            'expires_at' => $quote['expires_at']
        ]);
        
        return [
            'needs_fx' => true,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'source_amount' => $quote['source_amount'],
            'destination_amount' => $quote['destination_amount'],
            'rate' => $quote['rate'],
            'rate_source' => $quote['rate_source'],
            'markup_amount' => $quote['markup_amount'],
            'fee_amount' => $quote['fee_amount'],
            'forex_fee_percent' => $forexFeePercent,
            'forex_fee_amount' => $forexFeeAmount,
            'quote_id' => $quote['quote_id'],
            'quote_uuid' => $quote['quote_uuid'],
            'expires_at' => $quote['expires_at'],
            'fx_provider_id' => $quote['fx_provider_id'] ?? null
        ];
    }
    
    /**
     * Get forex fee percentage from fees.json configuration
     * Example: FOREX_FEE = { "percentage": 0.005, "min_amount": 0.50, "max_amount": 50.00 }
     */
    private function getForexFeePercent(string $sourceCurrency, string $destinationCurrency): float
    {
        if (!$this->feeService) {
            return 0;
        }
        
        // Try to get config from fees.json - FOREX_FEE section
        // This would be loaded by FeeService from country config
        try {
            $forexFeeConfig = $this->feeService->getForexFeeConfig();
            if ($forexFeeConfig && isset($forexFeeConfig['percentage'])) {
                return (float)$forexFeeConfig['percentage'];
            }
        } catch (Exception $e) {
            $this->logFxEvent('CONFIG', 'FOREX_FEE_CONFIG_MISSING', ['error' => $e->getMessage()]);
        }
        
        // Check corridor-specific forex fee
        $corridorKey = $sourceCurrency . '_' . $destinationCurrency;
        $corridorFxFee = $this->getCorridorForexFee($corridorKey);
        if ($corridorFxFee !== null) {
            return $corridorFxFee;
        }
        
        // Default forex fee (0.5%)
        return 0.005;
    }
    
    /**
     * Get corridor-specific forex fee from config
     */
    private function getCorridorForexFee(string $corridor): ?float
    {
        $corridorFxFees = $this->config['fx_fees']['corridors'] ?? [];
        
        if (isset($corridorFxFees[$corridor])) {
            return (float)$corridorFxFees[$corridor];
        }
        
        return null;
    }
    
    /**
     * Store forex fee in swap_requests for accounting
     */
    private function storeForexFeeInSwap(string $swapRef, float $feePercent, float $feeAmount): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE swap_requests 
                SET forex_fee_percent = ?,
                    forex_fee_amount = ?,
                    total_forex_fee = ?
                WHERE swap_uuid = ?
            ");
            $stmt->execute([$feePercent, $feeAmount, $feeAmount, $swapRef]);
        } catch (Exception $e) {
            $this->logFxEvent($swapRef, 'STORE_FOREX_FEE_FAILED', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get exchange rate with spread applied (used by CorridorModule)
     */
    public function getExchangeRateWithSpread(string $from, string $to, string $corridor): float
    {
        $baseRate = $this->getExchangeRate($from, $to);
        $spread = $this->getCorridorSpread($corridor);
        
        return $baseRate * (1 + $spread);
    }
    
    /**
     * Get basic exchange rate (no markup)
     */
    public function getExchangeRate(string $from, string $to): float
    {
        // Try to get from cached rates first
        $cachedRate = $this->getCachedRateOnly($from, $to);
        if ($cachedRate) {
            return $cachedRate;
        }
        
        // Fallback to default rates
        $defaultRates = $this->getDefaultCorridorRates();
        $key = $from . '_' . $to;
        
        if (isset($defaultRates[$key])) {
            return $defaultRates[$key];
        }
        
        // Inverse rate if available
        $inverseKey = $to . '_' . $from;
        if (isset($defaultRates[$inverseKey])) {
            return 1 / $defaultRates[$inverseKey];
        }
        
        // Default fallback
        return 1.0;
    }
    
    /**
     * Get corridor spread from config
     */
    private function getCorridorSpread(string $corridor): float
    {
        $corridorConfig = $this->config['fx_markup']['corridors'] ?? [];
        
        if (isset($corridorConfig[$corridor])) {
            return (float)$corridorConfig[$corridor];
        }
        
        return 0.01; // Default 1% spread
    }
    
    /**
     * Get cached rate only (no markup)
     */
    private function getCachedRateOnly(string $from, string $to): ?float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT market_rate FROM fx_rates
                WHERE from_currency = :from
                AND to_currency = :to
                AND status = 'ACTIVE'
                AND valid_until > NOW()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([':from' => $from, ':to' => $to]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                return (float)$result['market_rate'];
            }
            
        } catch (Exception $e) {
            $this->logFxEvent('CACHE', 'GET_CACHED_RATE_FAILED', ['error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * Resolve destination currency from participant or destination payload
     */
    private function resolveDestinationCurrency(array $participant, array $destination): string
    {
        // Priority 1: Explicit currency in destination
        if (isset($destination['currency']) && !empty($destination['currency'])) {
            return strtoupper($destination['currency']);
        }
        
        // Priority 2: Participant's default currency from config
        if (isset($participant['default_currency'])) {
            return strtoupper($participant['default_currency']);
        }
        
        // Priority 3: Country mapping (fallback)
        $countryCode = $participant['country_code'] ?? $this->config['country_code'] ?? 'BW';
        return $this->mapCountryToCurrency($countryCode);
    }
    
    /**
     * Get best FX quote from available providers
     */
    private function getBestQuote(
        string $swapRef,
        array $sourceParticipant,
        array $destinationParticipant,
        string $sourceCurrency,
        string $destinationCurrency,
        float $sourceAmount
    ): ?array {
        
        // Try to get live quote from participants
        $liveQuote = $this->getLiveParticipantQuote(
            $swapRef,
            $sourceParticipant,
            $destinationParticipant,
            $sourceCurrency,
            $destinationCurrency,
            $sourceAmount
        );
        
        if ($liveQuote) {
            return $this->lockQuote($swapRef, $liveQuote);
        }
        
        // Try cached/internal rates
        $cachedQuote = $this->getCachedRateWithMarkup(
            $sourceCurrency,
            $destinationCurrency,
            $sourceAmount
        );
        
        if ($cachedQuote) {
            return $this->lockQuote($swapRef, $cachedQuote);
        }
        
        // Try central bank rate as fallback
        $centralBankQuote = $this->getCentralBankRate(
            $sourceCurrency,
            $destinationCurrency,
            $sourceAmount
        );
        
        if ($centralBankQuote) {
            return $this->lockQuote($swapRef, $centralBankQuote);
        }
        
        return null;
    }
    
    /**
     * Get live quote from participant's FX endpoint
     */
    private function getLiveParticipantQuote(
        string $swapRef,
        array $sourceParticipant,
        array $destinationParticipant,
        string $sourceCurrency,
        string $destinationCurrency,
        float $sourceAmount
    ): ?array {
        
        // Check if source participant can provide FX
        if (!$this->participantCanProvideFx($sourceParticipant)) {
            return null;
        }
        
        try {
            $bankClient = new GenericBankClient($sourceParticipant);
            
            $payload = [
                'reference' => $swapRef,
                'source_currency' => $sourceCurrency,
                'destination_currency' => $destinationCurrency,
                'source_amount' => $sourceAmount,
                'action' => 'GET_FX_QUOTE'
            ];
            
            $result = $bankClient->getFxQuote($payload);
            
            if (isset($result['success']) && $result['success'] === true) {
                $data = $result['data'] ?? [];
                
                if (isset($data['rate']) && $data['rate'] > 0) {
                    $providerRate = (float)$data['rate'];
                    
                    // Apply VouchMorph markup
                    $markupPercent = $this->calculateMarkup($sourceCurrency, $destinationCurrency);
                    $finalRate = $providerRate * (1 - $markupPercent);
                    $destinationAmount = $sourceAmount * $finalRate;
                    $markupAmount = $sourceAmount * $providerRate * $markupPercent;
                    
                    return [
                        'source_currency' => $sourceCurrency,
                        'destination_currency' => $destinationCurrency,
                        'source_amount' => $sourceAmount,
                        'destination_amount' => round($destinationAmount, 2),
                        'rate' => $finalRate,
                        'provider_rate' => $providerRate,
                        'rate_source' => 'participant_live',
                        'markup_amount' => round($markupAmount, 2),
                        'fee_amount' => 0,
                        'fx_provider_id' => $sourceParticipant['participant_id'] ?? null,
                        'expires_at' => $data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+5 minutes')),
                        'provider_response' => $data
                    ];
                }
            }
            
        } catch (Exception $e) {
            $this->logFxEvent($swapRef, 'LIVE_QUOTE_FAILED', [
                'error' => $e->getMessage(),
                'provider' => $sourceParticipant['provider_code'] ?? 'unknown'
            ]);
        }
        
        return null;
    }
    
    /**
     * Check if participant can provide FX quotes
     */
    private function participantCanProvideFx(array $participant): bool
    {
        // Check capabilities
        $capabilities = $participant['capabilities'] ?? [];
        
        if (isset($capabilities['fx_provider']) && $capabilities['fx_provider'] === true) {
            return true;
        }
        
        // Check provider type
        $providerType = $participant['type'] ?? $participant['provider_type'] ?? '';
        
        if (in_array($providerType, ['forex_provider', 'bank_treasury', 'fx_shop'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get cached rate with markup applied
     */
    private function getCachedRateWithMarkup(
        string $sourceCurrency,
        string $destinationCurrency,
        float $sourceAmount
    ): ?array {
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM fx_rates
                WHERE from_currency = :from
                AND to_currency = :to
                AND status = 'ACTIVE'
                AND valid_until > NOW()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([
                ':from' => $sourceCurrency,
                ':to' => $destinationCurrency
            ]);
            
            $rate = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($rate) {
                $providerRate = (float)$rate['provider_rate'];
                $markupPercent = (float)($rate['your_markup_percent'] ?? $this->calculateMarkup($sourceCurrency, $destinationCurrency));
                $finalRate = $providerRate * (1 - $markupPercent);
                $destinationAmount = $sourceAmount * $finalRate;
                $markupAmount = $sourceAmount * $providerRate * $markupPercent;
                
                return [
                    'source_currency' => $sourceCurrency,
                    'destination_currency' => $destinationCurrency,
                    'source_amount' => $sourceAmount,
                    'destination_amount' => round($destinationAmount, 2),
                    'rate' => $finalRate,
                    'provider_rate' => $providerRate,
                    'rate_source' => 'cached',
                    'markup_amount' => round($markupAmount, 2),
                    'fee_amount' => 0,
                    'fx_provider_id' => $rate['fx_provider_id'] ?? null,
                    'expires_at' => $rate['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'))
                ];
            }
            
        } catch (Exception $e) {
            $this->logFxEvent('CACHE', 'CACHED_RATE_FAILED', ['error' => $e->getMessage()]);
        }
        
        return null;
    }
    
    /**
     * Get central bank rate as fallback
     */
    private function getCentralBankRate(
        string $sourceCurrency,
        string $destinationCurrency,
        float $sourceAmount
    ): ?array {
        
        // This would integrate with central bank APIs
        // For now, use a reasonable default based on common corridors
        
        $defaultRates = $this->getDefaultCorridorRates();
        $key = $sourceCurrency . '_' . $destinationCurrency;
        
        if (isset($defaultRates[$key])) {
            $providerRate = $defaultRates[$key];
            $markupPercent = $this->calculateMarkup($sourceCurrency, $destinationCurrency, 'high_risk');
            $finalRate = $providerRate * (1 - $markupPercent);
            $destinationAmount = $sourceAmount * $finalRate;
            $markupAmount = $sourceAmount * $providerRate * $markupPercent;
            
            return [
                'source_currency' => $sourceCurrency,
                'destination_currency' => $destinationCurrency,
                'source_amount' => $sourceAmount,
                'destination_amount' => round($destinationAmount, 2),
                'rate' => $finalRate,
                'provider_rate' => $providerRate,
                'rate_source' => 'central_bank_fallback',
                'markup_amount' => round($markupAmount, 2),
                'fee_amount' => 0,
                'fx_provider_id' => null,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))
            ];
        }
        
        return null;
    }
    
    /**
     * Lock a quote in the database
     */
    private function lockQuote(string $swapRef, array $quote): array
    {
        $quoteUuid = Uuid::uuid4()->toString();
        $forexFeePercent = $quote['forex_fee_percent'] ?? 0;
        $forexFeeAmount = $quote['forex_fee_amount'] ?? 0;
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO fx_quotes 
                (quote_uuid, swap_reference, source_participant_id, destination_participant_id,
                 fx_provider_participant_id, source_currency, destination_currency,
                 source_amount, rate, destination_amount, rate_source,
                 markup_amount, fee_amount, forex_fee_percent, forex_fee_amount, status, expires_at, created_at)
                VALUES 
                (:uuid, :swap_ref, 1, 1, :fx_provider_id,
                 :source_currency, :destination_currency, :source_amount,
                 :rate, :destination_amount, :rate_source,
                 :markup_amount, :fee_amount, :forex_fee_percent, :forex_fee_amount, 'QUOTED', :expires_at, NOW())
            ");
            
            $stmt->execute([
                ':uuid' => $quoteUuid,
                ':swap_ref' => $swapRef,
                ':fx_provider_id' => $quote['fx_provider_id'] ?? null,
                ':source_currency' => $quote['source_currency'],
                ':destination_currency' => $quote['destination_currency'],
                ':source_amount' => $quote['source_amount'],
                ':rate' => $quote['rate'],
                ':destination_amount' => $quote['destination_amount'],
                ':rate_source' => $quote['rate_source'],
                ':markup_amount' => $quote['markup_amount'],
                ':fee_amount' => $quote['fee_amount'],
                ':forex_fee_percent' => $forexFeePercent,
                ':forex_fee_amount' => $forexFeeAmount,
                ':expires_at' => $quote['expires_at']
            ]);
            
            $quoteId = $this->db->lastInsertId();
            
            $quote['quote_id'] = $quoteId;
            $quote['quote_uuid'] = $quoteUuid;
            
            return $quote;
            
        } catch (Exception $e) {
            $this->logFxEvent($swapRef, 'QUOTE_LOCK_FAILED', ['error' => $e->getMessage()]);
            throw new \RuntimeException("Failed to lock FX quote: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate markup based on currency corridor
     */
    private function calculateMarkup(string $sourceCurrency, string $destinationCurrency, ?string $riskLevel = null): float
    {
        $key = $sourceCurrency . '_' . $destinationCurrency;
        
        // Check corridor-specific markup
        $corridorMarkup = $this->getCorridorMarkup($key);
        if ($corridorMarkup !== null) {
            return $corridorMarkup;
        }
        
        // Determine risk level
        if ($riskLevel === null) {
            $riskLevel = $this->determineRiskLevel($sourceCurrency, $destinationCurrency);
        }
        
        return $this->defaultMarkup[$riskLevel] ?? $this->defaultMarkup['medium_risk'];
    }
    
    /**
     * Determine risk level for currency corridor
     */
    private function determineRiskLevel(string $sourceCurrency, string $destinationCurrency): string
    {
        // Stable currencies (USD, EUR, GBP, BWP, ZAR, etc.)
        $stableCurrencies = ['USD', 'EUR', 'GBP', 'CHF', 'CAD', 'AUD', 'BWP', 'ZAR', 'NAD'];
        
        // Volatile currencies
        $volatileCurrencies = ['NGN', 'GHS', 'UGX', 'TZS', 'KES', 'ZMW', 'MZN', 'ZWL'];
        
        $sourceStable = in_array($sourceCurrency, $stableCurrencies);
        $destStable = in_array($destinationCurrency, $stableCurrencies);
        $sourceVolatile = in_array($sourceCurrency, $volatileCurrencies);
        $destVolatile = in_array($destinationCurrency, $volatileCurrencies);
        
        if ($sourceVolatile || $destVolatile) {
            return 'volatile';
        }
        
        if (!$sourceStable || !$destStable) {
            return 'high_risk';
        }
        
        // Check if same region (Africa)
        $africanCurrencies = ['BWP', 'ZAR', 'NAD', 'NGN', 'GHS', 'UGX', 'TZS', 'KES', 'ZMW', 'MZN'];
        $bothAfrican = in_array($sourceCurrency, $africanCurrencies) && in_array($destinationCurrency, $africanCurrencies);
        
        if ($bothAfrican) {
            return 'medium_risk';
        }
        
        return 'low_risk';
    }
    
    /**
     * Get corridor-specific markup from config
     */
    private function getCorridorMarkup(string $corridor): ?float
    {
        $corridorConfig = $this->config['fx_markup']['corridors'] ?? [];
        
        if (isset($corridorConfig[$corridor])) {
            return (float)$corridorConfig[$corridor];
        }
        
        // Check wildcard patterns
        foreach ($corridorConfig as $pattern => $markup) {
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';
                if (preg_match($regex, $corridor)) {
                    return (float)$markup;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get default corridor rates (fallback)
     */
    private function getDefaultCorridorRates(): array
    {
        return [
            'USD_BWP' => 13.50,
            'BWP_USD' => 0.074,
            'USD_ZAR' => 18.20,
            'ZAR_USD' => 0.055,
            'USD_NGN' => 750.00,
            'NGN_USD' => 0.00133,
            'BWP_ZAR' => 1.35,
            'ZAR_BWP' => 0.74,
            'BWP_NGN' => 55.00,
            'NGN_BWP' => 0.018,
            'EUR_BWP' => 14.80,
            'BWP_EUR' => 0.0676,
            'GBP_BWP' => 17.20,
            'BWP_GBP' => 0.0581,
            'USD_GBP' => 0.79,
            'GBP_USD' => 1.27,
            'EUR_USD' => 1.09,
            'USD_EUR' => 0.92
        ];
    }
    
    /**
     * Map country code to currency
     */
    private function mapCountryToCurrency(string $countryCode): string
    {
        $map = [
            'BW' => 'BWP',
            'ZA' => 'ZAR',
            'NA' => 'NAD',
            'NG' => 'NGN',
            'GH' => 'GHS',
            'UG' => 'UGX',
            'TZ' => 'TZS',
            'KE' => 'KES',
            'ZM' => 'ZMW',
            'MZ' => 'MZN',
            'ZW' => 'ZWL',
            'US' => 'USD',
            'GB' => 'GBP',
            'EU' => 'EUR',
            'CA' => 'CAD',
            'AU' => 'AUD',
            'CN' => 'CNY',
            'JP' => 'JPY',
            'IN' => 'INR'
        ];
        
        return $map[strtoupper($countryCode)] ?? $this->defaultCurrency;
    }
    
    /**
     * Log FX events
     */
    private function logFxEvent(string $reference, string $event, array $data): void
    {
        $logFile = '/tmp/vouchmorph_fx.log';
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'reference' => $reference,
            'event' => $event,
            'data' => $data
        ]);
        file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * Update cached FX rates (for cron job)
     */
    public function updateCachedRates(array $rates): void
    {
        foreach ($rates as $rate) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO fx_rates 
                    (from_currency, to_currency, market_rate, provider_rate, 
                     your_markup_percent, your_final_rate, valid_from, valid_until, status, created_at)
                    VALUES 
                    (:from, :to, :market, :provider, :markup, :final, NOW(), :expires, 'ACTIVE', NOW())
                    ON CONFLICT (from_currency, to_currency, status) 
                    DO UPDATE SET 
                        market_rate = EXCLUDED.market_rate,
                        provider_rate = EXCLUDED.provider_rate,
                        your_markup_percent = EXCLUDED.your_markup_percent,
                        your_final_rate = EXCLUDED.your_final_rate,
                        valid_until = EXCLUDED.valid_until,
                        updated_at = NOW()
                ");
                
                $stmt->execute([
                    ':from' => $rate['from'],
                    ':to' => $rate['to'],
                    ':market' => $rate['market_rate'],
                    ':provider' => $rate['provider_rate'],
                    ':markup' => $rate['markup_percent'],
                    ':final' => $rate['final_rate'],
                    ':expires' => $rate['expires_at']
                ]);
                
            } catch (Exception $e) {
                $this->logFxEvent('CRON', 'RATE_UPDATE_FAILED', ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * Verify that a quote is still valid
     */
    public function verifyQuote(string $quoteUuid): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM fx_quotes 
                WHERE quote_uuid = :uuid 
                AND status = 'QUOTED'
                AND expires_at > NOW()
            ");
            $stmt->execute([':uuid' => $quoteUuid]);
            $quote = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$quote) {
                return ['valid' => false, 'reason' => 'Quote expired or not found'];
            }
            
            return [
                'valid' => true,
                'quote' => $quote
            ];
            
        } catch (Exception $e) {
            return ['valid' => false, 'reason' => $e->getMessage()];
        }
    }
    
    /**
     * Mark quote as used
     */
    public function markQuoteUsed(string $quoteUuid): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE fx_quotes 
                SET status = 'USED', 
                    updated_at = NOW()
                WHERE quote_uuid = :uuid
            ");
            $stmt->execute([':uuid' => $quoteUuid]);
            
        } catch (Exception $e) {
            $this->logFxEvent($quoteUuid, 'MARK_QUOTE_USED_FAILED', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get forex fee amount for a given amount and corridor
     */
    public function getForexFee(float $amount, string $sourceCurrency, string $destinationCurrency): array
    {
        $feePercent = $this->getForexFeePercent($sourceCurrency, $destinationCurrency);
        $feeAmount = $amount * $feePercent;
        
        // Apply min/max if configured
        if ($this->feeService) {
            try {
                $forexFeeConfig = $this->feeService->getForexFeeConfig();
                if ($forexFeeConfig) {
                    $minFee = $forexFeeConfig['min_amount'] ?? 0;
                    $maxFee = $forexFeeConfig['max_amount'] ?? PHP_FLOAT_MAX;
                    $feeAmount = min($maxFee, max($minFee, $feeAmount));
                }
            } catch (Exception $e) {
                // Use calculated fee
            }
        }
        
        return [
            'percent' => $feePercent,
            'amount' => round($feeAmount, 2),
            'currency' => $destinationCurrency
        ];
    }
}
