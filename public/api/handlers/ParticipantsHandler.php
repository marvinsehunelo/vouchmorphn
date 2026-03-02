<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\handlers;

use DFSP_ADAPTER_LAYER\dto\PartyLookupRequest;
use BUSINESS_LOGIC_LAYER\services\SwapService;
use PDO;

class ParticipantsHandler
{
    private SwapService $swapService;

    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }

    public function lookup(PartyLookupRequest $request): array
    {
        // Simplified: Return participant info from SwapService participants
        $participants = $this->swapService->participants ?? [];
        $id = strtoupper($request->partyIdentifier);
        if (!isset($participants[$id])) {
            return ['status' => 'error', 'message' => 'Participant not found'];
        }
        return ['status' => 'success', 'participant' => $participants[$id]];
    }
}

