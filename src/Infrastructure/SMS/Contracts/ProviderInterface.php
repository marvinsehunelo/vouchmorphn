<?php

namespace Infrastructure\SMS\Contracts;

interface CommunicationProviderInterface
{
    /**
     * Send an SMS
     *
     * @param string $phone
     * @param string $message
     * @return array ['success'=>bool, 'message'=>string, ...]
     */
    public function sendSMS(string $phone, string $message): array;

    /**
     * Optional: send a USSD / start session
     *
     * @param array|null $payload
     * @return array
     */
    public function sendUSSD(?array $payload = null): array;
}
