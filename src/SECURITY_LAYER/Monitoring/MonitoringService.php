<?php
// SECURITY_LAYER/Monitoring/MonitoringService.php

namespace SECURITY_LAYER\Monitoring;

use DateTime;

class MonitoringService
{
    private string $logDir;
    private array $endpoints = [
        '/swap' => ['maxResponseTime' => 0.2, 'successRate' => 0.999],
        '/cashout' => ['maxResponseTime' => 0.3, 'successRate' => 0.995],
        // Add other endpoints here
    ];

    private array $metrics = [];

    public function __construct(string $logDir = __DIR__ . '/../../APP_LAYER/logs/')
    {
        $this->logDir = rtrim($logDir, '/') . '/';
    }

    /**
     * Track a single API call
     *
     * @param string $endpoint API endpoint called
     * @param float $responseTime in seconds
     * @param bool $success whether the call was successful
     * @param string|null $clientId optional client identifier
     */
    public function trackRequest(string $endpoint, float $responseTime, bool $success, ?string $clientId = null): void
    {
        $date = (new DateTime())->format('Y-m-d');

        if (!isset($this->metrics[$date][$endpoint])) {
            $this->metrics[$date][$endpoint] = [
                'totalRequests' => 0,
                'successCount' => 0,
                'failCount' => 0,
                'responseTimes' => [],
            ];
        }

        $this->metrics[$date][$endpoint]['totalRequests']++;
        $this->metrics[$date][$endpoint]['responseTimes'][] = $responseTime;

        if ($success) {
            $this->metrics[$date][$endpoint]['successCount']++;
        } else {
            $this->metrics[$date][$endpoint]['failCount']++;
        }

        // Check SLA
        $this->checkSLA($endpoint, $responseTime, $success, $clientId);
    }

    /**
     * Check SLA for an endpoint and alert admin if breached
     */
    private function checkSLA(string $endpoint, float $responseTime, bool $success, ?string $clientId): void
    {
        if (!isset($this->endpoints[$endpoint])) return;

        $threshold = $this->endpoints[$endpoint];

        $alertMsg = null;

        if ($responseTime > $threshold['maxResponseTime']) {
            $alertMsg = "SLA breach: $endpoint response time {$responseTime}s exceeds max {$threshold['maxResponseTime']}s";
        }

        if (!$success && ($this->metrics[(new DateTime())->format('Y-m-d')][$endpoint]['successCount'] /
            $this->metrics[(new DateTime())->format('Y-m-d')][$endpoint]['totalRequests']) < $threshold['successRate']) {
            $alertMsg = "SLA breach: $endpoint success rate below required {$threshold['successRate']}";
        }

        if ($alertMsg) {
            if ($clientId) $alertMsg .= " | Client: $clientId";
            $this->alertAdmin($alertMsg);
        }
    }

    /**
     * Alert admin (append to ThreatAlerts.log)
     */
    public function alertAdmin(string $message): void
    {
        $logFile = $this->logDir . 'ThreatAlerts.log';
        $line = (new DateTime())->format('Y-m-d H:i:s') . " - ALERT - $message\n";
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    /**
     * Aggregate daily metrics to log file
     */
    public function logDailyMetrics(): void
    {
        $date = (new DateTime())->format('Y-m-d');
        if (!isset($this->metrics[$date])) return;

        $logFile = $this->logDir . 'daily_reconciliations.log';

        foreach ($this->metrics[$date] as $endpoint => $data) {
            $avgResp = count($data['responseTimes']) ? array_sum($data['responseTimes']) / count($data['responseTimes']) : 0;
            $line = sprintf(
                "%s | Endpoint: %s | Total: %d | Success: %d | Fail: %d | AvgRespTime: %.3fs\n",
                $date,
                $endpoint,
                $data['totalRequests'],
                $data['successCount'],
                $data['failCount'],
                $avgResp
            );
            file_put_contents($logFile, $line, FILE_APPEND);
        }
    }

    /**
     * Optionally, you can call this at the end of each week or month
     */
    public function logAggregateMetrics(string $period = 'weekly'): void
    {
        $logFile = $this->logDir . "{$period}_reconciliations.log";

        foreach ($this->metrics as $date => $endpoints) {
            foreach ($endpoints as $endpoint => $data) {
                $avgResp = count($data['responseTimes']) ? array_sum($data['responseTimes']) / count($data['responseTimes']) : 0;
                $line = sprintf(
                    "%s | Endpoint: %s | Total: %d | Success: %d | Fail: %d | AvgRespTime: %.3fs\n",
                    $date,
                    $endpoint,
                    $data['totalRequests'],
                    $data['successCount'],
                    $data['failCount'],
                    $avgResp
                );
                file_put_contents($logFile, $line, FILE_APPEND);
            }
        }
    }
}

