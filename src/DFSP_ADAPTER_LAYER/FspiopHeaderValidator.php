<?php
declare(strict_types=1);

namespace DFSP_ADAPTER_LAYER;

class FspiopHeaderValidator
{
    public static function validate(): void
    {
        $required = [
            'HTTP_FSPIOP_SOURCE',
            'HTTP_FSPIOP_DESTINATION',
            'HTTP_FSPIOP_SIGNATURE',
            'HTTP_DATE'
        ];

        foreach ($required as $h) {
            if (!isset($_SERVER[$h])) {
                self::reject("Missing header: $h");
            }
        }

        // Reject replay attacks (>60 seconds old)
        $date = strtotime($_SERVER['HTTP_DATE']);
        if (abs(time() - $date) > 60) {
            self::reject("Expired request");
        }
    }

    private static function reject(string $msg): void
    {
        http_response_code(400);
        echo json_encode([
            'errorInformation' => [
                'errorCode' => '3100',
                'errorDescription' => $msg
            ]
        ]);
        exit;
    }
}

