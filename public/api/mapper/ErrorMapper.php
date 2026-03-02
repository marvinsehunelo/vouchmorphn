<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\mapper;

class ErrorMapper
{
    public static function map(array $swapResult): array
    {
        $status = $swapResult['status'] ?? 'error';
        $isoError = $swapResult['iso_error'] ?? null;
        $message = $swapResult['message'] ?? 'Unknown error';

        // AML / Regulatory block
        if ($status === 'BLOCKED_REGULATORY') {
            return self::build('2001', 'AML_BLOCKED', $message);
        }

        // Insufficient liquidity
        if ($isoError === 'AC04') {
            return self::build('5100', 'INSUFFICIENT_FUNDS', $message);
        }

        // Participant not found / sanctions failure
        if ($isoError === 'RR04') {
            return self::build('3204', 'PARTY_NOT_FOUND', $message);
        }

        // Generic failure
        return self::build('5000', 'INTERNAL_SERVER_ERROR', $message);
    }

    private static function build(string $code, string $type, string $description): array
    {
        return [
            'errorInformation' => [
                'errorCode' => $code,
                'errorDescription' => $description
            ],
            'extensionList' => [
                [
                    'key' => 'errorType',
                    'value' => $type
                ]
            ]
        ];
    }
}

