<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\Helpers;

/**
 * CardHelper - Utility functions for card generation and validation
 */
class CardHelper
{
    /**
     * Generate a valid card number with BIN
     * 
     * @param string $bin Bank Identification Number (e.g., '411111' for Visa test)
     * @return array ['pan' => full PAN, 'formatted' => formatted with spaces, 'suffix' => last 4]
     */
    public static function generateCardNumber(string $bin = '411111'): array
    {
        // Generate 9 random digits (for 16-digit card: BIN(6) + random(9) + check(1) = 16)
        $random = str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        
        // Calculate Luhn check digit
        $checkDigit = self::calculateLuhnCheckDigit($bin . $random);
        
        // Full PAN
        $fullPan = $bin . $random . $checkDigit;
        
        // Format with spaces every 4 digits
        $formatted = chunk_split($fullPan, 4, ' ');
        $formatted = trim($formatted);
        
        return [
            'pan' => $fullPan,
            'formatted' => $formatted,
            'suffix' => substr($fullPan, -4)
        ];
    }
    
    /**
     * Calculate Luhn check digit (last digit of card number)
     */
    public static function calculateLuhnCheckDigit(string $number): string
    {
        $sum = 0;
        $alt = true;
        
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int)$number[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n = ($n % 10) + 1;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        
        return (string)((10 - ($sum % 10)) % 10);
    }
    
    /**
     * Validate a card number using Luhn algorithm
     */
    public static function validateLuhn(string $cardNumber): bool
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        $sum = 0;
        $alt = false;
        
        for ($i = strlen($cardNumber) - 1; $i >= 0; $i--) {
            $n = (int)$cardNumber[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n = ($n % 10) + 1;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        
        return ($sum % 10) === 0;
    }
    
    /**
     * Generate CVV (3-digit security code)
     * 
     * @param string $pan Card number
     * @param string $expiry MMYY format
     * @param string $serviceCode Usually '101'
     * @return string 3-digit CVV
     */
    public static function generateCVV(string $pan, string $expiry, string $serviceCode = '101'): string
    {
        // In production, use a proper CVV algorithm with secret key
        // This is a simplified version for demonstration
        $secretKey = getenv('CARD_SECRET_KEY') ?: 'vouchmorph-card-key-2025';
        
        $data = $pan . $expiry . $serviceCode . $secretKey;
        $hash = hash('sha256', $data);
        
        // Take first 3 digits and ensure they're numeric
        $cvv = substr(preg_replace('/[^0-9]/', '', $hash), 0, 3);
        
        // Pad if needed
        return str_pad($cvv, 3, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate a unique authorization code
     */
    public static function generateAuthCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
    
    /**
     * Mask card number for display (show only last 4)
     */
    public static function maskCardNumber(string $cardNumber): string
    {
        $last4 = substr(preg_replace('/\D/', '', $cardNumber), -4);
        return 'XXXX XXXX XXXX ' . $last4;
    }
    
    /**
     * Detect card brand from BIN
     */
    public static function detectCardBrand(string $bin): string
    {
        $bin = substr(preg_replace('/\D/', '', $bin), 0, 6);
        
        $brands = [
            'visa' => ['4'],
            'mastercard' => ['51', '52', '53', '54', '55', '2221', '2720'],
            'amex' => ['34', '37'],
            'discover' => ['6011', '65', '644', '645', '646', '647', '648', '649'],
            'diners' => ['300', '301', '302', '303', '304', '305', '36', '38', '39'],
            'jcb' => ['3528', '3529', '353', '354', '355', '356', '357', '358']
        ];
        
        foreach ($brands as $brand => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($bin, $pattern) === 0) {
                    return $brand;
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Format expiry for display (MM/YY)
     */
    public static function formatExpiry(int $month, int $year): string
    {
        return sprintf('%02d/%02d', $month, substr((string)$year, -2));
    }
}
