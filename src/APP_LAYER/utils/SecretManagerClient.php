<?php
class SecretManagerClient {
    public static function get($key) {
        // placeholder - integrate with Vault/KMS
        return getenv($key) ?: null;
    }
}
