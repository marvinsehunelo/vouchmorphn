<?php
declare(strict_types=1);

namespace INTEGRATION_LAYER\CLIENTS\CardSchemes;

use BUSINESS_LOGIC_LAYER\Helpers\CardHelper;

/**
 * CardNumberGenerator - Handles BIN ranges and card number generation
 */
class CardNumberGenerator
{
    private array $config;
    
    // BIN ranges for different card types
    private const BIN_RANGES = [
        'visa' => ['411111', '411112', '411113', '411114', '411115'],
        'mastercard' => ['511111', '511112', '511113', '511114', '511115'],
        'voucher' => ['601111', '601112', '601113', '601114', '601115'],
        'student' => ['811111', '811112', '811113', '811114', '811115'],
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Generate a card number for a specific purpose
     */
    public function generateForPurpose(string $purpose): array
    {
        // Get BIN based on purpose
        $bin = $this->getBinForPurpose($purpose);
        
        // Generate card number
        $cardData = CardHelper::generateCardNumber($bin);
        
        // Generate expiry (3 years from now)
        $expiryYear = (int)date('Y') + 3;
        $expiryMonth = (int)date('m');
        
        // Generate CVV
        $expiryFormatted = sprintf('%02d%02d', $expiryMonth, substr((string)$expiryYear, -2));
        $cvv = CardHelper::generateCVV($cardData['pan'], $expiryFormatted);
        
        return [
            'pan' => $cardData['pan'],
            'pan_formatted' => $cardData['formatted'],
            'pan_suffix' => $cardData['suffix'],
            'pan_hash' => hash('sha256', $cardData['pan']),
            'cvv' => $cvv,
            'cvv_hash' => hash('sha256', $cvv),
            'expiry_month' => $expiryMonth,
            'expiry_year' => $expiryYear,
            'expiry_formatted' => sprintf('%02d/%02d', $expiryMonth, substr((string)$expiryYear, -2)),
            'brand' => CardHelper::detectCardBrand($bin)
        ];
    }
    
    /**
     * Get appropriate BIN for purpose
     */
    private function getBinForPurpose(string $purpose): string
    {
        $binRanges = self::BIN_RANGES[$purpose] ?? self::BIN_RANGES['visa'];
        
        // Randomly select from available BINs
        return $binRanges[array_rand($binRanges)];
    }
    
    /**
     * Generate multiple cards from a batch (e.g., for DTEF students)
     */
    public function generateBatch(string $purpose, int $count): array
    {
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cards[] = $this->generateForPurpose($purpose);
        }
        return $cards;
    }
}
