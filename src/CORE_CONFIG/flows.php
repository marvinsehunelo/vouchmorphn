<?php
// 5. INTEGRATION_LAYER/config/flows.php

return [

    'asset_categories' => [
        'wallet',
        'bank_account',
        'card',
        'ewallet'
    ],

    'delivery_modes' => [
        'deposit',   // credit into account/card/wallet
        'cashout'    // ATM / agent withdrawal
    ],

    'supported_flows' => [

        // WALLET ORIGIN
        'wallet_to_wallet',
        'wallet_to_bank_account',
        'wallet_to_card',
        'wallet_to_ewallet',
        'wallet_to_cashout',

        // BANK ACCOUNT ORIGIN
        'bank_account_to_wallet',
        'bank_account_to_card',
        'bank_account_to_ewallet',
        'bank_account_to_cashout',

        // CARD ORIGIN
        'card_to_wallet',
        'card_to_bank_account',
        'card_to_ewallet',
        'card_to_cashout',

        // EWALLET ORIGIN
        'ewallet_to_wallet',
        'ewallet_to_bank_account',
        'ewallet_to_card',
        'ewallet_to_cashout',
        
        // VOUCHER ORIGIN
        'voucher_to_wallet',
        'voucher_to_bank_account',
        'voucher_to_card',
        'voucher_to_cashout'
    ]
];

