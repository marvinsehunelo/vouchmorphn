<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\handlers;

use DFSP_ADAPTER_LAYER\dto\QuoteRequest;
use BUSINESS_LOGIC_LAYER\services\SwapService;

class QuotesHandler
{
    private SwapService $swapService;

    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }

    public function createQuote(QuoteRequest $request): array
    {
        $fees = $this->swapService->calculateFeesAndFinalAmount($request->amount, false, false);
        return [
            'transferAmount' => [
                'amount' => $fees['final_amount'],
                'currency' => $request->currency
            ],
            'fees' => $fees['fee_details']
        ];
    }
}

