<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services\settlement;

use PDO;
use DateTimeImmutable;

/**
 * Ledger-Based Hybrid Settlement Strategy
 * Message-driven + double-entry accounting + net positions
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $currency = 'BWP';

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
       UPDATE NET POSITIONS TABLE - FIXED FOR YOUR TABLE STRUCTURE
    ===================================================== */

    private function updateNetPositionsTable(
        string $debtorInstitution,  // The one that owes money (source)
        string $creditorInstitution, // The one to be paid (destination)
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
       SETTLEMENT MESSAGE (NEW STRUCTURE)
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
