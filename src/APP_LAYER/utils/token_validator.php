<?php
// 2. APP_LAYER/utils/token_validator.php

namespace APP_LAYER\utils;

class TokenValidator
{
    public static function validate(string $token, string $expected): bool
    {
        return hash_equals($expected, $token);
    }

    public static function generate(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
