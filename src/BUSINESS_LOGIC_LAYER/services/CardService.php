<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

require_once __DIR__ . '/../../INTEGRATION_LAYER/CLIENTS/CardSchemes/CardNumberGenerator.php';
require_once __DIR__ . '/../Helpers/CardHelper.php';

use PDO;
use Exception;
use DateTimeImmutable;
use RuntimeException;
use BUSINESS_LOGIC_LAYER\Helpers\CardHelper;
use INTEGRATION_LAYER\CLIENTS\CardSchemes\CardNumberGenerator;

/**
 * CardService - Message Card Management
 * Handles issuance, authorization, and lifecycle of message-based cards
 */
class CardService
{
    private PDO $db;
    private string $countryCode;
    private array $config;
    private CardNumberGenerator $cardGenerator;
    
    // Card constants
    private const CARD_EXPIRY_YEARS = 3;
    private const DAILY_SPEND_LIMIT = 10000;
    private const MONTHLY_SPEND_LIMIT = 50000;
    private const ATM_DAILY_LIMIT = 2000;
    private const POS_MAX_TRANSACTION = 5000;
    
    public function __construct(PDO $db, string $countryCode, array $config)
    {
        $this->db = $db;
        $this->countryCode = $countryCode;
        $this->config = $config;
        $this->cardGenerator = new CardNumberGenerator($config);
    }
    
    /**
     * Authorize a card load (for message-based cards)
     * This is a wrapper around loadCard() that matches the interface expected by SwapService
     * 
     * @param array $data Contains:
     * - hold_reference: string (required)
     * - swap_reference: string (required)
     * - card_suffix: string (required)
     * - amount: float (required)
     * @return array
     */
    public function authorizeCardLoad(array $data): array
    {
        error_log("[CardService] authorizeCardLoad called with: " . json_encode([
            'hold_reference' => $data['hold_reference'] ?? null,
            'card_suffix' => $data['card_suffix'] ?? null,
            'amount' => $data['amount'] ?? null
        ]));
        
        try {
            // Validate required fields
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required for card authorization");
            }
            if (empty($data['card_suffix'])) {
                throw new RuntimeException("card_suffix is required for card authorization");
            }
            if (empty($data['amount']) || $data['amount'] <= 0) {
                throw new RuntimeException("Valid amount is required for card authorization");
            }
            
            // Map the data to match what loadCard expects
            $loadData = [
                'hold_reference' => $data['hold_reference'],
                'swap_reference' => $data['swap_reference'] ?? null,
                'card_suffix' => $data['card_suffix'],
                'amount' => $data['amount']
            ];
            
            // Call the existing loadCard method
            $result = $this->loadCard($loadData);
            
            // Add authorization-specific fields to the response
            $result['authorized'] = true;
            $result['authorization_id'] = $result['card_id'] ?? null;
            $result['authorized_amount'] = $data['amount'];
            $result['remaining_balance'] = $result['new_balance'] ?? $data['amount'];
            $result['status'] = 'AUTHORIZED';
            
            error_log("[CardService] authorizeCardLoad successful: " . json_encode($result));
            
            return $result;
            
        } catch (Exception $e) {
            error_log("[CardService] authorizeCardLoad failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'authorized' => false,
                'error' => $e->getMessage(),
                'message' => 'Card authorization failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Issue a new message card from a hold
     */
    public function issueCard(array $data): array
    {
        $this->db->beginTransaction();
        
        try {
            // Validate required fields
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required");
            }
            
            // Get the hold
            $holdStmt = $this->db->prepare("
                SELECT ht.*, sr.swap_uuid, sr.source_details 
                FROM hold_transactions ht
                LEFT JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
                WHERE ht.hold_reference = ? 
                AND ht.status = 'ACTIVE'
            ");
            $holdStmt->execute([$data['hold_reference']]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new RuntimeException("Hold not found or not active");
            }
            
            // Determine initial amount (from hold or provided)
            $initialAmount = $data['initial_amount'] ?? (float)$hold['amount'];
            
            if ($initialAmount <= 0) {
                throw new RuntimeException("Initial amount must be positive");
            }
            
            if ($initialAmount > (float)$hold['amount']) {
                throw new RuntimeException("Initial amount exceeds hold amount");
            }
            
            // Generate card details
            $purpose = $data['purpose'] ?? 'student';
            $cardDetails = $this->cardGenerator->generateForPurpose($purpose);
            
            // Get or create user/cardholder
            $userId = $this->getOrCreateUser($data);
            
            // Create message card record
            $cardStmt = $this->db->prepare("
                INSERT INTO message_cards (
                    card_number_hash,
                    card_suffix,
                    cvv_hash,
                    hold_reference,
                    swap_reference,
                    user_id,
                    cardholder_name,
                    initial_amount,
                    remaining_amount,
                    currency,
                    status,
                    issued_at,
                    expiry_year,
                    expiry_month,
                    daily_limit,
                    monthly_limit,
                    atm_daily_limit,
                    metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW(), ?, ?, ?, ?, ?, ?::jsonb)
                RETURNING card_id
            ");
            
            $cardStmt->execute([
                $cardDetails['pan_hash'],
                $cardDetails['pan_suffix'],
                $cardDetails['cvv_hash'],
                $hold['hold_reference'],
                $hold['swap_reference'] ?? null,
                $userId,
                $data['cardholder_name'] ?? 'Cardholder',
                $initialAmount,
                $initialAmount,
                $hold['currency'] ?? 'BWP',
                $cardDetails['expiry_year'],
                $cardDetails['expiry_month'],
                $data['daily_limit'] ?? self::DAILY_SPEND_LIMIT,
                $data['monthly_limit'] ?? self::MONTHLY_SPEND_LIMIT,
                $data['atm_daily_limit'] ?? self::ATM_DAILY_LIMIT,
                json_encode([
                    'source_institution' => $hold['source_institution'] ?? 'unknown',
                    'purpose' => $purpose,
                    'issued_by' => $data['issued_by'] ?? 'system',
                    'notes' => $data['notes'] ?? null
                ])
            ]);
            
            $cardId = $cardStmt->fetchColumn();
            
            // If amount is less than full hold, split the hold
            if ($initialAmount < (float)$hold['amount']) {
                $this->splitHold($hold['hold_reference'], $initialAmount);
            }
            
            // Log issuance transaction
            $this->logTransaction([
                'card_id' => $cardId,
                'type' => 'ISSUANCE',
                'amount' => $initialAmount,
                'auth_code' => CardHelper::generateAuthCode(),
                'reference' => $hold['hold_reference'],
                'channel' => 'ISSUANCE'
            ]);
            
            $this->db->commit();
            
            // Return card details (CVV only on issuance!)
            return [
                'success' => true,
                'card_id' => $cardId,
                'card_number' => $cardDetails['pan_formatted'],
                'card_suffix' => $cardDetails['pan_suffix'],
                'cvv' => $cardDetails['cvv'], // Only returned once!
                'expiry' => $cardDetails['expiry_formatted'],
                'expiry_month' => $cardDetails['expiry_month'],
                'expiry_year' => $cardDetails['expiry_year'],
                'cardholder_name' => $data['cardholder_name'] ?? 'Cardholder',
                'brand' => $cardDetails['brand'],
                'initial_amount' => $initialAmount,
                'remaining_amount' => $initialAmount,
                'currency' => $hold['currency'] ?? 'BWP',
                'message' => 'Card issued successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("[CARD] Issue failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Load funds onto an existing card (link hold to card)
     * 
     * @param array $data Contains:
     * - hold_reference: string (required)
     * - swap_reference: string (required)
     * - card_suffix: string (required)
     * - amount: float (required)
     * @return array
     */
    public function loadCard(array $data): array
    {
        // NO beginTransaction() here - transaction already active
        
        try {
            // Validate required fields
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required");
            }
            if (empty($data['card_suffix'])) {
                throw new RuntimeException("card_suffix is required");
            }
            if (empty($data['amount']) || $data['amount'] <= 0) {
                throw new RuntimeException("Valid amount is required");
            }
            
            // Find the card (FOR UPDATE will work because parent transaction has lock)
            $cardStmt = $this->db->prepare("
                SELECT * FROM message_cards 
                WHERE card_suffix = :suffix 
                AND lifecycle_status IN ('ASSIGNED', 'DELIVERED', 'ACTIVE')
                FOR UPDATE
            ");
            $cardStmt->execute([':suffix' => $data['card_suffix']]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or not available for loading");
            }
            
            // Check if hold exists and is active
            $holdStmt = $this->db->prepare("
                SELECT * FROM hold_transactions 
                WHERE hold_reference = :hold_ref 
                AND status = 'ACTIVE'
            ");
            $holdStmt->execute([':hold_ref' => $data['hold_reference']]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new RuntimeException("Hold not found or not active");
            }
            
            // Link the hold to the card
            $updateStmt = $this->db->prepare("
                UPDATE message_cards 
                SET hold_reference = :hold_ref,
                    swap_reference = :swap_ref,
                    initial_amount = initial_amount + :amount,
                    remaining_amount = remaining_amount + :amount,
                    financial_status = 'FUNDED',
                    lifecycle_status = 'ACTIVE',
                    activated_at = COALESCE(activated_at, NOW()),
                    updated_at = NOW()
                WHERE card_id = :card_id
                RETURNING *
            ");
            
            $updateStmt->execute([
                ':hold_ref' => $data['hold_reference'],
                ':swap_ref' => $data['swap_reference'] ?? null,
                ':amount' => $data['amount'],
                ':card_id' => $card['card_id']
            ]);
            
            $updatedCard = $updateStmt->fetch(PDO::FETCH_ASSOC);
            
            // Record the card load transaction
            $this->recordCardLoadTransaction(
                $updatedCard['card_id'],
                $data['hold_reference'],
                $data['amount']
            );
            
            // NO commit here - let parent transaction handle it
            
            return [
                'success' => true,
                'card_id' => $updatedCard['card_id'],
                'card_suffix' => $updatedCard['card_suffix'],
                'new_balance' => (float)$updatedCard['remaining_amount'],
                'old_balance' => (float)$card['remaining_amount'],
                'amount_loaded' => $data['amount'],
                'hold_reference' => $data['hold_reference'],
                'message' => 'Card loaded successfully'
            ];
            
        } catch (Exception $e) {
            // NO rollback here - let parent transaction handle it
            error_log("Card load error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Authorize a transaction (called by ATM/POS/online)
     */
    public function authorizeTransaction(array $data): array
    {
        $startTime = microtime(true);
        $this->db->beginTransaction();
        
        try {
            // Find card by PAN
            $cardHash = hash('sha256', $data['card_number']);
            
            $cardStmt = $this->db->prepare("
                SELECT mc.*, ht.source_institution, ht.amount as hold_amount
                FROM message_cards mc
                JOIN hold_transactions ht ON mc.hold_reference = ht.hold_reference
                WHERE mc.card_number_hash = ?
                AND mc.status = 'ACTIVE'
                FOR UPDATE
            ");
            $cardStmt->execute([$cardHash]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or inactive");
            }
            
            // Verify CVV
            $expectedCvvHash = hash('sha256', $data['cvv']);
            if ($expectedCvvHash !== $card['cvv_hash']) {
                throw new RuntimeException("Invalid CVV");
            }
            
            // Check expiry
            $currentYear = (int)date('Y');
            $currentMonth = (int)date('m');
            
            if ($card['expiry_year'] < $currentYear || 
                ($card['expiry_year'] == $currentYear && $card['expiry_month'] < $currentMonth)) {
                throw new RuntimeException("Card expired");
            }
            
            $amount = (float)$data['amount'];
            
            // Check sufficient funds
            if ($card['remaining_amount'] < $amount) {
                throw new RuntimeException("Insufficient funds");
            }
            
            // Apply channel-specific limits
            $channel = $data['channel'] ?? 'POS';
            
            if ($channel === 'ATM' && $amount > (float)($card['atm_daily_limit'] ?? self::ATM_DAILY_LIMIT)) {
                throw new RuntimeException("ATM withdrawal exceeds daily limit");
            }
            
            if ($channel === 'POS' && $amount > self::POS_MAX_TRANSACTION) {
                throw new RuntimeException("Transaction exceeds POS limit");
            }
            
            // Check daily limit
            $dailyStmt = $this->db->prepare("
                SELECT COALESCE(SUM(amount), 0) as daily_total
                FROM card_transactions
                WHERE card_id = ?
                AND DATE(created_at) = CURRENT_DATE
                AND auth_status = 'APPROVED'
            ");
            $dailyStmt->execute([$card['card_id']]);
            $dailyTotal = (float)$dailyStmt->fetchColumn();
            
            if ($dailyTotal + $amount > (float)($card['daily_limit'] ?? self::DAILY_SPEND_LIMIT)) {
                throw new RuntimeException("Daily spending limit exceeded");
            }
            
            // REDUCE THE HOLD (magic happens here!)
            $holdStmt = $this->db->prepare("
                UPDATE hold_transactions 
                SET amount = amount - ?
                WHERE hold_reference = ?
                AND amount >= ?
                RETURNING amount
            ");
            $holdStmt->execute([$amount, $card['hold_reference'], $amount]);
            $newHoldAmount = $holdStmt->fetchColumn();
            
            if ($newHoldAmount === false) {
                throw new RuntimeException("Failed to update hold");
            }
            
            // Update card remaining amount
            $updateCard = $this->db->prepare("
                UPDATE message_cards 
                SET remaining_amount = remaining_amount - ?,
                    last_used_at = NOW()
                WHERE card_id = ?
                RETURNING remaining_amount
            ");
            $updateCard->execute([$amount, $card['card_id']]);
            $newCardBalance = $updateCard->fetchColumn();
            
            // Create settlement obligation
            $authCode = CardHelper::generateAuthCode();
            
            $settlementStmt = $this->db->prepare("
                INSERT INTO settlement_queue (
                    debtor,
                    creditor,
                    amount,
                    hold_reference,
                    reference,
                    status,
                    metadata,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, 'PENDING', ?::jsonb, NOW())
                RETURNING id
            ");
            
            $settlementStmt->execute([
                $card['source_institution'],
                $data['acquirer'] ?? 'UNKNOWN',
                $amount,
                $card['hold_reference'],
                $authCode,
                json_encode([
                    'card_id' => $card['card_id'],
                    'card_suffix' => $card['card_suffix'],
                    'channel' => $channel,
                    'merchant' => $data['merchant_name'] ?? null,
                    'terminal' => $data['terminal_id'] ?? null
                ])
            ]);
            
            $settlementId = $settlementStmt->fetchColumn();
            
            // Log transaction
            $this->logTransaction([
                'card_id' => $card['card_id'],
                'type' => $channel === 'ATM' ? 'ATM_WITHDRAWAL' : 'PURCHASE',
                'amount' => $amount,
                'auth_code' => $authCode,
                'auth_status' => 'APPROVED',
                'merchant_name' => $data['merchant_name'] ?? null,
                'merchant_id' => $data['merchant_id'] ?? null,
                'terminal_id' => $data['terminal_id'] ?? null,
                'atm_id' => $data['atm_id'] ?? null,
                'channel' => $channel,
                'settlement_id' => $settlementId,
                'reference' => $data['reference'] ?? null
            ]);
            
            $this->db->commit();
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            // Log authorization for audit
            $this->logAuthRequest($card['card_id'], $data, [
                'success' => true,
                'auth_code' => $authCode,
                'response_time' => $responseTime
            ]);
            
            return [
                'success' => true,
                'authorized' => true,
                'auth_code' => $authCode,
                'remaining_balance' => (float)$newCardBalance,
                'transaction_id' => $authCode,
                'response_code' => '00',
                'response_message' => 'Approved',
                'processing_time_ms' => $responseTime
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            // Log failed authorization
            $this->logAuthRequest(
                $data['card_number'] ?? null,
                $data,
                [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'response_time' => $responseTime
                ]
            );
            
            return [
                'success' => false,
                'authorized' => false,
                'error' => $e->getMessage(),
                'response_code' => '51', // Generic decline
                'response_message' => 'Declined',
                'processing_time_ms' => $responseTime
            ];
        }
    }
    
    /**
     * Get card balance
     */
    public function getCardBalance(string $cardNumber): array
    {
        $cardHash = hash('sha256', preg_replace('/\D/', '', $cardNumber));
        
        $stmt = $this->db->prepare("
            SELECT * FROM card_balances_view
            WHERE card_id = (
                SELECT card_id FROM message_cards 
                WHERE card_number_hash = ?
            )
        ");
        $stmt->execute([$cardHash]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new RuntimeException("Card not found");
        }
        
        return [
            'success' => true,
            'card_suffix' => $card['card_suffix'],
            'cardholder_name' => $card['cardholder_name'],
            'balance' => (float)$card['balance'],
            'currency' => $card['currency'],
            'expiry' => $card['expiry'],
            'status' => $card['status'],
            'total_spent' => (float)$card['total_spent'],
            'transaction_count' => (int)$card['transaction_count'],
            'last_transaction' => $card['last_transaction'],
            'source_institution' => $card['source_institution']
        ];
    }
    
    /**
     * Block a card
     */
    public function blockCard(string $cardNumber, string $reason): array
    {
        $this->db->beginTransaction();
        
        try {
            $cardHash = hash('sha256', preg_replace('/\D/', '', $cardNumber));
            
            $cardStmt = $this->db->prepare("
                UPDATE message_cards 
                SET status = 'BLOCKED',
                    blocked_at = NOW(),
                    block_reason = ?
                WHERE card_number_hash = ?
                AND status = 'ACTIVE'
                RETURNING card_id, hold_reference
            ");
            $cardStmt->execute([$reason, $cardHash]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or already inactive");
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Card blocked successfully',
                'card_id' => $card['card_id'],
                'hold_reference' => $card['hold_reference']
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get transaction history for a card
     */
    public function getCardTransactions(string $cardNumber, int $limit = 10): array
    {
        $cardHash = hash('sha256', preg_replace('/\D/', '', $cardNumber));
        
        $stmt = $this->db->prepare("
            SELECT 
                ct.transaction_type,
                ct.amount,
                ct.currency,
                ct.auth_code,
                ct.auth_status,
                ct.merchant_name,
                ct.atm_id,
                ct.channel,
                ct.created_at,
                ct.response_code,
                ct.response_message
            FROM card_transactions ct
            JOIN message_cards mc ON ct.card_id = mc.card_id
            WHERE mc.card_number_hash = ?
            ORDER BY ct.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$cardHash, $limit]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Reverse a transaction (for disputes/errors)
     */
    public function reverseTransaction(string $authCode): array
    {
        $this->db->beginTransaction();
        
        try {
            // Find the original transaction
            $txStmt = $this->db->prepare("
                SELECT ct.*, mc.hold_reference
                FROM card_transactions ct
                JOIN message_cards mc ON ct.card_id = mc.card_id
                WHERE ct.auth_code = ?
                AND ct.auth_status = 'APPROVED'
                FOR UPDATE
            ");
            $txStmt->execute([$authCode]);
            $transaction = $txStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                throw new RuntimeException("Transaction not found or already reversed");
            }
            
            // Restore the hold
            $holdStmt = $this->db->prepare("
                UPDATE hold_transactions 
                SET amount = amount + ?
                WHERE hold_reference = ?
                RETURNING amount
            ");
            $holdStmt->execute([$transaction['amount'], $transaction['hold_reference']]);
            
            // Restore card balance
            $cardStmt = $this->db->prepare("
                UPDATE message_cards 
                SET remaining_amount = remaining_amount + ?
                WHERE card_id = ?
            ");
            $cardStmt->execute([$transaction['amount'], $transaction['card_id']]);
            
            // Mark transaction as reversed
            $updateTx = $this->db->prepare("
                UPDATE card_transactions 
                SET auth_status = 'REVERSED'
                WHERE transaction_id = ?
            ");
            $updateTx->execute([$transaction['transaction_id']]);
            
            // Remove from settlement queue
            $queueStmt = $this->db->prepare("
                DELETE FROM settlement_queue 
                WHERE reference = ?
            ");
            $queueStmt->execute([$authCode]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Transaction reversed successfully',
                'amount' => $transaction['amount'],
                'auth_code' => $authCode
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Split a hold (for partial card issuance)
     */
    private function splitHold(string $holdReference, float $cardAmount): void
    {
        // Create a new hold for the remaining amount
        $stmt = $this->db->prepare("
            INSERT INTO hold_transactions (
                hold_reference,
                swap_reference,
                participant_id,
                participant_name,
                asset_type,
                amount,
                currency,
                status,
                hold_expiry,
                source_details,
                destination_institution,
                metadata
            )
            SELECT 
                concat('HOLD-', gen_random_uuid()::text),
                swap_reference,
                participant_id,
                participant_name,
                asset_type,
                amount - ?,
                currency,
                'ACTIVE',
                hold_expiry,
                source_details,
                destination_institution,
                jsonb_build_object('parent_hold', ?)
            FROM hold_transactions
            WHERE hold_reference = ?
            RETURNING hold_reference
        ");
        $stmt->execute([$cardAmount, $holdReference, $holdReference]);
        $newHoldRef = $stmt->fetchColumn();
        
        // Update original hold to card amount
        $updateStmt = $this->db->prepare("
            UPDATE hold_transactions 
            SET amount = ?,
                metadata = jsonb_set(
                    COALESCE(metadata, '{}'::jsonb),
                    '{child_hold}',
                    to_jsonb(?)
                )
            WHERE hold_reference = ?
        ");
        $updateStmt->execute([$cardAmount, $newHoldRef, $holdReference]);
    }
    
    /**
     * Record card load transaction
     */
    private function recordCardLoadTransaction(int $cardId, string $holdReference, float $amount): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO card_transactions (
                card_id,
                transaction_type,
                amount,
                hold_reference,
                auth_status,
                created_at
            ) VALUES (
                :card_id,
                'LOAD',
                :amount,
                :hold_ref,
                'APPROVED',
                NOW()
            )
        ");
        
        $stmt->execute([
            ':card_id' => $cardId,
            ':amount' => $amount,
            ':hold_ref' => $holdReference
        ]);
    }
    
    /**
     * Get or create user from data
     */
    private function getOrCreateUser(array $data): ?int
    {
        if (!empty($data['user_id'])) {
            return (int)$data['user_id'];
        }
        
        if (!empty($data['student_id'])) {
            // Check if user exists for this student
            $stmt = $this->db->prepare("
                SELECT user_id FROM users 
                WHERE metadata->>'student_id' = ?
            ");
            $stmt->execute([$data['student_id']]);
            $userId = $stmt->fetchColumn();
            
            if ($userId) {
                return (int)$userId;
            }
        }
        
        return null; // Guest card without user account
    }
    
    /**
     * Log card transaction
     */
    private function logTransaction(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO card_transactions (
                card_id,
                transaction_type,
                amount,
                auth_code,
                auth_status,
                merchant_name,
                merchant_id,
                terminal_id,
                atm_id,
                channel,
                settlement_queue_id,
                reference,
                response_code,
                response_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['card_id'],
            $data['type'],
            $data['amount'],
            $data['auth_code'] ?? null,
            $data['auth_status'] ?? 'APPROVED',
            $data['merchant_name'] ?? null,
            $data['merchant_id'] ?? null,
            $data['terminal_id'] ?? null,
            $data['atm_id'] ?? null,
            $data['channel'] ?? null,
            $data['settlement_id'] ?? null,
            $data['reference'] ?? null,
            $data['response_code'] ?? '00',
            $data['response_message'] ?? 'Approved'
        ]);
    }
    
    /**
     * Log authorization request for audit
     */
    private function logAuthRequest($cardId, array $request, array $response): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO card_auth_logs (
                    card_id,
                    request_payload,
                    response_payload,
                    http_status_code,
                    response_time_ms,
                    success,
                    error_message,
                    created_at
                ) VALUES (?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                is_numeric($cardId) ? $cardId : null,
                json_encode($request),
                json_encode($response),
                200,
                $response['response_time'] ?? null,
                $response['success'] ?? false,
                $response['error'] ?? null
            ]);
        } catch (Exception $e) {
            // Non-critical, just log
            error_log("Failed to log auth request: " . $e->getMessage());
        }
    }
}
