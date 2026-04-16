<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// SECURITY_LAYER/Auth/MultiFactorAuth.php

namespace SECURITY_LAYER\Auth;

class MultifactorAuth
{
    public function generateOtp(int $length = 6): string
    {
        return str_pad((string)random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    public function validateOtp(string $inputOtp, string $actualOtp): bool
    {
        return hash_equals($actualOtp, $inputOtp);
    }

    public function sendOtp(string $recipient, string $otp): bool
    {
        // integrate SMS/Email API
        return true;
    }
}
