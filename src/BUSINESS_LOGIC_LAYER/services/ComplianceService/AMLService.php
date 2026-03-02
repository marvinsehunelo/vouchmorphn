<?php
// BUSINESS_LOGIC_LAYER/services/ComplianceService/AMLService.php

namespace BUSINESS_LOGIC_LAYER\Services\ComplianceService;

use PDO;
use Exception;

class AMLService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Detect suspicious swap requests within a time window
     */
    public function detectSuspiciousActivity(string $fromDate = null, string $toDate = null): array
    {
        $fromDate = $fromDate ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
        $toDate   = $toDate ?? date('Y-m-d 23:59:59');

        $stmt = $this->db->prepare("
            SELECT 
                s.swap_id,
                s.user_id,
                s.from_currency,
                s.to_currency,
                s.amount,
                s.status,
                s.fraud_check_status,
                s.created_at,
                u.username,
                u.aml_score,
                u.kyc_verified,
                u.role_id
            FROM swap_requests s
            JOIN users u ON u.user_id = s.user_id
            WHERE s.created_at BETWEEN :from AND :to
        ");
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $alerts = [];

        foreach ($rows as $r) {
            $riskLevel = $this->assessRisk($r);
            if ($riskLevel !== 'LOW') {
                $alerts[] = [
                    'swap_id' => $r['swap_id'],
                    'user' => $r['username'],
                    'user_id' => $r['user_id'],
                    'amount' => $r['amount'],
                    'from_currency' => $r['from_currency'],
                    'to_currency' => $r['to_currency'],
                    'aml_score' => $r['aml_score'],
                    'kyc_verified' => $r['kyc_verified'],
                    'risk_level' => $riskLevel,
                    'status' => $r['status'],
                    'fraud_check_status' => $r['fraud_check_status'],
                    'created_at' => $r['created_at']
                ];
            }
        }

        return $alerts;
    }

    /**
     * Risk assessment
     */
    private function assessRisk(array $r): string
    {
        $risk = 0;

        // Rule 1: Transaction amount
        if ($r['amount'] > 50000) $risk += 3;
        elseif ($r['amount'] > 20000) $risk += 2;
        elseif ($r['amount'] > 10000) $risk += 1;

        // Rule 2: Same user, multiple swaps in last 7 days
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT to_currency) AS currency_count
            FROM swap_requests
            WHERE user_id = :user_id
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([':user_id' => $r['user_id']]);
        $currencyCount = (int) $stmt->fetchColumn();
        if ($currencyCount > 2) $risk += 2;

        // Rule 3: AML score
        if ($r['aml_score'] > 70) $risk += 3;
        elseif ($r['aml_score'] > 40) $risk += 2;

        // Rule 4: KYC not verified
        if (!$r['kyc_verified']) $risk += 2;

        // Rule 5: Repeated OTPs
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM otp_logs
            WHERE phone = (SELECT phone FROM users WHERE user_id = :user_id)
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([':user_id' => $r['user_id']]);
        $otpCount = (int) $stmt->fetchColumn();
        if ($otpCount > 5) $risk += 1;

        if ($risk >= 7) return 'HIGH';
        if ($risk >= 4) return 'MEDIUM';
        return 'LOW';
    }

    /**
     * Generate report for admin dashboard
     */
    public function generateReport(string $fromDate = null, string $toDate = null): array
    {
        $alerts = $this->detectSuspiciousActivity($fromDate, $toDate);

        return [
            'total_checked' => count($alerts),
            'high_risk' => count(array_filter($alerts, fn($a) => $a['risk_level'] === 'HIGH')),
            'medium_risk' => count(array_filter($alerts, fn($a) => $a['risk_level'] === 'MEDIUM')),
            'low_risk' => count(array_filter($alerts, fn($a) => $a['risk_level'] === 'LOW')),
            'data' => $alerts
        ];
    }
}
