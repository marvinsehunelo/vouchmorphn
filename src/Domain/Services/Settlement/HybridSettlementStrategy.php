<?php

declare(strict_types=1);

namespace Domain\Services\Settlement;

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

    /**
     * UPDATE NET POSITION - Main entry point called by SwapService
     * @param string $fromInstitution - Source institution (debtor)
     * @param string $toInstitution - Destination institution (creditor)
     * @param float $amount - Amount to record
     * @param string $transactionType - Type of transaction (cashout, deposit, card_load, etc.)
     */
    public function updateNetPosition(string $fromInstitution, string $toInstitution, float $amount, string $transactionType): void
    {
        try {
            // Create ledger entry for audit trail
            $this->createSettlementLedgerEntry($fromInstitution, $toInstitution, $amount, $transactionType);
            
            // Update net positions table for quick reporting
            $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount);
            
            // Queue settlement message for batch processing
            $legRef = 'SWAP_' . bin2hex(random_bytes(8));
            $this->enqueueSettlementMessage(
                $legRef,
                $fromInstitution,
                $toInstitution,
                $amount,
                strtoupper($transactionType) . '_SETTLEMENT'
            );
            
            error_log("[SETTLEMENT] Updated net position: $fromInstitution → $toInstitution: $amount $this->currency ($transactionType)");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
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
        
        $this->updateNetPositionsTable($toInstitution, $fromInstitution, $amount);
    }

    /* =====================================================
       AUTO SWAP-TO-SWAP
    ===================================================== */

    public function autoSwapToSwap(string $from, string $to, float $amount): void
    {
        if ($amount <= 0) return;

        $legRef = 'AUTO_' . bin2hex(random_bytes(6));

        $this->createSettlementLedgerEntry($from, $to, $amount, 'SWAP_TO_SWAP');
        $this->enqueueSettlementMessage($legRef, $from, $to, $amount, 'SWAP_TO_SWAP');
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
                
                error_log("[SETTLEMENT] Net position updated: $debtorInstitution owes $creditorInstitution +$amount (total: " . ($existing['amount'] + $amount) . ")");
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
                    ':currency' => $this->currency
                ]);
                
                error_log("[SETTLEMENT] Net position created: $debtorInstitution owes $creditorInstitution $amount");
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net_positions table: " . $e->getMessage());
        }
    }

    /**
     * Get current net position between two participants
     */
    public function getNetPosition(string $debtor, string $creditor): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT amount FROM net_positions
                WHERE debtor = :debtor AND creditor = :creditor
            ");
            $stmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['amount'] : 0.0;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get net position: " . $e->getMessage());
            return 0.0;
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
    
    /* =====================================================
       ADDITIONAL METHODS FOR COMPATIBILITY
    ===================================================== */
    
    /**
     * Process pending settlements (for cron jobs)
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
                RETURNING id
            ");
            $completedStmt->execute();
            $stats['processed'] = $completedStmt->rowCount();
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to finalize settlements: " . $e->getMessage());
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
}
