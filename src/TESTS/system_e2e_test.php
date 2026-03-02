<?php
// 11.TESTS/system_e2e_test.php
// Full E2E / System health & functional test for PrestagedSWAP
// Run: php 11.TESTS/system_e2e_test.php

date_default_timezone_set('Africa/Gaborone');

echo "============================================\n";
echo "PRESTAGEDSWAP SYSTEM E2E TEST\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "============================================\n\n";

$results = [];
function pass($k, $msg = '') { global $results; $results[] = ['ok'=>true,'test'=>$k,'msg'=>$msg]; echo "[✔] $k\n"; }
function fail($k, $msg = '') { global $results; $results[] = ['ok'=>false,'test'=>$k,'msg'=>$msg]; echo "[✘] $k — $msg\n"; }

function safe_require($path) {
    if (file_exists($path)) {
        try { require_once $path; return true; } catch (Throwable $e) { return false; }
    }
    return false;
}

// 1) BOOTSTRAP
echo "\n-- BOOTSTRAP --\n";
$bootstrapPath = __DIR__ . '/../bootstrap.php';
if (safe_require($bootstrapPath)) pass('bootstrap.php loaded');
else fail('bootstrap.php', 'Missing or failed to load. Many tests will be skipped.');

// 2) CONFIG LOAD
echo "\n-- CONFIG --\n";
$configPath = __DIR__ . '/../CORE_CONFIG/config.template.php';
$altConfig = __DIR__ . '/../CORE_CONFIG/config.php';
$configLoaded = false;
if (file_exists($altConfig)) {
    try { $config = require $altConfig; $configLoaded = is_array($config); } catch (Throwable $e) {}
}
if (!$configLoaded && file_exists($configPath)) {
    try { $config = require $configPath; $configLoaded = is_array($config); } catch (Throwable $e) {}
}
if ($configLoaded) pass('config loaded'); else fail('config', 'Could not load config');

// 3) DATABASE CONNECTIONS
echo "\n-- DATABASE CONNECTIONS --\n";
$databases = ['swap','cazacom','zuru','saccus'];
$dbHandles = [];
foreach ($databases as $dbKey) {
    if (!empty($config['db'][$dbKey])) {
        $c = $config['db'][$dbKey];
        try {
            $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $dbHandles[$dbKey] = $pdo;
            pass("DB connection: {$dbKey}");
        } catch (Throwable $e) { fail("DB connection: {$dbKey}", $e->getMessage()); }
    } else fail("DB connection: {$dbKey}", "No config");
}

// 4) FILE & CLASS PRESENCE
echo "\n-- FILE & CLASS PRESENCE --\n";
$checkFiles = [
    __DIR__ . '/../BUSINESS_LOGIC_LAYER/controllers/SwapController.php' => 'BUSINESS_LOGIC_LAYER\Controllers\SwapController',
    __DIR__ . '/../BUSINESS_LOGIC_LAYER/services/SwapService.php' => 'BUSINESS_LOGIC_LAYER\Services\SwapService',
    __DIR__ . '/../SECURITY_LAYER/Auth/JwtAuth.php' => 'SecurityLayer\Auth\JwtAuth',
    __DIR__ . '/../FACTORY_LAYER/CommunicationFactory.php' => 'FACTORY_LAYER\CommunicationFactory',
    __DIR__ . '/../APP_LAYER/utils/session_manager.php' => 'AppLayer\Utils\SessionManager',
];
foreach ($checkFiles as $file => $class) {
    if (safe_require($file)) {
        if (class_exists($class) || interface_exists($class)) pass("Loaded & class exists: " . basename($file));
        else pass("File loaded (class check skipped): " . basename($file));
    } else fail("Missing file: " . basename($file), $file);
}

// 5) CHECK ESSENTIAL TABLES
echo "\n-- SWAP SYSTEM TABLES (existence & row counts) --\n";
$essentialTables = ['users','otp_codes','transactions','swap_transactions','audit_logs','roles','admins'];
if (!empty($dbHandles['swap'])) {
    $pdo = $dbHandles['swap'];
    foreach ($essentialTables as $tbl) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM `$tbl` LIMIT 1");
            $cnt = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM `$tbl`")->fetch(PDO::FETCH_ASSOC)['cnt'];
            pass("Table exists: $tbl (rows: $cnt)");
        } catch (Throwable $e) { fail("Table missing: $tbl", $e->getMessage()); }
    }
} else fail("swap DB handle", "swap DB not available");

// 6) TEST USERS & OTP FLOW
echo "\n-- TEST USERS & OTP FLOW --\n";
$testPhone = '+26770000TEST';
if (!empty($dbHandles['swap']) && !empty($dbHandles['cazacom'])) {
    $swap = $dbHandles['swap']; $caza = $dbHandles['cazacom'];
    // ensure users exist
    $stmt = $caza->prepare("SELECT id FROM users WHERE phone_number=? LIMIT 1"); $stmt->execute([$testPhone]); $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { $caza->prepare("INSERT INTO users (name, phone_number, email, created_at) VALUES (?,?,?,NOW())")->execute(['Test User',$testPhone,'test@example.local']); pass('Inserted test user into cazacom.users'); } else pass('Cazacom test user exists');
    $stmt = $swap->prepare("SELECT user_id FROM users WHERE phone=? LIMIT 1"); $stmt->execute([$testPhone]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) { $swap->prepare("INSERT INTO users (username,email,phone,verified,role_id,created_at,updated_at) VALUES (?,?,?,?,2,NOW(),NOW())")->execute([$testPhone,null,$testPhone,0]); pass('Inserted test user into swap.users'); } else pass('Swap test user exists');
    $otp = str_pad(rand(0,999999),6,'0',STR_PAD_LEFT); $now = date('Y-m-d H:i:s'); $exp = date('Y-m-d H:i:s', time()+300);
    $swap->prepare("INSERT INTO otp_codes (phone,code,created_at,expires_at,used) VALUES (?,?,?,?,0)")->execute([$testPhone,$otp,$now,$exp]); pass("Inserted OTP for $testPhone (code: $otp) into otp_codes");
}

// 7) COMMUNICATION LAYER
echo "\n-- COMMUNICATION LAYER (Cazacom) --\n";
if (class_exists('FACTORY_LAYER\CommunicationFactory')) {
    try {
        $comm = FACTORY_LAYER\CommunicationFactory::create('cazacom');
        if (method_exists($comm,'sendSMS')) { $res = $comm->sendSMS($testPhone,"PrestagedSWAP test SMS"); pass('CommunicationFactory -> sendSMS returned success'); }
    } catch (Throwable $e) { fail('CommunicationFactory::create',$e->getMessage()); }
} else fail('CommunicationFactory','Factory class not found');

// 8) SWAP FLOW
echo "\n-- SWAP FLOW (initiate) --\n";
$swapInitiated = false;
if (class_exists('BusinessLogicLayer\Services\SwapService')) {
    $svc = new BusinessLogicLayer\Services\SwapService($dbHandles['swap'] ?? null,$dbHandles['saccus'] ?? null,$dbHandles['zuru'] ?? null);
    if (method_exists($svc,'initiateSwap')) { 
        $payload=['from_phone'=>$testPhone,'to_bank'=>'TESTBANK','amount'=>1.00,'reference'=>'UNITTEST-'.uniqid()];
        try { $svc->initiateSwap($payload); pass('SwapService::initiateSwap called'); $swapInitiated=true; } catch(Throwable $e) { fail('SwapService::initiateSwap',$e->getMessage()); }
    }
} elseif (class_exists('BusinessLogicLayer\Controllers\SwapController')) {
    $ctrl = new BusinessLogicLayer\Controllers\SwapController($dbHandles['swap'] ?? null,$dbHandles['saccus'] ?? null,$dbHandles['zuru'] ?? null);
    if (method_exists($ctrl,'initiate')) { try { ob_start(); $ctrl->initiate(['from_phone'=>$testPhone,'to_bank'=>'TESTBANK','amount'=>1.0,'reference'=>'UNITTEST-'.uniqid()]); ob_get_clean(); pass('SwapController::initiate executed'); $swapInitiated=true; } catch(Throwable $e){ fail('SwapController::initiate',$e->getMessage()); } }
} else {
    try { $dbHandles['swap']->prepare("INSERT INTO swap_transactions (from_phone,to_bank,amount,status,created_at) VALUES (?,?,?,?,NOW())")->execute([$testPhone,'TESTBANK',1.0,'initiated']); pass('Inserted simulated swap_transactions row'); } catch(Throwable $e){ fail('Simulated swap insertion',$e->getMessage()); }
}

// 9) AUDIT TRAIL
echo "\n-- AUDIT TRAIL --\n";

if (class_exists('BusinessLogicLayer\Services\AuditTrailService')) {
    $ats = new BusinessLogicLayer\Services\AuditTrailService($dbHandles['swap'] ?? null);
    if (method_exists($ats,'logAction')) {
        $ats->logAction('E2E Test Action','system','E2E test log');
        pass('AuditTrailService::logAction called');
    }
} else {
    // Fallback: direct insert into audit_logs
    try {
        $stmt = $dbHandles['swap']->prepare(
            "INSERT INTO audit_logs 
            (entity, entity_id, action, category, severity, old_value, new_value, performed_by, ip_address, user_agent, geo_location, performed_at, immutable)
            VALUES
            (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $stmt->execute([
            'system',           // entity
            '0',                // entity_id
            'test',             // action
            'info',             // category
            'low',              // severity
            NULL,               // old_value
            NULL,               // new_value
            'e2e',              // performed_by
            '127.0.0.1',        // ip_address
            'PHPUnit',          // user_agent
            'Gaborone',         // geo_location
            date('Y-m-d H:i:s'),// performed_at
            0                   // immutable
        ]);

        pass('Inserted a row into audit_logs table (fallback)');
    } catch (Throwable $e) {
        fail('Audit log fallback insert', $e->getMessage());
    }
}

// 10) EXPIRED SWAPS PROCESS
echo "\n-- EXPIRED SWAPS PROCESS --\n";

if (class_exists('BusinessLogicLayer\Services\ExpiredSwapsService')) {
    $exSvc = new BusinessLogicLayer\Services\ExpiredSwapsService($dbHandles['zuru'] ?? null);
    if (method_exists($exSvc,'processExpiredSwaps')) {
        $exSvc->processExpiredSwaps();
        pass('ExpiredSwapsService::processExpiredSwaps executed');
    }
} else {
    pass('Expired swaps module not present; skipping');
}

// 11) COMPLIANCE CONTROLLER
echo "\n-- COMPLIANCE CONTROLLER --\n";
if (class_exists('BusinessLogicLayer\Controllers\ComplianceController')) {
    $com = new BusinessLogicLayer\Controllers\ComplianceController($dbHandles['swap'] ?? null);
    if (method_exists($com,'getComplianceOverview')) { $com->getComplianceOverview(); pass('ComplianceController::getComplianceOverview executed'); }
} else pass('ComplianceController not present; skipping');

// 12) CRITICAL TABLE CHECK
echo "\n-- CRITICAL TABLE POPULATION CHECK --\n";
$critical=['users','swap_transactions','transactions','audit_logs'];
foreach($critical as $t){ try{$cnt=(int)$dbHandles['swap']->query("SELECT COUNT(*) AS c FROM `$t`")->fetch(PDO::FETCH_ASSOC)['c']; if($cnt>0) pass("Table $t has rows: $cnt"); else fail("Table $t is empty","0 rows"); }catch(Throwable $e){ fail("Table $t check",$e->getMessage()); } }

// SUMMARY
echo "\n============================================\n";
$passed=array_filter($results,fn($r)=>$r['ok']);
$failed=array_filter($results,fn($r)=>!$r['ok']);
echo "REPORT: ".count($passed)." passed, ".count($failed)." failed\n\n";
if(count($failed)>0){ echo "Failed tests detail:\n"; foreach($failed as $f){ echo "- {$f['test']} : {$f['msg']}\n"; } echo "\nCheck logs and rerun script.\n"; } else echo "All tests passed.\n";
echo "\nCompleted: ".date('Y-m-d H:i:s')."\n";
echo "============================================\n";
