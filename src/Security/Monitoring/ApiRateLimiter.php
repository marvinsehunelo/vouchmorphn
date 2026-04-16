<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// SECURITY_LAYER/Monitoring/ApiRateLimiter.php

namespace SECURITY_LAYER\Monitoring;

class ApiRateLimiter
{
    private array $limits = [];
    private int $maxRequests;
    private int $period;

    public function __construct(int $maxRequests = 100, int $period = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->period = $period;
    }

    public function check(string $clientId): bool
    {
        $time = time();
        if (!isset($this->limits[$clientId])) {
            $this->limits[$clientId] = [];
        }

        // Remove expired timestamps
        $this->limits[$clientId] = array_filter($this->limits[$clientId], fn($t) => $t > $time - $this->period);
        if (count($this->limits[$clientId]) >= $this->maxRequests) return false;

        $this->limits[$clientId][] = $time;
        return true;
    }
}
