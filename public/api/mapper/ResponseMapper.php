<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\mapper;

class ResponseMapper
{
    public static function mapTransfer(array $swapResult): array
    {
        if (($swapResult['status'] ?? '') === 'success') {
            return [
                'transferState' => 'COMMITTED',
                'completedTimestamp' => date('c'),
                'settlementAmount' => $swapResult['sttlm_amt'] ?? null
            ];
        }

        if (($swapResult['status'] ?? '') === 'BLOCKED_REGULATORY') {
            return [
                'transferState' => 'REJECTED'
            ];
        }

        return [
            'transferState' => 'ABORTED'
        ];
    }

    public static function mapQuote(array $quoteData): array
    {
        return [
            'transferAmount' => [
                'amount' => $quoteData['transferAmount']['amount'] ?? 0,
                'currency' => $quoteData['transferAmount']['currency'] ?? 'BWP'
            ],
            'expiration' => date('c', strtotime('+30 seconds'))
        ];
    }

    public static function mapPartyLookup(array $result): array
    {
        if (($result['status'] ?? '') !== 'success') {
            return [];
        }

        return [
            'party' => [
                'partyIdInfo' => [
                    'fspId' => $result['participant']['fspId'] ?? null
                ]
            ]
        ];
    }
}

