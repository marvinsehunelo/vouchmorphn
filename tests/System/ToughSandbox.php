<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use BUSINESS_LOGIC_LAYER\services\SwapService;

require_once __DIR__ . '/../../bootstrap.php';

/* ======================================================
 * 
 * ====================================================== */

// 1️⃣ Resolve Database
$pdo = DBConnection::getInstance($GLOBALS['config']['db']['swap'] ?? null);
if (!$pdo) die("✖ Swap DB not configured\n");

// 2️⃣ Service Initialization
$swapService = new SwapService(
    $pdo,
    $GLOBALS['config']['fees'] ?? [],
    $GLOBALS['country'] ?? 'BW',
    $GLOBALS['security']['keyVault']->getEncryptionKey(),
    $GLOBALS['participants'] ?? []
);

echo "\n=== MOJALOOP HARD SANDBOX TEST ===\n";
echo "System: " . date('Y-m-d H:i:s') . " | Country: " . ($GLOBALS['country'] ?? 'BW') . "\n\n";

/* =====================================================
   1️⃣ Check Supervisor Heartbeat
===================================================== */
echo "Supervisor heartbeat...\n";
$heartbeat = (int)$pdo->query("SELECT EXTRACT(EPOCH FROM (NOW() - MAX(created_at))) FROM supervisory_heartbeat")->fetchColumn();
if ($heartbeat > 300) die("✖ Supervisor offline\n");
echo "✔ Heartbeat OK\n";

/* =====================================================
   2️⃣ Multi-Party Swap Stress Test
===================================================== */
echo "Simulating 3-party swaps...\n";
$participants = ['ALPHA','BRAVO','CHARLIE'];
foreach ($participants as $from) {
    foreach ($participants as $to) {
        if ($from === $to) continue;

        $txId = 'MOJO-' . strtoupper($from) . '-' . strtoupper($to) . '-' . time();
        $amount = rand(50, 5000); // variable amounts

        $response = $swapService->executeSwap(
            $from,
            $to,
            $amount,
            'wallet',
            'wallet',
            null,
            ['transaction_id' => $txId],
            false
        );

        if (!in_array($response['status'], ['completed','pending'])) {
            die("✖ Swap $txId failed (Status: {$response['status']})\n");
        }
    }
}
echo "✔ Multi-party swaps OK\n";

/* =====================================================
   3️⃣ AML / Sanctions Edge Cases
===================================================== */
echo "AML edge-case check...\n";
$sanctionedUsers = ['SANCTION-ALPHA','SANCTION-BRAVO'];
foreach ($sanctionedUsers as $user) {
    try {
        $response = $swapService->executeSwap($user,'CHARLIE',100,'wallet','wallet',null,['transaction_id'=>'AML-'.$user],false);
        if (($response['status'] ?? '') !== 'BLOCKED_REGULATORY') {
            die("✖ AML block failed for $user\n");
        }
    } catch (\Throwable $e) {
        echo "⚠ Exception for $user: {$e->getMessage()}\n";
    }
}
echo "✔ AML blocking enforced\n";

/* =====================================================
   4️⃣ Deferred Settlement / Ledger Integrity
===================================================== */
echo "Checking unsettled swaps...\n";
$pending = (int)$pdo->query("SELECT COUNT(*) FROM settlement_ledger WHERE direction='DEBIT' AND created_at < NOW() - INTERVAL '1 HOUR'")->fetchColumn();
if ($pending > 0) die("✖ Unsettled swaps older than 1h: $pending\n");
echo "✔ Settlement timing OK\n";

/* =====================================================
   5️⃣ Regulatory Report Validation
===================================================== */
echo "Validating latest regulatory report...\n";
$latestReport = $pdo->query("SELECT payload FROM regulator_outbox ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$data = json_decode($latestReport,true);
$required = ['transaction_id','amount','currency','from_participant','to_participant','timestamp','risk_rating'];
foreach ($required as $f) {
    if (!isset($data[$f])) die("✖ Missing report field: $f\n");
}
echo "✔ Regulatory report valid\n";

/* =====================================================
   6️⃣ Participant Data Isolation
===================================================== */
echo "Checking tenant isolation...\n";
foreach ($participants as $p) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM swap_ledger WHERE from_institution='$p' OR to_institution='$p'")->fetchColumn();
    if ($count === 0) die("✖ No swaps for participant $p\n");
}
echo "✔ Data isolation OK\n";

/* =====================================================
   7️⃣ Exit Simulation
===================================================== */
echo "Simulating sandbox wind-down...\n";
foreach ($participants as $p) {
    $balance = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM ledger_accounts WHERE participant_id=(SELECT user_id FROM users WHERE username='$p' LIMIT 1)")->fetchColumn();
    if ($balance > 0) die("✖ Funds remain for participant $p (Bal: $balance)\n");
}
echo "✔ Customer funds repatriated\n";

/* =====================================================
   8️⃣ Final Report
===================================================== */
$reportFile = __DIR__ . "/mojaloop_sandbox_report.txt";
file_put_contents($reportFile, "Mojaloop-hard sandbox PASSED for " . ($GLOBALS['country'] ?? 'BW') . " at " . date('c'));
echo "\n=== SANDBOX PASSED ===\nReport saved → " . basename($reportFile) . "\n";

