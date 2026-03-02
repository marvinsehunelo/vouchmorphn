<?php
// SECURITY_LAYER/Monitoring/IntrusionDetection.php

namespace SECURITY_LAYER\Monitoring;

class IntrusionDetection
{
    private array $suspiciousPatterns = [
        '/DROP\s+TABLE/i',
        '/UNION\s+SELECT/i',
        '/<script>/i'
    ];

    public function scanInput(string $input): bool
    {
        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) return true;
        }
        return false;
    }

    public function logIntrusion(string $input, string $ip): void
    {
        // log to audit or alert admin
        file_put_contents(__DIR__ . '/../../DATA_PERSISTENCE_LAYER/models/Intrusion.log', 
            date('Y-m-d H:i:s') . " - $ip - $input\n", FILE_APPEND
        );
    }
}
