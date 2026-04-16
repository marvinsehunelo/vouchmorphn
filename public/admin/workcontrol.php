<?php
declare(strict_types=1);

namespace CONTROL;

// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Start output buffering to catch any errors
ob_start();

try {
    require_once __DIR__ . '/../../src/bootstrap.php';
    require_once __DIR__ . '/../../src/Core/Database/config/DBConnection.php';
} catch (Throwable $e) {
    // If bootstrap fails, show error
    echo "<h1>Bootstrap Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use PDO;
use Throwable;

// Initialize database with error handling
try {
    $db = DBConnection::getConnection();
    $dbConnected = true;
} catch (Throwable $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

// ============================================================================
// HELPER FUNCTIONS WITH ERROR HANDLING
// ============================================================================
function getComplianceScore($db) {
    if (!$db) return 0;
    try {
        $score = 0;
        $total = 8;
        
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
        if ($reportQuery->fetchColumn() >= 4) $score++;
        
        return round(($score / $total) * 100);
    } catch (Throwable $e) {
        return 0;
    }
}

function getPartnerHealth($db) {
    if (!$db) return [];
    try {
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
            LIMIT 20
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
    } catch (Throwable $e) {
        return [];
    }
}

function getActiveAlerts($db) {
    if (!$db) return [];
    try {
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
                'message' => "{$row['participant_name']} has {$row['failures']} failed connections",
                'time' => $row['last_failure']
            ];
        }
        
        return $alerts;
    } catch (Throwable $e) {
        return [];
    }
}

// Get data safely
$complianceScore = $dbConnected ? getComplianceScore($db) : 0;
$partners = $dbConnected ? getPartnerHealth($db) : [];
$alerts = $dbConnected ? getActiveAlerts($db) : [];

// Get basic counts
try {
    $userCount = $dbConnected ? $db->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    $swapCount = $dbConnected ? $db->query("SELECT COUNT(*) FROM swap_requests")->fetchColumn() : 0;
    $participantCount = $dbConnected ? $db->query("SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE'")->fetchColumn() : 0;
} catch (Throwable $e) {
    $userCount = 0;
    $swapCount = 0;
    $participantCount = 0;
}

// Clear output buffer and send HTML
ob_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · CONTROL DASHBOARD</title>
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
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #001B44 0%, #002B6A 100%);
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .logo h1 {
            font-size: 2rem;
            font-weight: 300;
            color: #fff;
        }

        .logo span {
            color: #FFDA63;
            font-weight: 600;
        }

        .badge {
            background: rgba(255,218,99,0.1);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            padding: 0.75rem 1.5rem;
            border-radius: 40px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            padding: 1.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 200;
            color: #0f0;
            font-family: monospace;
        }

        .stat-label {
            color: #888;
            font-size: 0.9rem;
            text-transform: uppercase;
            margin-top: 0.5rem;
        }

        /* Compliance Score */
        .compliance-card {
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(#0f0 <?php echo $complianceScore; ?>%, #333 0%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
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
            color: #0f0;
            z-index: 2;
        }

        .score-details {
            flex: 1;
        }

        .score-title {
            font-size: 1.5rem;
            color: #FFDA63;
            margin-bottom: 0.5rem;
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
        }

        .partner-name {
            font-size: 1.2rem;
            color: #FFDA63;
            margin-bottom: 0.5rem;
        }

        .partner-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }

        .status-excellent { background: #1a3a1a; color: #0f0; }
        .status-good { background: #1a3a3a; color: #0ff; }
        .status-degraded { background: #3a3a1a; color: #ff0; }
        .status-critical { background: #3a1a1a; color: #f00; }

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
            color: #0f0;
        }

        .metric-label {
            font-size: 0.7rem;
            color: #888;
        }

        .partner-last {
            font-size: 0.8rem;
            color: #666;
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #222;
        }

        /* Alerts */
        .alert {
            background: #3a1a1a;
            border-left: 4px solid #f00;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-warning {
            background: #3a3a1a;
            border-left-color: #ff0;
        }

        /* Action Buttons */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }

        .action-btn {
            background: #111;
            border: 2px solid #222;
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s;
            display: block;
        }

        .action-btn:hover {
            border-color: #FFDA63;
            transform: translateY(-2px);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        /* Footer */
        .footer {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #666;
        }

        /* Error box */
        .error-box {
            background: #3a1a1a;
            border: 2px solid #f00;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <h1>VOUCHMORPH <span>CONTROL</span></h1>
            </div>
            <div class="badge">
                BANK OF BOTSWANA · SANDBOX
            </div>
        </div>

        <?php if (!$dbConnected): ?>
        <!-- Database Error -->
        <div class="error-box">
            <h2 style="color: #f00; margin-bottom: 1rem;">⚠️ Database Connection Error</h2>
            <p><?php echo htmlspecialchars($dbError ?? 'Could not connect to database'); ?></p>
            <p style="margin-top: 1rem; color: #888;">Showing limited dashboard. Please check your database configuration.</p>
        </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($userCount); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($swapCount); ?></div>
                <div class="stat-label">Total Swaps</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $participantCount; ?></div>
                <div class="stat-label">Active Partners</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $complianceScore; ?>%</div>
                <div class="stat-label">Compliance Score</div>
            </div>
        </div>

        <!-- Compliance Score Card -->
        <div class="compliance-card">
            <div class="score-circle">
                <div class="score-number"><?php echo $complianceScore; ?>%</div>
            </div>
            <div class="score-details">
                <div class="score-title">Regulatory Compliance</div>
                <p style="color: #888;">Real-time compliance with Bank of Botswana requirements</p>
            </div>
        </div>

        <!-- Active Alerts -->
        <?php if (!empty($alerts)): ?>
        <h2 style="color: #FFDA63; margin: 2rem 0 1rem;">⚠️ Active Alerts</h2>
        <?php foreach ($alerts as $alert): ?>
        <div class="alert <?php echo $alert['type'] == 'warning' ? 'alert-warning' : ''; ?>">
            <div style="font-size: 1.5rem;"><?php echo $alert['type'] == 'critical' ? '🔴' : '🟡'; ?></div>
            <div>
                <strong><?php echo htmlspecialchars($alert['title']); ?></strong><br>
                <?php echo htmlspecialchars($alert['message']); ?>
                <div style="font-size: 0.8rem; color: #888; margin-top: 0.25rem;">
                    <?php echo date('H:i:s', strtotime($alert['time'])); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Partner Connections -->
        <h2 style="color: #FFDA63; margin: 2rem 0 1rem;">🔌 Partner Connections</h2>
        <div class="partner-grid">
            <?php if (empty($partners)): ?>
            <div class="partner-card" style="grid-column: 1/-1; text-align: center;">
                <p>No partner data available</p>
            </div>
            <?php else: ?>
                <?php foreach ($partners as $partner): ?>
                <div class="partner-card">
                    <div class="partner-name"><?php echo htmlspecialchars($partner['name']); ?></div>
                    <div class="partner-status status-<?php echo $partner['health']; ?>">
                        <?php echo strtoupper($partner['health']); ?>
                    </div>
                    <div class="partner-metrics">
                        <div class="metric">
                            <div class="metric-value"><?php echo $partner['api_calls'] ?: 0; ?></div>
                            <div class="metric-label">API Calls</div>
                        </div>
                        <div class="metric">
                            <div class="metric-value"><?php echo round($partner['avg_response'] ?: 0); ?>ms</div>
                            <div class="metric-label">Response</div>
                        </div>
                    </div>
                    <div class="partner-last">
                        Last: <?php echo $partner['last_contact'] ? date('H:i:s', strtotime($partner['last_contact'])) : 'Never'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <h2 style="color: #FFDA63; margin: 2rem 0 1rem;">⚡ Quick Actions</h2>
        <div class="action-grid">
            <a href="api/connections.php" class="action-btn" onclick="alert('API Connections page coming soon'); return false;">
                <div class="action-icon">🔌</div>
                <div>Test Connections</div>
            </a>
            <a href="api/health.php" class="action-btn" onclick="alert('Health check page coming soon'); return false;">
                <div class="action-icon">🏥</div>
                <div>System Health</div>
            </a>
            <a href="api/compliance.php" class="action-btn" onclick="alert('Compliance scan coming soon'); return false;">
                <div class="action-icon">📋</div>
                <div>Compliance Scan</div>
            </a>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>VOUCHMORPH · CONTROL DASHBOARD · BANK OF BOTSWANA REGULATORY SANDBOX</p>
            <p style="margin-top: 0.5rem;">Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
            <?php if (!$dbConnected): ?>
            <p style="color: #f00; margin-top: 1rem;">⚠️ Database Disconnected - Limited Functionality</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Simple auto-refresh (30 seconds)
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
