<?php
return [
    /*
     |--------------------------------------------------------------------------
     | Environment
     |--------------------------------------------------------------------------
     | 'production', 'staging', 'development'
     */
    'environment' => 'production',

    /*
     |--------------------------------------------------------------------------
     | Timezone (all times stored in UTC)
     |--------------------------------------------------------------------------
     */
    'timezone' => 'UTC',

    /*
     |--------------------------------------------------------------------------
     | Default Limits (can be overridden per country)
     |--------------------------------------------------------------------------
     */
    'limits' => [
        'min_swap_amount' => 1.00,   // minimum voucher/ewallet amount
        'max_swap_amount' => 10000.00, // maximum voucher/ewallet amount
        'default_expiry_hours' => 24, // default swap expiry
    ],

    /*
     |--------------------------------------------------------------------------
     | Default Currency (can be overridden per country)
     |--------------------------------------------------------------------------
     */
    'currency' => 'BWP',
/*
     |--------------------------------------------------------------------------
     | Fee Structure (defaults; negotiator adjustable)
     |--------------------------------------------------------------------------
     */
    'fees' => [
        // 1. Large fixed charge dedicated to Middleman Escrow
        'fixed_escrow_charge' => 10.00,

        // 2. The amount to be split between Bank and Middleman Revenue
        'swap_fee' => 2.00,

        // 3. Fixed charge for SMS
        'sms_fee' => 0.10,

        // 4. Fixed charge for Admin (dedicated to Middleman Revenue)
        'admin_fee' => 0.10,
    ],

    /*
     |--------------------------------------------------------------------------
     | Fee Split Rules (numeric, auditable)
     |--------------------------------------------------------------------------
     | Split ratios are applied ONLY to the 'swap_fee' component.
     */
    'split' => [
        'used_swap' => [
            // These splits apply ONLY to the 'swap_fee' (2.00)
            'bank_share'      => 0.60, // 60% of swap_fee goes to partner_bank_settlement
            'middleman_share' => 0.40, // 40% of swap_fee goes to middleman_revenue
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Payer-of-Record Rules
     |--------------------------------------------------------------------------
     | Determines who pays first swap (sender/receiver) and subsequent swaps.
     */
    'payer_of_record' => [
        'sender_pays_first' => true,  // if true, receiver’s first swap is free
        'receiver_pays_after_first' => true, // subsequent swaps always paid by receiver
        'no_sender_funding' => true,  // if sender never pre-funds, receiver pays from start
    ],

    /*
     |--------------------------------------------------------------------------
     | Negotiator Adjustable (can be updated after agreements)
     |--------------------------------------------------------------------------
     */
    'negotiator_adjustable' => [
        'limits',
        'fees',
        'split',
        'payer_of_record',
    ],
];
