<?php

declare(strict_types=1);

namespace Domain\Services\Settlement;

use PDO;
use DateTimeImmutable;
use DateTime;
use Exception;

/**
 * Ledger-Based Hybrid Settlement Strategy
 * Multi-currency, cross-border capable, non-custodial
 * 
 * Design Principles:
 * 1. VouchMorph NEVER holds customer funds - only coordinates settlement messages
 * 2. Real money moves through participant rails (RTGS, SWIFT, central bank)
 * 3. Net settlement reduces correspondent banking dependency
 * 4. Supports retail to institutional high-value transfers
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $defaultCurrency = 'BWP';
    
    // Settlement profiles
    public const PROFILE_RETAIL = 'RETAIL';           // < $10,000
    public const PROFILE_COMMERCIAL = 'COMMERCIAL';   // $10k - $1M
    public const PROFILE_INSTITUTIONAL = 'INSTITUTIONAL'; // $1M - $50M
    public const PROFILE_HIGH_VALUE = 'HIGH_VALUE';   // $50M+
    public const PROFILE_SOVEREIGN = 'SOVEREIGN';     // Government level
    
    // Settlement statuses
    private const STATUS_PENDING = 'PENDING';
    private const STATUS_HELD = 'HELD';
    private const STATUS_CONFIRMED = 'CONFIRMED';
    private const STATUS_SETTLED = 'SETTLED';
    private const STATUS_FAILED = 'FAILED';
    private const STATUS_RELEASED = 'RELEASED';
    
    // Hold types
    private const HOLD_RETAIL = 'RETAIL_HOLD';
    private const HOLD_ESCROW = 'ESCROW_HOLD';
    private const HOLD_CONDITIONAL = 'CONDITIONAL_HOLD';
    private const HOLD_INSTITUTIONAL = 'INSTITUTIONAL_HOLD';
    private const HOLD_SETTLEMENT = 'SETTLEMENT_HOLD';
    
    // Settlement methods
    private const METHOD_RTGS = 'RTGS';
    private const METHOD_SWIFT = 'SWIFT';
    private const METHOD_NOSTRO = 'NOSTRO_VOSTRO';
    private const METHOD_CLS = 'CLS';
    private const METHOD_CENTRAL_BANK = 'CENTRAL_BANK';
    private const METHOD_NETTING = 'NETTING';
    private const METHOD_INTERNAL = 'INTERNAL';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTablesExist();
    }
    
    /**
     * Ensure settlement tables exist (run once)
     */
    private function ensureTablesExist(): void
    {
        // Settlement holds table - tracks conditional holds for high-value
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settlement_holds (
                hold_id BIGSERIAL PRIMARY KEY,
                hold_reference VARCHAR(100) UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                to_participant VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                hold_type VARCHAR(50) NOT NULL,
                hold_status VARCHAR(20) DEFAULT 'ACTIVE',
                settlement_profile VARCHAR(50) NOT NULL,
                settlement_method VARCHAR(50),
                conditions_met BOOLEAN DEFAULT FALSE,
                condition_metadata JSONB,
                expires_at TIMESTAMP,
                confirmed_at TIMESTAMP,
                settled_at TIMESTAMP,
                released_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Cross-border settlement queue
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cross_border_settlements (
                settlement_id BIGSERIAL PRIMARY KEY,
                settlement_reference VARCHAR(100) UNIQUE NOT NULL,
                from_country CHAR(2) NOT NULL,
                to_country CHAR(2) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                to_participant VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                source_currency CHAR(3) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                exchange_rate NUMERIC(24,10),
                fx_provider_id BIGINT,
                settlement_method VARCHAR(50),
                status VARCHAR(20) DEFAULT 'PENDING',
                swift_message_reference VARCHAR(50),
                nostro_account VARCHAR(100),
                vostro_account VARCHAR(100),
                net_settlement_amount NUMERIC(24,2),
                settlement_instructions JSONB,
                completed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Net position history for audit
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS net_position_history (
                history_id BIGSERIAL PRIMARY KEY,
                debtor VARCHAR(100) NOT NULL,
                creditor VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                settlement_batch_id VARCHAR(100),
                settled_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // FX settlement instructions for non-custodial movement
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_settlement_instructions (
                instruction_id BIGSERIAL PRIMARY KEY,
                instruction_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                to_participant VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                source_currency CHAR(3) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                exchange_rate NUMERIC(24,10),
                settlement_method VARCHAR(50),
                correspondent_bank VARCHAR(100),
                intermediary_bank VARCHAR(100),
                beneficiary_bank VARCHAR(100),
                beneficiary_account VARCHAR(100),
                swift_mt103 JSONB,
                swift_mt202 JSONB,
                status VARCHAR(20) DEFAULT 'PENDING',
                executed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    /**
     * UPDATE NET POSITION - Main entry point with multi-currency support
     * 
     * @param string $fromInstitution - Source institution (debtor)
     * @param string $toInstitution - Destination institution (creditor)
     * @param float $amount - Amount to record
     * @param string $transactionType - Type of transaction
     * @param string $currency - Currency code (default: BWP)
     * @param string $settlementProfile - RETAIL|COMMERCIAL|INSTITUTIONAL|HIGH_VALUE
     */
    public function updateNetPosition(
        string $fromInstitution, 
        string $toInstitution, 
        float $amount, 
        string $transactionType,
        string $currency = 'BWP',
        string $settlementProfile = self::PROFILE_RETAIL
    ): void {
        try {
            // For high value, use institutional flow with holds
            if (in_array($settlementProfile, [self::PROFILE_INSTITUTIONAL, self::PROFILE_HIGH_VALUE, self::PROFILE_SOVEREIGN])) {
                $this->processHighValueSettlement(
                    $fromInstitution,
                    $toInstitution,
                    $amount,
                    $transactionType,
                    $currency,
                    $settlementProfile
                );
                return;
            }
            
            // For retail/commercial, standard ledger entry
            $this->createSettlementLedgerEntry($fromInstitution, $toInstitution, $amount, $transactionType, $currency);
            $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount, $currency);
            
            $legRef = 'SWAP_' . bin2hex(random_bytes(8));
            $this->enqueueSettlementMessage(
                $legRef,
                $fromInstitution,
                $toInstitution,
                $amount,
                strtoupper($transactionType) . '_SETTLEMENT',
                ['currency' => $currency, 'profile' => $settlementProfile]
            );
            
            error_log("[SETTLEMENT] Updated net position: $fromInstitution → $toInstitution: $amount $currency ($transactionType)");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process high-value institutional settlement with holds
     * NON-CUSTODIAL: VouchMorph coordinates, never holds funds
     */
    private function processHighValueSettlement(
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $transactionType,
        string $currency,
        string $settlementProfile
    ): void {
        $holdRef = 'HLD_' . bin2hex(random_bytes(16));
        $swapRef = 'SWAP_' . bin2hex(random_bytes(16));
        
        // Determine appropriate settlement method based on amount and currencies
        $settlementMethod = $this->determineSettlementMethod($amount, $currency, $settlementProfile);
        
        // Create settlement hold record (not actual fund hold - just coordination)
        $stmt = $this->db->prepare("
            INSERT INTO settlement_holds 
            (hold_reference, swap_reference, from_participant, to_participant, 
             amount, currency, hold_type, settlement_profile, settlement_method, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ");
        
        $stmt->execute([
            $holdRef,
            $swapRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            $currency,
            self::HOLD_INSTITUTIONAL,
            $settlementProfile,
            $settlementMethod
        ]);
        
        error_log("[SETTLEMENT] High-value settlement hold created: $holdRef for $amount $currency");
        
        // Create cross-border settlement record
        $countryFrom = $this->getParticipantCountry($fromInstitution);
        $countryTo = $this->getParticipantCountry($toInstitution);
        
        if ($countryFrom !== $countryTo) {
            $this->createCrossBorderSettlement(
                $swapRef,
                $countryFrom,
                $countryTo,
                $fromInstitution,
                $toInstitution,
                $amount,
                $currency,
                $currency, // Same currency for now, FX handled separately
                null,
                $settlementMethod
            );
        }
        
        // Queue settlement instruction (non-custodial - just instructions)
        $this->queueSettlementInstruction(
            $swapRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            $currency,
            $settlementMethod
        );
    }
    
    /**
     * Determine appropriate settlement method based on value
     */
    private function determineSettlementMethod(float $amount, string $currency, string $profile): string
    {
        if ($profile === self::PROFILE_SOVEREIGN || $amount > 100000000) {
            return self::METHOD_CENTRAL_BANK;
        }
        
        if ($profile === self::PROFILE_HIGH_VALUE || $amount > 50000000) {
            return self::METHOD_RTGS;
        }
        
        if ($profile === self::PROFILE_INSTITUTIONAL || $amount > 1000000) {
            return self::METHOD_SWIFT;
        }
        
        if ($currency !== 'BWP') {
            return self::METHOD_NOSTRO;
        }
        
        return self::METHOD_INTERNAL;
    }
    
    /**
     * Queue settlement instruction (non-custodial)
     * Participants execute actual fund movement
     */
    private function queueSettlementInstruction(
        string $swapRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency,
        string $settlementMethod
    ): void {
        $instructionUuid = $this->generateUuid();
        
        $stmt = $this->db->prepare("
            INSERT INTO fx_settlement_instructions 
            (instruction_uuid, swap_reference, from_participant, to_participant, 
             amount, source_currency, destination_currency, settlement_method, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $instructionUuid,
            $swapRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            $currency,
            $currency,
            $settlementMethod
        ]);
        
        error_log("[SETTLEMENT] Settlement instruction queued: $instructionUuid - participants to settle $amount $currency");
        
        // Trigger webhook/notification to participants
        $this->notifyParticipantsOfSettlement($fromInstitution, $toInstitution, $amount, $currency, $swapRef);
    }
    
    /**
     * Notify participants to execute settlement (non-custodial)
     * VouchMorph only sends instructions - participants move actual funds
     */
    private function notifyParticipantsOfSettlement(
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency,
        string $swapRef
    ): void {
        $instruction = [
            'type' => 'SETTLEMENT_INSTRUCTION',
            'swap_reference' => $swapRef,
            'debtor' => $fromInstitution,
            'creditor' => $toInstitution,
            'amount' => $amount,
            'currency' => $currency,
            'settlement_deadline' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'instructions' => [
                'method' => 'RTGS_TRANSFER',
                'reference' => $swapRef,
                'notes' => 'Settlement per VouchMorph swap agreement'
            ]
        ];
        
        // Store instruction in outbox for participants to pick up
        $stmt = $this->db->prepare("
            INSERT INTO settlement_messages 
            (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
            VALUES (?, ?, ?, ?, 'SETTLEMENT_INSTRUCTION', 'PENDING', ?, NOW())
        ");
        
        $stmt->execute([
            $swapRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            json_encode($instruction)
        ]);
        
        error_log("[SETTLEMENT] Settlement instruction sent to $fromInstitution and $toInstitution");
    }
    
    /**
     * Create cross-border settlement record
     */
    private function createCrossBorderSettlement(
        string $swapRef,
        string $fromCountry,
        string $toCountry,
        string $fromParticipant,
        string $toParticipant,
        float $amount,
        string $sourceCurrency,
        string $destinationCurrency,
        ?float $exchangeRate,
        string $settlementMethod
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO cross_border_settlements 
            (settlement_reference, from_country, to_country, from_participant, to_participant,
             amount, source_currency, destination_currency, exchange_rate, settlement_method, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $swapRef,
            $fromCountry,
            $toCountry,
            $fromParticipant,
            $toParticipant,
            $amount,
            $sourceCurrency,
            $destinationCurrency,
            $exchangeRate,
            $settlementMethod
        ]);
        
        error_log("[SETTLEMENT] Cross-border settlement created: $fromCountry → $toCountry for $amount $sourceCurrency");
    }
    
    /**
     * Confirm settlement hold (when conditions are met)
     */
    public function confirmSettlementHold(string $holdReference, array $proofData = []): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE settlement_holds 
                SET conditions_met = TRUE,
                    condition_metadata = ?,
                    confirmed_at = NOW(),
                    hold_status = 'CONFIRMED',
                    updated_at = NOW()
                WHERE hold_reference = ? AND hold_status = 'ACTIVE'
                RETURNING hold_id
            ");
            
            $stmt->execute([json_encode($proofData), $holdReference]);
            $updated = $stmt->fetchColumn();
            
            if ($updated) {
                error_log("[SETTLEMENT] Settlement hold confirmed: $holdReference");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to confirm hold: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Release settlement hold (after successful settlement)
     */
    public function releaseSettlementHold(string $holdReference): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE settlement_holds 
                SET hold_status = 'RELEASED',
                    released_at = NOW(),
                    updated_at = NOW()
                WHERE hold_reference = ? AND conditions_met = TRUE
                RETURNING hold_id
            ");
            
            $stmt->execute([$holdReference]);
            $updated = $stmt->fetchColumn();
            
            if ($updated) {
                error_log("[SETTLEMENT] Settlement hold released: $holdReference");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to release hold: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process net settlement between nodes (reduces correspondent banking)
     * Example: Botswana node owes Nigeria node $20M, Nigeria owes Botswana $18M
     * Result: Net settlement of only $2M
     */
    public function processNetSettlement(array $nodeBalances): array
    {
        $netSettlements = [];
        $batchId = 'BATCH_' . bin2hex(random_bytes(8));
        
        foreach ($nodeBalances as $debtor => $creditors) {
            foreach ($creditors as $creditor => $amounts) {
                foreach ($amounts as $currency => $amount) {
                    if ($amount <= 0.01) continue;
                    
                    // Check if reverse position exists for netting
                    $reverseAmount = $this->getNetPosition($creditor, $debtor, $currency);
                    
                    if ($reverseAmount > 0) {
                        // Net settlement - only difference moves
                        $netAmount = abs($amount - $reverseAmount);
                        $direction = $amount > $reverseAmount ? 'debtor_to_creditor' : 'creditor_to_debtor';
                        
                        $netSettlements[] = [
                            'batch_id' => $batchId,
                            'from' => $amount > $reverseAmount ? $debtor : $creditor,
                            'to' => $amount > $reverseAmount ? $creditor : $debtor,
                            'gross_amount' => $amount,
                            'reverse_amount' => $reverseAmount,
                            'net_amount' => $netAmount,
                            'currency' => $currency,
                            'direction' => $direction,
                            'settlement_method' => self::METHOD_NETTING
                        ];
                        
                        // Clear both positions after netting
                        $this->clearNetPosition($debtor, $creditor, $currency);
                        $this->clearNetPosition($creditor, $debtor, $currency);
                        
                        error_log("[SETTLEMENT] Net settlement: $debtor → $creditor: $amount $currency netted with reverse, remaining: $netAmount");
                    } else {
                        // No netting possible - full settlement required
                        $netSettlements[] = [
                            'batch_id' => $batchId,
                            'from' => $debtor,
                            'to' => $creditor,
                            'gross_amount' => $amount,
                            'reverse_amount' => 0,
                            'net_amount' => $amount,
                            'currency' => $currency,
                            'direction' => 'debtor_to_creditor',
                            'settlement_method' => $this->determineSettlementMethod($amount, $currency, self::PROFILE_INSTITUTIONAL)
                        ];
                    }
                }
            }
        }
        
        // Record net settlement batch
        $this->recordNetSettlementBatch($batchId, $netSettlements);
        
        return $netSettlements;
    }
    
    /**
     * Record net settlement batch for audit
     */
    private function recordNetSettlementBatch(string $batchId, array $settlements): void
    {
        foreach ($settlements as $settlement) {
            $stmt = $this->db->prepare("
                INSERT INTO net_position_history 
                (debtor, creditor, amount, currency, settlement_batch_id, settled_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $settlement['from'],
                $settlement['to'],
                $settlement['net_amount'],
                $settlement['currency'],
                $batchId
            ]);
        }
        
        error_log("[SETTLEMENT] Net settlement batch recorded: $batchId with " . count($settlements) . " settlements");
    }
    
    /**
     * Get net position with currency support
     */
    public function getNetPosition(string $debtor, string $creditor, string $currency = 'BWP'): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT amount FROM net_positions
                WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
            ");
            $stmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor,
                ':currency' => $currency
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['amount'] : 0.0;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get net position: " . $e->getMessage());
            return 0.0;
        }
    }
    
    /**
     * Clear net position after settlement
     */
    private function clearNetPosition(string $debtor, string $creditor, string $currency): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM net_positions
            WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
        ");
        
        $stmt->execute([
            ':debtor' => $debtor,
            ':creditor' => $creditor,
            ':currency' => $currency
        ]);
    }
    
    /**
     * Get participant country (for cross-border detection)
     */
    private function getParticipantCountry(string $participantName): string
    {
        $stmt = $this->db->prepare("
            SELECT country_code FROM participant_currencies pc
            JOIN participants p ON pc.participant_id = p.participant_id
            WHERE p.name = ? OR p.provider_code = ?
            LIMIT 1
        ");
        
        $stmt->execute([$participantName, $participantName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['country_code'] ?? 'BW';
    }
    
    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /* =====================================================
       CORE LEDGER ENTRY (DOUBLE ENTRY) - Multi-Currency
    ===================================================== */

    private function createSettlementLedgerEntry(
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $type,
        string $currency
    ): void {
        $debitAccount  = $this->getOrCreateSettlementAccount($fromInstitution, $currency);
        $creditAccount = $this->getOrCreateSettlementAccount($toInstitution, $currency);

        $txStmt = $this->db->prepare("
            INSERT INTO transactions (transaction_type, amount, status, currency_code)
            VALUES (:type, :amount, 'PDNG', :currency)
            RETURNING transaction_id
        ");
        $txStmt->execute([
            ':type' => $type,
            ':amount' => $amount,
            ':currency' => $currency
        ]);
        $transactionId = $txStmt->fetchColumn();

        $entryStmt = $this->db->prepare("
            INSERT INTO ledger_entries
            (transaction_id, debit_account_id, credit_account_id, amount, currency_code, reference)
            VALUES (:tx, :debit, :credit, :amount, :currency, :reference)
        ");

        $entryStmt->execute([
            ':tx' => $transactionId,
            ':debit' => $debitAccount,
            ':credit' => $creditAccount,
            ':amount' => $amount,
            ':currency' => $currency,
            ':reference' => $type
        ]);
    }

    /* =====================================================
       UPDATE NET POSITIONS TABLE - Multi-Currency
    ===================================================== */

    private function updateNetPositionsTable(
        string $debtorInstitution,
        string $creditorInstitution,
        float $amount,
        string $currency
    ): void {
        try {
            $checkStmt = $this->db->prepare("
                SELECT amount, id FROM net_positions 
                WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
            ");
            $checkStmt->execute([
                ':debtor' => $debtorInstitution,
                ':creditor' => $creditorInstitution,
                ':currency' => $currency
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $stmt = $this->db->prepare("
                    UPDATE net_positions 
                    SET amount = amount + :amount,
                        updated_at = NOW()
                    WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
                ");
                $stmt->execute([
                    ':debtor' => $debtorInstitution,
                    ':creditor' => $creditorInstitution,
                    ':amount' => $amount,
                    ':currency' => $currency
                ]);
                
                error_log("[SETTLEMENT] Net position updated: $debtorInstitution owes $creditorInstitution +$amount $currency (total: " . ($existing['amount'] + $amount) . ")");
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO net_positions 
                        (debtor, creditor, amount, currency_code, created_at, updated_at)
                    VALUES 
                        (:debtor, :creditor, :amount, :currency, NOW(), NOW())
                ");
                $stmt->execute([
                    ':debtor' => $debtorInstitution,
                    ':creditor' => $creditorInstitution,
                    ':amount' => $amount,
                    ':currency' => $currency
                ]);
                
                error_log("[SETTLEMENT] Net position created: $debtorInstitution owes $creditorInstitution $amount $currency");
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net_positions table: " . $e->getMessage());
        }
    }

    /* =====================================================
       SETTLEMENT MESSAGE QUEUE
    ===================================================== */

    private function enqueueSettlementMessage(
        ?string $legRef,
        ?string $from,
        ?string $to,
        float $amount,
        string $type,
        array $metadata = []
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO settlement_messages
                (transaction_id, from_participant, to_participant, amount, type, status, metadata, created_at)
                VALUES (:tx, :from, :to, :amount, :type, 'PENDING', :meta, NOW())
            ");

            $stmt->execute([
                ':tx' => $legRef,
                ':from' => $from,
                ':to' => $to,
                ':amount' => $amount,
                ':type' => $type,
                ':meta' => json_encode($metadata)
            ]);
            
            error_log("[SETTLEMENT] Queued message: $type for $amount");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to enqueue message: " . $e->getMessage());
        }
    }

    /* =====================================================
       GET OR CREATE SETTLEMENT ACCOUNT - Multi-Currency
    ===================================================== */

    private function getOrCreateSettlementAccount(string $institution, string $currency): int
    {
        $accountName = $institution . '_SETTLEMENT_' . $currency;
        
        $stmt = $this->db->prepare("
            SELECT account_id
            FROM ledger_accounts
            WHERE account_name = :name
            AND account_type = 'settlement'
            LIMIT 1
        ");

        $stmt->execute([':name' => $accountName]);
        $accountId = $stmt->fetchColumn();

        if ($accountId) {
            return (int)$accountId;
        }

        $insert = $this->db->prepare("
            INSERT INTO ledger_accounts
            (account_code, account_name, account_type, balance, currency_code)
            VALUES (:code, :name, 'settlement', 0, :currency)
            RETURNING account_id
        ");

        $insert->execute([
            ':code' => strtoupper(substr($institution, 0, 6)) . '_' . $currency . '_' . time(),
            ':name' => $accountName,
            ':currency' => $currency
        ]);

        return (int)$insert->fetchColumn();
    }
    
    /* =====================================================
       PUBLIC API METHODS
    ===================================================== */
    
    /**
     * Process deposit - Multi-currency
     */
    public function processDeposit(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency = 'BWP'
    ): void {
        $this->createSettlementLedgerEntry($fromInstitution, $toInstitution, $amount, 'DEPOSIT', $currency);
        $this->enqueueSettlementMessage($legRef, $fromInstitution, $toInstitution, $amount, 'DEPOSIT', ['currency' => $currency]);
        $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount, $currency);
    }
    
    /**
     * Process cashout authorization
     */
    public function processCashoutAuthorization(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        DateTimeImmutable $expiry,
        float $feeAmount = 0.0,
        string $feeMode = 'deduct',
        string $currency = 'BWP'
    ): void {
        $this->enqueueSettlementMessage(
            $legRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            'CASHOUT_AUTH',
            [
                'expiry' => $expiry->format('Y-m-d H:i:s'),
                'fee_amount' => $feeAmount,
                'fee_mode' => $feeMode,
                'currency' => $currency
            ]
        );
    }
    
    /**
     * Confirm cashout
     */
    public function confirmCashout(string $legRef, float $amount, string $currency = 'BWP'): void
    {
        $this->enqueueSettlementMessage($legRef, null, null, $amount, 'CASHOUT_CONFIRM', ['currency' => $currency]);
    }
    
    /**
     * Reverse cashout
     */
    public function reverseCashout(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency = 'BWP'
    ): void {
        $this->createSettlementLedgerEntry($toInstitution, $fromInstitution, $amount, 'CASHOUT_REVERSAL', $currency);
        $this->enqueueSettlementMessage($legRef, $fromInstitution, $toInstitution, $amount, 'CASHOUT_REVERSAL', ['currency' => $currency]);
        $this->updateNetPositionsTable($toInstitution, $fromInstitution, $amount, $currency);
    }
    
    /**
     * Auto swap-to-swap settlement
     */
    public function autoSwapToSwap(string $from, string $to, float $amount, string $currency = 'BWP'): void
    {
        if ($amount <= 0) return;
        
        $legRef = 'AUTO_' . bin2hex(random_bytes(6));
        $this->createSettlementLedgerEntry($from, $to, $amount, 'SWAP_TO_SWAP', $currency);
        $this->enqueueSettlementMessage($legRef, $from, $to, $amount, 'SWAP_TO_SWAP', ['currency' => $currency]);
        $this->updateNetPositionsTable($from, $to, $amount, $currency);
    }
    
    /**
     * Process pending settlements (cron job)
     */
    public function finalizePendingSettlements(): array
    {
        $stats = ['processed' => 0, 'total_amount' => 0];
        
        try {
            $completedStmt = $this->db->prepare("
                UPDATE settlement_messages 
                SET status = 'COMPLETED', 
                    processed_at = NOW()
                WHERE status = 'PENDING'
                AND created_at < NOW() - INTERVAL '1 hour'
                RETURNING id, amount
            ");
            $completedStmt->execute();
            
            while ($row = $completedStmt->fetch(PDO::FETCH_ASSOC)) {
                $stats['processed']++;
                $stats['total_amount'] += $row['amount'];
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to finalize settlements: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Get settlement status for a swap
     */
    public function getSettlementStatus(string $swapReference): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_messages 
            WHERE transaction_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$swapReference]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Get cross-border settlement status
     */
    public function getCrossBorderStatus(string $settlementReference): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM cross_border_settlements 
            WHERE settlement_reference = ?
        ");
        $stmt->execute([$settlementReference]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Get all pending high-value holds
     */
    public function getPendingHighValueHolds(): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_holds 
            WHERE hold_status = 'ACTIVE' 
            AND settlement_profile IN ('INSTITUTIONAL', 'HIGH_VALUE', 'SOVEREIGN')
            AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
