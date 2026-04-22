<?php

declare(strict_types=1);

namespace Infrastructure\SMS;

use PDO;
use Exception;

/**
 * SMS Gateway Client for CazaCom Virtual Phone System
 * Connects VouchMorph to the telecom backend at http://localhost/CazaCom
 */
class SmsGatewayClient
{
    private string $baseUrl;
    private string $apiKey;
    private PDO $db;
    private array $config;
    
    private const LOG_FILE = '/tmp/vouchmorph_sms_gateway.log';
    
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        
        // Get configuration from config array
        $this->baseUrl = $config['base_url'] ?? 'https://cazacom-prod.up.railway.app';
        $this->apiKey = $config['api_key'] ?? $this->getApiKeyFromDB();
        
        $this->log("SmsGatewayClient initialized with URL: {$this->baseUrl}");
    }
    
    /**
     * Get API key from database if not provided
     */
    private function getApiKeyFromDB(): string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT config_value FROM communication_config 
                WHERE provider = 'cazacom' 
                AND config_key = 'api_key'
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['config_value'] ?? 'SACCUS_INTERNAL_KEY_2025';
        } catch (Exception $e) {
            $this->log("Failed to get API key from DB: " . $e->getMessage());
            return 'SACCUS_INTERNAL_KEY_2025';
        }
    }
    
    /**
     * Send SMS via CazaCom virtual phone system
     * 
     * @param string $phoneNumber Recipient phone number (e.g., +26770000000)
     * @param string $message SMS message content
     * @param array $options Additional options (priority, reference, etc.)
     * @return array Response with status and message_id
     */
    public function sendSms(string $phoneNumber, string $message, array $options = []): array
    {
        $this->log("Sending SMS to {$phoneNumber}");
        
        // Normalize phone number (remove + if present) - CazaCom expects without +
        $normalizedPhone = ltrim($phoneNumber, '+');
        
        // Prepare payload for CazaCom virtual phone system
        // Based on the api.php router, it expects 'recipient_number' and 'message'
        $payload = [
            'recipient_number' => $normalizedPhone,
            'message' => $message
        ];
        
        // Add optional parameters if provided (CazaCom might accept these)
        if (isset($options['reference'])) {
            $payload['reference'] = $options['reference'];
        }
        
        if (isset($options['sender_number'])) {
            $payload['sender_number'] = $options['sender_number'];
        }
        
        $this->log("SMS Payload: " . json_encode($payload));
        
        // Send to CazaCom virtual phone system
        $result = $this->callSmsApi($payload);
        
        // Log the API call
        $this->logApiCall($payload, $result);
        
        // Store in message_outbox for tracking
        $messageId = $this->storeInOutbox($phoneNumber, $message, $result, $options);
        
        // Check if the response indicates success
        $isSuccess = isset($result['status']) && $result['status'] === 'success';
        
        return [
            'success' => $isSuccess,
            'message_id' => $result['sms_id'] ?? $result['message_id'] ?? $messageId,
            'status' => $result['status'] ?? 'unknown',
            'provider' => 'cazacom',
            'raw_response' => $result
        ];
    }
    
    /**
     * Send bulk SMS messages
     * 
     * @param array $recipients Array of phone numbers
     * @param string $message Message to send
     * @param array $options Additional options
     * @return array Results for each recipient
     */
    public function sendBulkSms(array $recipients, string $message, array $options = []): array
    {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $results[] = $this->sendSms($recipient, $message, $options);
        }
        
        return [
            'success' => true,
            'total' => count($results),
            'results' => $results
        ];
    }
    
    /**
     * Call the CazaCom virtual phone system API
     * Uses the correct endpoint: http://localhost/CazaCom/backend/routes/api.php?path=sms/send
     */
    private function callSmsApi(array $payload): array
    {
        $ch = curl_init();
        
        // Construct the correct URL based on your CazaCom structure
        $apiPath = $this->config['api_path'] ?? '/backend/routes/api.php';
        $smsEndpoint = $this->config['sms_endpoint'] ?? '?path=sms/send';
        $url = rtrim($this->baseUrl, '/') . $apiPath . $smsEndpoint;
        
        $this->log("Calling CazaCom API at: {$url}");
        
        // CazaCom api.php expects X-Internal-Key header (from your api.php file)
        $headers = [
            'Content-Type: application/json',
            'X-Internal-Key: ' . $this->apiKey
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        $this->log("CazaCom API Response (HTTP {$httpCode}): " . substr($response, 0, 500));
        
        if ($curlError) {
            $this->log("CURL Error: " . $curlError);
            return [
                'success' => false,
                'error' => $curlError,
                'http_code' => $httpCode,
                'status' => 'error'
            ];
        }
        
        // Check for HTTP errors
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP Error: {$httpCode}",
                'http_code' => $httpCode,
                'raw_response' => $response,
                'status' => 'error'
            ];
        }
        
        $decoded = json_decode($response, true);
        
        if (!$decoded) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response',
                'raw_response' => $response,
                'status' => 'error'
            ];
        }
        
        return $decoded;
    }
    
    /**
     * Store SMS in message_outbox for tracking
     */
    private function storeInOutbox(string $phone, string $message, array $apiResponse, array $options): string
    {
        try {
            $messageId = 'SMS-' . uniqid();
            
            // Check if message_outbox table exists and has the right columns
            $stmt = $this->db->prepare("
                INSERT INTO message_outbox (
                    message_id, channel, destination, payload, status, created_at
                ) VALUES (
                    :message_id, 'SMS', :destination, :payload, :status, NOW()
                )
            ");
            
            $payload = json_encode([
                'phone' => $phone,
                'message' => $message,
                'api_response' => $apiResponse,
                'reference' => $options['reference'] ?? null
            ]);
            
            $isSuccess = isset($apiResponse['status']) && $apiResponse['status'] === 'success';
            $status = $isSuccess ? 'SENT' : 'FAILED';
            
            $stmt->execute([
                ':message_id' => $messageId,
                ':destination' => $phone,
                ':payload' => $payload,
                ':status' => $status
            ]);
            
            return $messageId;
            
        } catch (Exception $e) {
            $this->log("Failed to store in outbox: " . $e->getMessage());
            return 'ERROR-' . uniqid();
        }
    }
    
    /**
     * Get delivery status of an SMS
     * Note: CazaCom might not support this, returns mock response
     */
    public function getDeliveryStatus(string $messageId): array
    {
        // CazaCom api.php doesn't have a status endpoint based on the routes
        // Return a mock response
        return [
            'success' => true,
            'status' => 'unknown',
            'message' => 'Status checking not supported by CazaCom API'
        ];
    }
    
    /**
     * Get callback URL for delivery reports
     */
    private function getCallbackUrl(): string
    {
        return $this->config['callback_url'] ?? 'http://localhost/vouchmorph/public/api/callback/sms_delivery.php';
    }
    
    /**
     * Log messages for debugging
     */
    private function log(string $message): void
    {
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
        error_log("[CazaComSMS] " . $message);
    }
    
    /**
     * Log API call to database
     */
    private function logApiCall(array $request, array $response): void
    {
        try {
            // Check if api_message_logs table exists
            $stmt = $this->db->prepare("
                INSERT INTO api_message_logs (
                    message_id, message_type, direction, endpoint, 
                    request_payload, response_payload, http_status_code, 
                    success, created_at
                ) VALUES (
                    :message_id, 'sms_send', 'outgoing', :endpoint,
                    :request, :response, :http_code, :success, NOW()
                )
            ");
            
            $isSuccess = isset($response['status']) && $response['status'] === 'success';
            
            $stmt->execute([
                ':message_id' => 'SMS-API-' . uniqid(),
                ':endpoint' => '/backend/routes/api.php?path=sms/send',
                ':request' => json_encode($request),
                ':response' => json_encode($response),
                ':http_code' => $response['http_code'] ?? 200,
                ':success' => $isSuccess ? 1 : 0
            ]);
        } catch (Exception $e) {
            $this->log("Failed to log API call: " . $e->getMessage());
        }
    }
    
    /**
     * Test connection to CazaCom virtual phone system
     * Checks if the API endpoint is accessible
     */
    public function testConnection(): array
    {
        $ch = curl_init();
        
        $apiPath = $this->config['api_path'] ?? '/backend/routes/api.php';
        $smsEndpoint = $this->config['sms_endpoint'] ?? '?path=sms/send';
        $url = rtrim($this->baseUrl, '/') . $apiPath . $smsEndpoint;
        
        $this->log("Testing connection to: {$url}");
        
        // Do a HEAD request to check if endpoint exists
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Internal-Key: ' . $this->apiKey
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        $this->log("Test connection result: HTTP {$httpCode}");
        
        // HTTP 405 means Method Not Allowed - which is actually good because it means the endpoint exists
        // HTTP 200 also good
        $isAccessible = ($httpCode === 200 || $httpCode === 405);
        
        return [
            'success' => $isAccessible,
            'http_code' => $httpCode,
            'message' => $isAccessible ? 'API endpoint is accessible' : 'API endpoint is not accessible',
            'curl_error' => $curlError
        ];
    }
}
