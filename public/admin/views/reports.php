<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// Reports view - accessible to REGULATOR, COMPLIANCE, AUDITOR, GLOBAL_OWNER
?>

<div class="reports-grid">
    <!-- Regulatory Reports -->
    <div class="report-card" onclick="openReport('reports/regulatory_report.php')">
        <div class="report-icon">📊</div>
        <div class="report-title">REGULATORY REPORT</div>
        <div class="report-desc">Bank of Botswana formatted report</div>
    </div>

    <div class="report-card" onclick="openReport('reports/settlement_report.php?type=daily')">
        <div class="report-icon">💰</div>
        <div class="report-title">DAILY SETTLEMENT</div>
        <div class="report-desc">End-of-day net settlement report</div>
    </div>

    <div class="report-card" onclick="openReport('reports/settlement_report.php?type=weekly')">
        <div class="report-icon">📈</div>
        <div class="report-title">WEEKLY NETTING</div>
        <div class="report-desc">Weekly net position summary</div>
    </div>

    <div class="report-card" onclick="openReport('reports/audit_report.php')">
        <div class="report-icon">🔍</div>
        <div class="report-title">AUDIT TRAIL</div>
        <div class="report-desc">Full transaction audit log</div>
    </div>

    <div class="report-card" onclick="openReport('reports/compliance_report.php')">
        <div class="report-icon">⚖️</div>
        <div class="report-title">COMPLIANCE REPORT</div>
        <div class="report-desc">AML/KYC monitoring report</div>
    </div>

    <div class="report-card" onclick="openReport('reports/fee_report.php')">
        <div class="report-icon">💸</div>
        <div class="report-title">FEE COLLECTIONS</div>
        <div class="report-desc">Fee breakdown by participant</div>
    </div>
</div>

<!-- Report Modal -->
<div id="reportModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="document.getElementById('reportModal').style.display='none'">&times;</span>
        <div id="reportContent"></div>
    </div>
</div>
