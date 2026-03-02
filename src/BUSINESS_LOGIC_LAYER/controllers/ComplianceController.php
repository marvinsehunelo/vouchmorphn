<?php
// BUSINESS_LOGIC_LAYER/controllers/ComplianceController.php

namespace BUSINESS_LOGIC_LAYER\Controllers;

use BUSINESS_LOGIC_LAYER\Services\ComplianceService\AMLService;
use BUSINESS_LOGIC_LAYER\Services\ComplianceService\KYCService;
use BUSINESS_LOGIC_LAYER\Services\ComplianceService\FraudDetectionService;
use BUSINESS_LOGIC_LAYER\Services\AuditTrailService;
use SECURITY_LAYER\Monitoring\ThreatMonitor;
use SECURITY_LAYER\Monitoring\IntrusionDetection;
use APP_LAYER\Utils\SessionManager;
use PDO;
use Exception;

/**
 * ComplianceController
 * ---------------------
 * Coordinates AML, KYC, and FraudDetection services.
 * Used by admin dashboards, compliance reports, and security monitors.
 */
class ComplianceController
{
    private AMLService $amlService;
    private KYCService $kycService;
    private FraudDetectionService $fraudService;
    private AuditTrailService $auditTrail;
    private ThreatMonitor $threatMonitor;
    private IntrusionDetection $intrusionDetection;

    public function __construct(PDO $swapDB)
    {
        // Initialize compliance services
        $this->amlService = new AMLService($swapDB);
        $this->kycService = new KYCService($swapDB);
        $this->fraudService = new FraudDetectionService($swapDB);
        $this->auditTrail = new AuditTrailService($swapDB);

        // Initialize security monitors
        $this->threatMonitor = new ThreatMonitor();
        $this->intrusionDetection = new IntrusionDetection();
    }

    /**
     * Run all compliance checks and return a summarized dashboard report.
     */
    public function getComplianceOverview(): array
    {
        try {
            $amlStats = $this->amlService->getAMLStatusSummary();
            $kycStats = $this->kycService->getVerificationSummary();
            $fraudStats = $this->fraudService->getFraudAlerts();

            $summary = [
                'AML' => $amlStats,
                'KYC' => $kycStats,
                'Fraud' => $fraudStats,
            ];

            // Log audit trail
            $this->auditTrail->logAction(
                'Compliance Overview Generated',
                SessionManager::getUserName(),
                json_encode($summary)
            );

            return [
                'success' => true,
                'summary' => $summary,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            $this->auditTrail->logAction('Compliance Overview Failed', 'System', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Manually trigger AML review for a user or transaction.
     */
    public function runAMLReview(string $targetId): array
    {
        try {
            $result = $this->amlService->analyzeTransaction($targetId);
            $this->auditTrail->logAction('Manual AML Review', SessionManager::getUserName(), json_encode($result));
            return ['success' => true, 'result' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run KYC re-verification on a user.
     */
    public function reverifyKYC(string $userId): array
    {
        try {
            $result = $this->kycService->reverifyIdentity($userId);
            $this->auditTrail->logAction('KYC Reverification', SessionManager::getUserName(), json_encode($result));
            return ['success' => true, 'result' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run fraud detection scan across recent swaps.
     */
    public function detectFraud(): array
    {
        try {
            $alerts = $this->fraudService->scanRecentSwaps();
            $this->threatMonitor->recordAlerts($alerts);

            $this->auditTrail->logAction(
                'Fraud Detection Scan',
                SessionManager::getUserName(),
                json_encode(['alerts_found' => count($alerts)])
            );

            return [
                'success' => true,
                'alerts' => $alerts,
                'count' => count($alerts),
            ];
        } catch (Exception $e) {
            $this->auditTrail->logAction('Fraud Scan Failed', 'System', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check for real-time intrusion or abnormal requests.
     */
    public function monitorSecurity(): array
    {
        try {
            $intrusion = $this->intrusionDetection->checkIncomingRequest($_SERVER);
            if ($intrusion['suspicious']) {
                $this->auditTrail->logAction('Intrusion Attempt Detected', 'System', json_encode($intrusion));
            }

            return [
                'success' => true,
                'intrusion' => $intrusion,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
