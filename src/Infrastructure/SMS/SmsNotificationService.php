<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

use PDO;
use Exception;
use INTEGRATION_LAYER\CLIENTS\CommunicationClients\SmsGatewayClient;

/**
 * SMS Notification Service
 * Handles all SMS communications with customers
 */
class SmsNotificationService
{
    private PDO $db;
    private SmsGatewayClient $smsGateway;
    private array $config;
    
    private const LOG_FILE = '/tmp/vouchmorph_sms_service.log';
    
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        $this->smsGateway = new SmsGatewayClient($db, $config);
    }
    
    /**
     * Send ATM withdrawal code via SMS
     */
    public function sendWithdrawalCode(
        string $phoneNumber,
        string $code,
        float $amount,
        string $currency = 'BWP',
        array $additionalInfo = []
    ): array {
        $message = $this->buildWithdrawalMessage($code, $amount, $currency, $additionalInfo);
        
        $result = $this->smsGateway->sendSms($phoneNumber, $message, [
            'priority' => 'high',
            'reference' => 'ATM-' . uniqid(),
            'type' => 'withdrawal_code'
        ]);
        
        $this->logWithdrawalSms($phoneNumber, $code, $amount, $result);
        
        return $result;
    }
    
    /**
     * Send transaction confirmation SMS
     */
    public function sendTransactionConfirmation(
        string $phoneNumber,
        float $amount,
        string $type,
        string $reference,
        string $status = 'completed'
    ): array {
        $message = $this->buildConfirmationMessage($amount, $type, $reference, $status);
        
        return $this->smsGateway->sendSms($phoneNumber, $message, [
            'priority' => 'normal',
            'reference' => 'CONF-' . $reference
        ]);
    }
    
    /**
     * Send OTP for verification
     */
    public function sendOtp(string $phoneNumber, string $otp, string $purpose = 'login'): array
    {
        $message = "Your VouchMorph verification code is: {$otp}\nValid for 10 minutes.";
        
        return $this->smsGateway->sendSms($phoneNumber, $message, [
            'priority' => 'high',
            'reference' => 'OTP-' . uniqid(),
            'expiry' => time() + 600 // 10 minutes
        ]);
    }
    
    /**
     * Send alert for suspicious activity
     */
    public function sendSecurityAlert(string $phoneNumber, string $alertType, array $details = []): array
    {
        $message = "SECURITY ALERT: {$alertType} detected on your account.\n";
        $message .= "If this wasn't you, contact support immediately.";
        
        return $this->smsGateway->sendSms($phoneNumber, $message, [
            'priority' => 'urgent',
            'reference' => 'ALERT-' . uniqid()
        ]);
    }
    
    /**
     * Build withdrawal message
     */
    private function buildWithdrawalMessage(string $code, float $amount, string $currency, array $info): string
    {
        $message = "🔐 VouchMorph Withdrawal\n";
        $message .= "Code: {$code}\n";
        
        if (!empty($info['pin'])) {
            $message .= "PIN: {$info['pin']}\n";
        }
        
        $message .= "Amount: {$amount} {$currency}\n";
        $message .= "Valid for 24 hours.\n";
        $message .= "Keep this code secure!";
        
        return $message;
    }
    
    /**
     * Build confirmation message
     */
    private function buildConfirmationMessage(float $amount, string $type, string $reference, string $status): string
    {
        $statusText = $status === 'completed' ? 'successful' : $status;
        
        $message = "✅ VouchMorph Transaction {$statusText}\n";
        $message .= "Type: {$type}\n";
        $message .= "Amount: {$amount} BWP\n";
        $message .= "Ref: " . substr($reference, 0, 8) . "...\n";
        $message .= "Thank you for using VouchMorph!";
        
        return $message;
    }
    
    /**
     * Log SMS to database
     */
    private function logWithdrawalSms(string $phone, string $code, float $amount, array $result): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sms_logs (
                    phone_number, code_sent, amount, status, message_id, created_at
                ) VALUES (
                    :phone, :code, :amount, :status, :message_id, NOW()
                )
            ");
            
            $stmt->execute([
                ':phone' => $phone,
                ':code' => $code,
                ':amount' => $amount,
                ':status' => $result['success'] ? 'SENT' : 'FAILED',
                ':message_id' => $result['message_id'] ?? null
            ]);
            
        } catch (Exception $e) {
            $this->log("Failed to log SMS: " . $e->getMessage());
        }
    }
    
    /**
     * Process SMS delivery callback from virtual phone system
     */
    public function processDeliveryCallback(array $callbackData): void
    {
        $this->log("Received delivery callback: " . json_encode($callbackData));
        
        // Update message_outbox status
        if (isset($callbackData['message_id'])) {
            $stmt = $this->db->prepare("
                UPDATE message_outbox 
                SET status = :status, 
                    delivered_at = NOW(),
                    delivery_report = :report
                WHERE message_id = :message_id
            ");
            
            $stmt->execute([
                ':status' => $callbackData['status'] ?? 'DELIVERED',
                ':report' => json_encode($callbackData),
                ':message_id' => $callbackData['message_id']
            ]);
        }
    }
    
    /**
     * Get SMS history for a phone number
     */
    public function getSmsHistory(string $phoneNumber, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM message_outbox 
            WHERE destination = :phone 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        
        $stmt->execute([
            ':phone' => $phoneNumber,
            ':limit' => $limit
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Log messages
     */
    private function log(string $message): void
    {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
        error_log("[SMS Service] " . $message);
    }
}
