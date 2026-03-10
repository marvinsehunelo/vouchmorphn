<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

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
            
            // Note: We DON'T release the hold automatically
            // The hold can be used by other cards or later reversal
            
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
            throw $e
