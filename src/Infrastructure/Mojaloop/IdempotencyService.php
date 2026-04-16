<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

namespace DFSP_ADAPTER_LAYER;

use PDO;

class IdempotencyService
{
    public static function check(PDO $db, string $key): ?array
    {
        $s = $db->prepare("SELECT response FROM fspiop_idempotency WHERE id=?");
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $r ? json_decode($r, true) : null;
    }

    public static function store(PDO $db, string $key, array $response): void
    {
        $s = $db->prepare("INSERT INTO fspiop_idempotency VALUES (?, ?, NOW())");
        $s->execute([$key, json_encode($response)]);
    }
}

