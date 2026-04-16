<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/**
 * config_BW.php - Regulatory Compliant Sandbox 2026
 * UPDATED FOR RAILWAY DEPLOYMENT with function guards
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

// ======================================================
// DYNAMIC BASE URL DETECTION FOR RAILWAY
// ======================================================
if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . $host;
    }
}

// ======================================================
// PARSE RAILWAY DATABASE URL
// ======================================================
if (!function_exists('getDatabaseConfig')) {
    function getDatabaseConfig() {
        $database_url = getenv('DATABASE_URL');
        
        if ($database_url) {
            // Parse Railway's DATABASE_URL (postgresql://user:pass@host:port/dbname)
            $db = parse_url($database_url);
            return [
                'host' => $db['host'] ?? 'localhost',
                'port' => $db['port'] ?? '5432',
                'name' => ltrim($db['path'] ?? '', '/'),
                'user' => $db['user'] ?? 'postgres',
                'pass' => $db['pass'] ?? '',
                'password' => $db['pass'] ?? ''
            ];
        }
        
        // Fallback for local development
        return [
            'host' => getenv('PG_HOST') ?: 'localhost',
            'port' => getenv('PG_PORT') ?: 5432,
            'name' => getenv('PG_NAME') ?: 'swap_system_bw',
            'user' => getenv('PG_USER') ?: 'postgres',
            'pass' => getenv('PG_PASS') ?: '',
            'password' => getenv('PG_PASS') ?: ''
        ];
    }
}

$dbConfig = getDatabaseConfig();
$baseUrl = getBaseUrl();

return [
    'metadata' => [
        'version' => '2.1.0',
        'standard' => 'ISO-20022-JSON / GSMA-MM',
        'region' => 'BW-EU-CROSSBORDER',
        'governance' => 'CENTRAL_BANK_HUB',
        'deployment' => 'RAILWAY'
    ],

    'env' => getenv('APP_ENV') ?: 'production',

    'db' => [
    'swap' => [
        'type' => 'pgsql',
        'host' => $dbConfig['host'],
        'port' => $dbConfig['port'],
        'name' => $dbConfig['name'],
        'user' => $dbConfig['user'],
        'pass' => $dbConfig['pass'],
        'password' => $dbConfig['password']
    ],
    'source_client_key' => 'cazacom',
    'cazacom' => [
        'type' => 'pgsql',
        'host' => getenv('CAZACOM_DB_HOST') ?: $dbConfig['host'],
        'port' => getenv('CAZACOM_DB_PORT') ?: $dbConfig['port'],
        'name' => getenv('CAZACOM_DB_NAME') ?: 'cazacom_db',
        'user' => getenv('CAZACOM_DB_USER') ?: $dbConfig['user'],
        'pass' => getenv('CAZACOM_DB_PASS') ?: '',
        'password' => getenv('CAZACOM_DB_PASS') ?: ''
    ]
],
    
    'security' => [
        'encryption_key' => getenv('APP_ENCRYPTION_KEY') ?: 'PRODUCTION_KEY_MUST_BE_SET_IN_ENV',
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
            'base_url' => 'https://zurubank-prod.up.railway.app',
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
            'base_url' => 'https://saccussalis-prod.up.railway.app',
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

        'CAZACOM' => [
            'type' => 'FINANCIAL_INSTITUTION',
            'category' => 'BANK',
            'provider_code' => 'CAZABWXX',
            'auth_type' => 'MTLS_OAUTH2',
            'base_url' => 'https://cazacom-prod.up.railway.app',
            'identity' => [
                'system_user_id' => 1003,
                'legal_entity_identifier' => 'BW-CAZACOM-LEI-003',
                'license_number' => 'CB-BW-037'
            ],
            'capabilities' => [
                'supports_sca' => true,
                'supports_realtime_processing' => true,
                'wallet_types' => ['ACCOUNT', 'VOUCHER']
            ],
            'resource_endpoints' => [
                'sms_send' => '/backend/routes/api.php?path=sms/send'
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
