<?php
return [
'ussd' => [
'shortcode' => '*123#',
'mno_callback_token' => 'CHANGE_ME',
'session_ttl_seconds' => 300,
'log' => __DIR__ . '/../../APP_LAYER/logs/ussd.log'
],
'whatsapp' => [
'bsp_base_url' => 'https://bsp.example.com',
'bsp_token' => 'CHANGE_ME',
'templates' => [
'swap_success' => 'swap_success_template_id',
'swap_fail' => 'swap_fail_template_id'
],
'log' => __DIR__ . '/../../APP_LAYER/logs/whatsapp.log'
]
];
