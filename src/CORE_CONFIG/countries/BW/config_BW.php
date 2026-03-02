<?php
/**
 * config_BW.php - Regulatory Compliant Sandbox 2026
 * Enforces Secret Management & Least Privilege
 */

// ======================================================
// PASSWORD HASHING POLICY (Regulatory Adaptive)
// ======================================================
if (!defined('VM_HASH_ALGO')) {
    if (defined('PASSWORD_ARGON2ID')) {
        define('VM_HASH_ALGO', PASSWORD_ARGON2ID);
        define('VM_HASH_OPTIONS', [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2
        ]);
    } else {
        define('VM_HASH_ALGO', PASSWORD_BCRYPT);
        define('VM_HASH_OPTIONS', ['cost' => 12]);
        error_log("SECURITY NOTICE: Argon2ID not available — using BCRYPT fallback");
    }
}

return [
    'metadata' => [
        'version' => '2.1.0',
        'standard' => 'ISO-20022-JSON / GSMA-MM',
        'region' => 'BW-EU-CROSSBORDER',
        'governance' => 'CENTRAL_BANK_HUB'
    ],

    'env' => getenv('APP_ENV') ?: 'sandbox',

    'db' => [
        'swap' => [
            'type' => 'pgsql',
            'host' => getenv('PG_HOST') ?: 'localhost',
            'port' => getenv('PG_PORT') ?: 5432,
            'name' => getenv('PG_NAME') ?: 'swap_system_bw',
            'user' => getenv('PG_USER') ?: 'postgres',
            'pass' => getenv('PG_PASS') ?: '',
            'password' => getenv('PG_PASS') ?: '',
        ]
    ],
    
    'security' => [
        'encryption_key' => getenv('APP_ENCRYPTION_KEY') ?: 'SANDBOX_PLACEHOLDER_KEY',
        'cipher'         => 'AES-256-GCM',
        'hashing_algo'   => VM_HASH_ALGO,
        'hashing_opts'   => VM_HASH_OPTIONS,
    ],

    'participants' => [
        'ZURUBANK' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'ZURUBWXX',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'http://localhost/zurubank/Backend',
            'security' => [
                'api_key' => ['value_env' => 'API_KEY_ZURUBANK', 'header_name' => 'X-API-KEY'],
                'oauth2' => [
                    'token_endpoint' => '/oauth2/token',
                    'client_id_env' => 'ZURUBANK_CLIENT_ID',
                    'client_secret_env' => 'ZURUBANK_CLIENT_SECRET',
                    'scope' => 'payments settlement'
                ],
                'request_signing' => ['algorithm' => 'RSA-SHA256', 'private_key_env' => 'ZURUBANK_SIGNING_KEY']
            ],
            'identity' => [
                'system_user_id' => 1001,
                'legal_entity_identifier' => 'BW-ZURUBANK-LEI-001',
                'license_number' => 'CB-BW-017'
            ],
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_processing' => true,
                'supports_reversal' => true,
                'supports_idempotency' => true,
                'wallet_types' => ['ACCOUNT', 'VOUCHER', 'ATM']
            ],
            "resource_endpoints" => [
      "verify_asset"=> "/api/v1/verify_asset",
      "place_hold"=> "/api/v1/place_hold",
      "release_hold"=> "/api/v1/release_hold",
      "credit_funds"=> "/api/v1/transaction/credit_funds",
      "generate_token"=> "/api/v1/generate_token",
      "verify_token"=> "/api/v1/verify-token/index.php",
      "confirm_cashout"=> "/api/v1/confirm_cashout",
      "process_deposit"=> "/api/v1/process_deposit",
      "check_status"=> "/api/v1/status",
      "reverse_transaction"=> "/api/v1/release_hold"
            ],
            'status' => 'ACTIVE'
        ],

        'SACCUSSALIS' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'SACCUSBWXX',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'http://localhost/SaccusSalisbank/backend',
            'security' => [
                'api_key' => ['value_env' => 'API_KEY_SACCUSSALIS', 'header_name' => 'X-API-KEY'],
                'oauth2' => [
                    'token_endpoint' => '/oauth2/token',
                    'client_id_env' => 'SACCUSSALIS_CLIENT_ID',
                    'client_secret_env' => 'SACCUSSALIS_CLIENT_SECRET',
                    'scope' => 'payments settlement ewallet atm'
                ],
                'request_signing' => ['algorithm' => 'RSA-SHA256', 'private_key_env' => 'SACCUSSALIS_SIGNING_KEY']
            ],
            'identity' => [
                'system_user_id' => 1001,
                'legal_entity_identifier' => 'BW-SACCUSSALIS-LEI-001',
                'license_number' => 'CB-BW-027'
            ],
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_processing' => true,
                'supports_reversal' => true,
                'supports_idempotency' => true,
                'wallet_types' => ['ACCOUNT', 'E-WALLET', 'ATM']
            ],
            'resource_endpoints' => [
                'verify_account' => '/api/v1/verify-account',
                'verify_ewallet' => '/api/v1/external/verify-ewallet',
                'confirm_cashout' => '/api/v1/external/confirm-cashout',
                'generate_atm_code' => '/api/v1/atm/generate-code',
                'reverse_transaction' => '/api/v1/transaction/reverse',
                'transaction_status' => '/api/v1/transaction/status'
            ],
            'status' => 'ACTIVE'
        ],

        'TEST_BANK_A' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'TEST_BIC_A',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'https://sandbox-bank.local',
            'identity' => [
                'system_user_id' => 1,
                'legal_entity_identifier' => 'TEST_LEI_001',
                'license_number' => 'CB-BW-001'
            ],
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_settlement' => true,
                'wallet_types' => ['ACCOUNT', 'VOUCHER']
            ],
            'status' => 'ACTIVE'
        ],

        'TEST_BANK_B' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'TEST_BIC_B',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'https://sandbox-bank-b.local',
            'identity' => [
                'system_user_id' => 2,
                'legal_entity_identifier' => 'TEST_LEI_002',
                'license_number' => 'CB-BW-002'
            ],
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_settlement' => true,
                'wallet_types' => ['ACCOUNT', 'VOUCHER']
            ],
            'resource_endpoints' => [
                'identity_lookup' => '/api/v1/verify_account.php',
                'voucher_request' => '/api/v1/atm/generate_code.php',
                'credit_transfer' => '/api/v1/deposit/direct.php'
            ],
            'status' => 'ACTIVE'
        ],

        'TEST_MNO_A' => [
            'type' => 'MOBILE_MONEY_OPERATOR',
            'category' => 'MNO',
            'provider_code' => 'TEST_MNC_A',
            'auth_type' => 'OAUTH2_JWT',
            'base_url' => 'https://sandbox-mno.local',
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_disbursement' => true,
                'wallet_types' => ['WALLET']
            ],
            'status' => 'ACTIVE'
        ],

        'TEST_MNO_B' => [
            'type' => 'MOBILE_MONEY_OPERATOR',
            'category' => 'MNO',
            'provider_code' => 'TEST_MNC_B',
            'auth_type' => 'OAUTH2_JWT',
            'base_url' => 'https://sandbox-mno-b.local',
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_disbursement' => true,
                'wallet_types' => ['WALLET']
            ],
            'status' => 'ACTIVE'
        ],

        'TEST_DISTRIBUTOR_A' => [
            'type' => 'CARD_DISTRIBUTOR',
            'category' => 'EMI_CARD',
            'provider_code' => 'TEST_EMV_A',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'https://sandbox-distributor.local',
            'capabilities' => [
                'supports_card_issue' => true,
                'supports_top_up' => true,
                'wallet_types' => ['CARD']
            ],
            'status' => 'ACTIVE'
        ],

        'ALPHA' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'ALPHA_BIC',
            'auth_type' => 'MTLS_OAUTH2',
            'capabilities' => [
                'supports_sca' => true,
                'wallet_types' => ['ACCOUNT', 'E-WALLET']
            ],
            'status' => 'ACTIVE'
        ],

        'CARD' => [
            'type' => 'CARD_DISTRIBUTOR',
            'category' => 'EMI_CARD',
            'provider_code' => 'CARD_EMV',
            'auth_type' => 'MTLS_OAUTH2',
            'capabilities' => [
                'supports_card_issue' => true,
                'wallet_types' => ['CARD']
            ],
            'status' => 'ACTIVE'
        ],

        'BANK A' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'BANK_BIC',
            'auth_type' => 'MTLS_OAUTH2',
            'capabilities' => [
                'supports_sca' => true,
                'wallet_types' => ['ACCOUNT', 'E-WALLET']
            ],
            'status' => 'ACTIVE'
        ]
    ],
    
    'settings' => [
        'sat_purchased' => 1,
        'swap_fee' => 1.5,
    ],
    
    'country' => 'BW',
    'currency' => 'BWP'
];
