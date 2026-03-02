<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER\dto;

class TransferRequest
{
    public string $transferId;
    public string $payerFsp;
    public string $payeeFsp;
    public float $amount;
    public string $currency;
    public string $transactionType;
    public array $metadata;

    public function __construct(array $data)
    {
        $this->transferId = $data['transferId'];
        $this->payerFsp = $data['payerFsp'];
        $this->payeeFsp = $data['payeeFsp'];
        $this->amount = (float)$data['amount'];
        $this->currency = $data['currency'];
        $this->transactionType = $data['transactionType'] ?? 'TRANSFER';
        $this->metadata = $data['metadata'] ?? [];
    }
}

