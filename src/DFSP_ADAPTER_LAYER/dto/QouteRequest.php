<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\dto;

class QuoteRequest
{
    public string $transactionId;
    public string $payerFsp;
    public string $payeeFsp;
    public float $amount;
    public string $currency;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->transactionId = $data['transactionId'];
        $this->payerFsp = $data['payerFsp'];
        $this->payeeFsp = $data['payeeFsp'];
        $this->amount = (float)$data['amount'];
        $this->currency = $data['currency'];
        $this->metadata = $data['metadata'] ?? [];
    }
}

