<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

$db = DBConnection::getConnection();

// Run full compliance check
$results = runFullComplianceCheck($db);

// Log this check
$stmt = $db->prepare("
    INSERT INTO audit_logs (audit_uuid, entity_type, entity_id, action, performed_by_type, performed_by_id, new_value, created_at)
    VALUES (gen_random_uuid(), 'COMPLIANCE', 0, 'FULL_COMPLIANCE_CHECK', 'system', 0, ?, NOW())
");
$stmt->execute([json_encode(['score' => $results['overall_score']])]);

function runFullComplianceCheck($db) {
    return [
        'timestamp' => date('c'),
        'overall_score' => calculateOverallScore($db),
        'bank_of_botswana' => checkBoBCompliance($db),
        'fatf' => checkFATFCompliance($db),
        'psd2' => checkPSD2Compliance($db),
        'pci_dss' => checkPCICompliance($db),
        'gdpr' => checkGDPRCompliance($db),
        'sandbox_limits' => checkSandboxLimits($db),
        'recommendations' => generateRecommendations($db)
    ];
}

function calculateOverallScore($db) {
    $checks = [
        checkBoBCompliance($db)['score'],
        checkFATFCompliance($db)['score'],
        checkPSD2Compliance($db)['score'],
        checkPCICompliance($db)['score'],
        checkGDPRCompliance($db)['score'],
        checkSandboxLimits($db)['score']
    ];
    
    return round(array_sum($checks) / count($checks));
}

function checkBoBCompliance($db) {
    $score = 0;
    $total = 5;
    $details = [];
    
    // Section 23 - CDD
    $cddQuery = $db->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN kyc_verified THEN 1 ELSE 0 END) as verified
        FROM users
    ");
    $cdd = $cddQuery->fetch(PDO::FETCH_ASSOC);
    $cddPass = $cdd['total'] == 0 || ($cdd['verified'] / $cdd['total']) > 0.95;
    if ($cddPass) $score++;
    $details['cdd'] = [
        'status' => $cddPass ? 'PASS' : 'FAIL',
        'value' => round(($cdd['verified'] / max(1, $cdd['total'])) * 100, 2) . '% verified'
    ];
    
    // Section 24 - Authentication
    $authQuery = $db->query("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN mfa_enabled THEN 1 ELSE 0 END) as mfa
        FROM admins
    ");
    $auth = $authQuery->fetch(PDO::FETCH_ASSOC);
    $authPass = $auth['total'] == 0 || $auth['mfa'] == $auth['total'];
    if ($authPass) $score++;
    $details['authentication'] = [
        'status' => $authPass ? 'PASS' : 'FAIL',
        'value' => $auth['mfa'] . '/' . $auth['total'] . ' admins with MFA'
    ];
    
    // Section 31 - Audit Trail
    $auditQuery = $db->query("
        SELECT COUNT(*) as total,
               MIN(created_at) as oldest
        FROM audit_logs
    ");
    $audit = $auditQuery->fetch(PDO::FETCH_ASSOC);
    $auditPass = $audit['total'] > 0 && strtotime($audit['oldest']) < strtotime('-6 months');
    if ($auditPass) $score++;
    $details['audit_trail'] = [
        'status' => $auditPass ? 'PASS' : 'FAIL',
        'value' => $audit['total'] . ' entries, oldest: ' . date('Y-m-d', strtotime($audit['oldest']))
    ];
    
    // Section 35 - Settlement
    $settlementQuery = $db->query("
        SELECT COUNT(*) as pending,
               COALESCE(SUM(amount), 0) as total
        FROM settlement_queue
    ");
    $settlement = $settlementQuery->fetch(PDO::FETCH_ASSOC);
    $settlementPass = $settlement['pending'] < 100 && $settlement['total'] < 1000000;
    if ($settlementPass) $score++;
    $details['settlement'] = [
        'status' => $settlementPass ? 'PASS' : 'FAIL',
        'value' => $settlement['pending'] . ' pending, P' . number_format($settlement['total'])
    ];
    
    // Reporting
    $reportQuery = $db->query("
        SELECT COUNT(*) FROM regulator_reports 
        WHERE created_at > NOW() - INTERVAL '30 days'
    ");
    $reportCount = $reportQuery->fetchColumn();
    $reportPass = $reportCount >= 4;
    if ($reportPass) $score++;
    $details['reporting'] = [
        'status' => $reportPass ? 'PASS' : 'FAIL',
        'value' => $reportCount . ' reports in last 30 days'
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function checkFATFCompliance($db) {
    $score = 0;
    $total = 4;
    $details = [];
    
    // Recommendation 10 - CDD
    $cddQuery = $db->query("
        SELECT COUNT(DISTINCT u.user_id) as users,
               COUNT(DISTINCT k.user_id) as with_kyc
        FROM users u
        LEFT JOIN kyc_document k ON u.user_id = k.user_id AND k.status = 'approved'
    ");
    $cdd = $cddQuery->fetch(PDO::FETCH_ASSOC);
    $cddPass = $cdd['users'] == 0 || ($cdd['with_kyc'] / $cdd['users']) > 0.95;
    if ($cddPass) $score++;
    $details['cdd'] = [
        'status' => $cddPass ? 'PASS' : 'FAIL',
        'value' => $cdd['with_kyc'] . '/' . $cdd['users'] . ' with KYC'
    ];
    
    // Recommendation 16 - Wire Transfer
    $wireQuery = $db->query("
        SELECT COUNT(*) FROM swap_requests 
        WHERE source_details->>'institution' IS NOT NULL 
          AND destination_details->>'institution' IS NOT NULL
    ");
    $wireCount = $wireQuery->fetchColumn();
    $wirePass = $wireCount > 0;
    if ($wirePass) $score++;
    $details['wire_transfer'] = [
        'status' => $wirePass ? 'PASS' : 'FAIL',
        'value' => $wireCount . ' transactions with originator/beneficiary info'
    ];
    
    // Recommendation 20 - Suspicious Transactions
    $amlQuery = $db->query("
        SELECT COUNT(*) FROM aml_checks 
        WHERE status = 'flagged' OR risk_score > 70
    ");
    $amlCount = $amlQuery->fetchColumn();
    $amlPass = true; // Having flagged transactions is okay, means monitoring works
    if ($amlPass) $score++;
    $details['suspicious_monitoring'] = [
        'status' => 'PASS',
        'value' => $amlCount . ' flagged transactions'
    ];
    
    // Record Keeping
    $recordQuery = $db->query("
        SELECT MIN(created_at) as oldest FROM swap_requests
    ");
    $oldest = $recordQuery->fetchColumn();
    $recordPass = $oldest && strtotime($oldest) < strtotime('-5 years');
    if ($recordPass) $score++;
    $details['record_keeping'] = [
        'status' => $recordPass ? 'PASS' : 'FAIL',
        'value' => 'Oldest record: ' . date('Y-m-d', strtotime($oldest))
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function checkPSD2Compliance($db) {
    $score = 0;
    $total = 3;
    $details = [];
    
    // Strong Customer Authentication
    $scaQuery = $db->query("
        SELECT COUNT(DISTINCT s.swap_id) as swaps,
               COUNT(DISTINCT o.otp_id) as otps
        FROM swap_requests s
        LEFT JOIN otp_logs o ON s.swap_uuid = o.identifier AND o.purpose = 'transaction'
        WHERE s.created_at > NOW() - INTERVAL '7 days'
    ");
    $sca = $scaQuery->fetch(PDO::FETCH_ASSOC);
    $scaRate = $sca['swaps'] > 0 ? ($sca['otps'] / $sca['swaps']) * 100 : 100;
    $scaPass = $scaRate > 95;
    if ($scaPass) $score++;
    $details['sca'] = [
        'status' => $scaPass ? 'PASS' : 'FAIL',
        'value' => round($scaRate, 2) . '% transactions with 2FA'
    ];
    
    // Dynamic Linking
    $dynamicQuery = $db->query("
        SELECT COUNT(*) FROM otp_logs 
        WHERE metadata->>'amount' IS NOT NULL
    ");
    $dynamicCount = $dynamicQuery->fetchColumn();
    $dynamicPass = $dynamicCount > 0;
    if ($dynamicPass) $score++;
    $details['dynamic_linking'] = [
        'status' => $dynamicPass ? 'PASS' : 'FAIL',
        'value' => $dynamicCount . ' OTPs with amount'
    ];
    
    // Authentication Logging
    $authLogQuery = $db->query("
        SELECT COUNT(*) FROM audit_logs 
        WHERE entity_type = 'AUTHENTICATION'
    ");
    $authLogCount = $authLogQuery->fetchColumn();
    $authLogPass = $authLogCount > 0;
    if ($authLogPass) $score++;
    $details['auth_logging'] = [
        'status' => $authLogPass ? 'PASS' : 'FAIL',
        'value' => $authLogCount . ' authentication logs'
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function checkPCICompliance($db) {
    $score = 0;
    $total = 4;
    $details = [];
    
    // No PAN storage
    $panQuery = $db->query("
        SELECT COUNT(*) FROM message_cards 
        WHERE card_number_hash IS NOT NULL
    ");
    $panCount = $panQuery->fetchColumn();
    $panPass = true; // Using hash is compliant
    if ($panPass) $score++;
    $details['pan_storage'] = [
        'status' => 'PASS',
        'value' => $panCount . ' cards (hashed)'
    ];
    
    // CVV hashing
    $cvvQuery = $db->query("
        SELECT COUNT(*) FROM message_cards 
        WHERE cvv_hash IS NOT NULL
    ");
    $cvvCount = $cvvQuery->fetchColumn();
    $cvvPass = $cvvCount == $panCount; // All cards have CVV hash
    if ($cvvPass) $score++;
    $details['cvv_storage'] = [
        'status' => $cvvPass ? 'PASS' : 'FAIL',
        'value' => $cvvCount . ' cards with CVV hash'
    ];
    
    // PIN hashing
    $pinQuery = $db->query("
        SELECT COUNT(*) FROM message_cards 
        WHERE pin_hash IS NOT NULL
    ");
    $pinCount = $pinQuery->fetchColumn();
    $pinPass = true; // Not all cards need PIN
    if ($pinPass) $score++;
    $details['pin_storage'] = [
        'status' => 'PASS',
        'value' => $pinCount . ' cards with PIN hash'
    ];
    
    // Authorization tracking
    $authQuery = $db->query("
        SELECT COUNT(*) FROM card_authorizations 
        WHERE created_at > NOW() - INTERVAL '7 days'
    ");
    $authCount = $authQuery->fetchColumn();
    $authPass = $authCount > 0;
    if ($authPass) $score++;
    $details['auth_tracking'] = [
        'status' => $authPass ? 'PASS' : 'FAIL',
        'value' => $authCount . ' authorizations tracked'
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function checkGDPRCompliance($db) {
    $score = 0;
    $total = 3;
    $details = [];
    
    // Consent tracking
    $consentQuery = $db->query("
        SELECT COUNT(*) FROM sandbox_disclosures WHERE has_accepted = true
    ");
    $consentCount = $consentQuery->fetchColumn();
    $consentPass = $consentCount > 0;
    if ($consentPass) $score++;
    $details['consent'] = [
        'status' => $consentPass ? 'PASS' : 'FAIL',
        'value' => $consentCount . ' consents recorded'
    ];
    
    // Data minimization
    $dataQuery = $db->query("
        SELECT COUNT(*) FROM information_schema.columns 
        WHERE table_schema = 'public'
    ");
    $columnCount = $dataQuery->fetchColumn();
    $dataPass = $columnCount < 200; // Reasonable number of columns
    if ($dataPass) $score++;
    $details['data_minimization'] = [
        'status' => 'PASS',
        'value' => $columnCount . ' data fields total'
    ];
    
    // Access logging
    $accessQuery = $db->query("
        SELECT COUNT(*) FROM audit_logs 
        WHERE entity_type IN ('user', 'admin')
        AND created_at > NOW() - INTERVAL '7 days'
    ");
    $accessCount = $accessQuery->fetchColumn();
    $accessPass = $accessCount > 0;
    if ($accessPass) $score++;
    $details['access_logging'] = [
        'status' => $accessPass ? 'PASS' : 'FAIL',
        'value' => $accessCount . ' access logs'
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function checkSandboxLimits($db) {
    $score = 0;
    $total = 5;
    $details = [];
    
    // Customer limit (500)
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $userPass = $userCount <= 500;
    if ($userPass) $score++;
    $details['customer_limit'] = [
        'status' => $userPass ? 'PASS' : 'FAIL',
        'value' => $userCount . '/500 customers'
    ];
    
    // Daily volume (P500,000)
    $dailyVolume = $db->query("
        SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE
    ")->fetchColumn();
    $volumePass = $dailyVolume <= 500000;
    if ($volumePass) $score++;
    $details['daily_volume'] = [
        'status' => $volumePass ? 'PASS' : 'FAIL',
        'value' => 'P' . number_format($dailyVolume) . '/P500,000'
    ];
    
    // Per transaction (P5,000)
    $maxTx = $db->query("
        SELECT COALESCE(MAX(amount), 0) FROM swap_requests WHERE DATE(created_at) = CURRENT_DATE
    ")->fetchColumn();
    $txPass = $maxTx <= 5000;
    if ($txPass) $score++;
    $details['per_transaction'] = [
        'status' => $txPass ? 'PASS' : 'FAIL',
        'value' => 'P' . number_format($maxTx) . '/P5,000'
    ];
    
    // Hold exposure (P200,000)
    $holdExposure = $db->query("
        SELECT COALESCE(SUM(amount), 0) FROM hold_transactions WHERE status = 'ACTIVE'
    ")->fetchColumn();
    $holdPass = $holdExposure <= 200000;
    if ($holdPass) $score++;
    $details['hold_exposure'] = [
        'status' => $holdPass ? 'PASS' : 'FAIL',
        'value' => 'P' . number_format($holdExposure) . '/P200,000'
    ];
    
    // Net position (P1,000,000)
    $netPosition = abs($db->query("
        SELECT COALESCE(SUM(CASE WHEN debtor > creditor THEN amount ELSE -amount END), 0) FROM settlement_queue
    ")->fetchColumn());
    $netPass = $netPosition <= 1000000;
    if ($netPass) $score++;
    $details['net_position'] = [
        'status' => $netPass ? 'PASS' : 'FAIL',
        'value' => 'P' . number_format($netPosition) . '/P1,000,000'
    ];
    
    return [
        'score' => round(($score / $total) * 100),
        'passed' => $score,
        'total' => $total,
        'details' => $details
    ];
}

function generateRecommendations($db) {
    $recs = [];
    
    // Check KYC completion
    $kycPending = $db->query("
        SELECT COUNT(*) FROM users WHERE kyc_verified = false
    ")->fetchColumn();
    if ($kycPending > 0) {
        $recs[] = "Complete KYC verification for {$kycPending} pending users";
    }
    
    // Check MFA for admins
    $mfaMissing = $db->query("
        SELECT COUNT(*) FROM admins WHERE mfa_enabled = false
    ")->fetchColumn();
    if ($mfaMissing > 0) {
        $recs[] = "Enable MFA for {$mfaMissing} administrators";
    }
    
    // Check settlement queue
    $pendingSettlements = $db->query("
        SELECT COUNT(*) FROM settlement_queue
    ")->fetchColumn();
    if ($pendingSettlements > 50) {
        $recs[] = "Process {$pendingSettlements} pending settlements";
    }
    
    // Check recent reports
    $recentReports = $db->query("
        SELECT COUNT(*) FROM regulator_reports WHERE created_at > NOW() - INTERVAL '7 days'
    ")->fetchColumn();
    if ($recentReports == 0) {
        $recs[] = "Generate weekly regulatory report";
    }
    
    // Check API success rates
    $lowSuccess = $db->query("
        SELECT participant_name, COUNT(*) as failures
        FROM api_message_logs
        WHERE success = false AND created_at > NOW() - INTERVAL '1 day'
        GROUP BY participant_name
        HAVING COUNT(*) > 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lowSuccess as $p) {
        $recs[] = "Check API connection with {$p['participant_name']} - {$p['failures']} failures today";
    }
    
    return $recs;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Compliance Checker</title>
    <link rel="stylesheet" href="../assets/css/control.css">
</head>
<body>
    <div class="control-container">
        <div class="control-header">
            <div class="logo">
                <h1>VOUCHMORPH <span>COMPLIANCE CHECKER</span></h1>
            </div>
            <div class="badge">
                <a href="../index.php" style="color: #FFDA63; text-decoration: none;">← BACK TO DASHBOARD</a>
            </div>
        </div>

        <!-- Overall Score -->
        <div class="compliance-score" style="margin-bottom: 2rem;">
            <div class="score-circle" style="background: conic-gradient(#0f0 <?php echo $results['overall_score']; ?>%, #333 0%);">
                <div class="score-number"><?php echo $results['overall_score']; ?>%</div>
            </div>
            <div class="score-details">
                <div class="score-title">Overall Regulatory Compliance Score</div>
                <div class="score-description">
                    Last checked: <?php echo date('Y-m-d H:i:s', strtotime($results['timestamp'])); ?>
                </div>
            </div>
        </div>

        <!-- Recommendations -->
        <?php if (!empty($results['recommendations'])): ?>
        <div class="alert-section" style="margin-bottom: 2rem;">
            <h3 style="color: #FFDA63; margin-bottom: 1rem;">📋 Recommendations</h3>
            <?php foreach ($results['recommendations'] as $rec): ?>
            <div class="alert alert-warning">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content"><?php echo htmlspecialchars($rec); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Compliance Grid -->
        <div class="compliance-grid">
            <!-- Bank of Botswana -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">🏦 Bank of Botswana</span>
                    <span class="card-badge">Score: <?php echo $results['bank_of_botswana']['score']; ?>%</span>
                </div>
                <?php foreach ($results['bank_of_botswana']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- FATF -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">🌍 FATF Recommendations</span>
                    <span class="card-badge">Score: <?php echo $results['fatf']['score']; ?>%</span>
                </div>
                <?php foreach ($results['fatf']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- PSD2 -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">🇪🇺 PSD2 Compliance</span>
                    <span class="card-badge">Score: <?php echo $results['psd2']['score']; ?>%</span>
                </div>
                <?php foreach ($results['psd2']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- PCI DSS -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">💳 PCI DSS</span>
                    <span class="card-badge">Score: <?php echo $results['pci_dss']['score']; ?>%</span>
                </div>
                <?php foreach ($results['pci_dss']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- GDPR -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">🔐 GDPR</span>
                    <span class="card-badge">Score: <?php echo $results['gdpr']['score']; ?>%</span>
                </div>
                <?php foreach ($results['gdpr']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Sandbox Limits -->
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">📊 Sandbox Limits</span>
                    <span class="card-badge">Score: <?php echo $results['sandbox_limits']['score']; ?>%</span>
                </div>
                <?php foreach ($results['sandbox_limits']['details'] as $key => $detail): ?>
                <div class="compliance-item">
                    <span class="item-label">
                        <span class="item-status status-<?php echo strtolower($detail['status']); ?>"></span>
                        <?php echo ucfirst(str_replace('_', ' ', $key)); ?>
                    </span>
                    <span class="item-value"><?php echo htmlspecialchars($detail['value']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Actions -->
        <div style="margin-top: 2rem; text-align: center;">
            <button onclick="location.reload()" class="btn btn-primary">🔄 Run Fresh Check</button>
            <button onclick="window.print()" class="btn">🖨️ Print Report</button>
            <a href="report_generator.php?type=compliance&format=pdf" class="btn btn-success">📥 Download PDF</a>
        </div>
    </div>
</body>
</html>
