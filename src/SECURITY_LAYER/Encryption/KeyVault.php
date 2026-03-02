<?php
namespace SECURITY_LAYER\Encryption;

use RuntimeException;

class KeyVault
{
    private array $keys = [];

    public function __construct(array $config = [])
    {
        /**
         * Load encryption key from ENV or config
         * NEVER generate keys at runtime for production
         */
        $envKey = getenv('APP_ENCRYPTION_KEY');

        if (!$envKey || strlen($envKey) < 32) {
            throw new RuntimeException(
                'Missing or weak APP_ENCRYPTION_KEY (min 32 bytes)'
            );
        }

        $this->keys['default'] = $envKey;
    }

    /**
     * Primary encryption key (used by TokenEncryptor)
     */
    public function getEncryptionKey(): string
    {
        return $this->keys['default'];
    }

    /**
     * Optional named keys (future use)
     */
    public function getKey(string $name): ?string
    {
        return $this->keys[$name] ?? null;
    }

    /**
     * Rotation hook (manual / admin controlled)
     * NEVER auto-rotate during runtime
     */
    public function rotateKey(string $name, string $newKey): void
    {
        if (strlen($newKey) < 32) {
            throw new RuntimeException('Key too short');
        }

        $this->keys[$name] = $newKey;
    }
}

