<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace DFSP_ADAPTER_LAYER\mapper;

class ErrorMapper
{
    public static function map($error): array
    {
        return [
            'status' => 'error',
            'data' => [
                'errorInformation' => [
                    'errorCode' => $error['errorCode'] ?? '2001',
                    'errorDescription' => $error['message'] ?? $error['errorDescription'] ?? 'Internal server error'
                ]
            ]
        ];
    }
}
