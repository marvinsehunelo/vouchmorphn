<?php
/**
 * Fixed test.php for participants and fee overrides
 * PostgreSQL-safe: avoids FK and unique constraint issues
 */

$dsn = "pgsql:host=localhost;port=5432;dbname=swap_system_bw;user=postgres;password=StrongPassword!";
try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ----------------------------
// 0️⃣ Recreate participants table safely
// ----------------------------
$pdo->exec("
DROP TABLE IF EXISTS participant_fee_overrides;
DROP TABLE IF EXISTS participants;

CREATE TABLE participants (
    participant_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    type VARCHAR(50),
    category VARCHAR(50),
    provider_code VARCHAR(50),
    auth_type VARCHAR(50),
    base_url TEXT,
    system_user_id BIGINT,
    legal_entity_identifier VARCHAR(50),
    license_number VARCHAR(50),
    settlement_account VARCHAR(50),
    settlement_type VARCHAR(50),
    status VARCHAR(20),
    capabilities JSONB,
    resource_endpoints JSONB
);

CREATE TABLE participant_fee_overrides (
    override_id BIGSERIAL PRIMARY KEY,
    participant_id BIGINT REFERENCES participants(participant_id) ON DELETE CASCADE,
    transaction_type VARCHAR(20),
    fee_amount NUMERIC(12,2),
    split JSONB,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT now(),
    UNIQUE(participant_id, transaction_type)
);
");

// ----------------------------
// 1️⃣ Participants Data
// ----------------------------
$participantsData = [
    [
        'name' => 'TEST_BANK_A',
        'type' => 'FINANCIAL_INSTITUTION',
        'category' => 'BANK',
        'provider_code' => 'TEST_BIC_A',
        'auth_type' => 'MTLS_OAUTH2',
        'base_url' => 'https://sandbox-bank.local',
        'system_user_id' => 1,
        'legal_entity_identifier' => 'TEST_LEI_001',
        'license_number' => 'CB-BW-001',
        'settlement_account' => 'TEST_ACC_001',
        'settlement_type' => 'RTGS_TRANSIT',
        'status' => 'ACTIVE',
        'capabilities' => ['supports_sca'=>true, 'wallet_types'=>['ACCOUNT','VOUCHER'],'supports_realtime_settlement'=>true],
        'resource_endpoints' => ['reversal'=>'/sandbox/payments/{id}/reversal','funds_confirmation'=>'/sandbox/accounts/{id}/balance','payment_initiation'=>'/sandbox/payments','beneficiary_validation'=>'/sandbox/identity/verify']
    ],
    [
        'name' => 'TEST_BANK_B',
        'type' => 'FINANCIAL_INSTITUTION',
        'category' => 'BANK',
        'provider_code' => 'TEST_BIC_B',
        'auth_type' => 'MTLS_OAUTH2',
        'base_url' => 'https://sandbox-bank-b.local',
        'system_user_id' => 2,
        'legal_entity_identifier' => 'TEST_LEI_002',
        'license_number' => 'CB-BW-002',
        'settlement_account' => 'TEST_ACC_002',
        'settlement_type' => 'RTGS_TRANSIT',
        'status' => 'ACTIVE',
        'capabilities' => ['supports_sca'=>true, 'wallet_types'=>['ACCOUNT','VOUCHER'],'supports_realtime_settlement'=>true],
        'resource_endpoints' => ['reversal'=>'/sandbox/payments/{id}/reversal','funds_confirmation'=>'/sandbox/accounts/{id}/balance','payment_initiation'=>'/sandbox/payments','beneficiary_validation'=>'/sandbox/identity/verify']
    ],
    [
        'name' => 'TEST_MNO_A',
        'type' => 'MOBILE_MONEY_OPERATOR',
        'category' => 'MNO',
        'provider_code' => 'TEST_MNC_A',
        'auth_type' => 'OAUTH2_JWT',
        'base_url' => 'https://sandbox-mno.local',
        'status' => 'ACTIVE',
        'capabilities' => ['supports_sca'=>true,'wallet_types'=>['WALLET'],'supports_realtime_disbursement'=>true],
        'resource_endpoints' => ['kyc_check'=>'/sandbox/subscribers/{msisdn}/validate','collection'=>'/sandbox/request-to-pay','disbursement'=>'/sandbox/disbursements']
    ],
    [
        'name' => 'TEST_MNO_B',
        'type' => 'MOBILE_MONEY_OPERATOR',
        'category' => 'MNO',
        'provider_code' => 'TEST_MNC_B',
        'auth_type' => 'OAUTH2_JWT',
        'base_url' => 'https://sandbox-mno-b.local',
        'status' => 'ACTIVE',
        'capabilities' => ['supports_sca'=>true,'wallet_types'=>['WALLET'],'supports_realtime_disbursement'=>true],
        'resource_endpoints' => ['kyc_check'=>'/sandbox/subscribers/{msisdn}/validate','collection'=>'/sandbox/request-to-pay','disbursement'=>'/sandbox/disbursements']
    ],
    // Add more participants here as needed...
];

// ----------------------------
// 2️⃣ Insert participants safely
// ----------------------------
$participantsMap = [];
$stmt = $pdo->prepare("
    INSERT INTO participants
    (name,type,category,provider_code,auth_type,base_url,system_user_id,legal_entity_identifier,license_number,settlement_account,settlement_type,status,capabilities,resource_endpoints)
    VALUES
    (:name,:type,:category,:provider_code,:auth_type,:base_url,:system_user_id,:lei,:license_number,:settlement_account,:settlement_type,:status,:capabilities,:endpoints)
    ON CONFLICT (name) DO UPDATE SET name=EXCLUDED.name
    RETURNING participant_id
");

foreach ($participantsData as $p) {
    $stmt->execute([
        ':name'=>$p['name'],
        ':type'=>$p['type'] ?? null,
        ':category'=>$p['category'] ?? null,
        ':provider_code'=>$p['provider_code'] ?? null,
        ':auth_type'=>$p['auth_type'] ?? null,
        ':base_url'=>$p['base_url'] ?? null,
        ':system_user_id'=>$p['system_user_id'] ?? null,
        ':lei'=>$p['legal_entity_identifier'] ?? null,
        ':license_number'=>$p['license_number'] ?? null,
        ':settlement_account'=>$p['settlement_account'] ?? null,
        ':settlement_type'=>$p['settlement_type'] ?? null,
        ':status'=>$p['status'] ?? 'ACTIVE',
        ':capabilities'=>json_encode($p['capabilities'] ?? []),
        ':endpoints'=>json_encode($p['resource_endpoints'] ?? [])
    ]);
    $id = $stmt->fetchColumn();
    $participantsMap[$p['name']] = $id;
}

// ----------------------------
// 3️⃣ Insert participant fee overrides safely (MNOs)
// ----------------------------
$stmtOverride = $pdo->prepare("
    INSERT INTO participant_fee_overrides (participant_id, transaction_type, fee_amount, split)
    VALUES (:pid,'CASHOUT',5.0,:split)
    ON CONFLICT (participant_id, transaction_type) DO NOTHING
");

foreach ($participantsMap as $name=>$id) {
    if (stripos($name,'MNO')!==false) {
        $stmtOverride->execute([
            ':pid'=>$id,
            ':split'=>json_encode(['mno'=>2.5,'vouchmorph'=>2.5])
        ]);
    }
}

echo "✅ Participants and fee overrides inserted successfully!\n";
