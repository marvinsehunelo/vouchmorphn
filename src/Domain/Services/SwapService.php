<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use DateTimeImmutable;
use RuntimeException;
require_once __DIR__ . '/../ValueObjects/SwapStatusResolver.php';
require_once __DIR__ . '/Settlement/HybridSettlementStrategy.php';

use Security\Encryption\TokenEncryptor;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\CardService;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Domain\ValueObjects\SwapStatusResolver;

/**
 * SwapService - ISO20022 & FSPIOP Compliant
 * Multi-country aware, dynamic configuration loading
 * ALL fees and rates are loaded from country configuration files - NO HARDCODING
 */
class SwapService
{
    private PDO $swapDB;
    private array $settings;
    private array $config;
    private array $participants;
    private TokenEncryptor $encryptor;
    private SwapStatusResolver $swapStatusResolver;
    private string $countryCode;
    private array $feesConfig = [];
    private array $flowsConfig = [];
    private array $atmNotes = [];
    private array $cardConfig = [];
    private HybridSettlementStrategy $settlement;
    private ?SmsNotificationService $smsService = null;
    private ?CardService $cardService = null;

    private const LOG_FILE = '/tmp/vouchmorphn_swap_audit.log';
    private const DEBUG_FILE = '/tmp/hold_debug.log';
    
    private const HOLD_EXPIRY_HOURS = 24;
    private const VOUCHER_EXPIRY_HOURS = 24;
    private const EXPIRY_BATCH_SIZE = 100;
    private const MESSAGE_CARD_EXPIRY_DAYS = 30;
    
    private const PHONE_FIELDS = [
        'phone',
        'wallet_phone',
        'ewallet_phone',
        'card_phone',
        'claimant_phone',
        'beneficiary_phone',
        'account_phone'
    ];

    public function __construct(PDO $swapDB, array $settings, string $country, string $encryptionKey, array $config)
    {
        $this->swapDB = $swapDB;
        $this->settings = $settings;
        $this->countryCode = strtoupper($country);
        $this->config = $config;
        
        if (isset($config['participants']) && is_array($config['participants'])) {
            $this->participants = $config['participants'];
            error_log("[SwapService] Using new config structure with 'participants' key");
        } elseif (isset($config[0]) && is_array($config[0])) {
            $this->participants = [];
            foreach ($config as $participant) {
                if (isset($participant['id'])) {
                    $this->participants[$participant['id']] = $participant;
                }
            }
            error_log("[SwapService] Using indexed participants array structure");
        } else {
            $this->participants = $config;
            error_log("[SwapService] Using legacy direct participants array structure");
        }
        
        if (!is_array($this->participants)) {
            $this->participants = [];
            error_log("[SwapService] WARNING: Participants was not an array, reset to empty array");
        }
        
        $this->participants = array_change_key_case($this->participants, CASE_LOWER);
        
        error_log("=== SWAPSERVICE CONSTRUCTOR ===");
        error_log("Country: " . $this->countryCode);
        error_log("Participants count: " . count($this->participants));

        try {
            $this->swapStatusResolver = new SwapStatusResolver(
                fn($event, $data) => $this->logEvent('RESOLVER', $event, $data),
                $this->config,
                $this->participants
            );
            error_log("[SwapService] SwapStatusResolver initialized successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR initializing SwapStatusResolver: " . $e->getMessage());
            $this->swapStatusResolver = null;
        }

        // PRIORITY 1: Use config from LoadCountry (already loaded)
        if (isset($config['fees']) && !empty($config['fees'])) {
            $this->feesConfig = $config['fees'];
            error_log("[SwapService] Using fees config from LoadCountry");
        } else {
            try {
                $this->loadCountryFees();
                error_log("[SwapService] Country fees loaded successfully from files");
            } catch (\Exception $e) {
                error_log("[SwapService] ERROR loading country fees: " . $e->getMessage());
                throw new RuntimeException("Failed to load country fees: " . $e->getMessage());
            }
        }
        
        // PRIORITY 1: Use card config from LoadCountry
        if (isset($config['card_config']) && !empty($config['card_config'])) {
            $this->cardConfig = $config['card_config'];
            error_log("[SwapService] Using card config from LoadCountry");
        } else {
            try {
                $this->loadCardConfig();
                error_log("[SwapService] Card config loaded successfully from files");
            } catch (\Exception $e) {
                error_log("[SwapService] ERROR loading card config: " . $e->getMessage());
                throw new RuntimeException("Failed to load card config: " . $e->getMessage());
            }
        }
        
        // PRIORITY 1: Use ATM notes from LoadCountry
        if (isset($config['atm_notes']) && !empty($config['atm_notes'])) {
            $this->atmNotes = $config['atm_notes'];
            error_log("[SwapService] Using ATM notes from LoadCountry");
        } else {
            try {
                $this->loadAtmNotes();
                error_log("[SwapService] ATM notes loaded successfully from files");
            } catch (\Exception $e) {
                error_log("[SwapService] ERROR loading ATM notes: " . $e->getMessage());
                throw new RuntimeException("Failed to load ATM notes: " . $e->getMessage());
            }
        }
        
        try {
            $this->loadFlows();
            error_log("[SwapService] Flows loaded successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] WARNING loading flows: " . $e->getMessage());
        }
        
        try {
            $this->settlement = new HybridSettlementStrategy($this->swapDB);
            error_log("[SwapService] HybridSettlementStrategy initialized");
        } catch (\Exception $e) {
            error_log("[SwapService] ERROR initializing settlement: " . $e->getMessage());
            throw new RuntimeException("Failed to initialize settlement strategy: " . $e->getMessage());
        }
        
        try {
            $this->initSmsService();
            error_log("[SwapService] SMS Service initialized successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] WARNING: SMS Service initialization failed: " . $e->getMessage());
        }
        
        // Initialize Card Service
        try {
            if (isset($this->participants['vouchmorph'])) {
                $this->cardService = new CardService(
                    $this->swapDB, 
                    $this->countryCode, 
                    $this->participants['vouchmorph']
                );
                error_log("[SwapService] ✅ Card Service initialized successfully");
            } else {
                $this->cardService = new CardService(
                    $this->swapDB, 
                    $this->countryCode, 
                    []
                );
                error_log("[SwapService] ✅ Card Service initialized with default config");
            }
        } catch (\Exception $e) {
            error_log("[SwapService] ❌ Card Service initialization failed: " . $e->getMessage());
            $this->cardService = null;
        }
        
        error_log("=== SWAPSERVICE CONSTRUCTOR COMPLETE ===");
        error_log("Card service status: " . ($this->cardService ? "ACTIVE" : "NOT AVAILABLE"));
    }
    
    /**
     * Get country data directory path - Compatible with LoadCountry paths
     * Now searches in the correct locations for config files
     */
    private function getCountryDataDir(): string
    {
        // First, get the country slug from SystemCountry.php (same as LoadCountry)
        $systemCountryFile = __DIR__ . "/../../Core/Config/SystemCountry.php";
        if (file_exists($systemCountryFile)) {
            $countryMeta = require $systemCountryFile;
            $countrySlug = $countryMeta['slug'] ?? 'botswana';
            
            // Look in the same locations as LoadCountry
            $possibleLocations = [
                dirname(__DIR__, 3) . "/config/countries/" . $countrySlug,
                __DIR__ . "/../../../config/countries/" . $countrySlug,
                __DIR__ . "/../../config/countries/" . $countrySlug,
                getenv('APP_ROOT') . "/config/countries/" . $countrySlug,
            ];
            
            foreach ($possibleLocations as $location) {
                if (is_dir($location)) {
                    error_log("[SwapService] Using country data dir: {$location}");
                    return $location;
                }
            }
        }
        
        // Fallback: try with country code
        $possiblePaths = [
            dirname(__DIR__, 3) . "/config/countries/" . strtolower($this->countryCode),
            __DIR__ . "/../../../config/countries/" . strtolower($this->countryCode),
            __DIR__ . "/../../config/countries/" . strtolower($this->countryCode),
            getenv('APP_ROOT') . "/config/countries/" . strtolower($this->countryCode),
            __DIR__ . "/../../../../config/countries/" . strtolower($this->countryCode),
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                error_log("[SwapService] Using country data dir (fallback): {$path}");
                return $path;
            }
        }
        
        // Ultimate fallback
        $fallback = dirname(__DIR__, 3) . "/config/countries/botswana";
        error_log("[SwapService] WARNING: Using fallback country data dir: {$fallback}");
        return $fallback;
    }
    
    /**
     * Load ATM notes from configuration
     */
    private function loadAtmNotes(): void
    {
        $dataDir = $this->getCountryDataDir();
        $atmFile = $dataDir . '/atm_notes.json';
        
        if (!file_exists($atmFile)) {
            throw new RuntimeException("ATM notes file missing for country {$this->countryCode}: {$atmFile}");
        }
        
        $atmContent = file_get_contents($atmFile);
        if ($atmContent === false) {
            throw new RuntimeException("Failed to read ATM notes file: {$atmFile}");
        }
        
        $this->atmNotes = json_decode($atmContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in ATM notes file: {$atmFile} - " . json_last_error_msg());
        }
        
        if (!isset($this->atmNotes['BWP']) || empty($this->atmNotes['BWP'])) {
            throw new RuntimeException("ATM notes missing BWP denominations in {$atmFile}");
        }
        
        error_log("[SwapService] ATM notes loaded from: {$atmFile}");
    }
    
    /**
     * Load card configuration from country config - NO HARDCODING
     */
    private function loadCardConfig(): void
    {
        $dataDir = $this->getCountryDataDir();
        $cardFile = $dataDir . '/cards.json';
        
        if (!file_exists($cardFile)) {
            throw new RuntimeException("Card config file missing for country {$this->countryCode}: {$cardFile}");
        }
        
        $content = file_get_contents($cardFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read card config file: {$cardFile}");
        }
        
        $this->cardConfig = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in card config file: {$cardFile} - " . json_last_error_msg());
        }
        
        if (!isset($this->cardConfig['message_based_issuers'])) {
            throw new RuntimeException("Card config missing 'message_based_issuers' in {$cardFile}");
        }
        
        if (!isset($this->cardConfig['default_card_type'])) {
            throw new RuntimeException("Card config missing 'default_card_type' in {$cardFile}");
        }
        
        error_log("[SwapService] Card config loaded from: {$cardFile}");
    }

    /**
     * Load country fees from configuration - NO HARDCODING, MUST load from file
     */
    private function loadCountryFees(): void
    {
        $dataDir = $this->getCountryDataDir();
        
        $feesFile = $dataDir . '/fees.json';
        if (!file_exists($feesFile)) {
            throw new RuntimeException("Fees file missing for country {$this->countryCode}: {$feesFile}");
        }
        
        $content = file_get_contents($feesFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read fees file: {$feesFile}");
        }
        
        $this->feesConfig = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in fees file: {$feesFile} - " . json_last_error_msg());
        }
        
        $requiredFees = ['CASHOUT_SWAP_FEE', 'DEPOSIT_SWAP_FEE', 'CARD_LOAD_FEE', 'CARD_ISSUANCE_FEE'];
        foreach ($requiredFees as $requiredFee) {
            if (!isset($this->feesConfig['fees'][$requiredFee])) {
                throw new RuntimeException("Missing required fee configuration: {$requiredFee} in {$feesFile}");
            }
            
            $fee = $this->feesConfig['fees'][$requiredFee];
            if (!isset($fee['total_amount'])) {
                throw new RuntimeException("Fee {$requiredFee} missing 'total_amount' in {$feesFile}");
            }
            
            if (!isset($fee['split'])) {
                throw new RuntimeException("Fee {$requiredFee} missing 'split' configuration in {$feesFile}");
            }
        }
        
        if (!isset($this->feesConfig['regulatory']['vat_rate'])) {
            throw new RuntimeException("Missing 'regulatory.vat_rate' in {$feesFile}");
        }
        
        error_log("[SwapService] Fees loaded from: {$feesFile}");
    }

    /**
     * Initialize SMS service from country config - NO HARDCODING
     */
    private function initSmsService(): void
    {
        $dataDir = $this->getCountryDataDir();
        $configPath = $dataDir . '/communication.json';
        
        if (!file_exists($configPath)) {
            error_log("[SwapService] Communication config missing for {$this->countryCode}: {$configPath}");
            $this->smsService = null;
            return;
        }
        
        $content = file_get_contents($configPath);
        if ($content === false) {
            error_log("[SwapService] Failed to read communication config: {$configPath}");
            $this->smsService = null;
            return;
        }
        
        $fullConfig = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[SwapService] Invalid JSON in communication config: " . json_last_error_msg());
            $this->smsService = null;
            return;
        }
        
        $smsConfig = $fullConfig['sms_gateway'] ?? $fullConfig;
        
        if (empty($smsConfig) || !($smsConfig['enabled'] ?? false)) {
            error_log("[SwapService] SMS service disabled in config for {$this->countryCode}");
            $this->smsService = null;
            return;
        }
        
        $this->smsService = new SmsNotificationService($this->swapDB, $smsConfig);
        error_log("[SwapService] SMS Service initialized from: {$configPath}");
    }

    private function getCardType(array $destination): string
    {
        if (isset($destination['card_type'])) {
            return $destination['card_type'];
        }
        
        $institution = $destination['institution'] ?? '';
        $messageBasedIssuers = $this->cardConfig['message_based_issuers'] ?? ['VOUCHMORPH'];
        
        if (in_array(strtoupper($institution), array_map('strtoupper', $messageBasedIssuers))) {
            return 'message_based';
        }
        
        return $this->cardConfig['default_card_type'] ?? 'balance_based';
    }

    private function shouldSkipDebit(array $destination): bool
    {
        $deliveryMode = $destination['delivery_mode'] ?? '';
        
        if (!in_array($deliveryMode, ['card_load', 'card'])) {
            return false;
        }
        
        $cardType = $this->getCardType($destination);
        $institution = $destination['institution'] ?? '';
        
        return ($cardType === 'message_based' && strtoupper($institution) === 'VOUCHMORPH');
    }

    private function getDispensableAmount(float $amount, string $currency): array
    {
        $denominations = $this->atmNotes[$currency] ?? null;
        if (!$denominations) {
            throw new RuntimeException("No ATM denominations for currency {$currency} in country {$this->countryCode}");
        }
        
        rsort($denominations);

        $remainingCents = (int)round($amount * 100);
        $dispensedNotes = [];
        $originalAmount = $remainingCents;

        foreach ($denominations as $note) {
            $noteCents = (int)round($note * 100);
            if ($noteCents <= 0) continue;

            $count = intdiv($remainingCents, $noteCents);
            if ($count > 0) {
                $dispensedNotes[(string)$note] = $count;
                $remainingCents -= $noteCents * $count;
            }
        }

        $dispensableCents = $originalAmount - $remainingCents;

        if ($dispensableCents === 0) {
            $dispensableCents = $this->findClosestDispensableAmount($originalAmount, $denominations);
            $remainingCents = $originalAmount - $dispensableCents;

            if ($dispensableCents > 0) {
                $tempRemaining = $dispensableCents;
                $dispensedNotes = [];
                foreach ($denominations as $note) {
                    $noteCents = (int)round($note * 100);
                    $count = intdiv($tempRemaining, $noteCents);
                    if ($count > 0) {
                        $dispensedNotes[(string)$note] = $count;
                        $tempRemaining -= $noteCents * $count;
                    }
                }
            }
        }

        return [
            'dispensable_amount' => round($dispensableCents / 100, 2),
            'notes' => $dispensedNotes,
            'undispensed_amount' => round($remainingCents / 100, 2),
            'denominations_used' => $denominations
        ];
    }

    private function findClosestDispensableAmount(int $targetCents, array $denominations): int
    {
        $denomCents = array_map(fn($d) => (int)round($d * 100), $denominations);
        sort($denomCents);

        $dp = array_fill(0, $targetCents + 1, false);
        $dp[0] = true;

        for ($i = 1; $i <= $targetCents; $i++) {
            foreach ($denomCents as $denom) {
                if ($i >= $denom && $dp[$i - $denom]) {
                    $dp[$i] = true;
                    break;
                }
            }
        }

        for ($i = $targetCents; $i >= 0; $i--) {
            if ($dp[$i]) return $i;
        }

        return 0;
    }

    private function validateCashoutAmount(float $amount, string $currency): array
    {
        $errors = [];

        try {
            $atmResult = $this->getDispensableAmount($amount, $currency);

            if ($atmResult['dispensable_amount'] <= 0) {
                $errors[] = "Amount cannot be dispensed with available denominations";
            }

            if ($atmResult['undispensed_amount'] > 0.01) {
                $errors[] = sprintf(
                    "Amount %0.2f %s cannot be dispensed exactly. Closest dispensable: %0.2f %s",
                    $amount,
                    $currency,
                    $atmResult['dispensable_amount'],
                    $currency
                );
            }

            if (!empty($atmResult['notes'])) {
                $breakdown = [];

                foreach ($atmResult['notes'] as $note => $count) {
                    $breakdown[] = "$count × $note";
                }

                $this->logEvent('CASHOUT_BREAKDOWN', 'INFO', [
                    'amount' => $amount,
                    'dispensable' => $atmResult['dispensable_amount'],
                    'breakdown' => implode(' + ', $breakdown)
                ]);
            }

        } catch (Exception $e) {
            $errors[] = "Cashout validation error: " . $e->getMessage();
        }

        return $errors;
    }
    
    private function formatPhoneForInstitution(?string $phone, array $participant): ?string
    {
        if ($phone === null) {
            return null;
        }
        
        $formatConfig = $participant['phone_format'] ?? null;
        
        if (!$formatConfig) {
            return $phone;
        }
        
        $digits = preg_replace('/\D/', '', $phone);
        
        $this->logEvent('PHONE_FORMATTING', 'INFO', [
            'institution' => $participant['provider_code'] ?? 'unknown',
            'original' => $phone,
            'digits' => $digits,
            'config' => $formatConfig
        ]);
        
        $formatted = $digits;
        $countryCode = $formatConfig['country_code'] ?? '';
        
        if (!empty($countryCode)) {
            if ($formatConfig['remove_country_code_for_local'] ?? false) {
                if (strpos($formatted, $countryCode) === 0) {
                    $formatted = substr($formatted, strlen($countryCode));
                }
            } elseif ($formatConfig['always_add_country_code'] ?? false) {
                if (strpos($formatted, $countryCode) !== 0) {
                    $formatted = $countryCode . $formatted;
                }
            }
        }
        
        if (isset($formatConfig['prefix'])) {
            $formatted = $formatConfig['prefix'] . $formatted;
        }
        
        $this->logEvent('PHONE_FORMATTED', 'INFO', [
            'institution' => $participant['provider_code'] ?? 'unknown',
            'formatted' => $formatted
        ]);
        
        return $formatted;
    }

    private function sanitizePhones(array $payload, ?array $participant = null): array
    {
        foreach (self::PHONE_FIELDS as $field) {
            if (isset($payload[$field]) && $participant) {
                $payload[$field] = $this->formatPhoneForInstitution($payload[$field], $participant);
            }
            
            foreach (['source', 'destination', 'ewallet', 'wallet', 'voucher', 'card', 'cashout'] as $nested) {
                if (isset($payload[$nested][$field]) && $participant) {
                    $payload[$nested][$field] = $this->formatPhoneForInstitution($payload[$nested][$field], $participant);
                }
            }
        }

        return $payload;
    }

    private function findInstitutionKey(string $search): ?string
    {
        $searchLower = strtolower($search);
        
        if (isset($this->participants[$searchLower])) {
            return $searchLower;
        }
        
        foreach ($this->participants as $key => $participant) {
            if (isset($participant['provider_code']) && 
                strtolower($participant['provider_code']) === $searchLower) {
                return $key;
            }
        }
        
        foreach (array_keys($this->participants) as $key) {
            if (strtolower($key) === $searchLower) {
                return $key;
            }
        }
        
        return null;
    }

    private function debugApiCall(string $type, array $payload, array $result): void
    {
        $debugData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'payload' => $payload,
            'result' => [
                'success' => $result['success'] ?? null,
                'status_code' => $result['status_code'] ?? null,
                'curl_error' => $result['curl_error'] ?? null,
                'data' => $result['data'] ?? null,
                'raw_response' => $result['raw_response'] ?? null
            ]
        ];
        
        file_put_contents(self::DEBUG_FILE, json_encode($debugData, JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);
        error_log("=== DEBUG WRITTEN TO " . self::DEBUG_FILE . " ===");
    }

    private function logApiMessage(
        string $messageId,
        string $messageType,
        string $direction,
        ?array $participant,
        string $endpoint,
        array $requestPayload,
        array $responseResult,
        ?int $durationMs = null
    ): void {
        try {
            $participantId = null;
            $participantName = null;
            
            if ($participant) {
                $participantId = $participant['participant_id'] ?? $participant['id'] ?? null;
                $participantName = $participant['name'] ?? $participant['provider_code'] ?? null;
            }
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO api_message_logs 
                (message_id, message_type, direction, participant_id, participant_name, 
                 endpoint, request_payload, response_payload, http_status_code, curl_error, 
                 success, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $messageId,
                $messageType,
                $direction,
                $participantId,
                $participantName,
                $endpoint,
                json_encode($requestPayload),
                json_encode($responseResult['data'] ?? $responseResult),
                $responseResult['status_code'] ?? null,
                $responseResult['curl_error'] ?? null,
                isset($responseResult['success']) && $responseResult['success'] === true ? true : false,
                $durationMs
            ]);
        } catch (Exception $e) {
            error_log("Failed to log API message: " . $e->getMessage());
        }
    }

    private function recordHoldTransaction(
        string $swapRef,
        array $holdResult,
        array $source,
        array $participant,
        ?array $destination = null
    ): void {
        try {
            $participantId = $participant['participant_id'] ?? $participant['id'] ?? null;
            $participantName = $participant['name'] ?? $participant['provider_code'] ?? null;
            
            $destinationParticipantId = null;
            if ($destination && isset($destination['institution'])) {
                $stmt = $this->swapDB->prepare("
                    SELECT participant_id FROM participants 
                    WHERE name = ? OR provider_code = ?
                    LIMIT 1
                ");
                $stmt->execute([$destination['institution'], $destination['institution']]);
                $destParticipant = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($destParticipant) {
                    $destinationParticipantId = $destParticipant['participant_id'];
                }
            }
            
            $stmt = $this->swapDB->prepare("
                INSERT INTO hold_transactions 
                (hold_reference, swap_reference, participant_id, participant_name,
                 asset_type, amount, currency, hold_expiry, source_details, 
                 destination_institution, destination_participant_id, metadata, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
            ");
            
            $stmt->execute([
                $holdResult['hold_reference'],
                $swapRef,
                $participantId,
                $participantName,
                $source['asset_type'],
                $source['amount'],
                $source['currency'] ?? 'BWP',
                $holdResult['hold_expiry'] ?? null,
                json_encode($this->maskIdentifier($source)),
                $destination['institution'] ?? null,
                $destinationParticipantId,
                json_encode([
                    'source_institution' => $source['institution'],
                    'source_asset_type' => $source['asset_type'],
                    'destination_institution' => $destination['institution'] ?? null,
                ])
            ]);
        } catch (Exception $e) {
            error_log("Failed to record hold transaction: " . $e->getMessage());
        }
    }

    private function updateHoldStatus(string $holdReference, string $status): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE hold_transactions 
                SET status = ?, updated_at = NOW(), 
                    {$status}_at = NOW()
                WHERE hold_reference = ?
            ");
            $stmt->execute([$status, $holdReference]);
        } catch (Exception $e) {
            error_log("Failed to update hold status: " . $e->getMessage());
        }
    }

    private function getFeeKey(string $transactionType): string
    {
        $map = [
            'CASHOUT' => 'CASHOUT_SWAP_FEE',
            'DEPOSIT' => 'DEPOSIT_SWAP_FEE',
            'CARD_LOAD' => 'CARD_LOAD_FEE',
            'CARD_ISSUANCE' => 'CARD_ISSUANCE_FEE'
        ];
        
        return $map[$transactionType] ?? 'DEPOSIT_SWAP_FEE';
    }

    /**
     * Deduct swap fee using configuration - NO HARDCODING
     */
    private function deductSwapFee(
        string $swapRef,
        string $transactionType,
        float $grossAmount,
        string $sourceInstitution,
        string $destinationInstitution
    ): array {
        
        $feeKey = $this->getFeeKey($transactionType);
        $feeConfig = $this->feesConfig['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            throw new RuntimeException("Fee configuration not found for {$feeKey} in country {$this->countryCode}");
        }
        
        if (!isset($feeConfig['total_amount'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'total_amount' in config");
        }
        
        if (!isset($feeConfig['split'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'split' configuration");
        }
        
        $totalFee = (float)$feeConfig['total_amount'];
        $split = $feeConfig['split'];
        $currency = $feeConfig['currency'] ?? 'BWP';
        
        $vatRate = isset($this->feesConfig['regulatory']['vat_rate']) ? (float)$this->feesConfig['regulatory']['vat_rate'] : 0;
        $vatAmount = $totalFee * $vatRate;
        
        $netAmount = $grossAmount - $totalFee;
        
        if ($netAmount <= 0) {
            throw new RuntimeException("Amount after fee deduction must be positive. Gross: {$grossAmount}, Fee: {$totalFee}");
        }
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_fee_collections
            (swap_reference, fee_type, total_amount, currency, 
             source_institution, destination_institution,
             split_config, vat_amount, status)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 'COLLECTED')
            RETURNING fee_id
        ");
        
        $stmt->execute([
            $swapRef,
            $feeKey,
            $totalFee,
            $currency,
            $sourceInstitution,
            $destinationInstitution,
            json_encode($split),
            $vatAmount
        ]);
        
        $feeId = $stmt->fetchColumn();
        
        $stmt = $this->swapDB->prepare("
            UPDATE swap_ledgers 
            SET swap_fee = :fee
            WHERE swap_reference = :ref
        ");
        
        $stmt->execute([
            ':fee' => $totalFee,
            ':ref' => $swapRef
        ]);
        
        $this->logEvent($swapRef, 'FEE_DEDUCTED', [
            'gross' => $grossAmount,
            'fee' => $totalFee,
            'net' => $netAmount,
            'fee_id' => $feeId,
            'split' => $split,
            'fee_key' => $feeKey,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount
        ]);
        
        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'fee_id' => $feeId,
            'split' => $split,
            'fee_key' => $feeKey
        ];
    }

    /**
     * Calculate fees without deducting - NO HARDCODING
     */
    private function calculateFees(string $swapRef, string $transactionType, float $grossAmount): array
    {
        $feeKey = $this->getFeeKey($transactionType);
        $feeConfig = $this->feesConfig['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            throw new RuntimeException("Fee configuration not found for {$feeKey} in country {$this->countryCode}");
        }
        
        if (!isset($feeConfig['total_amount'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'total_amount' in config");
        }
        
        if (!isset($feeConfig['split'])) {
            throw new RuntimeException("Fee {$feeKey} missing 'split' configuration");
        }
        
        $totalFee = (float)$feeConfig['total_amount'];
        $split = $feeConfig['split'];
        
        $vatRate = isset($this->feesConfig['regulatory']['vat_rate']) ? (float)$this->feesConfig['regulatory']['vat_rate'] : 0;
        $vatAmount = $totalFee * $vatRate;
        
        $netAmount = $grossAmount - $totalFee;
        
        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'split' => $split,
            'fee_type' => $feeKey,
            'vat_rate' => $vatRate
        ];
    }

    /**
     * Verify source asset with institution
     */
    private function verifySourceAsset(string $swapRef, array $source, array $participant): array
    {
        $assetType = strtoupper($source['asset_type'] ?? 'UNKNOWN');

        $this->logEvent($swapRef, 'VERIFYING_SOURCE', [
            'institution' => $participant['provider_code'] ?? $source['institution'],
            'asset_type' => $assetType,
            'reference' => $this->maskIdentifier($source)
        ]);

        $walletTypes = array_map('strtoupper', $participant['capabilities']['wallet_types'] ?? []);
        if (!in_array($assetType, $walletTypes)) {
            throw new RuntimeException("Institution does not support asset type: {$assetType}");
        }

        $bankClient = new GenericBankClient($participant);

        $verificationPayload = [
            'reference' => $swapRef,
            'institution' => $source['institution'],
            'asset_type' => $assetType,
            'amount' => $source['amount'] ?? 0
        ];

        switch ($assetType) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'claimant_phone' => $this->formatPhoneForInstitution($voucher['claimant_phone'] ?? null, $participant),
                    'voucher_number' => $voucher['voucher_number'] ?? null,
                    'voucher_pin' => $voucher['voucher_pin'] ?? null
                ]);
                break;

            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'account_holder' => $account['account_holder'] ?? null,
                    'account_number' => $account['account_number'] ?? null,
                    'account_pin' => $account['account_pin'] ?? null
                ]);
                break;

            case 'WALLET':
            case 'E-WALLET':
                $phone = $source['ewallet']['ewallet_phone'] ??
                         $source['ewallet']['phone'] ??
                         $source['wallet']['wallet_phone'] ??
                         $source['wallet']['phone'] ??
                         $source['ewallet_phone'] ??
                         $source['wallet_phone'] ??
                         $source['phone'] ??
                         null;

                if (!$phone) {
                    throw new RuntimeException("Phone number required for WALLET/E-WALLET verification");
                }

                $verificationPayload = array_merge($verificationPayload, [
                    'phone' => $this->formatPhoneForInstitution($phone, $participant)
                ]);
                break;

            case 'CARD':
                $card = $source['card'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'card_number' => $card['card_number'] ?? null,
                    'card_pin' => $card['card_pin'] ?? null,
                    'card_holder' => $card['card_holder'] ?? null
                ]);
                break;

            default:
                throw new RuntimeException("Unsupported asset type: {$assetType}");
        }

        try {
            $result = $bankClient->verifyAsset($verificationPayload);
            
            $this->debugApiCall('verify_asset', $verificationPayload, $result);
            
            $this->logApiMessage(
                $swapRef,
                'verify_asset',
                'outgoing',
                $participant,
                '/api/verify-asset',
                $verificationPayload,
                $result,
                null
            );
            
            if (!isset($result['success']) || $result['success'] !== true) {
                return [
                    'verified' => false,
                    'message' => 'Bank communication failed: ' . ($result['curl_error'] ?? 'HTTP ' . ($result['status_code'] ?? 'unknown'))
                ];
            }

            $bankResponse = $result['data'] ?? [];

            if (!isset($bankResponse['verified']) || $bankResponse['verified'] !== true) {
                $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Verification failed';
                return [
                    'verified' => false,
                    'message' => $errorMessage
                ];
            }

            return [
                'verified' => true,
                'asset_details' => [
                    'id' => $bankResponse['asset_id'] ?? $bankResponse['wallet_id'] ?? $bankResponse['account_id'] ?? null,
                    'available_balance' => $bankResponse['available_balance'] ?? $bankResponse['balance'] ?? null,
                    'holder_name' => $bankResponse['holder_name'] ?? $bankResponse['account_holder'] ?? null,
                    'expiry_date' => $bankResponse['expiry_date'] ?? null,
                    'metadata' => $bankResponse['metadata'] ?? []
                ]
            ];
            
        } catch (\Throwable $e) {
            throw new RuntimeException("Source verification failed: " . $e->getMessage());
        }
    }

    /**
     * Place hold on source asset
     */
    private function placeHoldOnSourceAsset(string $swapRef, array $source, array $verificationResult, array $participant, array $destination): array
    {
        $this->logEvent($swapRef, 'PLACING_HOLD', [
            'institution' => $participant['provider_code'] ?? $source['institution'],
            'amount' => $source['amount'],
            'asset_type' => $source['asset_type']
        ]);

        $bankClient = new GenericBankClient($participant);
        
        $holdPayload = [
            'reference' => $swapRef,
            'asset_type' => $source['asset_type'],
            'asset_id' => $verificationResult['asset_details']['id'] ?? null,
            'amount' => $source['amount'],
            'currency' => $source['currency'] ?? 'BWP',
            'expiry_hours' => self::HOLD_EXPIRY_HOURS,
            'reason' => 'Swap transaction'
        ];

        switch ($source['asset_type']) {
            case 'VOUCHER':
                $voucher = $source['voucher'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'voucher_number' => $voucher['voucher_number'] ?? null,
                    'voucher_pin' => $voucher['voucher_pin'] ?? null,
                    'claimant_phone' => $this->formatPhoneForInstitution($voucher['claimant_phone'] ?? null, $participant)
                ]);
                break;

            case 'ACCOUNT':
                $account = $source['account'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'account_number' => $account['account_number'] ?? null,
                    'account_pin' => $account['account_pin'] ?? null
                ]);
                break;

            case 'WALLET':
            case 'E-WALLET':
                $phone = $source['ewallet']['phone'] ?? 
                         $source['phone'] ?? 
                         $source['ewallet_phone'] ?? 
                         $source['wallet']['wallet_phone'] ??
                         null;
                $holdPayload = array_merge($holdPayload, [
                    'phone' => $this->formatPhoneForInstitution($phone, $participant)
                ]);
                break;

            case 'CARD':
                $card = $source['card'] ?? [];
                $holdPayload = array_merge($holdPayload, [
                    'card_number' => $card['card_number'] ?? null,
                    'card_pin' => $card['card_pin'] ?? null
                ]);
                break;
        }

        try {
            $result = $bankClient->placeHold($holdPayload);
            
            $this->debugApiCall('place_hold', $holdPayload, $result);
            
            $this->logApiMessage(
                $swapRef,
                'place_hold',
                'outgoing',
                $participant,
                '/api/place-hold',
                $holdPayload,
                $result,
                null
            );

            if (!isset($result['success']) || $result['success'] !== true) {
                $errorMsg = 'Bank communication failed';
                if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                    $errorMsg .= ': ' . $result['curl_error'];
                } elseif (isset($result['status_code'])) {
                    $errorMsg .= ': HTTP ' . $result['status_code'];
                }
                return [
                    'hold_placed' => false,
                    'message' => $errorMsg
                ];
            }

            $bankResponse = $result['data'] ?? [];

            if (!isset($bankResponse['hold_placed']) || $bankResponse['hold_placed'] !== true) {
                $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Hold placement failed';
                return [
                    'hold_placed' => false,
                    'message' => $errorMessage
                ];
            }

            $holdResult = [
                'hold_placed' => true,
                'hold_reference' => $bankResponse['hold_reference'] ?? $swapRef . '-HOLD',
                'hold_expiry' => $bankResponse['hold_expiry'] ?? date('Y-m-d H:i:s', strtotime('+' . self::HOLD_EXPIRY_HOURS . ' hours'))
            ];

            $this->recordHoldTransaction($swapRef, $holdResult, $source, $participant, $destination);
            
            $this->logEvent($swapRef, 'HOLD_PLACED', [
                'hold_reference' => $holdResult['hold_reference'],
                'expiry' => $holdResult['hold_expiry']
            ]);

            return $holdResult;

        } catch (\Throwable $e) {
            throw new RuntimeException("Hold placement failed: " . $e->getMessage());
        }
    }

    /**
     * Execute swap with unified payload structure
     */
    public function executeSwap(array $payload): array
    { 
        $this->swapDB->beginTransaction();
        
        $holdResult = null;
        $bankClient = null;
        $swapRef = bin2hex(random_bytes(16));
        $atmResult = null;
        $cashoutResult = null;
        
        $debugSteps = [];
        $debugSteps[] = ['time' => microtime(true), 'step' => 'START', 'swap_ref' => $swapRef];
        
        try {
            if (empty($payload['source']['institution'])) {
                throw new RuntimeException("Source institution is required");
            }

            if (empty($payload['destination']['institution'])) {
                throw new RuntimeException("Destination institution is required");
            }

            $currency = $payload['currency'] ?? 'BWP';
            $source = $payload['source'];
            $destination = $payload['destination'];
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'PAYLOAD_VALIDATED', 'source' => $source['institution'], 'dest' => $destination['institution']];

            $sourceInstitutionKey = $this->findInstitutionKey($source['institution']);
            if (!$sourceInstitutionKey) {
                throw new RuntimeException("Source institution not found: {$source['institution']}");
            }
            $sourceParticipant = $this->participants[$sourceInstitutionKey];
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'SOURCE_FOUND', 'participant' => $sourceParticipant['provider_code'] ?? $sourceInstitutionKey];
            
            $source = $this->sanitizePhones($source, $sourceParticipant);

            if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
                $errors = $this->validateCashoutAmount((float)$destination['amount'], $currency);
                if (!empty($errors)) {
                    throw new RuntimeException("Cashout validation failed: " . implode(', ', $errors));
                }
                $atmResult = $this->getDispensableAmount((float)$destination['amount'], $currency);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'CASHOUT_VALIDATED', 'dispensable' => $atmResult['dispensable_amount']];
            }

            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_VERIFY_ASSET'];
            $verificationResult = $this->verifySourceAsset($swapRef, $source, $sourceParticipant);
            $debugSteps[] = ['time' => microtime(true), 'step' => 'VERIFY_ASSET_COMPLETE', 'verified' => $verificationResult['verified']];

            if (!$verificationResult['verified']) {
                throw new RuntimeException(
                    "Source verification failed: " . 
                    ($verificationResult['message'] ?? 'Invalid credentials')
                );
            }
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PLACE_HOLD'];
            $holdResult = $this->placeHoldOnSourceAsset($swapRef, $source, $verificationResult, $sourceParticipant, $destination);
            $bankClient = new GenericBankClient($sourceParticipant);
            
            $debugSteps[] = ['time' => microtime(true), 'step' => 'PLACE_HOLD_COMPLETE', 
                            'hold_placed' => $holdResult['hold_placed'], 
                            'hold_reference' => $holdResult['hold_reference'] ?? null];
            
            if (!$holdResult['hold_placed']) {
                throw new RuntimeException("Failed to place hold: " . ($holdResult['message'] ?? 'Unknown error'));
            }

            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_RECORD_SWAP'];
            $swapId = $this->recordMasterSwap(
                $swapRef,
                $source,
                $destination,
                $currency,
                $verificationResult
            );
            $debugSteps[] = ['time' => microtime(true), 'step' => 'RECORD_SWAP_COMPLETE', 'swap_id' => $swapId];

            $debugSteps[] = ['time' => microtime(true), 'step' => 'BEFORE_PROCESS_DESTINATION', 
                            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit'];

            if (isset($destination['delivery_mode'])) {
                switch ($destination['delivery_mode']) {
                    case 'card_load':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_CARD_LOAD'];
                        $this->processCardLoad($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_COMPLETE'];
                        break;
                        
                    case 'card':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_CARD_ISSUANCE'];
                        $this->processCardIssuance($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_COMPLETE'];
                        break;
                        
                    case 'cashout':
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_CASHOUT'];
                        $cashoutResult = $this->processCashout($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_CASHOUT_COMPLETE'];
                        break;
                        
                    case 'deposit':
                    default:
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_DEPOSIT'];
                        $this->processDeposit($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                        $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_DEPOSIT_COMPLETE'];
                        break;
                }
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_DEPOSIT'];
                $this->processDeposit($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_DEPOSIT_COMPLETE'];
            }

            $debugSteps[] = ['time' => microtime(true), 'step' => 'CHECKING_IF_DEBIT_NEEDED'];
            
            $skipDebit = $this->shouldSkipDebit($destination);
            
            if (!$skipDebit) {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_DEBIT_FUNDS'];
                $this->debitSourceFunds($swapRef, $source, $holdResult, $sourceParticipant);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'DEBIT_FUNDS_COMPLETE'];
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'SKIP_DEBIT_FOR_MESSAGE_CARD'];
                $this->logEvent($swapRef, 'HOLD_MAINTAINED', [
                    'hold_reference' => $holdResult['hold_reference'],
                    'reason' => 'Message-based card - funds remain at source until usage'
                ]);
            }

            if ($atmResult && $atmResult['undispensed_amount'] > 0.01) {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'HANDLE_UNDISPENSED', 
                                'amount' => $atmResult['undispensed_amount']];
                $this->handleUndispensedAmount(
                    $source['institution'],
                    $destination,
                    $atmResult['undispensed_amount'],
                    $swapRef
                );
            }

            $this->swapDB->commit();
            $debugSteps[] = ['time' => microtime(true), 'step' => 'TRANSACTION_COMMITTED'];
            
            $this->logEvent($swapRef, 'SWAP_EXECUTED', [
                'status' => 'SUCCESS',
                'source_type' => $source['asset_type'],
                'destination_type' => $destination['asset_type'],
                'skip_debit' => $skipDebit,
                'debug_steps' => $debugSteps
            ]);

            $response = [
                'status' => 'success',
                'swap_reference' => $swapRef,
                'message' => 'Swap initiated successfully',
                'hold_reference' => $holdResult['hold_reference'] ?? null,
                'debug' => $debugSteps
            ];

            // Add cashout response data if applicable
            if ($cashoutResult) {
                $response['withdrawal_code'] = $cashoutResult['generated_code'];
                $response['sat_number'] = $cashoutResult['sat_number'] ?? null;
                $response['token_reference'] = $cashoutResult['token_reference'];
                $response['expires_at'] = $cashoutResult['expires_at'];
                $response['message'] = 'Cashout code generated by ' . $destination['institution'];
            }

            if (isset($destination['delivery_mode'])) {
                $metadata = $this->getSwapMetadata($swapRef);
                
                if ($destination['delivery_mode'] === 'card') {
                    if (isset($metadata['card_issuance_result'])) {
                        $response['card_details'] = $metadata['card_issuance_result'];
                        $response['message'] = 'Card issued successfully';
                    }
                } elseif ($destination['delivery_mode'] === 'card_load') {
                    if (isset($metadata['card_load_result']) || isset($metadata['card_authorization'])) {
                        $cardInfo = $metadata['card_load_result'] ?? $metadata['card_authorization'];
                        $response['card_details'] = $cardInfo;
                        
                        $cardType = $this->getCardType($destination);
                        if ($cardType === 'message_based') {
                            $response['message'] = 'Card authorized successfully - funds remain at source until usage';
                        } else {
                            $response['message'] = 'Card loaded successfully';
                        }
                    }
                }
            }

            if (!isset($response['card_details']) && $atmResult && !$cashoutResult) {
                $response['dispensed_notes'] = $atmResult['notes'] ?? [];
            }

            return $response;

        } catch (Exception $e) {
            $debugSteps[] = ['time' => microtime(true), 'step' => 'EXCEPTION_CAUGHT', 
                            'error' => $e->getMessage(), 
                            'trace' => $e->getTraceAsString()];
            
            if ($holdResult && $holdResult['hold_placed'] && $bankClient) {
                try {
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'RELEASING_HOLD'];
                    $bankClient->releaseHold([
                        'hold_reference' => $holdResult['hold_reference'],
                        'reason' => 'Transaction failed: ' . $e->getMessage()
                    ]);
                    $this->logEvent($swapRef, 'HOLD_RELEASED', ['reason' => $e->getMessage()]);
                    if (isset($holdResult['hold_reference'])) {
                        $this->updateHoldStatus($holdResult['hold_reference'], 'RELEASED');
                    }
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'HOLD_RELEASED'];
                } catch (Exception $releaseError) {
                    $debugSteps[] = ['time' => microtime(true), 'step' => 'HOLD_RELEASE_FAILED', 
                                    'error' => $releaseError->getMessage()];
                    error_log("Failed to release hold: " . $releaseError->getMessage());
                }
            }
            
            if ($this->swapDB->inTransaction()) {
                $this->swapDB->rollBack();
                $debugSteps[] = ['time' => microtime(true), 'step' => 'TRANSACTION_ROLLED_BACK'];
            }
            
            $this->logEvent($swapRef ?? 'N/A', 'SWAP_FAILED', [
                'error' => $e->getMessage(),
                'debug_steps' => $debugSteps
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'debug' => $debugSteps
            ];
        }
    }

    /**
     * Debit source funds after successful delivery
     */
    private function debitSourceFunds(string $swapRef, array $source, array $holdResult, array $participant): void
    {
        $this->logEvent($swapRef, 'DEBITING_FUNDS', [
            'hold_reference' => $holdResult['hold_reference']
        ]);

        $bankClient = new GenericBankClient($participant);
        
        $debitPayload = [
            'reference' => $swapRef,
            'hold_reference' => $holdResult['hold_reference'],
            'amount' => $source['amount'],
            'reason' => 'Swap completed'
        ];

        $result = $bankClient->debitHold($debitPayload);
        
        $this->debugApiCall('debit_hold', $debitPayload, $result);
        
        $this->logApiMessage(
            $swapRef,
            'debit_hold',
            'outgoing',
            $participant,
            '/api/debit-hold',
            $debitPayload,
            $result,
            null
        );

        if (!isset($result['success']) || $result['success'] !== true) {
            $errorMsg = 'Failed to debit funds';
            if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (isset($result['status_code'])) {
                $errorMsg .= ': HTTP ' . $result['status_code'];
            }
            throw new RuntimeException($errorMsg);
        }

        $bankResponse = $result['data'] ?? [];

        if (!isset($bankResponse['debited']) || $bankResponse['debited'] !== true) {
            $errorMessage = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Debit failed';
            throw new RuntimeException("Failed to debit funds: " . $errorMessage);
        }

        $this->updateHoldStatus($holdResult['hold_reference'], 'DEBITED');
        
        $this->logEvent($swapRef, 'FUNDS_DEBITED', [
            'hold_reference' => $holdResult['hold_reference']
        ]);
    }

    /**
     * Process card load
     */
    private function processCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_START'];
        
        try {
            $grossAmount = (float)$destination['amount'];
            $cardType = $this->getCardType($destination);
            
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_TYPE_DETERMINED', 
                       'type' => $cardType, 
                       'institution' => $destination['institution']];
            
            if ($cardType === 'balance_based') {
                $this->processBalanceBasedCardLoad($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            } else {
                $this->processMessageBasedCardLoad($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            }
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_LOAD_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CARD LOAD ERROR: " . json_encode($debug));
            throw $e;
        }
    }

    /**
     * Process balance-based card load
     */
    private function processBalanceBasedCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_START'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'CARD_LOAD',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        $debug[] = ['time' => microtime(true), 'step' => 'FEE_DEDUCTED', 'fee' => $feeDetails['fee_amount'], 'net' => $netAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardSuffix = $destination['card_suffix'] ?? null;
        if (!$cardSuffix) {
            throw new RuntimeException("card_suffix is required for card_load");
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'LOADING_BALANCE_CARD', 'card_suffix' => $cardSuffix];
        
        $cardResult = $this->cardService->loadCard([
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'load_type' => 'balance'
        ]);
        
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_LOADED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? $cardSuffix,
                   'new_balance' => $cardResult['new_balance'] ?? null];
        
        $this->updateSwapMetadata($swapRef, [
            'card_load_result' => [
                'card_suffix' => $cardSuffix,
                'amount' => $netAmount,
                'new_balance' => $cardResult['new_balance'] ?? null,
                'card_type' => 'balance_based'
            ]
        ]);
        
        $this->logEvent($swapRef, 'BALANCE_CARD_LOADED', [
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount
        ]);
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'card_load');
    }

    /**
     * Process message-based card load
     */
    private function processMessageBasedCardLoad(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_START'];
        
        $feeDetails = $this->calculateFees($swapRef, 'CARD_LOAD', $grossAmount);
        $netAmount = $feeDetails['net_amount'];
        
        $debug[] = ['time' => microtime(true), 'step' => 'FEES_CALCULATED', 
                    'fee' => $feeDetails['fee_amount'], 
                    'net' => $netAmount,
                    'gross' => $grossAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardSuffix = $destination['card_suffix'] ?? null;
        if (!$cardSuffix) {
            throw new RuntimeException("card_suffix is required for card_load");
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'AUTHORIZING_MESSAGE_CARD', 'card_suffix' => $cardSuffix];
        
        $authData = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'authorized_amount' => $netAmount,
            'gross_amount' => $grossAmount,
            'fee_details' => $feeDetails,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'],
            'expiry_days' => self::MESSAGE_CARD_EXPIRY_DAYS,
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution'],
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeDetails['fee_amount']
            ]
        ];
        
        if (!method_exists($this->cardService, 'authorizeCardLoad')) {
            error_log("[CRITICAL] authorizeCardLoad method missing in CardService!");
            
            if (method_exists($this->cardService, 'loadCard')) {
                error_log("[FALLBACK] Using loadCard instead");
                $cardResult = $this->cardService->loadCard([
                    'hold_reference' => $holdResult['hold_reference'],
                    'swap_reference' => $swapRef,
                    'card_suffix' => $cardSuffix,
                    'amount' => $netAmount
                ]);
                
                $cardResult['authorized'] = true;
                $cardResult['authorized_amount'] = $netAmount;
                $cardResult['gross_amount'] = $grossAmount;
                $cardResult['status'] = 'AUTHORIZED';
            } else {
                throw new RuntimeException("Card service has neither authorizeCardLoad nor loadCard methods");
            }
        } else {
            $cardResult = $this->cardService->authorizeCardLoad($authData);
        }
        
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_AUTHORIZED', 
                   'card_suffix' => $cardSuffix,
                   'result' => $cardResult];
        
        $this->recordCardAuthorization($swapId, $swapRef, $cardSuffix, $netAmount, $holdResult, $feeDetails);
        
        $this->updateSwapMetadata($swapRef, [
            'card_authorization' => [
                'card_suffix' => $cardSuffix,
                'authorized_amount' => $netAmount,
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeDetails['fee_amount'],
                'hold_reference' => $holdResult['hold_reference'],
                'status' => 'AUTHORIZED',
                'card_type' => 'message_based',
                'fee_details' => $feeDetails
            ]
        ]);
        
        $this->logEvent($swapRef, 'MESSAGE_CARD_AUTHORIZED', [
            'card_suffix' => $cardSuffix,
            'amount' => $netAmount,
            'gross_amount' => $grossAmount,
            'fee' => $feeDetails['fee_amount'],
            'hold_reference' => $holdResult['hold_reference']
        ]);
    }

    /**
     * Process card issuance
     */
    private function processCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_START'];
        
        try {
            $grossAmount = (float)$destination['amount'];
            $cardType = $this->getCardType($destination);
            
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_TYPE_DETERMINED', 
                       'type' => $cardType, 
                       'institution' => $destination['institution']];
            
            if ($cardType === 'balance_based') {
                $this->processBalanceBasedCardIssuance($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            } else {
                $this->processMessageBasedCardIssuance($swapId, $swapRef, $source, $destination, $currency, $holdResult, $grossAmount);
            }
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CARD_ISSUANCE_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CARD ISSUANCE ERROR: " . json_encode($debug));
            throw $e;
        }
    }

    /**
     * Process balance-based card issuance
     */
    private function processBalanceBasedCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_ISSUANCE_START'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'CARD_ISSUANCE',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        $debug[] = ['time' => microtime(true), 'step' => 'FEE_DEDUCTED', 'fee' => $feeDetails['fee_amount'], 'net' => $netAmount];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardData = $destination['card'] ?? [];
        $cardholderName = $cardData['cardholder_name'] ?? 
                         $destination['beneficiary_name'] ?? 
                         $source['cardholder_name'] ?? 
                         'Cardholder';
        
        $debug[] = ['time' => microtime(true), 'step' => 'ISSUING_BALANCE_CARD', 'cardholder' => $cardholderName];
        
        $cardPayload = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'cardholder_name' => $cardholderName,
            'initial_amount' => $netAmount,
            'daily_limit' => $cardData['daily_limit'] ?? null,
            'monthly_limit' => $cardData['monthly_limit'] ?? null,
            'atm_daily_limit' => $cardData['atm_daily_limit'] ?? null,
            'issued_by' => 'swap_service',
            'card_type' => 'balance_based',
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution']
            ]
        ];
        
        $cardResult = $this->cardService->issueCard($cardPayload);
        
        $debug[] = ['time' => microtime(true), 'step' => 'BALANCE_CARD_ISSUED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4)];
        
        $this->updateSwapMetadata($swapRef, [
            'card_issuance_result' => [
                'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
                'expiry' => $cardResult['expiry'] ?? null,
                'amount' => $netAmount,
                'card_id' => $cardResult['card_id'] ?? null,
                'card_type' => 'balance_based'
            ]
        ]);
        
        $this->logEvent($swapRef, 'BALANCE_CARD_ISSUED', [
            'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
            'amount' => $netAmount
        ]);
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'card_issuance');
    }

    /**
     * Process message-based card issuance
     */
    private function processMessageBasedCardIssuance(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult, float $grossAmount): void
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_ISSUANCE_START'];
        
        $feeDetails = $this->calculateFees($swapRef, 'CARD_ISSUANCE', $grossAmount);
        $debug[] = ['time' => microtime(true), 'step' => 'FEES_CALCULATED', 'fee' => $feeDetails['fee_amount']];
        
        if (!$this->cardService) {
            throw new RuntimeException("Card service not available");
        }
        
        $cardData = $destination['card'] ?? [];
        $cardholderName = $cardData['cardholder_name'] ?? 
                         $destination['beneficiary_name'] ?? 
                         $source['cardholder_name'] ?? 
                         'Cardholder';
        
        $debug[] = ['time' => microtime(true), 'step' => 'ISSUING_MESSAGE_CARD', 'cardholder' => $cardholderName];
        
        $cardPayload = [
            'hold_reference' => $holdResult['hold_reference'],
            'swap_reference' => $swapRef,
            'cardholder_name' => $cardholderName,
            'authorized_amount' => $grossAmount,
            'daily_limit' => $cardData['daily_limit'] ?? null,
            'monthly_limit' => $cardData['monthly_limit'] ?? null,
            'atm_daily_limit' => $cardData['atm_daily_limit'] ?? null,
            'issued_by' => 'swap_service',
            'card_type' => 'message_based',
            'status' => 'AUTHORIZED',
            'expiry' => date('Y-m-d H:i:s', strtotime('+' . self::MESSAGE_CARD_EXPIRY_DAYS . ' days')),
            'metadata' => [
                'source_institution' => $source['institution'],
                'source_asset_type' => $source['asset_type'],
                'destination_institution' => $destination['institution'],
                'fee_details' => $feeDetails
            ]
        ];
        
        $cardResult = $this->cardService->issueCard($cardPayload);
        
        $debug[] = ['time' => microtime(true), 'step' => 'MESSAGE_CARD_ISSUED', 
                   'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4)];
        
        $this->recordCardAuthorization($swapId, $swapRef, $cardResult['card_suffix'] ?? '', $grossAmount, $holdResult, $feeDetails);
        
        $this->updateSwapMetadata($swapRef, [
            'card_issuance_result' => [
                'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
                'expiry' => $cardResult['expiry'] ?? null,
                'authorized_amount' => $grossAmount,
                'card_id' => $cardResult['card_id'] ?? null,
                'card_type' => 'message_based',
                'status' => 'AUTHORIZED'
            ]
        ]);
        
        $this->logEvent($swapRef, 'MESSAGE_CARD_ISSUED', [
            'card_suffix' => $cardResult['card_suffix'] ?? substr($cardResult['card_number'] ?? '', -4),
            'amount' => $grossAmount
        ]);
    }

    private function recordCardAuthorization(int $swapId, string $swapRef, string $cardSuffix, float $amount, array $holdResult, array $feeDetails): void
    {
        $expiryDays = $this->cardConfig['authorization_expiry_days'] ?? self::MESSAGE_CARD_EXPIRY_DAYS;
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO card_authorizations 
            (swap_id, swap_reference, card_suffix, authorized_amount, 
             remaining_balance, hold_reference, source_institution, 
             fee_amount, vat_amount, status, expiry_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW() + (? || ' days')::INTERVAL, NOW())
        ");
        
        $stmt->execute([
            $swapId,
            $swapRef,
            $cardSuffix,
            $amount,
            $amount,
            $holdResult['hold_reference'],
            $source['institution'] ?? 'SACCUSSALIS',
            $feeDetails['fee_amount'],
            $feeDetails['vat_amount'],
            $expiryDays
        ]);
    }

    /**
     * Process cashout to ATM/Agent - UPDATED to use destination institution's generated code
     */
    private function processCashout(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): array
    {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'CASHOUT_START'];
        
        try {
            $grossAmount = (float)$destination['amount'];
            $debug[] = ['time' => microtime(true), 'step' => 'GROSS_AMOUNT', 'amount' => $grossAmount];
            
            $feeDetails = $this->deductSwapFee(
                $swapRef,
                'CASHOUT',
                $grossAmount,
                $source['institution'],
                $destination['institution']
            );
            $debug[] = ['time' => microtime(true), 'step' => 'FEE_DEDUCTED', 'fee' => $feeDetails['fee_amount'], 'net' => $feeDetails['net_amount']];
            
            $netAmount = $feeDetails['net_amount'];
            $cashoutData = $destination['cashout'] ?? [];
            
            $destInstitutionKey = $this->findInstitutionKey($destination['institution']);
            if (!$destInstitutionKey) {
                throw new RuntimeException("Destination institution not found: {$destination['institution']}");
            }
            $destParticipant = $this->participants[$destInstitutionKey];
            $debug[] = ['time' => microtime(true), 'step' => 'DEST_FOUND', 'participant' => $destParticipant['provider_code'] ?? $destInstitutionKey];
            
            if (isset($cashoutData['beneficiary_phone'])) {
                $originalPhone = $cashoutData['beneficiary_phone'];
                $cashoutData['beneficiary_phone'] = $this->formatPhoneForInstitution(
                    $cashoutData['beneficiary_phone'], $destParticipant
                );
                $debug[] = ['time' => microtime(true), 'step' => 'PHONE_FORMATTED', 'original' => $originalPhone, 'formatted' => $cashoutData['beneficiary_phone']];
            }
            
            $debug[] = ['time' => microtime(true), 'step' => 'REQUESTING_TOKEN_FROM_DESTINATION'];
            
            // Request token from destination institution - it will generate its own code
            $tokenResult = $this->requestTokenFromDestination(
                $swapRef, 
                $source, 
                $destination, 
                $holdResult, 
                $destParticipant, 
                $netAmount
            );
            $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_RECEIVED_FROM_DESTINATION'];
            
            // Extract the generated code from destination's response
            $generatedCode = $tokenResult['generated_code'] ?? $tokenResult['pin'] ?? null;
            $satNumber = $tokenResult['sat_number'] ?? $tokenResult['token_reference'] ?? null;
            
            if (!$generatedCode) {
                throw new RuntimeException("Destination institution did not return a withdrawal code");
            }
            
            $debug[] = ['time' => microtime(true), 'step' => 'CODE_FROM_DESTINATION', 'code_suffix' => substr($generatedCode, -4)];
            
            $this->storeDestinationToken($swapId, $tokenResult);
            
            $debug[] = ['time' => microtime(true), 'step' => 'SENDING_SMS_WITH_DESTINATION_CODE'];
            $this->sendWithdrawalSms(
                $cashoutData['beneficiary_phone'] ?? '', 
                $generatedCode,
                $satNumber,
                $netAmount, 
                $currency,
                $tokenResult['expires_at'] ?? null
            );
            $debug[] = ['time' => microtime(true), 'step' => 'SMS_SENT'];
            
            $debug[] = ['time' => microtime(true), 'step' => 'QUEUEING_SETTLEMENT'];
            $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
            $debug[] = ['time' => microtime(true), 'step' => 'SETTLEMENT_QUEUED'];

            $debug[] = ['time' => microtime(true), 'step' => 'UPDATING_NET_POSITION'];
            $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'cashout');
            $debug[] = ['time' => microtime(true), 'step' => 'NET_POSITION_UPDATED'];
            
            $debug[] = ['time' => microtime(true), 'step' => 'UPDATING_SWAP_LEDGER'];
            $this->updateSwapLedgerFees($swapRef, $source['institution'], $destination['institution'], $grossAmount, $currency);
            $debug[] = ['time' => microtime(true), 'step' => 'SWAP_LEDGER_UPDATED'];
            
            error_log("CASHOUT DEBUG: " . json_encode($debug));
            
            return [
                'generated_code' => $generatedCode,
                'sat_number' => $satNumber,
                'token_reference' => $tokenResult['token_reference'] ?? $satNumber,
                'expires_at' => $tokenResult['expires_at'] ?? null,
                'net_amount' => $netAmount,
                'fee_amount' => $feeDetails['fee_amount']
            ];
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CASHOUT_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CASHOUT ERROR: " . json_encode($debug));
            throw $e;
        }
    }

    /**
     * Request token/code from destination institution
     */
    private function requestTokenFromDestination(
        string $swapRef, 
        array $source, 
        array $destination, 
        array $holdResult, 
        array $participant, 
        ?float $netAmount = null
    ): array {
        $debug = [];
        $debug[] = ['time' => microtime(true), 'step' => 'REQUEST_TOKEN_START'];
        
        try {
            $bankClient = new GenericBankClient($participant);
            $debug[] = ['time' => microtime(true), 'step' => 'BANK_CLIENT_CREATED'];

            $payload = [
                'reference' => $swapRef,
                'source_institution' => $source['institution'],
                'source_hold_reference' => $holdResult['hold_reference'] ?? null,
                'source_asset_type' => $source['asset_type'],
                'beneficiary_phone' => $destination['cashout']['beneficiary_phone'] ?? '',
                'amount' => $netAmount ?? $destination['amount'],
                'code_hash' => null,
                'action' => 'GENERATE_ATM_TOKEN'
            ];
            
            $debug[] = ['time' => microtime(true), 'step' => 'PAYLOAD_PREPARED'];

            error_log("REQUEST_TOKEN: About to call bankClient->transfer with payload: " . json_encode($payload));
            
            $result = $bankClient->transfer($payload, 'generate_atm_code');
            
            $debug[] = ['time' => microtime(true), 'step' => 'API_CALL_COMPLETE', 'result_success' => $result['success'] ?? false];
            error_log("REQUEST_TOKEN: API call result: " . json_encode($result));

            $this->debugApiCall('generate_token', $payload, $result);

            $this->logApiMessage(
                $swapRef,
                'generate_token',
                'outgoing',
                $participant,
                '/api/generate-token',
                $payload,
                $result,
                null
            );

            if (!isset($result['success']) || $result['success'] !== true) {
                $errorMsg = 'Bank communication failed';
                if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                    $errorMsg .= ': ' . $result['curl_error'];
                    $debug[] = ['time' => microtime(true), 'step' => 'CURL_ERROR', 'error' => $result['curl_error']];
                } elseif (isset($result['status_code'])) {
                    $errorMsg .= ': HTTP ' . $result['status_code'];
                    $debug[] = ['time' => microtime(true), 'step' => 'HTTP_ERROR', 'code' => $result['status_code']];
                }
                error_log("REQUEST_TOKEN ERROR: " . $errorMsg);
                throw new RuntimeException("Failed to generate token: " . $errorMsg);
            }

            $bankResponse = $result['data'] ?? [];
            $debug[] = ['time' => microtime(true), 'step' => 'BANK_RESPONSE_RECEIVED'];

            $this->logEvent($swapRef, 'TOKEN_BANK_RESPONSE', [
                'bank_response' => $bankResponse
            ]);

            if (!isset($bankResponse['token_generated']) || $bankResponse['token_generated'] !== true) {
                $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Unknown error';
                $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_NOT_GENERATED', 'error' => $errorMsg];
                throw new RuntimeException("Failed to generate token: " . $errorMsg);
            }

            $generatedCode = $bankResponse['pin'] ?? $bankResponse['atm_pin'] ?? null;
            $tokenReference = $bankResponse['token_reference'] ?? $bankResponse['sat_number'] ?? null;
            
            $this->logEvent($swapRef, 'TOKEN_GENERATED', [
                'institution' => $participant['provider_code'] ?? $destination['institution'],
                'token_reference' => $tokenReference,
                'has_code' => !empty($generatedCode)
            ]);
            
            $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_GENERATED_SUCCESS'];
            error_log("REQUEST_TOKEN SUCCESS - Code: " . ($generatedCode ? substr($generatedCode, -4) : 'null'));

            return [
                'token_generated' => true,
                'generated_code' => $generatedCode,
                'pin' => $generatedCode,
                'token_reference' => $tokenReference,
                'sat_number' => $bankResponse['sat_number'] ?? $tokenReference,
                'expires_at' => $bankResponse['expires_at'] ?? $bankResponse['expiry'] ?? null,
                'instrument_id' => $bankResponse['instrument_id'] ?? null,
                'sat_id' => $bankResponse['sat_id'] ?? null,
                'issuer_bank' => $bankResponse['issuer_bank'] ?? null,
                'acquirer_network' => $bankResponse['acquirer_network'] ?? null,
                'raw_response' => $bankResponse
            ];

        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'REQUEST_TOKEN_EXCEPTION', 'error' => $e->getMessage()];
            error_log("REQUEST_TOKEN EXCEPTION: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Store destination token reference in database
     */
    private function storeDestinationToken(int $swapId, array $tokenResult): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb
                WHERE swap_id = ?
            ");
            
            $metadata = json_encode([
                'destination_token' => [
                    'token_reference' => $tokenResult['token_reference'] ?? null,
                    'sat_number' => $tokenResult['sat_number'] ?? null,
                    'generated_code' => $tokenResult['generated_code'] ?? $tokenResult['pin'] ?? null,
                    'expires_at' => $tokenResult['expires_at'] ?? null,
                    'instrument_id' => $tokenResult['instrument_id'] ?? null,
                    'sat_id' => $tokenResult['sat_id'] ?? null,
                    'issuer_bank' => $tokenResult['issuer_bank'] ?? null,
                    'acquirer_network' => $tokenResult['acquirer_network'] ?? null,
                    'institution' => 'DESTINATION'
                ]
            ]);
            
            $stmt->execute([$metadata, $swapId]);
            
            $this->logEvent((string)$swapId, 'DESTINATION_TOKEN_STORED', [
                'token_reference' => $tokenResult['token_reference'] ?? null,
                'has_code' => !empty($tokenResult['generated_code']),
                'expires_at' => $tokenResult['expires_at'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to store destination token: " . $e->getMessage());
        }
    }

    private function sendWithdrawalSms(string $phone, string $code, ?string $satNumber, float $amount, string $currency, ?string $expiry = null): void
    {
        $expiryText = $expiry ? date('Y-m-d H:i:s', strtotime($expiry)) : date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        error_log("=== SMS WOULD BE SENT ===");
        error_log("To: " . $phone);
        error_log("Code: " . $code);
        error_log("SAT Number: " . ($satNumber ?? 'N/A'));
        error_log("Amount: " . $amount . " " . $currency);
        error_log("Expires: " . $expiryText);
        
        if ($this->smsService) {
            $message = "Your withdrawal code is: {$code}\n";
            $message .= "Amount: {$amount} {$currency}\n";
            if ($satNumber) {
                $message .= "Reference: {$satNumber}\n";
            }
            $message .= "Valid until: {$expiryText}";
            
            $this->smsService->send($phone, $message);
            error_log("SMS SENT via service");
        } else {
            error_log("SMS NOT SENT - service unavailable");
        }
    }

    /**
     * Cancel a pending swap
     */
    public function cancelSwap(string $swapRef, string $reason = 'User requested cancellation', ?int $cancelledBy = null): array
    {
        $this->swapDB->beginTransaction();
        
        try {
            $stmt = $this->swapDB->prepare("
                SELECT 
                    sr.*,
                    ht.hold_reference,
                    ht.status as hold_status,
                    ht.participant_id,
                    p.config as participant_config,
                    sv.voucher_id,
                    sv.status as voucher_status,
                    ca.authorization_id,
                    ca.status as card_auth_status
                FROM swap_requests sr
                LEFT JOIN hold_transactions ht ON sr.swap_uuid = ht.swap_reference
                LEFT JOIN participants p ON ht.participant_id = p.participant_id
                LEFT JOIN swap_vouchers sv ON sr.swap_id = sv.swap_id
                LEFT JOIN card_authorizations ca ON sr.swap_uuid = ca.swap_reference
                WHERE sr.swap_uuid = ?
                AND sr.status IN ('pending', 'processing')
                FOR UPDATE
            ");
            $stmt->execute([$swapRef]);
            $swap = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$swap) {
                throw new RuntimeException("Swap not found or already completed/cancelled");
            }
            
            error_log("[CANCEL] Cancelling swap: $swapRef, Reason: $reason");
            
            if ($swap['hold_reference'] && $swap['hold_status'] === 'ACTIVE' && $swap['participant_config']) {
                $participant = json_decode($swap['participant_config'], true);
                $bankClient = new GenericBankClient($participant);
                
                $releaseResult = $bankClient->releaseHold([
                    'hold_reference' => $swap['hold_reference'],
                    'reason' => $reason
                ]);
                
                if (!isset($releaseResult['success']) || $releaseResult['success'] !== true) {
                    throw new RuntimeException("Failed to release hold: " . ($releaseResult['message'] ?? 'Unknown error'));
                }
                
                $this->updateHoldStatus($swap['hold_reference'], 'RELEASED');
            }
            
            if ($swap['voucher_id'] && $swap['voucher_status'] === 'ACTIVE') {
                $voidStmt = $this->swapDB->prepare("
                    UPDATE swap_vouchers 
                    SET status = 'VOIDED',
                        voided_at = NOW(),
                        void_reason = ?
                    WHERE voucher_id = ?
                ");
                $voidStmt->execute([$reason, $swap['voucher_id']]);
            }
            
            if ($swap['authorization_id'] && $swap['card_auth_status'] === 'ACTIVE') {
                $voidAuth = $this->swapDB->prepare("
                    UPDATE card_authorizations 
                    SET status = 'VOIDED',
                        voided_at = NOW(),
                        void_reason = ?
                    WHERE authorization_id = ?
                ");
                $voidAuth->execute([$reason, $swap['authorization_id']]);
            }
            
            $updateStmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET status = 'cancelled',
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{cancellation}',
                        jsonb_build_object(
                            'cancelled_at', to_jsonb(NOW()),
                            'cancelled_by', to_jsonb(?),
                            'reason', to_jsonb(?)
                        )
                    )
                WHERE swap_uuid = ?
                RETURNING swap_id
            ");
            $updateStmt->execute([$cancelledBy ?? 'system', $reason, $swapRef]);
            
            if ($swap['hold_reference']) {
                $queueStmt = $this->swapDB->prepare("
                    DELETE FROM settlement_queue 
                    WHERE hold_reference = ? AND status = 'PENDING'
                ");
                $queueStmt->execute([$swap['hold_reference']]);
            }
            
            $this->logApiMessage(
                $swapRef,
                'swap_cancelled',
                'internal',
                null,
                '/api/swap/cancel',
                ['reason' => $reason],
                ['success' => true],
                null
            );
            
            $this->logEvent($swapRef, 'SWAP_CANCELLED', [
                'reason' => $reason,
                'cancelled_by' => $cancelledBy ?? 'system'
            ]);
            
            $this->swapDB->commit();
            
            return [
                'success' => true,
                'message' => 'Swap cancelled successfully',
                'swap_reference' => $swapRef,
                'hold_released' => !empty($swap['hold_reference'])
            ];
            
        } catch (Exception $e) {
            $this->swapDB->rollBack();
            error_log("[CANCEL] Failed for $swapRef: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Record card swipe transaction
     */
    public function recordCardSwipe(string $cardSuffix, float $amount, string $merchantId): array
    {
        $stmt = $this->swapDB->prepare("
            SELECT * FROM card_authorizations 
            WHERE card_suffix = ? AND status = 'ACTIVE'
            AND expiry_at > NOW()
            FOR UPDATE
        ");
        $stmt->execute([$cardSuffix]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new RuntimeException("Card authorization not found or expired");
        }
        
        if ($card['remaining_balance'] < $amount) {
            throw new RuntimeException("Insufficient funds on card");
        }
        
        $feePortion = ($amount / $card['authorized_amount']) * $card['fee_amount'];
        $vatPortion = ($amount / $card['authorized_amount']) * $card['vat_amount'];
        
        $holdRef = $card['hold_reference'];
        $sourceInstitution = $card['source_institution'];
        
        $txnId = $this->recordCardTransaction($cardSuffix, $amount, $merchantId, $holdRef, $feePortion, $vatPortion);
        
        $this->queueSettlementForCardSwipe($txnId, $holdRef, $sourceInstitution, $merchantId, $amount, $feePortion);
        
        $newBalance = $card['remaining_balance'] - $amount;
        $updateStmt = $this->swapDB->prepare("
            UPDATE card_authorizations 
            SET remaining_balance = ?,
                used_amount = used_amount + ?,
                updated_at = NOW()
            WHERE authorization_id = ?
        ");
        $updateStmt->execute([$newBalance, $amount, $card['authorization_id']]);
        
        return [
            'success' => true,
            'transaction_id' => $txnId,
            'remaining_balance' => $newBalance,
            'fee_deducted' => $feePortion
        ];
    }

    private function recordCardTransaction(string $cardSuffix, float $amount, string $merchantId, string $holdRef, float $feePortion, float $vatPortion): string
    {
        $txnId = 'CARD-' . uniqid() . '-' . bin2hex(random_bytes(4));
        
        $stmt = $this->swapDB->prepare("
            INSERT INTO card_transactions 
            (transaction_id, card_suffix, amount, merchant_id, hold_reference, 
             fee_amount, vat_amount, status, settlement_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'COMPLETED', 'PENDING', NOW())
        ");
        
        $stmt->execute([$txnId, $cardSuffix, $amount, $merchantId, $holdRef, $feePortion, $vatPortion]);
        
        return $txnId;
    }

    private function queueSettlementForCardSwipe(string $txnId, string $holdRef, string $sourceInstitution, string $merchantId, float $amount, float $feePortion): void
    {
        $stmt = $this->swapDB->prepare("
            INSERT INTO settlement_queue 
            (transaction_id, hold_reference, source_institution, destination_institution,
             amount, fee_amount, type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'CARD_SWIPE', 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $txnId,
            $holdRef,
            $sourceInstitution,
            $merchantId,
            $amount,
            $feePortion
        ]);
    }

    /**
     * Process card settlements
     */
    public function processCardSettlements(): array
    {
        $stats = ['processed' => 0, 'total_amount' => 0, 'total_fees' => 0];
        
        $pending = $this->swapDB->prepare("
            SELECT sq.*, ca.source_institution, ca.hold_reference, p.config as participant_config
            FROM settlement_queue sq
            JOIN card_authorizations ca ON sq.hold_reference = ca.hold_reference
            LEFT JOIN participants p ON ca.source_institution = p.name OR ca.source_institution = p.provider_code
            WHERE sq.type = 'CARD_SWIPE' AND sq.status = 'PENDING'
            ORDER BY sq.created_at ASC
            LIMIT 100
            FOR UPDATE SKIP LOCKED
        ");
        $pending->execute();
        
        while ($txn = $pending->fetch(PDO::FETCH_ASSOC)) {
            try {
                $this->swapDB->beginTransaction();
                
                $sourceKey = $this->findInstitutionKey($txn['source_institution']);
                if (!$sourceKey || !isset($this->participants[$sourceKey])) {
                    throw new RuntimeException("Source institution not found: {$txn['source_institution']}");
                }
                
                $sourceParticipant = $this->participants[$sourceKey];
                $bankClient = new GenericBankClient($sourceParticipant);
                
                $debitResult = $bankClient->debitHold([
                    'hold_reference' => $txn['hold_reference'],
                    'amount' => $txn['amount'],
                    'reference' => $txn['transaction_id'],
                    'reason' => 'Card swipe settlement'
                ]);
                
                if (!isset($debitResult['success']) || $debitResult['success'] !== true) {
                    throw new RuntimeException("Failed to debit source: " . ($debitResult['message'] ?? 'Unknown'));
                }
                
                $this->creditMerchant($txn['destination_institution'], $txn['amount'], $txn['transaction_id']);
                
                $updateStmt = $this->swapDB->prepare("
                    UPDATE settlement_queue 
                    SET status = 'SETTLED', settled_at = NOW()
                    WHERE transaction_id = ?
                ");
                $updateStmt->execute([$txn['transaction_id']]);
                
                $updateCardTxn = $this->swapDB->prepare("
                    UPDATE card_transactions 
                    SET settlement_status = 'SETTLED', settled_at = NOW()
                    WHERE transaction_id = ?
                ");
                $updateCardTxn->execute([$txn['transaction_id']]);
                
                $this->swapDB->commit();
                
                $stats['processed']++;
                $stats['total_amount'] += $txn['amount'];
                $stats['total_fees'] += $txn['fee_amount'];
                
            } catch (Exception $e) {
                $this->swapDB->rollBack();
                error_log("Failed to settle card transaction {$txn['transaction_id']}: " . $e->getMessage());
                
                $failStmt = $this->swapDB->prepare("
                    UPDATE settlement_queue 
                    SET status = 'FAILED', error_message = ?
                    WHERE transaction_id = ?
                ");
                $failStmt->execute([$e->getMessage(), $txn['transaction_id']]);
            }
        }
        
        return $stats;
    }

    private function creditMerchant(string $merchantId, float $amount, string $reference): void
    {
        $stmt = $this->swapDB->prepare("
            INSERT INTO merchant_settlements 
            (merchant_id, amount, reference, status, created_at)
            VALUES (?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([$merchantId, $amount, $reference]);
    }

    /**
     * Process expired holds
     */
    public function processExpiredHolds(): array
    {
        $stats = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
            'holds_released' => 0,
            'swaps_updated' => 0,
            'card_auths_voided' => 0
        ];
        
        try {
            $expired = $this->swapDB->prepare("
                SELECT 
                    ht.hold_id,
                    ht.hold_reference,
                    ht.swap_reference,
                    ht.participant_id,
                    ht.amount,
                    ht.hold_expiry,
                    p.config as participant_config,
                    sr.swap_id,
                    sr.status as swap_status,
                    ca.authorization_id
                FROM hold_transactions ht
                JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
                LEFT JOIN participants p ON ht.participant_id = p.participant_id
                LEFT JOIN card_authorizations ca ON ht.hold_reference = ca.hold_reference AND ca.status = 'ACTIVE'
                WHERE ht.status = 'ACTIVE' 
                AND ht.hold_expiry < NOW()
                ORDER BY ht.hold_expiry ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");
            $expired->bindValue(':limit', self::EXPIRY_BATCH_SIZE, PDO::PARAM_INT);
            $expired->execute();
            
            while ($hold = $expired->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $this->swapDB->beginTransaction();
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSING', [
                        'hold_reference' => $hold['hold_reference'],
                        'expiry_time' => $hold['hold_expiry']
                    ]);
                    
                    $holdReleased = false;
                    if ($hold['participant_config']) {
                        try {
                            $participant = json_decode($hold['participant_config'], true);
                            $bankClient = new GenericBankClient($participant);
                            
                            $releaseResult = $bankClient->releaseHold([
                                'hold_reference' => $hold['hold_reference'],
                                'reason' => 'Hold expired after ' . self::HOLD_EXPIRY_HOURS . ' hours'
                            ]);
                            
                            if (isset($releaseResult['success']) && $releaseResult['success'] === true) {
                                $holdReleased = true;
                            } else {
                                $errorMsg = $releaseResult['message'] ?? 'Unknown error';
                                throw new RuntimeException("Bank release failed: " . $errorMsg);
                            }
                        } catch (Exception $e) {
                            $this->logEvent($hold['swap_reference'], 'EXPIRY_BANK_RELEASE_FAILED', [
                                'hold_reference' => $hold['hold_reference'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    $updateHold = $this->swapDB->prepare("
                        UPDATE hold_transactions 
                        SET status = 'EXPIRED', 
                            released_at = NOW(),
                            metadata = jsonb_set(
                                COALESCE(metadata, '{}'::jsonb),
                                '{expiry_details}',
                                jsonb_build_object(
                                    'expired_at', to_jsonb(NOW()),
                                    'bank_released', to_jsonb(?),
                                    'reason', 'Auto-expired after 24 hours'
                                )
                            )
                        WHERE hold_reference = ?
                    ");
                    $updateHold->execute([$holdReleased ? 'true' : 'false', $hold['hold_reference']]);
                    $stats['holds_released']++;
                    
                    if ($hold['authorization_id']) {
                        $voidAuth = $this->swapDB->prepare("
                            UPDATE card_authorizations 
                            SET status = 'EXPIRED', 
                                expired_at = NOW(),
                                void_reason = 'Associated hold expired'
                            WHERE authorization_id = ?
                        ");
                        $voidAuth->execute([$hold['authorization_id']]);
                        $stats['card_auths_voided']++;
                    }
                    
                    $updateSwap = $this->swapDB->prepare("
                        UPDATE swap_requests 
                        SET status = 'expired',
                            metadata = jsonb_set(
                                COALESCE(metadata, '{}'::jsonb),
                                '{expiry}',
                                jsonb_build_object(
                                    'expired_at', to_jsonb(NOW()),
                                    'hold_reference', to_jsonb(?)
                                )
                            )
                        WHERE swap_uuid = ?
                        AND status IN ('pending', 'processing')
                    ");
                    $updateSwap->execute([$hold['hold_reference'], $hold['swap_reference']]);
                    $stats['swaps_updated'] += $updateSwap->rowCount();
                    
                    $voidVoucher = $this->swapDB->prepare("
                        UPDATE swap_vouchers 
                        SET status = 'EXPIRED', 
                            voided_at = NOW(),
                            void_reason = 'Associated hold expired'
                        WHERE swap_id = ? 
                        AND status = 'ACTIVE'
                    ");
                    $voidVoucher->execute([$hold['swap_id']]);
                    
                    $removeSettlement = $this->swapDB->prepare("
                        DELETE FROM settlement_queue 
                        WHERE hold_reference = ? AND status = 'PENDING'
                    ");
                    $removeSettlement->execute([$hold['hold_reference']]);
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSED', [
                        'hold_reference' => $hold['hold_reference'],
                        'bank_released' => $holdReleased
                    ]);
                    
                    $this->swapDB->commit();
                    $stats['processed']++;
                    
                } catch (Exception $e) {
                    $this->swapDB->rollBack();
                    $stats['failed']++;
                    $stats['errors'][] = [
                        'hold' => $hold['hold_reference'],
                        'error' => $e->getMessage()
                    ];
                    
                    $this->logEvent($hold['swap_reference'], 'EXPIRY_PROCESSING_FAILED', [
                        'hold_reference' => $hold['hold_reference'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Fatal error in processExpiredHolds: " . $e->getMessage());
            $stats['fatal_error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processExpiredVouchers(): array
    {
        $stats = ['processed' => 0];
        
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_vouchers 
                SET status = 'EXPIRED',
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{expired_at}',
                        to_jsonb(NOW())
                    )
                WHERE expiry_at < NOW() 
                AND status = 'ACTIVE'
            ");
            $stmt->execute();
            
            $stats['processed'] = $stmt->rowCount();
            
            if ($stats['processed'] > 0) {
                $this->logEvent('CRON', 'VOUCHER_EXPIRY_PROCESSED', [
                    'count' => $stats['processed']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Failed to process expired vouchers: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processExpiredCardAuthorizations(): array
    {
        $stats = ['processed' => 0];
        
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE card_authorizations 
                SET status = 'EXPIRED',
                    expired_at = NOW(),
                    metadata = jsonb_set(
                        COALESCE(metadata, '{}'::jsonb),
                        '{expired_at}',
                        to_jsonb(NOW())
                    )
                WHERE expiry_at < NOW() 
                AND status = 'ACTIVE'
            ");
            $stmt->execute();
            
            $stats['processed'] = $stmt->rowCount();
            
            if ($stats['processed'] > 0) {
                $this->logEvent('CRON', 'CARD_AUTH_EXPIRY_PROCESSED', [
                    'count' => $stats['processed']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("[EXPIRY] Failed to process expired card authorizations: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }

    public function processAllExpired(): array
    {
        $result = [
            'holds' => $this->processExpiredHolds(),
            'vouchers' => $this->processExpiredVouchers(),
            'card_authorizations' => $this->processExpiredCardAuthorizations(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        error_log("[EXPIRY] Completed: " . json_encode($result));
        
        return $result;
    }

    private function queueSettlementMessage(string $swapRef, array $source, array $destination, float $amount, array $holdResult, ?array $feeDetails = null): void
    {
        error_log("[QUEUE_SETTLEMENT] Starting for swap: $swapRef");
        
        $beneficiaryPhone = isset($destination['cashout']['beneficiary_phone']) 
            ? substr($destination['cashout']['beneficiary_phone'], -8) 
            : null;
        
        $metadata = [
            'source_type' => $source['asset_type'] ?? 'UNKNOWN',
            'source_reference' => $this->maskIdentifier($source),
            'beneficiary_phone' => $beneficiaryPhone,
            'hold_reference' => $holdResult['hold_reference'] ?? null
        ];
        
        if ($feeDetails) {
            $metadata['fee'] = [
                'fee_id' => $feeDetails['fee_id'] ?? null,
                'total_fee' => $feeDetails['fee_amount'],
                'net_amount' => $feeDetails['net_amount'],
                'split' => $feeDetails['split'] ?? null
            ];
        }

        $stmt = $this->swapDB->prepare("
            INSERT INTO settlement_messages
            (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, 'PENDING', ?, NOW())
        ");

        $type = $destination['delivery_mode'] ?? 'DEPOSIT';
        $type = strtoupper($type) . '_SETTLEMENT';

        $stmt->execute([
            $swapRef,
            $source['institution'],
            $destination['institution'],
            $amount,
            $type,
            json_encode($metadata)
        ]);
        
        error_log("[QUEUE_SETTLEMENT] Success");
    }

    private function handleUndispensedAmount(string $origin, array $destination, float $amount, string $ref): void
    {
        if ($destination['asset_type'] === 'E-WALLET') {
            $this->logEvent('MICRO_SWAP', 'INFO', [
                'participant' => $origin, 'amount' => $amount, 'ref' => $ref
            ]);
            $stmt = $this->swapDB->prepare("
                INSERT INTO settlement_messages 
                (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
                VALUES (?, ?, ?, ?, 'RETURN_SETTLEMENT', 'PENDING', ?, NOW())
            ");
            $stmt->execute([
                $ref, $destination['institution'], $origin, $amount,
                json_encode(['reason' => 'undispensed_cashout_amount'])
            ]);
        } else {
            $this->logEvent('SWAP_TO_SWAP', 'INFO', ['amount' => $amount, 'ref' => $ref]);
            $this->settlement->updateNetPosition($destination['institution'], $origin, $amount);
        }
    }

    private function recordMasterSwap(string $swapRef, array $source, array $destination, string $currency, array $verificationResult): int
    {
        $sourceDetails = [
            'institution' => $source['institution'],
            'asset_type' => $source['asset_type'],
            'reference' => $this->maskIdentifier($source),
            'holder_name' => $verificationResult['asset_details']['holder_name'] ?? null,
            'available_balance' => $verificationResult['asset_details']['available_balance'] ?? null
        ];

        $destinationAssetType = 'UNKNOWN';
        
        if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
            $destinationAssetType = 'CASHOUT';
        } elseif (isset($destination['asset_type'])) {
            $destinationAssetType = $destination['asset_type'];
        } elseif (isset($destination['beneficiary_account'])) {
            $destinationAssetType = 'ACCOUNT';
        } elseif (isset($destination['beneficiary_wallet'])) {
            $destinationAssetType = 'WALLET';
        } elseif (isset($destination['cashout'])) {
            $destinationAssetType = 'CASHOUT';
        } elseif (in_array($destination['delivery_mode'] ?? '', ['card_load', 'card'])) {
            $destinationAssetType = 'CARD';
        }

        $destinationDetails = [
            'institution' => $destination['institution'],
            'asset_type' => $destinationAssetType,
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'card_type' => $this->getCardType($destination),
            'beneficiary' => $destination['cashout']['beneficiary_phone'] ?? 
                            $destination['beneficiary_account'] ?? 
                            $destination['beneficiary_wallet'] ?? 
                            $destination['card_suffix'] ??
                            $destination['cashout']['beneficiary'] ??
                            null
        ];

        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_requests 
            (swap_uuid, from_currency, to_currency, amount, source_details, destination_details, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            RETURNING swap_id
        ");

        $stmt->execute([
            $swapRef, $currency, $currency, $source['amount'],
            json_encode($sourceDetails), json_encode($destinationDetails)
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function updateSwapLedgerFees(string $swapRef, string $from, string $to, float $amount, string $currency): void
    {
        $stmt = $this->swapDB->prepare("
            SELECT total_amount FROM swap_fee_collections 
            WHERE swap_reference = ? 
            ORDER BY fee_id DESC LIMIT 1
        ");
        $stmt->execute([$swapRef]);
        $feeResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $swapFee = $feeResult ? (float)$feeResult['total_amount'] : 0.00;

        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_ledgers 
            (swap_reference, from_institution, to_institution, amount, currency_code, swap_fee, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([$swapRef, $from, $to, $amount, $currency, $swapFee]);
    }

    private function processDeposit(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
    {
        $grossAmount = (float)$destination['amount'];
        
        $feeDetails = $this->deductSwapFee(
            $swapRef,
            'DEPOSIT',
            $grossAmount,
            $source['institution'],
            $destination['institution']
        );
        
        $netAmount = $feeDetails['net_amount'];
        
        $destInstitutionKey = $this->findInstitutionKey($destination['institution']);
        if (!$destInstitutionKey) {
            throw new RuntimeException("Destination institution not found: {$destination['institution']}");
        }
        $participant = $this->participants[$destInstitutionKey];
        
        $bankClient = new GenericBankClient($participant);

        $result = $bankClient->transfer([
            'reference' => $swapRef,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $holdResult['hold_reference'] ?? null,
            'destination_account' => $destination['beneficiary_account'] ?? $destination['beneficiary_wallet'] ?? null,
            'amount' => $netAmount,
            'action' => 'PROCESS_DEPOSIT'
        ], 'deposit_direct');

        $this->debugApiCall('process_deposit', ['reference' => $swapRef], $result);

        $this->logApiMessage(
            $swapRef,
            'process_deposit',
            'outgoing',
            $participant,
            '/api/process-deposit',
            ['reference' => $swapRef, 'destination' => $destination['beneficiary_account'] ?? $destination['beneficiary_wallet'] ?? null],
            $result,
            null
        );

        if (!isset($result['success']) || $result['success'] !== true) {
            $errorMsg = 'Bank communication failed';
            if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (isset($result['status_code'])) {
                $errorMsg .= ': HTTP ' . $result['status_code'];
            }
            throw new RuntimeException("Deposit failed: " . $errorMsg);
        }

        $bankResponse = $result['data'] ?? [];

        if (!isset($bankResponse['processed']) || $bankResponse['processed'] !== true) {
            $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Unknown error';
            throw new RuntimeException("Deposit failed: " . $errorMsg);
        }
        
        $this->queueSettlementMessage($swapRef, $source, $destination, $grossAmount, $holdResult, $feeDetails);
        $this->settlement->updateNetPosition($source['institution'], $destination['institution'], $netAmount, 'deposit');
    }

    private function maskIdentifier(array $source): string
    {
        $assetType = 'UNKNOWN';
        
        if (isset($source['asset_type'])) {
            $assetType = strtoupper($source['asset_type']);
        } elseif (isset($source['type'])) {
            $assetType = strtoupper($source['type']);
        } elseif (isset($source['source']['asset_type'])) {
            $assetType = strtoupper($source['source']['asset_type']);
        } elseif (isset($source['delivery_mode'])) {
            $assetType = strtoupper($source['delivery_mode']);
        }
        
        switch ($assetType) {
            case 'VOUCHER':
                $voucherNumber = $source['voucher']['voucher_number'] ?? $source['voucher_number'] ?? '';
                return 'VCH-' . substr($voucherNumber, -4);
                
            case 'ACCOUNT':
                $accountNumber = $source['account']['account_number'] ?? $source['account_number'] ?? '';
                return 'ACC-' . substr($accountNumber, -4);
                
            case 'WALLET':
                $phone = $source['wallet']['wallet_phone'] ?? 
                         $source['wallet']['phone'] ?? 
                         $source['wallet_phone'] ?? 
                         $source['phone'] ?? 
                         '';
                return 'WLT-' . substr($phone, -8);
                
            case 'E-WALLET':
                $phone = $source['ewallet']['ewallet_phone'] ?? 
                         $source['ewallet']['phone'] ?? 
                         $source['ewallet_phone'] ?? 
                         $source['phone'] ?? 
                         '';
                return 'EWL-' . substr($phone, -8);
                
            case 'CARD':
                $cardNumber = $source['card']['card_number'] ?? $source['card_number'] ?? '';
                return 'CRD-' . substr($cardNumber, -4);
                
            case 'CASHOUT':
                $phone = $source['cashout']['beneficiary_phone'] ?? $source['beneficiary_phone'] ?? '';
                return 'CSH-' . substr($phone, -8);
                
            case 'CARD_LOAD':
            case 'CARD_ISSUANCE':
            case 'DEPOSIT':
                if (isset($source['card_suffix'])) {
                    return 'CRD-' . $source['card_suffix'];
                }
                if (isset($source['beneficiary_account'])) {
                    return 'ACC-' . substr($source['beneficiary_account'], -4);
                }
                if (isset($source['beneficiary_wallet'])) {
                    return 'WLT-' . substr($source['beneficiary_wallet'], -8);
                }
                return 'DST-' . substr(uniqid(), -6);
                
            default:
                if (isset($source['phone'])) {
                    return 'USR-' . substr($source['phone'], -8);
                }
                if (isset($source['account_number'])) {
                    return 'ACC-' . substr($source['account_number'], -4);
                }
                if (isset($source['voucher_number'])) {
                    return 'VCH-' . substr($source['voucher_number'], -4);
                }
                if (isset($source['beneficiary_phone'])) {
                    return 'BNF-' . substr($source['beneficiary_phone'], -8);
                }
                if (isset($source['card_suffix'])) {
                    return 'CRD-' . $source['card_suffix'];
                }
                return 'SRC-' . substr(uniqid(), -6);
        }
    }

    private function logEvent(string $msgId, string $phase, array $data): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'msg_id' => $msgId,
            'phase' => $phase,
            'details' => $data
        ]);
        file_put_contents(self::LOG_FILE, $logEntry . PHP_EOL, FILE_APPEND);
    }

    private function loadFlows(): void
    {
        $flowsFile = __DIR__ . "/../../CORE_CONFIG/flows.php";
        if (file_exists($flowsFile)) {
            $this->flowsConfig = include $flowsFile;
        }
    }

    private function updateSwapMetadata(string $swapRef, array $data): void
    {
        try {
            $stmt = $this->swapDB->prepare("
                UPDATE swap_requests 
                SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb
                WHERE swap_uuid = ?
            ");
            $stmt->execute([json_encode($data), $swapRef]);
        } catch (Exception $e) {
            error_log("Failed to update swap metadata: " . $e->getMessage());
        }
    }

    private function getSwapMetadata(string $swapRef): array
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT metadata FROM swap_requests WHERE swap_uuid = ?
            ");
            $stmt->execute([$swapRef]);
            $metadata = $stmt->fetchColumn();
            return $metadata ? json_decode($metadata, true) : [];
        } catch (Exception $e) {
            error_log("Failed to get swap metadata: " . $e->getMessage());
            return [];
        }
    }

    public function getAtmDenominations(string $currency = 'BWP'): array
    {
        return $this->atmNotes[$currency] ?? [];
    }

    public function canDispenseAmount(float $amount, string $currency = 'BWP'): bool
    {
        try {
            $result = $this->getDispensableAmount($amount, $currency);
            return $result['dispensable_amount'] > 0 && $result['undispensed_amount'] <= 0.01;
        } catch (Exception) {
            return false;
        }
    }

    public function getTransactionTrace(string $swapRef): array
    {
        try {
            $stmt = $this->swapDB->prepare("
                SELECT * FROM swap_requests WHERE swap_uuid = ?
            ");
            $stmt->execute([$swapRef]);
            $swap = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM hold_transactions WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM api_message_logs 
                WHERE message_id = ? 
                ORDER BY created_at ASC
            ");
            $stmt->execute([$swapRef]);
            $apiLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM swap_fee_collections 
                WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->swapDB->prepare("
                SELECT * FROM card_authorizations 
                WHERE swap_reference = ?
            ");
            $stmt->execute([$swapRef]);
            $cardAuths = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'swap' => $swap,
                'holds' => $holds,
                'api_messages' => $apiLogs,
                'fees' => $fees,
                'card_authorizations' => $cardAuths
            ];
        } catch (Exception $e) {
            error_log("Failed to get transaction trace: " . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
