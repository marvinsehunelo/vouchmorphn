<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

require_once __DIR__ . '/settlement/HybridSettlementStrategy.php';
require_once __DIR__ . '/../Helpers/SwapStatusResolver.php';
require_once __DIR__ . '/../../INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php';
require_once __DIR__ . '/SmsNotificationService.php';

use PDO;
use Exception;
use DateTimeImmutable;
use SECURITY_LAYER\Encryption\TokenEncryptor;
use BUSINESS_LOGIC_LAYER\Helpers\SwapStatusResolver;
use BUSINESS_LOGIC_LAYER\services\settlement\HybridSettlementStrategy;
use BUSINESS_LOGIC_LAYER\services\SmsNotificationService;
use RuntimeException;
use INTEGRATION_LAYER\CLIENTS\BankClients\GenericBankClient;

/**
 * SwapService - ISO20022 & FSPIOP Compliant
 * Multi-country aware, dynamic ATM denomination loading
 * Supports all wallet_types: ACCOUNT, VOUCHER, E-WALLET, WALLET, CARD, ATM
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
    private HybridSettlementStrategy $settlement;
    private ?SmsNotificationService $smsService = null;

    private const LOG_FILE = '/tmp/vouchmorphn_swap_audit.log';
    private const DEBUG_FILE = '/tmp/hold_debug.log';
    
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

        try {
            $this->loadCountryFees();
            error_log("[SwapService] Country fees loaded successfully");
        } catch (\Exception $e) {
            error_log("[SwapService] WARNING loading country fees: " . $e->getMessage());
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
        
        error_log("=== SWAPSERVICE CONSTRUCTOR COMPLETE ===");
    }

    private function loadCountryFees(): void
    {
        $basePath = __DIR__ . "/../../CORE_CONFIG/countries/{$this->countryCode}";
        
        $feesFile = "{$basePath}/fees_{$this->countryCode}.json";
        if (file_exists($feesFile)) {
            $this->feesConfig = json_decode(file_get_contents($feesFile), true);
        } else {
            $this->feesConfig = [
                'fees' => [
                    'account_issuance' => ['amount' => 0.50],
                    'cross_border_markup' => 0.02,
                    'cashout_fee_percentage' => 0.02,
                    'cashout_fee_fixed' => 2.00
                ]
            ];
            $this->logEvent('LOAD_FEES', 'WARNING', ['message' => "Fees file missing for {$this->countryCode}, using defaults"]);
        }

        $atmFile = "{$basePath}/atm_notes_{$this->countryCode}.json";
        if (file_exists($atmFile)) {
            $this->atmNotes = json_decode(file_get_contents($atmFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Invalid JSON in ATM notes file: {$atmFile}");
            }
            $this->logEvent('LOAD_ATM_NOTES', 'SUCCESS', ['file' => $atmFile]);
        } else {
            throw new RuntimeException("ATM notes file missing for country {$this->countryCode}");
        }
    }

    private function initSmsService(): void
    {
        $configPath = __DIR__ . "/../../CORE_CONFIG/countries/{$this->countryCode}/communication_config_{$this->countryCode}.json";
        
        $smsConfig = [];
        if (file_exists($configPath)) {
            $fullConfig = json_decode(file_get_contents($configPath), true);
            $smsConfig = $fullConfig['sms_gateway'] ?? [];
            error_log("[SwapService] Loaded SMS config for {$this->countryCode}");
        } else {
            $smsConfig = [
                'base_url' => 'http://localhost/CazaCOm',
                'api_key' => 'SACCUS_INTERNAL_KEY_2025',
                'api_path' => '/backend/routes/api.php',
                'sms_endpoint' => '?path=sms/send',
                'enabled' => true
            ];
            error_log("[SwapService] Using default SMS config for {$this->countryCode}");
        }
        
        $this->smsService = new SmsNotificationService($this->swapDB, $smsConfig);
    }

    private function getDispensableAmount(float $amount, string $currency): array
    {
        $denominations = $this->atmNotes[$currency] ?? throw new RuntimeException("No ATM denominations for currency $currency");
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

    private function deductSwapFee(
        string $swapRef,
        string $transactionType,
        float $grossAmount,
        string $sourceInstitution,
        string $destinationInstitution
    ): array {
        
        $feeKey = $transactionType === 'CASHOUT' ? 'CASHOUT_SWAP_FEE' : 'DEPOSIT_SWAP_FEE';
        $feeConfig = $this->feesConfig['fees'][$feeKey] ?? null;
        
        if (!$feeConfig) {
            $totalFee = $transactionType === 'CASHOUT' ? 10.00 : 6.00;
            $split = $transactionType === 'CASHOUT' 
                ? ['source_participant' => 2.00, 'vouchmorph' => 4.00, 'destination_participant' => 4.00]
                : ['source_participant' => 1.20, 'vouchmorph' => 2.40, 'destination_participant' => 2.40];
            
            $this->logEvent($swapRef, 'FEE_FALLBACK_USED', ['type' => $transactionType, 'fee' => $totalFee]);
        } else {
            $totalFee = $feeConfig['total_amount'];
            $split = $feeConfig['split'];
        }
        
        $vatRate = $this->feesConfig['regulatory']['vat_rate'] ?? 0.14;
        $vatAmount = $totalFee * $vatRate;
        
        $netAmount = $grossAmount - $totalFee;
        
        if ($netAmount <= 0) {
            throw new RuntimeException("Amount after fee deduction must be positive. Gross: $grossAmount, Fee: $totalFee");
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
            $feeConfig['currency'] ?? 'BWP',
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
            'split' => $split
        ]);
        
        return [
            'gross_amount' => $grossAmount,
            'fee_amount' => $totalFee,
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'fee_id' => $feeId,
            'split' => $split
        ];
    }

    /**
     * Execute swap with unified payload structure - ENHANCED WITH DEBUGGING
     */
    public function executeSwap(array $payload): array
    { 
        $this->swapDB->beginTransaction();
        
        $holdResult = null;
        $bankClient = null;
        $swapRef = bin2hex(random_bytes(16));
        $atmResult = null;
        
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

            if (isset($destination['delivery_mode']) && $destination['delivery_mode'] === 'cashout') {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_CASHOUT'];
                $this->processCashout($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_CASHOUT_COMPLETE'];
            } else {
                $debugSteps[] = ['time' => microtime(true), 'step' => 'START_PROCESS_DEPOSIT'];
                $this->processDeposit($swapId, $swapRef, $source, $destination, $currency, $holdResult);
                $debugSteps[] = ['time' => microtime(true), 'step' => 'PROCESS_DEPOSIT_COMPLETE'];
            }

            $debugSteps[] = ['time' => microtime(true), 'step' => 'START_DEBIT_FUNDS'];
            $this->debitSourceFunds($swapRef, $source, $holdResult, $sourceParticipant);
            $debugSteps[] = ['time' => microtime(true), 'step' => 'DEBIT_FUNDS_COMPLETE'];

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
                'debug_steps' => $debugSteps
            ]);

            return [
                'status' => 'success',
                'swap_reference' => $swapRef,
                'message' => 'Swap initiated successfully',
                'hold_reference' => $holdResult['hold_reference'] ?? null,
                'dispensed_notes' => $atmResult['notes'] ?? [],
                'debug' => $debugSteps
            ];

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

    private function debitSourceFunds(string $swapRef, array $source, array $holdResult, array $participant): void
    {
        $bankClient = new GenericBankClient($participant);
        
        $result = $bankClient->debitFunds([
            'hold_reference' => $holdResult['hold_reference'],
            'amount' => $source['amount'],
            'transaction_reference' => $swapRef,
            'final' => true
        ]);
        
        $this->debugApiCall('debit_funds', ['hold_reference' => $holdResult['hold_reference']], $result);

        $this->logApiMessage(
            $swapRef,
            'debit_funds',
            'outgoing',
            $participant,
            '/api/debit',
            ['hold_reference' => $holdResult['hold_reference']],
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
            throw new RuntimeException("Failed to debit funds: " . $errorMsg);
        }

        $bankResponse = $result['data'] ?? [];

        if (!isset($bankResponse['debited']) || $bankResponse['debited'] !== true) {
            throw new RuntimeException("Failed to debit funds: " . ($bankResponse['message'] ?? 'Unknown error'));
        }
        
        if (isset($holdResult['hold_reference'])) {
            $this->updateHoldStatus($holdResult['hold_reference'], 'DEBITED');
        }
        
        $this->logEvent($swapRef, 'FUNDS_DEBITED', [
            'amount' => $source['amount'],
            'hold_reference' => $holdResult['hold_reference']
        ]);
    }

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
                $wallet = $source['wallet'] ?? [];
                $verificationPayload = array_merge($verificationPayload, [
                    'wallet_phone' => $this->formatPhoneForInstitution($wallet['wallet_phone'] ?? null, $participant),
                    'wallet_pin' => $wallet['wallet_pin'] ?? null
                ]);
                break;

            case 'E-WALLET':
                $phone = $source['ewallet']['phone'] ?? 
                         $source['phone'] ?? 
                         $source['ewallet_phone'] ?? 
                         null;
                
                if (!$phone) {
                    error_log("[VERIFY_ASSET] CRITICAL: No phone number found for E-WALLET verification");
                    throw new RuntimeException("Phone number required for E-WALLET verification");
                }
                
                $verificationPayload = array_merge($verificationPayload, [
                    'phone' => $this->formatPhoneForInstitution($phone, $participant)
                ]);
                
                error_log("[VERIFY_ASSET] E-WALLET phone: " . $phone . " → formatted: " . $verificationPayload['phone']);
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

        $this->logEvent($swapRef, 'VERIFICATION_PAYLOAD', [
            'payload' => $verificationPayload
        ]);

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
            
            $this->logEvent($swapRef, 'BANK_CLIENT_RESPONSE', [
                'result' => $result
            ]);

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
            error_log("=== VERIFICATION EXCEPTION === " . $e->getMessage());
            throw new RuntimeException("Source verification failed: " . $e->getMessage());
        }
    }

    private function placeHoldOnSourceAsset(string $swapRef, array $source, array $verificationResult, array $participant, ?array $destination = null): array
    {
        $assetType = strtoupper($source['asset_type']);
        $bankClient = new GenericBankClient($participant);

        $holdPayload = [
            'reference' => $swapRef,
            'asset_type' => $assetType,
            'amount' => $source['amount'],
            'action' => 'PLACE_HOLD',
            'expiry' => (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s'),
            'hold_reason' => 'PENDING_TRANSACTION'
        ];

        if ($destination && isset($destination['institution'])) {
            $holdPayload['foreign_bank'] = $destination['institution'];
            $holdPayload['destination_institution'] = $destination['institution'];
            $holdPayload['destination'] = $destination['institution'];
            $holdPayload['beneficiary_bank'] = $destination['institution'];
            
            $this->logEvent($swapRef, 'HOLD_ADDED_FOREIGN_BANK', [
                'foreign_bank' => $destination['institution']
            ]);
        } else {
            $this->logEvent($swapRef, 'HOLD_NO_FOREIGN_BANK', [
                'warning' => 'No destination institution provided for hold'
            ]);
            $holdPayload['foreign_bank'] = 'UNKNOWN';
        }

        switch ($assetType) {
            case 'VOUCHER':
                $holdPayload['voucher_number'] = $source['voucher']['voucher_number'] ?? null;
                $holdPayload['claimant_phone'] = $this->formatPhoneForInstitution(
                    $source['voucher']['claimant_phone'] ?? null, $participant
                );
                break;
            case 'ACCOUNT':
                $holdPayload['account_number'] = $source['account']['account_number'] ?? null;
                break;
            case 'WALLET':
                $holdPayload['wallet_phone'] = $this->formatPhoneForInstitution(
                    $source['wallet']['wallet_phone'] ?? null, $participant
                );
                break;
            case 'E-WALLET':
                   $phone = $source['ewallet']['phone'] ??          
                            $source['ewallet']['ewallet_phone'] ??  
                            $source['phone'] ??                      
                            $source['ewallet_phone'] ??              
                            $source['beneficiary_phone'] ??          
                            $source['claimant_phone'] ??             
                            $source['wallet_phone'] ??               
                            $source['account_phone'] ??             
                            null;
                
                if (!$phone) {
                    error_log("[PLACE_HOLD] CRITICAL: No phone number found for E-WALLET hold placement");
                    throw new RuntimeException("Phone number required for E-WALLET hold placement");
                }
                
                $holdPayload['phone'] = $this->formatPhoneForInstitution($phone, $participant);
                
                error_log("[PLACE_HOLD] E-WALLET phone found: " . $phone . " → formatted: " . $holdPayload['phone']);
                break;
            case 'CARD':
                $holdPayload['card_number'] = $source['card']['card_number'] ?? null;
                break;
        }

        $this->logEvent($swapRef, 'PLACE_HOLD_PAYLOAD', [
            'payload' => $holdPayload,
            'participant' => $participant['provider_code'] ?? 'unknown'
        ]);

        $result = $bankClient->authorize($holdPayload);
        $this->debugApiCall('place_hold', $holdPayload, $result);
        
        $this->logApiMessage(
            $swapRef,
            'hold_placement',
            'outgoing',
            $participant,
            '/api/authorize',
            $holdPayload,
            $result,
            null
        );
        
        $this->logEvent($swapRef, 'PLACE_HOLD_RESPONSE_RAW', ['result' => $result]);

        if (!isset($result['success']) || $result['success'] !== true) {
            $errorMsg = 'Bank communication failed';
            if (isset($result['curl_error'])) {
                $errorMsg .= ': ' . $result['curl_error'];
            } elseif (isset($result['status_code'])) {
                $errorMsg .= ': HTTP ' . $result['status_code'];
            }
            
            $this->logEvent($swapRef, 'PLACE_HOLD_COMMS_FAILED', [
                'error' => $errorMsg,
                'status_code' => $result['status_code'] ?? null,
                'curl_error' => $result['curl_error'] ?? null
            ]);
            
            return ['hold_placed' => false, 'message' => $errorMsg];
        }

        $bankResponse = $result['data'] ?? [];
        
        $this->logEvent($swapRef, 'PLACE_HOLD_BANK_RESPONSE', ['bank_response' => $bankResponse]);

        $holdPlaced = false;
        if (isset($bankResponse['hold_placed'])) {
            $holdPlaced = $bankResponse['hold_placed'] === true || $bankResponse['hold_placed'] === 'true' || $bankResponse['hold_placed'] === 1;
        } elseif (isset($bankResponse['status']) && strtoupper($bankResponse['status']) === 'SUCCESS') {
            $holdPlaced = true;
        } elseif (isset($bankResponse['success']) && $bankResponse['success'] === true) {
            $holdPlaced = true;
        }

        if (!$holdPlaced) {
            $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? $bankResponse['reason'] ?? 'Failed to place hold';
            $this->logEvent($swapRef, 'PLACE_HOLD_FAILED', ['error' => $errorMsg, 'bank_response' => $bankResponse]);
            return ['hold_placed' => false, 'message' => $errorMsg];
        }

        $holdReference = $bankResponse['hold_reference'] ?? $bankResponse['reference'] ?? $bankResponse['transaction_id'] ?? null;
        $holdExpiry = $bankResponse['hold_expiry'] ?? $bankResponse['expiry'] ?? null;

        $this->logEvent($swapRef, 'PLACE_HOLD_SUCCESS', [
            'hold_reference' => $holdReference,
            'hold_expiry' => $holdExpiry
        ]);

        if ($holdReference) {
            $this->recordHoldTransaction($swapRef, [
                'hold_reference' => $holdReference,
                'hold_expiry' => $holdExpiry,
                'hold_placed' => true
            ], $source, $participant, $destination);
        }

        return [
            'hold_placed' => true,
            'hold_reference' => $holdReference,
            'hold_expiry' => $holdExpiry
        ];
    }

    private function processCashout(int $swapId, string $swapRef, array $source, array $destination, string $currency, array $holdResult): void
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
            $cashoutData = $destination['cashout'];
            
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
            
            $rawCode = (string)random_int(100000, 999999);
            $codeHash = password_hash($rawCode, PASSWORD_BCRYPT);
            $debug[] = ['time' => microtime(true), 'step' => 'CODE_GENERATED', 'raw_length' => strlen($rawCode), 'hash_length' => strlen($codeHash)];

            $debug[] = ['time' => microtime(true), 'step' => 'CREATING_VOUCHER'];
            $this->createCashoutVoucher($swapId, $codeHash, $destination, $rawCode, $cashoutData);
            $debug[] = ['time' => microtime(true), 'step' => 'VOUCHER_CREATED'];
            
            $debug[] = ['time' => microtime(true), 'step' => 'REQUESTING_ATM_TOKEN'];
            $atmToken = $this->requestAtmToken($swapRef, $source, $destination, $codeHash, $holdResult, $destParticipant, $netAmount);
            $debug[] = ['time' => microtime(true), 'step' => 'ATM_TOKEN_RECEIVED', 'token_reference' => $atmToken['token_reference'] ?? null];

            $debug[] = ['time' => microtime(true), 'step' => 'SENDING_SMS'];
            $this->sendWithdrawalSms($cashoutData['beneficiary_phone'], $rawCode, $atmToken, $netAmount, $currency);
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
            
        } catch (Exception $e) {
            $debug[] = ['time' => microtime(true), 'step' => 'CASHOUT_EXCEPTION', 'error' => $e->getMessage()];
            error_log("CASHOUT ERROR: " . json_encode($debug));
            throw $e;
        }
        
        error_log("CASHOUT DEBUG: " . json_encode($debug));
    }

    private function createCashoutVoucher(int $swapId, string $codeHash, array $destination, string $rawCode, array $cashoutData): void
    {
        $expiry = new DateTimeImmutable('+24 hours');

        $stmt = $this->swapDB->prepare("
            INSERT INTO swap_vouchers 
            (swap_id, code_hash, code_suffix, amount, expiry_at, status, claimant_phone, is_cardless_redemption)
            VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, TRUE)
            RETURNING voucher_id
        ");

        $stmt->execute([
            $swapId,
            $codeHash,
            substr($rawCode, -4),
            $destination['amount'],
            $expiry->format('Y-m-d H:i:s'),
            $cashoutData['beneficiary_phone']
        ]);

        $voucherId = $stmt->fetchColumn();
        
        $this->logEvent('VOUCHER_CREATED', 'INFO', [
            'voucher_id' => $voucherId,
            'beneficiary' => substr($cashoutData['beneficiary_phone'], -8),
            'amount' => $destination['amount']
        ]);
    }

   /**
 * Request ATM token from destination institution - ENHANCED WITH DEBUGGING
 */
private function requestAtmToken(string $swapRef, array $source, array $destination, string $codeHash, array $holdResult, array $participant, ?float $netAmount = null): array
{
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
            'beneficiary_phone' => $destination['cashout']['beneficiary_phone'],
            'amount' => $netAmount ?? $destination['amount'],
            'code_hash' => $codeHash,
            'action' => 'GENERATE_ATM_TOKEN'
        ];
        
        $debug[] = ['time' => microtime(true), 'step' => 'PAYLOAD_PREPARED', 'payload' => $payload];

        // Log before API call
        error_log("REQUEST_TOKEN: About to call bankClient->transfer with payload: " . json_encode($payload));
        
        $result = $bankClient->transfer($payload, 'generate_atm_code');
        
        $debug[] = ['time' => microtime(true), 'step' => 'API_CALL_COMPLETE', 'result_success' => $result['success'] ?? false];
        error_log("REQUEST_TOKEN: API call result: " . json_encode($result));

        $this->debugApiCall('generate_token', ['reference' => $swapRef], $result);

        // Log the API call
        $this->logApiMessage(
            $swapRef,
            'generate_token',
            'outgoing',
            $participant,
            '/api/generate-atm-code',
            $payload,
            $result,
            null
        );

        // Check if HTTP request was successful
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
            error_log("REQUEST_TOKEN DEBUG: " . json_encode($debug));
            throw new RuntimeException("Failed to generate ATM token: " . $errorMsg);
        }

        // Extract the actual bank response from the 'data' field
        $bankResponse = $result['data'] ?? [];
        $debug[] = ['time' => microtime(true), 'step' => 'BANK_RESPONSE_RECEIVED', 'bank_response' => $bankResponse];

        $this->logEvent($swapRef, 'ATM_TOKEN_BANK_RESPONSE', [
            'bank_response' => $bankResponse
        ]);

        // Check if token was generated successfully
        if (!isset($bankResponse['token_generated']) || $bankResponse['token_generated'] !== true) {
            $errorMsg = $bankResponse['message'] ?? $bankResponse['error'] ?? 'Unknown error';
            $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_NOT_GENERATED', 'error' => $errorMsg];
            error_log("REQUEST_TOKEN ERROR: Token not generated - " . $errorMsg);
            error_log("REQUEST_TOKEN DEBUG: " . json_encode($debug));
            throw new RuntimeException("Failed to generate ATM token: " . $errorMsg);
        }

        $this->logEvent($swapRef, 'ATM_TOKEN_GENERATED', [
            'institution' => $participant['provider_code'] ?? $destination['institution'],
            'token_reference' => $bankResponse['token_reference'] ?? null
        ]);
        
        $debug[] = ['time' => microtime(true), 'step' => 'TOKEN_GENERATED_SUCCESS', 'token_ref' => $bankResponse['token_reference'] ?? null];
        error_log("REQUEST_TOKEN SUCCESS: " . json_encode($debug));

    } catch (Exception $e) {
        $debug[] = ['time' => microtime(true), 'step' => 'REQUEST_TOKEN_EXCEPTION', 'error' => $e->getMessage()];
        error_log("REQUEST_TOKEN EXCEPTION: " . $e->getMessage());
        error_log("REQUEST_TOKEN EXCEPTION DEBUG: " . json_encode($debug));
        error_log("REQUEST_TOKEN EXCEPTION TRACE: " . $e->getTraceAsString());
        throw $e;
    }

    return $bankResponse;
}

    private function sendBankAuthorization(string $swapRef, array $source, array $destination, string $codeHash, array $participant): void
    {
        $bankClient = new GenericBankClient($participant);
        $bankClient->transfer([
            'reference' => $swapRef,
            'source_institution' => $source['institution'],
            'source_hold_reference' => $source['hold_reference'] ?? null,
            'beneficiary_phone' => $destination['cashout']['beneficiary_phone'],
            'amount' => $destination['amount'],
            'code_hash' => $codeHash,
            'action' => 'AUTHORIZE_CASHOUT'
        ], 'authorize');
    }

    /**
     * COMPLETELY DISABLED SMS SENDING - JUST LOGS
     */
    private function sendWithdrawalSms(string $phone, string $code, array $atmInfo, float $amount, string $currency): void
    {
        error_log("=== SMS COMPLETELY DISABLED ===");
        error_log("Would send SMS to: " . $phone);
        error_log("Withdrawal code: " . $code);
        error_log("ATM PIN: " . ($atmInfo['atm_pin'] ?? 'N/A'));
        error_log("Amount: " . $amount . " " . $currency);
        return;
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
                'fee_id' => $feeDetails['fee_id'],
                'total_fee' => $feeDetails['fee_amount'],
                'net_amount' => $feeDetails['net_amount'],
                'split' => $feeDetails['split']
            ];
        }

        error_log("[QUEUE_SETTLEMENT] Metadata: " . json_encode($metadata));

        $stmt = $this->swapDB->prepare("
            INSERT INTO settlement_messages
            (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
            VALUES (?, ?, ?, ?, 'CASHOUT_SETTLEMENT', 'PENDING', ?, NOW())
        ");

        $stmt->execute([
            $swapRef,
            $source['institution'],
            $destination['institution'],
            $amount,
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
                VALUES (?, ?, ?, ?, 'RETURN', 'PENDING', ?, NOW())
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
        }

        $destinationDetails = [
            'institution' => $destination['institution'],
            'asset_type' => $destinationAssetType,
            'delivery_mode' => $destination['delivery_mode'] ?? 'deposit',
            'beneficiary' => $destination['cashout']['beneficiary_phone'] ?? 
                            $destination['beneficiary_account'] ?? 
                            $destination['beneficiary_wallet'] ?? 
                            $destination['cashout']['beneficiary'] ??
                            null
        ];

        error_log("[recordMasterSwap] Destination details: " . json_encode($destinationDetails));

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

        return $stmt->fetchColumn();
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
        }
        
        error_log("[maskIdentifier] Asset type: {$assetType}, Source keys: " . implode(', ', array_keys($source)));
        
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
            
            return [
                'swap' => $swap,
                'holds' => $holds,
                'api_messages' => $apiLogs,
                'fees' => $fees
            ];
        } catch (Exception $e) {
            error_log("Failed to get transaction trace: " . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}

