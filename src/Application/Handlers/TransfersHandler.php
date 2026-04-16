<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\handlers;

use DFSP_ADAPTER_LAYER\dto\TransferRequest;
use BUSINESS_LOGIC_LAYER\services\SwapService;

class TransfersHandler
{
    private SwapService $swapService;

    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }

    public function executeTransfer(TransferRequest $request): array
    {
        return $this->swapService->executeSwap(
            $request->payerFsp,
            $request->payeeFsp,
            $request->amount,
            'wallet',
            'wallet',
            null,
            $request->metadata,
            false
        );
    }
}

