<?php

/**
 * Mojaloop Router - Routes ISO 20022 requests to appropriate handlers
 * 
 * Namespace: Infrastructure\Mojaloop
 */

namespace Infrastructure\Mojaloop;

use Infrastructure\Mojaloop\Mappers\ResponseMapper;
use Infrastructure\Mojaloop\Mappers\ErrorMapper;
use Domain\Services\SwapService;
use Infrastructure\Mojaloop\Handlers\PartiesHandler;
use Infrastructure\Mojaloop\Handlers\QuotesHandler;
use Infrastructure\Mojaloop\Handlers\TransfersHandler;
use Core\Database\DBConnection;

class Router
{
    private $partiesHandler;
    private $quotesHandler;
    private $transfersHandler;
    private $swapService;
    private $db;

    public function __construct()
    {
        // Get the database configuration from bootstrap
        global $dbConfig, $mojaloopConfig, $logger, $cache, $eventDispatcher, $participants;
        
        if (!isset($dbConfig)) {
            throw new \RuntimeException("Database configuration not found in global scope");
        }
        
        // Initialize DBConnection with config
        $this->db = new DBConnection($dbConfig);
        
        // Get the PDO instance from DBConnection
        $pdo = $this->db->getConnection();
        
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException("Failed to get PDO instance from DBConnection");
        }
        
        // Get encryption key from environment
        $appKey = getenv('APP_ENCRYPTION_KEY') ?: 'default_test_key';
        
        // Get country code from constant or global
        $countryCode = defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : (getenv('COUNTRY_CODE') ?: 'botswana');
        
        // Prepare participants array
        $participantsList = [];
        foreach (($participants ?? []) as $code => $participant) {
            $participantsList[$code] = [
                'id' => $participant['id'] ?? $code,
                'name' => $participant['name'] ?? $code,
                'type' => $participant['type'] ?? 'DFSP',
                'status' => $participant['status'] ?? 'ACTIVE'
            ];
        }
        
        // Initialize SwapService with ALL 5 required arguments
        $this->swapService = new SwapService(
            $pdo,                                   // Argument 1: PDO
            $mojaloopConfig ?? [],                   // Argument 2: Config array
            $countryCode,                            // Argument 3: Country string
            $appKey,                                 // Argument 4: Encryption key
            $participantsList                         // Argument 5: Participants array
        );
        
        // Initialize endpoint handlers
        $this->partiesHandler = new PartiesHandler($this->swapService);
        $this->quotesHandler = new QuotesHandler($this->swapService);
        $this->transfersHandler = new TransfersHandler($this->swapService);
        
        error_log("[MojaloopRouter] Initialized successfully with PDO and country: " . $countryCode);
    }

    /**
     * Route incoming request to appropriate handler
     * 
     * @param string $path Request path
     * @param array $payload Request payload (decoded JSON)
     * @param array $headers Request headers
     * @return array Response data
     */
    public function route(string $path, array $payload = [], array $headers = []): array
    {
        try {
            error_log("[MojaloopRouter] Routing request: $path");
            
            switch (true) {
                case strpos($path, '/participants') === 0:
                case strpos($path, '/parties') === 0:
                    // Extract party type and ID from path
                    $pathParts = explode('/', trim($path, '/'));
                    $partyType = $pathParts[1] ?? 'MSISDN';
                    $partyId = $pathParts[2] ?? '';
                    
                    $result = $this->partiesHandler->lookup([
                        'type' => $partyType,
                        'id' => $partyId
                    ], $headers);
                    
                    return ResponseMapper::mapPartyLookup($result);

                case strpos($path, '/quotes') === 0:
                    $quote = $this->quotesHandler->createQuote($payload, $headers);
                    return ResponseMapper::mapQuote($quote);

                case strpos($path, '/transfers') === 0:
                    $swap = $this->transfersHandler->executeTransfer($payload, $headers);

                    if (($swap['status'] ?? '') !== 'success') {
                        return ErrorMapper::map($swap);
                    }

                    return ResponseMapper::mapTransfer($swap);

                case $path === '/health':
                    return [
                        'status' => 'success',
                        'data' => [
                            'status' => 'OK',
                            'timestamp' => date('c'),
                            'service' => 'VouchMorph Mojaloop Adapter'
                        ]
                    ];

                default:
                    error_log("[MojaloopRouter] Endpoint not implemented: $path");
                    return ErrorMapper::map([
                        'status' => 'error',
                        'message' => "Endpoint '{$path}' not implemented"
                    ]);
            }

        } catch (\Throwable $e) {
            error_log("[MojaloopRouter] Error: " . $e->getMessage());
            return ErrorMapper::map([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send callback to TTK (Test Toolkit)
     * 
     * @param string $path Callback path
     * @param array $payload Callback payload
     * @param array $headers Original request headers
     * @return bool Success status
     */
    public function sendCallback(string $path, array $payload, array $headers = []): bool
    {
        // TTK expects callbacks on port 5050 with the original path
        $ttkHost = '172.17.0.1';
        $callbackUrl = "http://{$ttkHost}:5050{$path}";
        
        error_log("[Callback] Sending callback to TTK: $callbackUrl");
        error_log("[Callback] Payload: " . json_encode($payload));
        
        $ch = curl_init($callbackUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/vnd.interoperability.parties+json;version=1.0',
            'FSPIOP-Source: ' . ($headers['FSPIOP-SOURCE'] ?? $headers['Fspiop-Source'] ?? 'VOUCHMORPH'),
            'FSPIOP-Destination: ' . ($headers['FSPIOP-DESTINATION'] ?? $headers['Fspiop-Destination'] ?? $headers['FSPIOP-SOURCE'] ?? 'VOUCHMORPH'),
            'Accept: application/json',
            'Date: ' . gmdate('D, d M Y H:i:s GMT')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            error_log("[Callback] Connection error: $error");
            
            // Try alternative IP as fallback
            if ($ttkHost === '172.17.0.1') {
                $altUrl = "http://172.18.0.1:5050{$path}";
                error_log("[Callback] Trying alternative IP: $altUrl");
                
                curl_setopt($ch, CURLOPT_URL, $altUrl);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                if (!$error) {
                    $callbackUrl = $altUrl;
                }
            }
        }
        
        curl_close($ch);
        
        if ($error) {
            error_log("[Callback] Failed: $error");
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[Callback] Success (HTTP $httpCode)");
            return true;
        } else {
            error_log("[Callback] Received HTTP $httpCode from TTK");
            return $httpCode === 404 ? true : false;
        }
    }

    /**
     * Queue callback for later processing if HTTP fails
     * 
     * @param string $path Callback path
     * @param array $payload Callback payload
     * @param array $headers Original request headers
     * @return bool Success status
     */
    public function queueCallback(string $path, array $payload, array $headers = []): bool
    {
        $queueDir = __DIR__ . '/../../../storage/callback_queue';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0777, true);
        }
        
        $filename = $queueDir . '/' . uniqid('callback_', true) . '.json';
        $data = [
            'path' => $path,
            'payload' => $payload,
            'headers' => $headers,
            'created_at' => date('c'),
            'attempts' => 0
        ];
        
        $result = file_put_contents($filename, json_encode($data));
        error_log("[Callback] Queued callback to: $filename");
        return $result !== false;
    }
}
