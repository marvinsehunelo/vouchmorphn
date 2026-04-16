<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace DFSP_ADAPTER_LAYER\mojaloop;

use BUSINESS_LOGIC_LAYER\services\SwapService;

class quotes
{
    private $swapService;
    
    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }
    
    /**
     * Create a quote
     * 
     * @param array $payload Request payload
     * @param array $headers Request headers
     * @return array Quote data
     */
    public function createQuote(array $payload, array $headers): array
    {
        error_log("[Quotes] Create quote called with payload: " . json_encode($payload));
        
        // Extract data from payload
        $quoteId = $payload['quoteId'] ?? uniqid('quote_');
        $transactionId = $payload['transactionId'] ?? uniqid('txn_');
        $amount = $payload['amount']['amount'] ?? '100';
        $currency = $payload['amount']['currency'] ?? 'BWP';
        
        // Return quote response matching Mojaloop spec
        return [
            'quoteId' => $quoteId,
            'transactionId' => $transactionId,
            'transferAmount' => [
                'amount' => $amount,
                'currency' => $currency
            ],
            'payeeFsp' => 'VOUCHMORPHN',
            'payerFsp' => 'VOUCHMORPHN',
            'amountType' => $payload['amountType'] ?? 'SEND',
            'expiration' => date('c', strtotime('+1 hour')),
            'ilpPacket' => 'AYIBwgQAAAAAAAASAwBYZXhhbXBsZSB0ZXN0IHZhbHVlIG9ubHk',
            'condition' => 'ILPcZXy5K8q_7Ff6QtUABnX5Hn3RXq4l8YqR6b8Ff6Q'
        ];
    }
    
    /**
     * Get a quote by ID
     * 
     * @param string $quoteId Quote ID
     * @return array Quote data
     */
    public function getQuote(string $quoteId): array
    {
        return [
            'quoteId' => $quoteId,
            'quoteState' => 'ACCEPTED',
            'expiration' => date('c', strtotime('+1 hour'))
        ];
    }
}
