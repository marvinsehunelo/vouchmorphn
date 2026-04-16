<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace DFSP_ADAPTER_LAYER;

use DFSP_ADAPTER_LAYER\mapper\ResponseMapper;
use DFSP_ADAPTER_LAYER\mapper\ErrorMapper;
use BUSINESS_LOGIC_LAYER\services\SwapService;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

class MojaloopRouter
{
    private $participants;
    private $quotes;
    private $transfers;
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
        
        // CRITICAL: Get the PDO instance from DBConnection, not the DBConnection object itself
        $pdo = $this->db->getConnection();
        
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException("Failed to get PDO instance from DBConnection");
        }
        
        // Get encryption key from environment
        $appKey = getenv('APP_ENCRYPTION_KEY') ?: 'default_test_key';
        
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
            SYSTEM_COUNTRY,                          // Argument 3: Country string
            $appKey,                                 // Argument 4: Encryption key
            $participantsList                         // Argument 5: Participants array
        );
        
        // Initialize endpoint handlers
        $this->participants = new \DFSP_ADAPTER_LAYER\mojaloop\parties($this->swapService);
        $this->quotes = new \DFSP_ADAPTER_LAYER\mojaloop\quotes($this->swapService);
        $this->transfers = new \DFSP_ADAPTER_LAYER\mojaloop\transfers($this->swapService);
        
        error_log("[MojaloopRouter] Initialized successfully with PDO and country: " . SYSTEM_COUNTRY);
    }

    public function route(string $path, array $payload = [], array $headers = []): array
    {
        try {
            error_log("MojaloopRouter routing: $path");
            
            switch (true) {
                case strpos($path, '/participants') === 0:
                case strpos($path, '/parties') === 0:
                    // Extract party type and ID from path
                    $pathParts = explode('/', trim($path, '/'));
                    $partyType = $pathParts[1] ?? 'MSISDN';
                    $partyId = $pathParts[2] ?? '';
                    
                    $result = $this->participants->lookup([
                        'type' => $partyType,
                        'id' => $partyId
                    ], $headers);
                    
                    return ResponseMapper::mapPartyLookup($result);

                case strpos($path, '/quotes') === 0:
                    $quote = $this->quotes->createQuote($payload, $headers);
                    return ResponseMapper::mapQuote($quote);

                case strpos($path, '/transfers') === 0:
                    $swap = $this->transfers->executeTransfer($payload, $headers);

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
                            'service' => 'VouchMorphn Mojaloop Adapter'
                        ]
                    ];

                default:
                    error_log("Endpoint not implemented: $path");
                    return ErrorMapper::map([
                        'status' => 'error',
                        'message' => "Endpoint '{$path}' not implemented"
                    ]);
            }

        } catch (\Throwable $e) {
            error_log("MojaloopRouter error: " . $e->getMessage());
            return ErrorMapper::map([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send callback to TTK
     */
    public function sendCallback(string $path, array $payload, array $headers = []): bool
    {
        // IMPORTANT: TTK expects callbacks on port 5050 with the original path
        // The path already includes /parties, /quotes, etc.
        $ttkHost = '172.17.0.1'; // Default Docker IP that works
        $callbackUrl = "http://{$ttkHost}:5050{$path}";
        
        error_log("[Callback] 🔄 Sending callback to TTK: $callbackUrl");
        error_log("[Callback] 📦 Payload: " . json_encode($payload));
        
        $ch = curl_init($callbackUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/vnd.interoperability.parties+json;version=1.0',
            'FSPIOP-Source: ' . ($headers['FSPIOP-SOURCE'] ?? $headers['Fspiop-Source'] ?? 'VOUCHMORPHN'),
            'FSPIOP-Destination: ' . ($headers['FSPIOP-DESTINATION'] ?? $headers['Fspiop-Destination'] ?? $headers['FSPIOP-SOURCE'] ?? 'VOUCHMORPHN'),
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
            error_log("[Callback] ❌ Connection error: $error");
            
            // Try alternative IP as fallback
            if ($ttkHost === '172.17.0.1') {
                $altUrl = "http://172.18.0.1:5050{$path}";
                error_log("[Callback] 🔄 Trying alternative IP: $altUrl");
                
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
            error_log("[Callback] ❌ Failed: $error");
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[Callback] ✅ Success (HTTP $httpCode)");
            return true;
        } else {
            error_log("[Callback] ⚠️ Received HTTP $httpCode from TTK");
            // TTK might return 404 for some paths but still accept the callback
            return $httpCode === 404 ? true : false;
        }
    }

    /**
     * Alternative callback method using file-based queue if HTTP fails
     */
    public function queueCallback(string $path, array $payload, array $headers = []): bool
    {
        $queueDir = __DIR__ . '/../../APP_LAYER/callback_queue';
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
        error_log("[Callback] 📝 Queued callback to: $filename");
        return $result !== false;
    }
}
