<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return [
    // --- Swap System DB (internal logging & ledgers) ---
    'db' => [
        'swap' => [
            'type' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,        // PostgreSQL default port
            'name' => 'swap_system_ng',
            'user' => 'postgres',   // replace with your Postgres user
            'pass' => 'StrongPassword!',
        ],
        'source_client' => [
            'type' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'name' => 'cazacom_ng',
            'user' => 'postgres',
            'pass' => 'StrongPassword!',
        ],
        'zurubank' => [
            'type' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'name' => 'zurubank_ng',
            'user' => 'postgres',
            'pass' => 'StrongPassword!',
        ],
        'access' => [
            'type' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'name' => 'access',
            'user' => 'postgres',
            'pass' => 'StrongPassword!',
        ],
        'saccussalis' => [
            'type' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'name' => 'saccussalis_ng',
            'user' => 'postgres',
            'pass' => 'StrongPassword!',
        ],
    ],

    // --- Global API Keys for each participant ---
    'api_keys' => [
        'SWAP'    => 'SWAP_SYSTEM_KEY_BOT_001',
        'SACCUS'  => 'SACCUS_LOCAL_KEY_DEF456',
        'ZURU'    => 'ZURU_LOCAL_KEY_ABC123',
        'ACCESS'  => 'ACCESS_LOCAL_KEY_ABC123',
        'CAZACOM' => 'CAZACOM_LOCAL_KEY_123',
    ],

    // --- Swap Fees ---
    'fees' => [
        'creation_fee' => 10.00,
        'swap_fee'     => 2.00,
        'admin_fee'    => 0.10,
        'sms_fee'      => 0.10,
    ],

    // --- Fee Splits ---
    'fee_split' => [
        'used_swap' => [
            'bank_share'      => 0.60,
            'middleman_share' => 0.40,
        ]
    ],

    'voucher_multiples' => [20, 50],

    'encryption' => [
        'key'    => 'your_very_secure_32byte_key_here',
        'cipher' => 'AES-256-CBC',
    ],

    'sms_sender' => 'SWAP_SYSTEM',

    'logging' => [
        'enabled'  => true,
        'log_file' => __DIR__ . '/../../logs/system.log',
    ],

    // --- Participants (one API key per participant, with type & wallet_type) ---
    'participants' => [
        'zurubank' => [
            'type' => 'bank',
            'wallet_type' => 'voucher',
            'account_type' => 'account',
            'url' => 'http://localhost/zurubank/Backend/transactions/',
            'api_key' => 'ZURU_LOCAL_KEY_GHI789'
        ],
        'access' => [
            'type' => 'bank',
            'wallet_type' => 'voucher',
            'account_type' => 'account',
            'url' => 'http://localhost/access/Backend/transactions/',
            'api_key' => 'ACCESS_LOCAL_KEY_GHI789'
        ],
        'saccussalis' => [
            'type' => 'bank',
            'wallet_type' => 'ewallet',
            'account_type' => 'account',
            'url' => 'http://localhost/SaccuSsalisbank/backend/transactions/',
            'api_key' => 'SACCUS_LOCAL_KEY_DEF456'
        ],
        'cazacom' => [
            'type' => 'telco',
            'url' => 'http://localhost/CazaCom/backend/routes/',
            'api_key' => 'CAZACOM_LOCAL_KEY_123'
        ]
    ]
];

