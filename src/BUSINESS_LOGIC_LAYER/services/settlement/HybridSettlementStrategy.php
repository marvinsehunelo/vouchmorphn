<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services\settlement;

use PDO;
use DateTimeImmutable;
use Exception;

/**
 * Ledger-Based Hybrid Settlement Strategy
 * Message-driven + double-entry accounting + net positions
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $currency = 'BWP';
    
    // Settlement constants
    private const SETTLEMENT_BATCH_SIZE = 100;
    private const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /* =====================================================
       DEPOSIT
    ===================================================== */

    public function processDeposit(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount
    ): void {

        $this->createSettlementLedgerEntry(
            $fromInstitution,
            $toInstitution,
            $amount,
            'DEPOSIT'
        );

        $this->enqueueSettlementMessage(
            $legRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            'DEPOSIT'
        );
        
        // Also update net_positions
        $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount);
    }

    /* =====================================================
       CASHOUT AUTH
    ===================================================== */

    public function processCashoutAuthorization(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        DateTimeImmutable $expiry,
        float $feeAmount = 0.0,
        string $feeMode = 'deduct'
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
                'fee_mode' => $feeMode
            ]
        );
        
        // For auth, we might not want to update net positions yet
        // Only update when cashout is confirmed
    }

    /* =====================================================
       CASHOUT CONFIRM
    ===================================================== */

    public function confirmCashout(string $legRef, float $amount): void
    {
        $this->enqueueSettlementMessage(
            $legRef,
            null,
            null,
            $amount,
            'CASHOUT_CONFIRM'
        );
        
        // You might need to lookup the institutions from the original auth
        // Then update net_positions here
    }

    /* =====================================================
       CASHOUT REVERSAL
    ===================================================== */

    public function reverseCashout(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount
    ): void {

        $this->createSettlementLedgerEntry(
            $toInstitution,
            $fromInstitution,
            $amount,
            'CASHOUT_REVERSAL'
        );

        $this->enqueueSettlementMessage(
            $legRef,
            $fromInstitution,
            $toInstitution,
            $amount,
            'CASHOUT_REVERSAL'
        );
        
        // Update net_positions (reverse the original amount)
        $this->updateNetPositionsTable($toInstitution, $fromInstitution, $amount);
    }

    /* =====================================================
       AUTO SWAP-TO-SWAP
    ===================================================== */

    public function autoSwapToSwap(string $from, string $to, float $amount): void
    {
        if ($amount <= 0) return;

        $legRef = 'AUTO_' . bin2hex(random_bytes(6));

        $this->createSettlementLedgerEntry(
            $from,
            $to,
            $amount,
            'SWAP_TO_SWAP'
        );

        $this->enqueueSettlementMessage(
            $legRef,
            $from,
            $to,
            $amount,
            'SWAP_TO_SWAP'
        );
        
        // Update net_positions
        $this->updateNetPositionsTable($from, $to, $amount);
    }

    /* =====================================================
       CORE LEDGER ENTRY (DOUBLE ENTRY)
    ===================================================== */

    private function createSettlementLedgerEntry(
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $type
    ): void {

        $debitAccount  = $this->getOrCreateSettlementAccount($fromInstitution);
        $creditAccount = $this->getOrCreateSettlementAccount($toInstitution);

        // Create transaction
        $txStmt = $this->db->prepare("
            INSERT INTO transactions (transaction_type, amount, status)
            VALUES (:type, :amount, 'PDNG')
            RETURNING transaction_id
        ");
        $txStmt->execute([
            ':type' => $type,
            ':amount' => $amount
        ]);
        $transactionId = $txStmt->fetchColumn();

        // Create ledger entry
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
            ':currency' => $this->currency,
            ':reference' => $type
        ]);
    }

    /* =====================================================
       UPDATE NET POSITIONS TABLE
    ===================================================== */

    private function updateNetPositionsTable(
        string $debtorInstitution,
        string $creditorInstitution,
        float $amount
    ): void {
        try {
            // Check if a record already exists
            $checkStmt = $this->db->prepare("
                SELECT amount FROM net_positions 
                WHERE debtor = :debtor AND creditor = :creditor
            ");
            $checkStmt->execute([
                ':debtor' => $debtorInstitution,
                ':creditor' => $creditorInstitution
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing record
                $stmt = $this->db->prepare("
                    UPDATE net_positions 
                    SET amount = amount + :amount,
                        updated_at = NOW()
                    WHERE debtor = :debtor AND creditor = :creditor
                ");
                
                $stmt->execute([
                    ':debtor' => $debtorInstitution,
                    ':creditor' => $creditorInstitution,
                    ':amount' => $amount
                ]);
                
                error_log("Net position updated: $debtorInstitution owes $creditorInstitution +$amount (total now: " . ($existing['amount'] + $amount) . ")");
            } else {
                // Insert new record
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
                    ':currency' => $this->currency
                ]);
                
                error_log("Net position created: $debtorInstitution owes $creditorInstitution $amount");
            }
            
        } catch (Exception $e) {
            error_log("Failed to update net_positions table: " . $e->getMessage());
            // Don't throw - net positions are important but shouldn't break the main flow
        }
    }

    /**
     * Get participant ID by name or provider code
     */
    private function getParticipantIdByName(string $name): ?int
    {
        static $cache = [];
        
        if (isset($cache[$name])) {
            return $cache[$name];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT participant_id FROM participants 
                WHERE name = :name OR provider_code = :name
                LIMIT 1
            ");
            $stmt->execute([':name' => $name]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $cache[$name] = (int)$result['participant_id'];
                return $cache[$name];
            }
        } catch (Exception $e) {
            error_log("Failed to get participant ID for $name: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Get current net position between two participants
     */
    public function getNetPosition(string $debtor, string $creditor): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT amount FROM net_positions
                WHERE debtor = :debtor 
                AND creditor = :creditor
            ");
            $stmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['amount'] : 0.0;
            
        } catch (Exception $e) {
            error_log("Failed to get net position: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Reset net position after settlement (for end-of-day)
     */
    public function resetNetPosition(string $debtor, string $creditor): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE net_positions 
                SET amount = 0, 
                    updated_at = NOW()
                WHERE debtor = :debtor 
                AND creditor = :creditor
            ");
            $stmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor
            ]);
            
            error_log("Net position reset: $debtor -> $creditor");
            
        } catch (Exception $e) {
            error_log("Failed to reset net position: " . $e->getMessage());
        }
    }

    /* =====================================================
       UPDATE NET POSITION (MAIN ENTRY POINT)
    ===================================================== */

    public function updateNetPosition(string $fromInstitution, string $toInstitution, float $amount): void
    {
        // Keep your existing ledger entry (for audit)
        $this->createSettlementLedgerEntry(
            $fromInstitution,
            $toInstitution,
            $amount,
            'INTER_PARTICIPANT_SETTLEMENT'
        );

        // Also update the net_positions table (for quick reporting)
        $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount);
    }

    /* =====================================================
       SETTLEMENT MESSAGE
    ===================================================== */

    private function enqueueSettlementMessage(
        ?string $legRef,
        ?string $from,
        ?string $to,
        float $amount,
        string $type,
        array $metadata = []
    ): void {

        $stmt = $this->db->prepare("
            INSERT INTO settlement_messages
            (transaction_id, from_participant, to_participant, amount, type, status, metadata)
            VALUES (:tx, :from, :to, :amount, :type, 'PENDING', :meta)
        ");

        $stmt->execute([
            ':tx' => $legRef,
            ':from' => $from,
            ':to' => $to,
            ':amount' => $amount,
            ':type' => $type,
            ':meta' => json_encode($metadata)
        ]);
    }

    // ============================================================================
    // SETTLEMENT FINALIZATION METHODS
    // ============================================================================

    /**
     * Process pending settlement messages and finalize them
     * This should be called by a cron job every 15-30 minutes
     * 
     * @return array Statistics of processed settlements
     */
    public function finalizePendingSettlements(): array
    {
        $stats = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
            'net_positions_updated' => 0,
            'total_amount' => 0
        ];
        
        try {
            // Get pending settlements, grouped by participant pair
            $pending = $this->db->prepare("
                SELECT 
                    from_participant,
                    to_participant,
                    COUNT(*) as message_count,
                    SUM(amount) as total_amount,
                    MIN(created_at) as oldest_message,
                    MAX(created_at) as newest_message
                FROM settlement_messages
                WHERE status = 'PENDING'
                GROUP BY from_participant, to_participant
                ORDER BY oldest_message ASC
                LIMIT :limit
            ");
            $pending->bindValue(':limit', self::SETTLEMENT_BATCH_SIZE, PDO::PARAM_INT);
            $pending->execute();
            
            while ($group = $pending->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $this->db->beginTransaction();
                    
                    $fromParticipant = $group['from_participant'];
                    $toParticipant = $group['to_participant'];
                    $totalAmount = (float)$group['total_amount'];
                    
                    $this->logSettlementEvent('SETTLEMENT_GROUP_PROCESSING', [
                        'from' => $fromParticipant,
                        'to' => $toParticipant,
                        'amount' => $totalAmount,
                        'message_count' => $group['message_count']
                    ]);
                    
                    // 1. Create ledger entry for this settlement
                    $debitAccount = $this->getOrCreateSettlementAccount($fromParticipant);
                    $creditAccount = $this->getOrCreateSettlementAccount($toParticipant);
                    
                    $txStmt = $this->db->prepare("
                        INSERT INTO transactions 
                        (transaction_type, amount, status, created_at)
                        VALUES ('SETTLEMENT_FINAL', :amount, 'COMPLETED', NOW())
                        RETURNING transaction_id
                    ");
                    $txStmt->execute([':amount' => $totalAmount]);
                    $transactionId = $txStmt->fetchColumn();
                    
                    $ledgerStmt = $this->db->prepare("
                        INSERT INTO ledger_entries
                        (transaction_id, debit_account_id, credit_account_id, 
                         amount, currency_code, reference, created_at)
                        VALUES (:tx, :debit, :credit, :amount, :currency, 
                                 'SETTLEMENT_FINAL', NOW())
                    ");
                    $ledgerStmt->execute([
                        ':tx' => $transactionId,
                        ':debit' => $debitAccount,
                        ':credit' => $creditAccount,
                        ':amount' => $totalAmount,
                        ':currency' => $this->currency
                    ]);
                    
                    // 2. Update all settlement messages in this group to COMPLETED
                    $updateMsg = $this->db->prepare("
                        UPDATE settlement_messages 
                        SET status = 'COMPLETED', 
                            processed_at = NOW(),
                            metadata = jsonb_set(
                                COALESCE(metadata, '{}'::jsonb),
                                '{settlement_details}',
                                jsonb_build_object(
                                    'finalized_at', to_jsonb(NOW()),
                                    'transaction_id', to_jsonb(?),
                                    'batch_total', to_jsonb(?)
                                )
                            )
                        WHERE from_participant = ? 
                        AND to_participant = ? 
                        AND status = 'PENDING'
                    ");
                    $updateMsg->execute([
                        $transactionId,
                        $totalAmount,
                        $fromParticipant,
                        $toParticipant
                    ]);
                    
                    // 3. Update net positions (add to existing or create new)
                    $this->updateNetPositionsForSettlement($fromParticipant, $toParticipant, $totalAmount);
                    
                    // 4. Update swap_ledgers if needed
                    $updateLedgers = $this->db->prepare("
                        UPDATE swap_ledgers 
                        SET settled_at = NOW(),
                            status = 'settled'
                        WHERE from_institution = ? 
                        AND to_institution = ?
                        AND status = 'pending'
                    ");
                    $updateLedgers->execute([$fromParticipant, $toParticipant]);
                    
                    $this->db->commit();
                    
                    $stats['processed']++;
                    $stats['net_positions_updated']++;
                    $stats['total_amount'] += $totalAmount;
                    
                    $this->logSettlementEvent('SETTLEMENT_GROUP_COMPLETED', [
                        'from' => $fromParticipant,
                        'to' => $toParticipant,
                        'amount' => $totalAmount,
                        'message_count' => $group['message_count']
                    ]);
                    
                } catch (Exception $e) {
                    $this->db->rollBack();
                    $stats['failed']++;
                    $stats['errors'][] = $fromParticipant . '→' . $toParticipant . ': ' . $e->getMessage();
                    
                    $this->logSettlementEvent('SETTLEMENT_GROUP_FAILED', [
                        'from' => $fromParticipant,
                        'to' => $toParticipant,
                        'error' => $e->getMessage()
                    ], 'error');
                }
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Fatal error in finalizePendingSettlements: " . $e->getMessage());
            $stats['fatal_error'] = $e->getMessage();
        }
        
        return $stats;
    }

    /**
     * Update net positions table specifically for settlement finalization
     */
    private function updateNetPositionsForSettlement(string $debtor, string $creditor, float $amount): void
    {
        try {
            // Check if record exists
            $checkStmt = $this->db->prepare("
                SELECT amount FROM net_positions 
                WHERE debtor = :debtor AND creditor = :creditor
            ");
            $checkStmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Add to existing (this is a DEBIT for debtor, CREDIT for creditor)
                $stmt = $this->db->prepare("
                    UPDATE net_positions 
                    SET amount = amount + :amount,
                        updated_at = NOW()
                    WHERE debtor = :debtor AND creditor = :creditor
                ");
                $stmt->execute([
                    ':debtor' => $debtor,
                    ':creditor' => $creditor,
                    ':amount' => $amount
                ]);
            } else {
                // Create new record
                $stmt = $this->db->prepare("
                    INSERT INTO net_positions 
                        (debtor, creditor, amount, currency_code, created_at, updated_at)
                    VALUES 
                        (:debtor, :creditor, :amount, :currency, NOW(), NOW())
                ");
                $stmt->execute([
                    ':debtor' => $debtor,
                    ':creditor' => $creditor,
                    ':amount' => $amount,
                    ':currency' => $this->currency
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Failed to update net positions for settlement: " . $e->getMessage());
            throw $e; // Re-throw because settlement should fail if net positions can't be updated
        }
    }

    /**
     * Calculate net positions for all participants (for reporting)
     */
    public function calculateAllNetPositions(): array
    {
        $result = [];
        
        try {
            $stmt = $this->db->query("
                SELECT 
                    debtor,
                    creditor,
                    SUM(amount) as net_amount
                FROM net_positions
                GROUP BY debtor, creditor
                ORDER BY debtor, creditor
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[] = [
                    'debtor' => $row['debtor'],
                    'creditor' => $row['creditor'],
                    'amount' => (float)$row['net_amount']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Failed to calculate net positions: " . $e->getMessage());
        }
        
        return $result;
    }

    /**
     * Reset net positions after settlement (end of day)
     */
    public function resetNetPositions(): array
    {
        $stats = ['reset_count' => 0];
        
        try {
            $this->db->beginTransaction();
            
            // Check if archive table exists, create if not
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS net_positions_archive (
                    id BIGSERIAL PRIMARY KEY,
                    debtor VARCHAR(100),
                    creditor VARCHAR(100),
                    amount NUMERIC(20,8),
                    currency_code CHAR(3),
                    archived_at TIMESTAMPTZ DEFAULT NOW()
                )
            ");
            
            // Archive current positions (optional - for audit)
            $archiveStmt = $this->db->query("
                INSERT INTO net_positions_archive 
                    (debtor, creditor, amount, currency_code, archived_at)
                SELECT 
                    debtor, creditor, amount, currency_code, NOW()
                FROM net_positions
                WHERE amount != 0
            ");
            
            // Reset all positions to zero
            $resetStmt = $this->db->prepare("
                UPDATE net_positions 
                SET amount = 0, updated_at = NOW()
                WHERE amount != 0
            ");
            $resetStmt->execute();
            $stats['reset_count'] = $resetStmt->rowCount();
            
            $this->db->commit();
            
            $this->logSettlementEvent('NET_POSITIONS_RESET', [
                'count' => $stats['reset_count']
            ]);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Failed to reset net positions: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }

    /**
     * Log settlement events for audit trail
     */
    private function logSettlementEvent(string $event, array $data, string $severity = 'info'): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'event' => $event,
            'severity' => $severity,
            'data' => $data
        ]);
        
        file_put_contents('/tmp/vouchmorph_settlement.log', $logEntry . PHP_EOL, FILE_APPEND);
        
        // Also log to database audit_logs if available
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs 
                (entity, action, category, severity, performed_at, new_value)
                VALUES ('SETTLEMENT', ?, 'SETTLEMENT', ?, NOW(), ?::jsonb)
            ");
            $stmt->execute([$event, $severity, json_encode($data)]);
        } catch (Exception $e) {
            // Non-critical, just log
            error_log("Failed to write settlement audit log: " . $e->getMessage());
        }
    }

    /**
     * Get settlement summary for dashboard
     */
    public function getSettlementSummary(): array
    {
        $summary = [];
        
        try {
            // Pending settlements count and value
            $pendingStmt = $this->db->query("
                SELECT 
                    COUNT(*) as pending_count,
                    COALESCE(SUM(amount), 0) as pending_value
                FROM settlement_messages
                WHERE status = 'PENDING'
            ");
            $summary['pending'] = $pendingStmt->fetch(PDO::FETCH_ASSOC);
            
            // Net positions
            $netStmt = $this->db->query("
                SELECT 
                    COUNT(DISTINCT debtor) as debtor_count,
                    COUNT(DISTINCT creditor) as creditor_count,
                    COALESCE(SUM(amount), 0) as total_exposure
                FROM net_positions
                WHERE amount != 0
            ");
            $summary['net_positions'] = $netStmt->fetch(PDO::FETCH_ASSOC);
            
            // Today's settlements
            $todayStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as today_count,
                    COALESCE(SUM(amount), 0) as today_value
                FROM settlement_messages
                WHERE DATE(created_at) = CURRENT_DATE
                AND status = 'COMPLETED'
            ");
            $todayStmt->execute();
            $summary['today'] = $todayStmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get settlement summary: " . $e->getMessage());
        }
        
        return $summary;
    }

    /* =====================================================
       GET OR CREATE SETTLEMENT ACCOUNT
    ===================================================== */

    private function getOrCreateSettlementAccount(string $institution): int
    {
        $stmt = $this->db->prepare("
            SELECT account_id
            FROM ledger_accounts
            WHERE account_name = :name
            AND account_type = 'settlement'
            LIMIT 1
        ");

        $stmt->execute([
            ':name' => $institution . '_SETTLEMENT'
        ]);

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
            ':code' => strtoupper(substr($institution, 0, 6)) . '_' . time(),
            ':name' => $institution . '_SETTLEMENT',
            ':currency' => $this->currency
        ]);

        return (int)$insert->fetchColumn();
    }
}
