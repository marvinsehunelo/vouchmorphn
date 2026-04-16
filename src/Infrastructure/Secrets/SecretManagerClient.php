<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class SecretManagerClient {
    public static function get($key) {
        // placeholder - integrate with Vault/KMS
        return getenv($key) ?: null;
    }
}
