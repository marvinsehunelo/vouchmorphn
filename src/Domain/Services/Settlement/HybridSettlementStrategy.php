<?php

declare(strict_types=1);

namespace Domain\Services\Settlement;

use PDO;
use DateTimeImmutable;
use Exception;

/**
 * Hybrid Settlement Strategy - NON-CUSTODIAL
 * 
 * VouchMorph NEVER holds customer funds.
 * VouchMorph ONLY:
 * 1. Orchestrates settlement messages between participants
 * 2. Tracks net positions for reconciliation
 * 3. Bills participants for fees into VouchMorph's operational account
 * 
 * Real money movement happens directly between participants via:
 * - RTGS
 * - SWIFT  
 * - Central bank rails
 * - Correspondent banking
 * 
 * VouchMorph's only account: Where financial institutions pay fees
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $defaultCurrency = 'BWP';
    
    // VouchMorph's operational account (where fees are paid)
    private const VOUCHMORPH_FEE_ACCOUNT = 'VOUCHMORPH_OPERATIONS';
    private const VOUCHMORPH_FEE_ACCOUNT_NUMBER = 'VM-OP-001';
    
    // Settlement statuses - messages only, not fund status
    private const STATUS_PENDING = 'PENDING';      // Message queued
    private const STATUS_SENT = 'SENT';            // Message sent to participant
    private const STATUS_ACKNOWLEDGED = 'ACK';     // Participant acknowledged
    private const STATUS_COMPLETED = 'COMPLETED';  // Participant confirmed settlement
    private const STATUS_FAILED = 'FAILED';        // Message delivery failed
    
    // Message types
    private const MSG_SETTLEMENT_INSTRUCTION = 'SETTLEMENT_INSTRUCTION';
    private const MSG_DEBIT_INSTRUCTION = 'DEBIT_INSTRUCTION';
    private const MSG_CREDIT_INSTRUCTION = 'CREDIT_INSTRUCTION';
    private const MSG_FEE_INVOICE = 'FEE_INVOICE';
    private const MSG_RECONCILIATION = 'RECONCILIATION';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureMessageTablesExist();
    }
    
    /**
     * Ensure message tracking tables exist
     * These track MESSAGES, NOT funds
     */
    private function ensureMessageTablesExist(): void
    {
        // Settlement messages outbox - messages to participants
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settlement_outbox (
                message_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                to_participant VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                message_type VARCHAR(50) NOT NULL,
                message_payload JSONB NOT NULL,
                status VARCHAR(20) DEFAULT 'PENDING',
                retry_count INT DEFAULT 0,
                sent_at TIMESTAMP,
                acknowledged_at TIMESTAMP,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Net position tracking - for reconciliation only
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS net_positions (
                id BIGSERIAL PRIMARY KEY,
                debtor VARCHAR(100) NOT NULL,
                creditor VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency_code CHAR(3) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(debtor, creditor, currency_code)
            )
        ");
        
        // Fee invoices sent to participants
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fee_invoices (
                invoice_id BIGSERIAL PRIMARY KEY,
                invoice_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                participant_id BIGINT NOT NULL,
                participant_name VARCHAR(100) NOT NULL,
                fee_type VARCHAR(50) NOT NULL,
                fee_amount NUMERIC(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                vat_amount NUMERIC(12,2) DEFAULT 0,
                total_amount NUMERIC(12,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'SENT',
                paid_at TIMESTAMP,
                paid_reference VARCHAR(100),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Cross-border message routing
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cross_border_messages (
                message_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                from_country CHAR(2) NOT NULL,
                to_country CHAR(2) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                to_participant VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                source_currency CHAR(3) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                exchange_rate NUMERIC(24,10),
                fx_provider_id BIGINT,
                message_type VARCHAR(50),
                swift_reference VARCHAR(50),
                status VARCHAR(20) DEFAULT 'PENDING',
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Settlement acknowledgements from participants
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settlement_acknowledgements (
                ack_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                from_participant VARCHAR(100) NOT NULL,
                ack_type VARCHAR(20) NOT NULL,
                ack_payload JSONB,
                received_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    /**
     * UPDATE NET POSITION - Track who owes whom (message-level only)
     * VouchMorph does NOT move money - just tracks obligations
     * 
     * @param string $fromInstitution - Debtor institution
     * @param string $toInstitution - Creditor institution  
     * @param float $amount - Amount
     * @param string $transactionType - Type of transaction
     * @param string $currency - Currency code
     */
    public function updateNetPosition(
        string $fromInstitution, 
        string $toInstitution, 
        float $amount, 
        string $transactionType,
        string $currency = 'BWP'
    ): void {
        try {
            // Update net positions table for reconciliation
            $this->updateNetPositionsTable($fromInstitution, $toInstitution, $amount, $currency);
            
            // Send settlement instruction message to participants
            $messageUuid = $this->sendSettlementInstruction(
                $fromInstitution,
                $toInstitution,
                $amount,
                $currency,
                $transactionType
            );
            
            // Log the obligation (not the fund movement)
            $this->logObligation($fromInstitution, $toInstitution, $amount, $currency, $transactionType, $messageUuid);
            
            error_log("[SETTLEMENT] Obligation recorded: $fromInstitution owes $toInstitution $amount $currency ($transactionType)");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Send settlement instruction to participants
     * Participants settle directly - VouchMorph only messages
     */
    private function sendSettlementInstruction(
        string $fromParticipant,
        string $toParticipant,
        float $amount,
        string $currency,
        string $transactionType
    ): string {
        $messageUuid = $this->generateUuid();
        $swapRef = 'SWAP_' . bin2hex(random_bytes(8));
        
        // Construct settlement instruction message
        $instruction = [
            'instruction_id' => $messageUuid,
            'swap_reference' => $swapRef,
            'type' => 'SETTLEMENT_INSTRUCTION',
            'debtor' => $fromParticipant,
            'creditor' => $toParticipant,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_type' => $transactionType,
            'settlement_deadline' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'instructions' => [
                'method' => 'DIRECT_PARTICIPANT_SETTLEMENT',
                'reference' => $swapRef,
                'notes' => 'Please settle directly with counterparty. VouchMorph does not hold funds.',
                'reconciliation_required' => true
            ]
        ];
        
        // Store in outbox for delivery to participants
        $stmt = $this->db->prepare("
            INSERT INTO settlement_outbox 
            (message_uuid, swap_reference, from_participant, to_participant, 
             amount, currency, message_type, message_payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $messageUuid,
            $swapRef,
            $fromParticipant,
            $toParticipant,
            $amount,
            $currency,
            self::MSG_SETTLEMENT_INSTRUCTION,
            json_encode($instruction)
        ]);
        
        // Trigger delivery to participants (webhook/queue)
        $this->deliverToParticipant($fromParticipant, $instruction);
        $this->deliverToParticipant($toParticipant, $instruction);
        
        return $messageUuid;
    }
    
    /**
     * Invoice participants for fees
     * This is the ONLY money that moves to VouchMorph
     */
    public function invoiceFee(
        string $swapReference,
        string $participantName,
        int $participantId,
        string $feeType,
        float $feeAmount,
        string $currency = 'BWP',
        float $vatRate = 0.14
    ): string {
        $invoiceUuid = $this->generateUuid();
        $vatAmount = $feeAmount * $vatRate;
        $totalAmount = $feeAmount + $vatAmount;
        
        $invoice = [
            'invoice_uuid' => $invoiceUuid,
            'swap_reference' => $swapReference,
            'fee_type' => $feeType,
            'fee_amount' => $feeAmount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'payee' => self::VOUCHMORPH_FEE_ACCOUNT,
            'payee_account' => self::VOUCHMORPH_FEE_ACCOUNT_NUMBER,
            'payment_instructions' => [
                'bank' => 'VouchMorph Operations Account',
                'account_name' => 'VouchMorph Pty Ltd',
                'account_number' => 'VM-FEE-001',
                'bank_code' => 'VM001',
                'reference' => $invoiceUuid,
                'notes' => 'Fee for swap transaction ' . $swapReference
            ],
            'due_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'late_fee' => $totalAmount * 0.05
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO fee_invoices 
            (invoice_uuid, swap_reference, participant_id, participant_name, 
             fee_type, fee_amount, currency, vat_amount, total_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SENT', NOW())
        ");
        
        $stmt->execute([
            $invoiceUuid,
            $swapReference,
            $participantId,
            $participantName,
            $feeType,
            $feeAmount,
            $currency,
            $vatAmount,
            $totalAmount
        ]);
        
        // Send invoice to participant
        $this->deliverToParticipant($participantName, $invoice);
        
        error_log("[SETTLEMENT] Fee invoice sent to $participantName: $totalAmount $currency");
        
        return $invoiceUuid;
    }
    
    /**
     * Record fee payment received by VouchMorph
     */
    public function recordFeePayment(string $invoiceUuid, string $paymentReference): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE fee_invoices 
                SET status = 'PAID',
                    paid_at = NOW(),
                    paid_reference = ?
                WHERE invoice_uuid = ? AND status = 'SENT'
                RETURNING invoice_id
            ");
            
            $stmt->execute([$paymentReference, $invoiceUuid]);
            $updated = $stmt->fetchColumn();
            
            if ($updated) {
                error_log("[SETTLEMENT] Fee payment recorded for invoice $invoiceUuid");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to record fee payment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deliver message to participant via webhook/queue
     */
    private function deliverToParticipant(string $participantName, array $message): void
    {
        // This would call participant's webhook or put in their queue
        // For now, just log
        error_log("[SETTLEMENT] Message delivered to $participantName: " . json_encode($message));
        
        // Mark message as sent
        if (isset($message['instruction_id'])) {
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'SENT', sent_at = NOW()
                WHERE message_uuid = ?
            ");
            $stmt->execute([$message['instruction_id']]);
        }
    }
    
    /**
     * Acknowledge settlement from participant
     * Participant confirms they have settled directly with counterparty
     */
    public function acknowledgeSettlement(string $messageUuid, string $participantName, array $proofData = []): bool
    {
        try {
            // Record acknowledgement
            $stmt = $this->db->prepare("
                INSERT INTO settlement_acknowledgements 
                (message_uuid, swap_reference, from_participant, ack_type, ack_payload, received_at)
                SELECT ?, swap_reference, ?, 'SETTLED', ?, NOW()
                FROM settlement_outbox 
                WHERE message_uuid = ?
            ");
            
            $stmt->execute([$messageUuid, $participantName, json_encode($proofData), $messageUuid]);
            
            // Update outbox status
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'ACKNOWLEDGED', acknowledged_at = NOW()
                WHERE message_uuid = ? AND to_participant = ?
            ");
            $stmt->execute([$messageUuid, $participantName]);
            
            error_log("[SETTLEMENT] Settlement acknowledged by $participantName for $messageUuid");
            
            // Check if both parties have acknowledged
            $this->checkSettlementComplete($messageUuid);
            
            return true;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to acknowledge settlement: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if both parties have acknowledged settlement
     */
    private function checkSettlementComplete(string $messageUuid): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as ack_count, 
                   (SELECT COUNT(*) FROM settlement_outbox WHERE message_uuid = ?) as expected_count
            FROM settlement_acknowledgements 
            WHERE message_uuid = ? AND ack_type = 'SETTLED'
        ");
        
        $stmt->execute([$messageUuid, $messageUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['ack_count'] >= 2) {
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'COMPLETED'
                WHERE message_uuid = ?
            ");
            $stmt->execute([$messageUuid]);
            
            error_log("[SETTLEMENT] Settlement $messageUuid fully completed by both parties");
        }
    }
    
    /**
     * Log obligation for audit trail
     */
    private function logObligation(
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency,
        string $transactionType,
        string $messageUuid
    ): void {
        // Obligation logging can be extended as needed
        // This is just for audit - no fund movement
    }
    
    /**
     * Process net settlement between nodes (reduces correspondent banking)
     * VouchMorph calculates net obligations and sends updated instructions
     */
    public function calculateNetObligations(array $participantBalances): array
    {
        $netObligations = [];
        $batchId = 'BATCH_' . bin2hex(random_bytes(8));
        
        foreach ($participantBalances as $debtor => $creditors) {
            foreach ($creditors as $creditor => $amounts) {
                foreach ($amounts as $currency => $amount) {
                    if ($amount <= 0.01) continue;
                    
                    // Check if reverse position exists for netting
                    $reverseAmount = $this->getNetPosition($creditor, $debtor, $currency);
                    
                    if ($reverseAmount > 0) {
                        // Net settlement - only difference needs to move
                        $netAmount = abs($amount - $reverseAmount);
                        $netObligations[] = [
                            'batch_id' => $batchId,
                            'debtor' => $amount > $reverseAmount ? $debtor : $creditor,
                            'creditor' => $amount > $reverseAmount ? $creditor : $debtor,
                            'gross_amount' => $amount,
                            'reverse_amount' => $reverseAmount,
                            'net_amount' => $netAmount,
                            'currency' => $currency,
                            'original_message_id' => $this->generateUuid()
                        ];
                        
                        // Clear both positions after netting
                        $this->clearNetPosition($debtor, $creditor, $currency);
                        $this->clearNetPosition($creditor, $debtor, $currency);
                        
                        error_log("[SETTLEMENT] Net calculation: $debtor owes $creditor $amount $currency, reverse $reverseAmount, net: $netAmount");
                    } else {
                        $netObligations[] = [
                            'batch_id' => $batchId,
                            'debtor' => $debtor,
                            'creditor' => $creditor,
                            'gross_amount' => $amount,
                            'reverse_amount' => 0,
                            'net_amount' => $amount,
                            'currency' => $currency,
                            'original_message_id' => $this->generateUuid()
                        ];
                    }
                }
            }
        }
        
        // Send net settlement instructions
        foreach ($netObligations as $obligation) {
            if ($obligation['net_amount'] > 0) {
                $this->sendSettlementInstruction(
                    $obligation['debtor'],
                    $obligation['creditor'],
                    $obligation['net_amount'],
                    $obligation['currency'],
                    'NET_SETTLEMENT'
                );
            }
        }
        
        return $netObligations;
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
     * Update net positions table (tracking only)
     */
    private function updateNetPositionsTable(
        string $debtorInstitution,
        string $creditorInstitution,
        float $amount,
        string $currency
    ): void {
        try {
            $checkStmt = $this->db->prepare("
                SELECT amount FROM net_positions 
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
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net_positions table: " . $e->getMessage());
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
       PUBLIC API METHODS - Message-only
    ===================================================== */
    
    /**
     * Process deposit - Send instruction only
     */
    public function processDeposit(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency = 'BWP'
    ): void {
        $this->sendSettlementInstruction($fromInstitution, $toInstitution, $amount, $currency, 'DEPOSIT');
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
        // Send authorization message
        $message = [
            'type' => 'CASHOUT_AUTHORIZATION',
            'from' => $fromInstitution,
            'to' => $toInstitution,
            'amount' => $amount,
            'currency' => $currency,
            'expiry' => $expiry->format('Y-m-d H:i:s'),
            'fee_amount' => $feeAmount,
            'fee_mode' => $feeMode,
            'reference' => $legRef
        ];
        
        $this->deliverToParticipant($toInstitution, $message);
    }
    
    /**
     * Confirm cashout - Send confirmation message
     */
    public function confirmCashout(string $legRef, float $amount, string $currency = 'BWP'): void
    {
        $message = [
            'type' => 'CASHOUT_CONFIRMATION',
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $legRef
        ];
        
        // Would deliver to relevant participants
        error_log("[SETTLEMENT] Cashout confirmation sent for $legRef");
    }
    
    /**
     * Reverse cashout - Send reversal instruction
     */
    public function reverseCashout(
        string $legRef,
        string $fromInstitution,
        string $toInstitution,
        float $amount,
        string $currency = 'BWP'
    ): void {
        $this->sendSettlementInstruction($toInstitution, $fromInstitution, $amount, $currency, 'CASHOUT_REVERSAL');
        $this->updateNetPositionsTable($toInstitution, $fromInstitution, $amount, $currency);
    }
    
    /**
     * Auto swap-to-swap settlement
     */
    public function autoSwapToSwap(string $from, string $to, float $amount, string $currency = 'BWP'): void
    {
        if ($amount <= 0) return;
        
        $this->sendSettlementInstruction($from, $to, $amount, $currency, 'SWAP_TO_SWAP');
        $this->updateNetPositionsTable($from, $to, $amount, $currency);
    }
    
    /**
     * Get pending settlement messages for a participant
     */
    public function getPendingMessagesForParticipant(string $participantName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_outbox 
            WHERE (from_participant = ? OR to_participant = ?)
            AND status IN ('PENDING', 'SENT')
            ORDER BY created_at ASC
        ");
        
        $stmt->execute([$participantName, $participantName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get outstanding fee invoices for a participant
     */
    public function getOutstandingInvoices(string $participantName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM fee_invoices 
            WHERE participant_name = ? AND status = 'SENT'
            ORDER BY created_at ASC
        ");
        
        $stmt->execute([$participantName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Generate reconciliation report for a participant
     */
    public function generateReconciliationReport(string $participantName, string $currency = 'BWP'): array
    {
        // Get net position
        $netAsDebtor = $this->getTotalNetPositionAsDebtor($participantName, $currency);
        $netAsCreditor = $this->getTotalNetPositionAsCreditor($participantName, $currency);
        
        $netObligation = $netAsDebtor - $netAsCreditor;
        
        // Get pending settlement messages
        $pendingMessages = $this->getPendingMessagesForParticipant($participantName);
        
        return [
            'participant' => $participantName,
            'currency' => $currency,
            'as_at' => date('Y-m-d H:i:s'),
            'total_owed_to_others' => $netAsDebtor,
            'total_owed_by_others' => $netAsCreditor,
            'net_position' => $netObligation,
            'net_position_text' => $netObligation > 0 ? "OWES $netObligation $currency" : "IS OWED " . abs($netObligation) . " $currency",
            'pending_settlements' => count($pendingMessages),
            'pending_messages' => $pendingMessages
        ];
    }
    
    private function getTotalNetPositionAsDebtor(string $participant, string $currency): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM net_positions 
            WHERE debtor = ? AND currency_code = ?
        ");
        $stmt->execute([$participant, $currency]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    }
    
    private function getTotalNetPositionAsCreditor(string $participant, string $currency): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM net_positions 
            WHERE creditor = ? AND currency_code = ?
        ");
        $stmt->execute([$participant, $currency]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    }
}
