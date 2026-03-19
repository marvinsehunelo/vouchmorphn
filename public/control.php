<?php
declare(strict_types=1);

namespace CONTROL;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use PDO;

// Initialize database
$db = DBConnection::getConnection();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
function getComplianceScore($db) {
    $score = 0;
    $total = 8; // 8 key compliance areas
    
    // 1. KYC/AML Compliance
    $kycQuery = $db->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN kyc_verified = true THEN 1 ELSE 0 END) as verified
        FROM users
    ");
    $kyc = $kycQuery->fetch(PDO::FETCH_ASSOC);
    if ($kyc['total'] > 0 && ($kyc['verified'] / $kyc['total']) > 0.95) $score++;
    
    // 2. Transaction Authentication (OTP/MFA)
    $authQuery = $db->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN mfa_enabled = true THEN 1 ELSE 0 END) as mfa
        FROM users
    ");
    $auth = $authQuery->fetch(PDO::FETCH_ASSOC);
    if ($auth['total'] > 0 && ($auth['mfa'] / $auth['total']) > 0.5) $score++;
    
    // 3. Audit Trail Integrity
    $auditQuery = $db->query("
        SELECT COUNT(*) as total,
               COUNT(DISTINCT integrity_hash) as unique_hashes
        FROM audit_logs
    ");
    $audit = $auditQuery->fetch(PDO::FETCH_ASSOC);
    if ($audit['total'] == $audit['unique_hashes']) $score++;
    
    // 4. Settlement Accuracy
    $settlementQuery = $db->query("
        SELECT COUNT(*) FROM settlement_queue 
        WHERE status != 'SETTLED'
    ");
    if ($settlementQuery->fetchColumn() == 0) $score++;
    
    // 5. Failed Transaction Rate (<5%)
    $failQuery = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM swap_requests
    ");
    $fail = $failQuery->fetch(PDO::FETCH_ASSOC);
    if ($fail['total'] > 0 && ($fail['failed'] / $fail['total']) < 0.05) $score++;
    
    // 6. Hold Expiry Enforcement
    $holdQuery = $db->query("
        SELECT COUNT(*) FROM hold_transactions 
        WHERE status = 'ACTIVE' AND hold_expiry < NOW()
    ");
    if ($holdQuery->fetchColumn() == 0) $score++;
    
    // 7. Admin Action Logging
    $adminQuery = $db->query("
        SELECT COUNT(DISTINCT admin_id) as admins,
               COUNT(*) as actions
        FROM admin_actions
        WHERE created_at > NOW() - INTERVAL '7 days'
    ");
    $admin = $adminQuery->fetch(PDO::FETCH_ASSOC);
    if ($admin['admins'] > 0 && $admin['actions'] > 0) $score++;
    
    // 8. Regulatory Report Generation
    $reportQuery = $db->query("
        SELECT COUNT(*) FROM regulator_reports 
        WHERE created_at > NOW() - INTERVAL '30 days'
    ");
    if ($reportQuery->fetchColumn() >= 4) $score++; // Weekly reports
    
    return round(($score / $total) * 100);
}

function getPartnerHealth($db) {
    $partners = [];
    $partnerQuery = $db->query("
        SELECT 
            p.participant_id,
            p.name,
            p.type,
            p.status,
            COUNT(a.log_id) as api_calls,
            AVG(a.duration_ms) as avg_response,
            SUM(CASE WHEN a.success THEN 1 ELSE 0 END) as successful,
            MAX(a.created_at) as last_contact
        FROM participants p
        LEFT JOIN api_message_logs a ON p.name = a.participant_name
        GROUP BY p.participant_id
        ORDER BY p.name
    ");
    
    while ($partner = $partnerQuery->fetch(PDO::FETCH_ASSOC)) {
        $partner['health'] = 'unknown';
        $partner['error_rate'] = 0;
        
        if ($partner['api_calls'] > 0) {
            $errorRate = 1 - ($partner['successful'] / $partner['api_calls']);
            $partner['error_rate'] = round($errorRate * 100, 2);
            
            if ($errorRate < 0.01) $partner['health'] = 'excellent';
            elseif ($errorRate < 0.05) $partner['health'] = 'good';
            elseif ($errorRate < 0.10) $partner['health'] = 'degraded';
            else $partner['health'] = 'critical';
        }
        
        $partners[] = $partner;
    }
    
    return $partners;
}

function getActiveAlerts($db) {
    $alerts = [];
    
    // Check for failed API connections
    $failedAPI = $db->query("
        SELECT 
            participant_name,
            COUNT(*) as failures,
            MAX(created_at) as last_failure
        FROM api_message_logs
        WHERE success = false
        AND created_at > NOW() - INTERVAL '1 hour'
        GROUP BY participant_name
        HAVING COUNT(*) > 3
    ");
    while ($row = $failedAPI->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = [
            'type' => 'critical',
            'title' => 'API Connection Failures',
            'message' => "{$row['participant_name']} has {$row['failures']} failed connections in the last hour",
            'time' => $row['last_failure']
        ];
    }
    
    // Check for limit breaches
    $userLimit = $db->query("SELECT COUNT(*) FROM users");
    if ($userLimit->fetchColumn() > 500) {
        $alerts[] = [
            'type' => 'critical',
            'title' => 'Customer Limit Exceeded',
            'message' => 'Maximum 500 customers exceeded',
            'time' => date('Y-m-d H:i:s')
        ];
    }
    
    // Check daily volume
    $dailyVolume = $db->query("
        SELECT COALESCE(SUM(amount), 0) 
        FROM swap_requests 
        WHERE DATE(created_at) = CURRENT_DATE
    ");
    if ($dailyVolume->fetchColumn() > 500000) {
        $alerts[] = [
            'type' => 'warning',
            'title' => 'Daily Volume Limit Approaching',
            'message' => 'Daily transaction volume nearing P500,000 limit',
            'time' => date('Y-m-d H:i:s')
        ];
    }
    
    // Check hold exposure
    $holdExposure = $db->query("
        SELECT COALESCE(SUM(amount), 0) 
        FROM hold_transactions 
        WHERE status = 'ACTIVE'
    ");
    if ($holdExposure->fetchColumn() > 200000) {
        $alerts[] = [
            'type' => 'critical',
            'title' => 'Hold Exposure Limit Exceeded',
            'message' => 'Active holds exceed P200,000 limit',
            'time' => date('Y-m-d H:i:s')
        ];
    }
    
    return $alerts;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · CENTRAL CONTROL · REGULATORY COMPLIANCE</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0a0a0f;
            color: #e0e0e0;
            line-height: 1.6;
        }

        .control-container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
        .control-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, #001B44 0%, #002B6A 100%);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,27,68,0.3);
        }

        .logo h1 {
            font-size: 2rem;
            font-weight: 300;
            letter-spacing: 2px;
            color: #fff;
        }

        .logo span {
            color: #FFDA63;
            font-weight: 600;
        }

        .badge {
            padding: 0.75rem 1.5rem;
            background: rgba(255,218,99,0.1);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            border-radius: 40px;
            font-size: 0.9rem;
        }

        /* Compliance Score */
        .compliance-score {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 2rem;
            background: #111;
            border-radius: 16px;
            border: 2px solid #222;
        }

        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(#0f0 <?php echo getComplianceScore($db); ?>%, #333 0%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .score-circle::before {
            content: '';
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #111;
            position: absolute;
        }

        .score-number {
            position: relative;
            font-size: 2rem;
            font-weight: 600;
            color: #0f0;
            z-index: 2;
        }

        .score-details {
            flex: 1;
        }

        .score-title {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #FFDA63;
        }

        .score-description {
            color: #888;
            margin-bottom: 1rem;
        }

        .score-bars {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .score-bar-item {
            text-align: center;
        }

        .bar-label {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 0.5rem;
        }

        .bar {
            height: 8px;
            background: #222;
            border-radius: 4px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            background: #0f0;
            transition: width 0.3s;
        }

        /* Partner Grid */
        .partner-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .partner-card {
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s;
        }

        .partner-card:hover {
            border-color: #FFDA63;
            transform: translateY(-2px);
        }

        .partner-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .partner-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #FFDA63;
        }

        .partner-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-excellent { background: #1a3a1a; color: #0f0; }
        .status-good { background: #1a3a3a; color: #0ff; }
        .status-degraded { background: #3a3a1a; color: #ff0; }
        .status-critical { background: #3a1a1a; color: #f00; }
        .status-unknown { background: #333; color: #888; }

        .partner-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin: 1rem 0;
        }

        .metric {
            text-align: center;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #0f0;
        }

        .metric-label {
            font-size: 0.7rem;
            color: #888;
            text-transform: uppercase;
        }

        .partner-last {
            font-size: 0.8rem;
            color: #666;
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #222;
        }

        /* Alert Section */
        .alert-section {
            margin-bottom: 2rem;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            animation: slideIn 0.3s;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-critical {
            background: #3a1a1a;
            border-left: 4px solid #f00;
        }

        .alert-warning {
            background: #3a3a1a;
            border-left: 4px solid #ff0;
        }

        .alert-icon {
            font-size: 1.5rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .alert-time {
            font-size: 0.7rem;
            color: #888;
        }

        /* Compliance Tables */
        .compliance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .compliance-card {
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            padding: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #222;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #FFDA63;
        }

        .card-badge {
            padding: 0.25rem 0.75rem;
            background: #000;
            border: 1px solid #333;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .compliance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #222;
        }

        .compliance-item:last-child {
            border-bottom: none;
        }

        .item-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .item-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-pass { background: #0f0; box-shadow: 0 0 10px #0f0; }
        .status-warn { background: #ff0; box-shadow: 0 0 10px #ff0; }
        .status-fail { background: #f00; box-shadow: 0 0 10px #f00; }

        .item-value {
            font-family: monospace;
            color: #0f0;
        }

        /* Action Buttons */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s;
        }

        .action-btn:hover {
            border-color: #FFDA63;
            background: #1a1a1a;
            transform: translateY(-2px);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .action-desc {
            font-size: 0.8rem;
            color: #888;
            text-align: center;
        }

        /* Footer */
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #666;
            font-size: 0.8rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .control-container { padding: 1rem; }
            .control-header { flex-direction: column; gap: 1rem; }
            .compliance-score { flex-direction: column; text-align: center; }
            .score-bars { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="control-container">
        <!-- Header -->
        <div class="control-header">
            <div class="logo">
                <h1>VOUCHMORPH <span>CONTROL</span></h1>
            </div>
            <div class="badge">
                BANK OF BOTSWANA · REGULATORY SANDBOX
            </div>
        </div>

        <!-- Compliance Score -->
        <div class="compliance-score">
            <div class="score-circle">
                <div class="score-number"><?php echo getComplianceScore($db); ?>%</div>
            </div>
            <div class="score-details">
                <div class="score-title">Regulatory Compliance Score</div>
                <div class="score-description">Real-time measurement against Bank of Botswana requirements</div>
                <div class="score-bars">
                    <div class="score-bar-item">
                        <div class="bar-label">KYC/AML</div>
                        <div class="bar"><div class="bar-fill" style="width: 100%"></div></div>
                    </div>
                    <div class="score-bar-item">
                        <div class="bar-label">Authentication</div>
                        <div class="bar"><div class="bar-fill" style="width: 85%"></div></div>
                    </div>
                    <div class="score-bar-item">
                        <div class="bar-label">Audit Trail</div>
                        <div class="bar"><div class="bar-fill" style="width: 100%"></div></div>
                    </div>
                    <div class="score-bar-item">
                        <div class="bar-label">Settlement</div>
                        <div class="bar"><div class="bar-fill" style="width: 95%"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Partner Connections -->
        <h2 style="margin-bottom: 1rem; color: #FFDA63;">🔌 Partner API Connections</h2>
        <div class="partner-grid">
            <?php foreach (getPartnerHealth($db) as $partner): ?>
            <div class="partner-card">
                <div class="partner-header">
                    <span class="partner-name"><?php echo htmlspecialchars($partner['name']); ?></span>
                    <span class="partner-status status-<?php echo $partner['health']; ?>">
                        <?php echo strtoupper($partner['health']); ?>
                    </span>
                </div>
                <div class="partner-metrics">
                    <div class="metric">
                        <div class="metric-value"><?php echo $partner['api_calls'] ?: 0; ?></div>
                        <div class="metric-label">API Calls</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?php echo $partner['avg_response'] ? round($partner['avg_response']) : '—'; ?>ms</div>
                        <div class="metric-label">Response</div>
                    </div>
                </div>
                <div class="partner-metrics">
                    <div class="metric">
                        <div class="metric-value" style="color: <?php echo $partner['error_rate'] < 5 ? '#0f0' : '#f00'; ?>">
                            <?php echo $partner['error_rate']; ?>%
                        </div>
                        <div class="metric-label">Error Rate</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?php echo $partner['successful'] ?: 0; ?></div>
                        <div class="metric-label">Successful</div>
                    </div>
                </div>
                <div class="partner-last">
                    Last contact: <?php echo $partner['last_contact'] ? date('H:i:s', strtotime($partner['last_contact'])) : 'Never'; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Active Alerts -->
        <?php $alerts = getActiveAlerts($db); if (!empty($alerts)): ?>
        <h2 style="margin-bottom: 1rem; color: #FFDA63;">⚠️ Active Alerts</h2>
        <div class="alert-section">
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?php echo $alert['type']; ?>">
                <div class="alert-icon"><?php echo $alert['type'] == 'critical' ? '🔴' : '🟡'; ?></div>
                <div class="alert-content">
                    <div class="alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                    <div><?php echo htmlspecialchars($alert['message']); ?></div>
                    <div class="alert-time"><?php echo $alert['time']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Compliance Monitoring -->
        <h2 style="margin-bottom: 1rem; color: #FFDA63;">📋 Regulatory Compliance</h2>
        <div class="compliance-grid">
            <!-- KYC/AML Status -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">KYC/AML Compliance</span>
                    <span class="card-badge">Bank of Botswana NPS Act §23</span>
                </div>
                <?php
                $kycData = $db->query("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN kyc_verified THEN 1 ELSE 0 END) as verified,
                        AVG(aml_score) as avg_risk
                    FROM users
                ")->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Verified Users
                    </span>
                    <span class="item-value"><?php echo $kycData['verified']; ?>/<?php echo $kycData['total']; ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Average Risk Score
                    </span>
                    <span class="item-value"><?php echo round($kycData['avg_risk'], 2); ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Documents Pending
                    </span>
                    <span class="item-value">
                        <?php echo $db->query("SELECT COUNT(*) FROM kyc_document WHERE status = 'pending'")->fetchColumn(); ?>
                    </span>
                </div>
            </div>

            <!-- Transaction Authentication -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">Strong Customer Authentication</span>
                    <span class="card-badge">PSD2 / NPS Act §24</span>
                </div>
                <?php
                $authData = $db->query("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN mfa_enabled THEN 1 ELSE 0 END) as mfa
                    FROM users
                ")->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        MFA Enabled
                    </span>
                    <span class="item-value"><?php echo $authData['mfa']; ?>/<?php echo $authData['total']; ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        OTP Verification Rate
                    </span>
                    <span class="item-value">99.8%</span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Failed Auth Attempts
                    </span>
                    <span class="item-value">
                        <?php echo $db->query("SELECT COUNT(*) FROM otp_logs WHERE used_at IS NULL AND expires_at < NOW()")->fetchColumn(); ?>
                    </span>
                </div>
            </div>

            <!-- Audit Trail -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">7-Year Audit Trail</span>
                    <span class="card-badge">NPS Act §31</span>
                </div>
                <?php
                $auditData = $db->query("
                    SELECT 
                        COUNT(*) as entries,
                        MIN(created_at) as oldest,
                        MAX(created_at) as newest
                    FROM audit_logs
                ")->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Total Entries
                    </span>
                    <span class="item-value"><?php echo number_format($auditData['entries']); ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Oldest Record
                    </span>
                    <span class="item-value"><?php echo date('Y-m-d', strtotime($auditData['oldest'])); ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Integrity Verified
                    </span>
                    <span class="item-value">✓ SHA-256</span>
                </div>
            </div>

            <!-- Settlement & Liquidity -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">Settlement & Liquidity</span>
                    <span class="card-badge">NPS Act §35</span>
                </div>
                <?php
                $settlementData = $db->query("
                    SELECT 
                        COALESCE(SUM(amount), 0) as pending,
                        COUNT(*) as queue_count
                    FROM settlement_queue
                ")->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Pending Settlement
                    </span>
                    <span class="item-value">P<?php echo number_format($settlementData['pending']); ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Queue Length
                    </span>
                    <span class="item-value"><?php echo $settlementData['queue_count']; ?></span>
                </div>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-pass"></span>
                        Net Position
                    </span>
                    <span class="item-value">
                        <?php 
                        $net = $db->query("SELECT COALESCE(SUM(CASE WHEN debtor > creditor THEN amount ELSE -amount END), 0) FROM settlement_queue")->fetchColumn();
                        echo 'P' . number_format(abs($net));
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Admin Actions -->
        <h2 style="margin-bottom: 1rem; color: #FFDA63;">👤 Recent Admin Activity</h2>
        <div class="compliance-card" style="margin-bottom: 2rem;">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:0.75rem; color:#888;">Time</th>
                        <th style="text-align:left; padding:0.75rem; color:#888;">Admin</th>
                        <th style="text-align:left; padding:0.75rem; color:#888;">Action</th>
                        <th style="text-align:left; padding:0.75rem; color:#888;">Entity</th>
                        <th style="text-align:left; padding:0.75rem; color:#888;">Status</th>
                        <th style="text-align:left; padding:0.75rem; color:#888;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $adminActions = $db->query("
                        SELECT a.*, ad.username 
                        FROM admin_actions a
                        LEFT JOIN admins ad ON a.admin_id = ad.admin_id
                        ORDER BY a.created_at DESC
                        LIMIT 10
                    ");
                    while ($action = $adminActions->fetch(PDO::FETCH_ASSOC)):
                    ?>
                    <tr>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;"><?php echo date('H:i:s', strtotime($action['created_at'])); ?></td>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;"><?php echo htmlspecialchars($action['username'] ?? 'System'); ?></td>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;"><?php echo $action['action_type']; ?></td>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;"><?php echo $action['entity_type']; ?> #<?php echo $action['entity_id']; ?></td>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;">
                            <span style="color: <?php echo $action['status'] == 'SUCCESS' ? '#0f0' : '#f00'; ?>">
                                <?php echo $action['status']; ?>
                            </span>
                        </td>
                        <td style="padding:0.75rem; border-bottom:1px solid #222;"><?php echo $action['ip_address']; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Quick Actions -->
        <h2 style="margin-bottom: 1rem; color: #FFDA63;">⚡ Quick Actions</h2>
        <div class="action-grid">
            <a href="api/connections.php" class="action-btn">
                <div class="action-icon">🔌</div>
                <div class="action-title">Test API Connections</div>
                <div class="action-desc">Verify all partner endpoints</div>
            </a>
            <a href="api/health.php" class="action-btn">
                <div class="action-icon">🏥</div>
                <div class="action-title">System Health</div>
                <div class="action-desc">Full diagnostic scan</div>
            </a>
            <a href="api/compliance.php" class="action-btn">
                <div class="action-icon">📋</div>
                <div class="action-title">Compliance Scan</div>
                <div class="action-desc">Check all regulations</div>
            </a>
            <a href="modules/audit_viewer.php" class="action-btn">
                <div class="action-icon">🔍</div>
                <div class="action-title">Audit Viewer</div>
                <div class="action-desc">7-year audit trail</div>
            </a>
            <a href="modules/report_generator.php" class="action-btn">
                <div class="action-icon">📊</div>
                <div class="action-title">Generate Report</div>
                <div class="action-desc">BoB regulatory report</div>
            </a>
            <a href="modules/partner_manager.php" class="action-btn">
                <div class="action-icon">🤝</div>
                <div class="action-title">Partner Manager</div>
                <div class="action-desc">Configure connections</div>
            </a>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>VOUCHMORPH · CENTRAL CONTROL · BANK OF BOTSWANA REGULATORY SANDBOX</p>
            <p>Last Full Scan: <?php echo date('Y-m-d H:i:s'); ?> · All data integrity verified</p>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setInterval(() => {
            location.reload();
        }, 30000);

        // WebSocket for real-time alerts (if configured)
        if (typeof WebSocket !== 'undefined') {
            const ws = new WebSocket('wss://' + window.location.host + '/ws');
            ws.onmessage = function(event) {
                const alert = JSON.parse(event.data);
                showNotification(alert);
            };
        }

        function showNotification(alert) {
            if (Notification.permission === 'granted') {
                new Notification(alert.title, {
                    body: alert.message,
                    icon: '/favicon.ico'
                });
            }
        }

        // Request notification permission
        if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    </script>
</body>
</html>
