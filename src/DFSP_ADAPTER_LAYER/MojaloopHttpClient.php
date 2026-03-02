<?php
namespace DFSP_ADAPTER_LAYER;

class MojaloopHttpClient
{
    private string $baseUrl;
    private string $fspId;

    public function __construct(array $config)
    {
        $this->baseUrl = "{$config['scheme']}://{$config['host']}:{$config['port']}";
        $this->fspId   = $config['fspid'];
    }

    private function send(string $method, string $path, array $body, string $resourceType): void
    {
        $url = $this->baseUrl . $path;

        // MANDATORY: TTK assertions look for version 1.0 specifically
        $contentType = "application/vnd.interoperability.{$resourceType}+json;version=1.0";

        $headers = [
            "Content-Type: $contentType",
            "Accept: $contentType",
            "FSPIOP-Source: {$this->fspId}",
            "FSPIOP-Destination: VOUCHMORPHN", // TTK likes this header set
            "Date: " . gmdate('D, d M Y H:i:s') . " GMT"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    public function putParties(string $type, string $id, array $body): void
    {
        $this->send('PUT', "/parties/$type/$id", $body, 'parties');
    }

    public function putQuotes(string $quoteId, array $body): void
    {
        $this->send('PUT', "/quotes/$quoteId", $body, 'quotes');
    }

    public function putTransfers(string $transferId, array $body): void
    {
        $this->send('PUT', "/transfers/$transferId", $body, 'transfers');
    }
}
