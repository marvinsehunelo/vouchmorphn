<?php
return [
    'switch' => [
        'scheme' => 'http',
        'host'   => 'host.docker.internal',
        'port'   => 4040
    ],

    'fspid' => getenv('MOJALOOP_DFSP_ID') ?: 'VOUCHMORPHN',

    // REQUIRED FOR TTK
    'callback_base' => getenv('MOJALOOP_CALLBACK_URL') ?: 'http://localhost:8080/mojaloop/callback',

    // REQUIRED FOR PARTY LOOKUPS
    'dfsp_participant_map' => [
        'ALPHA'   => 'ALPHA',
        'BRAVO'   => 'BRAVO',
        'CHARLIE' => 'CHARLIE',
        'CARD'    => 'CARD',
        'BANK'    => 'BANK',
        'VOUCHMORPHN' => 'VOUCHMORPHN'
    ]
];

