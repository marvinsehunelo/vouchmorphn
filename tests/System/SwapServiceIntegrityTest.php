<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

use BUSINESS_LOGIC_LAYER\services\SwapService;

require_once __DIR__ . '/../../bootstrap.php';

echo "\n==============================\n";
echo " VOUCHMORPHN SWITCH CERTIFICATION\n";
echo " Bank of Botswana Sandbox Mode\n";
echo "==============================\n\n";

function result($name, $ok, $data = null){
    echo ($ok ? "✔ PASS " : "✖ FAIL ") . $name . "\n";
    if(!$ok && $data) print_r($data);
}

/* -------------------------------------------------------
   1️⃣ NORMAL SWAP
-------------------------------------------------------*/
echo "\n1. NORMAL SWAP\n";

$r = $swapService->executeSwap(
    'ORANGE',
    'ACCESS',
    50.00,
    'wallet',
    'bank',
    null,
    [
        'transaction_id' => 'TXN-1',
        'sender_phone' => '26770000001',
        'recipient_account' => 'ACC1001',
        'currency' => 'BWP'
    ],
    false
);

result("Wallet → Bank success", $r['status'] === 'success', $r);


/* -------------------------------------------------------
   2️⃣ AML SANCTIONS BLOCK
-------------------------------------------------------*/
echo "\n2. AML TEST\n";

$r = $swapService->executeSwap(
    'ORANGE',
    'ACCESS',
    20,
    'wallet',
    'bank',
    null,
    ['transaction_id' => 'SANCTION-TEST-001'],
    false
);

result("Sanction blocked", $r['status'] === 'BLOCKED_REGULATORY', $r);


/* -------------------------------------------------------
   3️⃣ INSUFFICIENT LIQUIDITY
-------------------------------------------------------*/
echo "\n3. LIQUIDITY TEST\n";

$r = $swapService->executeSwap(
    'ORANGE',
    'ACCESS',
    999999999,
    'wallet',
    'bank',
    null,
    ['transaction_id' => 'TXN-LIQ'],
    false
);

result("Liquidity rejection", $r['iso_error'] === 'LIQUIDITY', $r);


/* -------------------------------------------------------
   4️⃣ IDEMPOTENCY
-------------------------------------------------------*/
echo "\n4. IDEMPOTENCY TEST\n";

$ref = 'IDEMPOTENT-123';

$r1 = $swapService->executeSwap(
    'ORANGE','ACCESS',10,'wallet','bank',null,
    ['swap_reference'=>$ref],false
);

$r2 = $swapService->executeSwap(
    'ORANGE','ACCESS',10,'wallet','bank',null,
    ['swap_reference'=>$ref],false
);

result("Duplicate not double processed",
    $r2['msg_id'] === $r1['msg_id'], [$r1,$r2]);


/* -------------------------------------------------------
   5️⃣ REVERSAL ON FAILURE
-------------------------------------------------------*/
echo "\n5. REVERSAL TEST\n";

/* simulate fulfilment failure */
$GLOBALS['FORCE_FAIL'] = true;

$r = $swapService->executeSwap(
    'ORANGE','ACCESS',30,'wallet','bank',null,
    ['transaction_id'=>'TXN-REV'],false
);

unset($GLOBALS['FORCE_FAIL']);

result("Reversal triggered", isset($r['reversal']), $r);


/* -------------------------------------------------------
   6️⃣ REGULATORY REPORT GENERATED
-------------------------------------------------------*/
echo "\n6. REGULATOR REPORT\n";

$q = $swapDB->query("SELECT count(*) FROM regulator_outbox");
$count = $q->fetchColumn();

result("Reported to regulator", $count > 0);


/* -------------------------------------------------------
   7️⃣ FLOAT ACCOUNTING
-------------------------------------------------------*/
echo "\n7. NET SETTLEMENT\n";

$q = $swapDB->query("SELECT count(*) FROM net_positions");
$count = $q->fetchColumn();

result("Net positions updated", $count > 0);


/* -------------------------------------------------------
   8️⃣ HEARTBEAT SUPERVISOR
-------------------------------------------------------*/
echo "\n8. SUPERVISOR\n";

$q = $swapDB->query("SELECT NOW() - MAX(created_at) < interval '2 minutes' FROM supervisory_heartbeat");
$alive = $q->fetchColumn();

result("Supervisor alive", $alive);


/* -------------------------------------------------------
   FINAL RESULT
-------------------------------------------------------*/
echo "\n==============================\n";
echo " CERTIFICATION COMPLETE\n";
echo "==============================\n";

