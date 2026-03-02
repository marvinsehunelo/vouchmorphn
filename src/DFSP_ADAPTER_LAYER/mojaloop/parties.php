<?php
namespace DFSP_ADAPTER_LAYER\mojaloop;

use BUSINESS_LOGIC_LAYER\services\SwapService;

class parties
{
    private $swapService;
    
    // Mock party data for testing
    private $parties = [
        'ALPHA' => [
            'name' => 'Alpha Financial',
            'firstName' => 'Alpha',
            'lastName' => 'User'
        ],
        'BRAVO' => [
            'name' => 'Bravo Bank',
            'firstName' => 'Bravo',
            'lastName' => 'Customer'
        ],
        'CHARLIE' => [
            'name' => 'Charlie Services',
            'firstName' => 'Charlie',
            'lastName' => 'Client'
        ],
        'CARD' => [
            'name' => 'Card Issuer',
            'firstName' => 'Card',
            'lastName' => 'Holder'
        ],
        'BANK' => [
            'name' => 'Bank Institution',
            'firstName' => 'Bank',
            'lastName' => 'Account'
        ]
    ];
    
    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }
    
    /**
     * Look up party information
     * 
     * @param array $payload Request payload (contains type, id from route params)
     * @param array $headers Request headers
     * @return array Party information
     */
    public function lookup(array $payload, array $headers): array
    {
        error_log("[Parties] Lookup called with payload: " . json_encode($payload));
        
        // Extract party details from payload (passed from router)
        $partyType = $payload['type'] ?? 'MSISDN';
        $partyId = $payload['id'] ?? '';
        
        error_log("[Parties] Looking up $partyType: $partyId");
        
        // Check if party exists in mock data
        if (isset($this->parties[$partyId])) {
            $party = $this->parties[$partyId];
            
            return [
                'partyIdType' => $partyType,
                'partyIdentifier' => $partyId,
                'fspId' => 'VOUCHMORPHN',
                'name' => $party['name'],
                'firstName' => $party['firstName'],
                'lastName' => $party['lastName']
            ];
        }
        
        // For any other ID, return a generic response (for testing)
        error_log("[Parties] Party $partyId not found in mock data, returning generic");
        
        return [
            'partyIdType' => $partyType,
            'partyIdentifier' => $partyId,
            'fspId' => 'VOUCHMORPHN',
            'name' => 'Test User',
            'firstName' => 'Test',
            'lastName' => 'User'
        ];
    }
    
    /**
     * Alternative method that processes the request directly
     * This mimics the original standalone functionality
     * 
     * @param string $partyType The party type (MSISDN, etc.)
     * @param string $partyId The party identifier
     * @return array Formatted response
     */
    public function getParty(string $partyType, string $partyId): array
    {
        error_log("[Parties] Direct getParty: $partyType: $partyId");
        
        if (isset($this->parties[$partyId])) {
            $party = $this->parties[$partyId];
            
            return [
                'party' => [
                    'partyIdInfo' => [
                        'partyIdType' => $partyType,
                        'partyIdentifier' => $partyId,
                        'fspId' => 'VOUCHMORPHN'
                    ],
                    'name' => $party['name'],
                    'personalInfo' => [
                        'complexName' => [
                            'firstName' => $party['firstName'],
                            'lastName' => $party['lastName']
                        ]
                    ]
                ]
            ];
        }
        
        // Return 404-style response
        return [
            'error' => 'Party not found',
            'partyId' => $partyId
        ];
    }
}
