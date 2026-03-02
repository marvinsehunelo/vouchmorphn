<?php
// SECURITY_LAYER/Monitoring/ThreatMonitor.php

namespace SECURITY_LAYER\Monitoring;

class ThreatMonitor
{
    public function scanLogs(array $logs): array
    {
        // Example: return logs with errors or attacks
        return array_filter($logs, fn($log) => strpos(strtolower($log['message']), 'error') !== false);
    }

    public function alertAdmin(string $message): void
    {
        // Could call NotificationService
        file_put_contents(__DIR__ . '/../../DATA_PERSISTENCE_LAYER/models/ThreatAlerts.log',
            date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND
        );
    }
}
