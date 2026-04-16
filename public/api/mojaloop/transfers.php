<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace DFSP_ADAPTER_LAYER\mojaloop;

use BUSINESS_LOGIC_LAYER\services\SwapService;

class transfers
{
    private $swapService;
    
    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }
    
    /**
     * Execute a transfer
     * 
     * @param array $payload Request payload
     * @param array $headers Request headers
     * @return array Transfer result
     */
    public function executeTransfer(array $payload, array $headers): array
    {
        error_log("[Transfers] Execute transfer called with payload: " . json_encode($payload));
        
        $transferId = $payload['transferId'] ?? uniqid('transfer_');
        $amount = $payload['amount']['amount'] ?? '100';
        $currency = $payload['amount']['currency'] ?? 'BWP';
        
        // For testing, always return success
        return [
            'status' => 'success',
            'transferId' => $transferId,
            'transferState' => 'COMMITTED',
            'completedTimestamp' => date('c'),
            'fulfilment' => 'ILPf4q8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q',
            'amount' => [
                'amount' => $amount,
                'currency' => $currency
            ]
        ];
    }
    
    /**
     * Get transfer by ID
     * 
     * @param string $transferId Transfer ID
     * @return array Transfer data
     */
    public function getTransfer(string $transferId): array
    {
        return [
            'transferId' => $transferId,
            'transferState' => 'COMMITTED',
            'fulfilment' => 'ILPf4q8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q'
        ];
    }
}
