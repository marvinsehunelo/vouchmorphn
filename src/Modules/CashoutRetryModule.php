<?php
declare(strict_types=1);

namespace Modules;

use PDO;
use Domain\Services\FeeService;

/**
 * CashoutRetryModule - Handles failed cashout retries
 * 
 * Features:
 * - Tracks retry attempts per client
 * - First retry is FREE (no swap-on-swap fee)
 * - Subsequent retries charged
 * - Stores failure details in JSONB
 */
class CashoutRetryModule
{
    private PDO $db;
    private FeeService $feeService;
    
    public function __construct(PDO $db, FeeService $feeService)
    {
        $this->db = $db;
        $this->feeService = $feeService;
        $this->ensureTablesExist();
    }
    
    private function ensureTablesExist(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cashout_retry_tracking (
                id BIGSERIAL PRIMARY KEY,
                client_identifier VARCHAR(100) NOT NULL,
                original_swap_ref VARCHAR(100) NOT NULL,
                retry_count INT DEFAULT 0,
                free_retry_used BOOLEAN DEFAULT FALSE,
                last_error TEXT,
                metadata JSONB DEFAULT '{}'::jsonb,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(client_identifier, original_swap_ref)
            )
        ");
        
        // Add columns to swap_requests if not exists (handled by migration)
    }
    
    /**
     * Get retry information for a client and swap
     */
    public function getRetryInfo(string $clientIdentifier, string $originalSwapRef): array
    {
        $stmt = $this->db->prepare("
            SELECT retry_count, free_retry_used, last_error, metadata
            FROM cashout_retry_tracking 
            WHERE client_identifier = ? AND original_swap_ref = ?
        ");
        $stmt->execute([$clientIdentifier, $originalSwapRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: [
            'retry_count' => 0,
            'free_retry_used' => false,
            'last_error' => null,
            'metadata' => []
        ];
    }
    
    /**
     * Record a failed cashout attempt
     */
    public function recordFailedCashout(
        string $clientIdentifier, 
        string $swapRef, 
        array $payload, 
        string $error,
        array $feeBreakdown = []
    ): void {
        // Get existing record
        $existing = $this->getRetryInfo($clientIdentifier, $swapRef);
        
        $newRetryCount = $existing['retry_count'] + 1;
        
        // Insert or update
        $stmt = $this->db->prepare("
            INSERT INTO cashout_retry_tracking 
            (client_identifier, original_swap_ref, retry_count, free_retry_used, last_error, metadata, updated_at)
            VALUES (?, ?, ?, ?, ?, ?::jsonb, NOW())
            ON CONFLICT (client_identifier, original_swap_ref) 
            DO UPDATE SET 
                retry_count = EXCLUDED.retry_count,
                last_error = EXCLUDED.last_error,
                metadata = cashout_retry_tracking.metadata || EXCLUDED.metadata,
                updated_at = NOW()
        ");
        
        $metadata = array_merge($feeBreakdown, [
            'last_attempt_at' => date('c'),
            'attempts' => $newRetryCount,
            'original_payload' => $payload
        ]);
        
        $stmt->execute([
            $clientIdentifier,
            $swapRef,
            $newRetryCount,
            $existing['free_retry_used'],
            $error,
            json_encode($metadata)
        ]);
        
        // Update swap_requests with retry info
        $stmt = $this->db->prepare("
            UPDATE swap_requests 
            SET retry_count = ?,
                metadata = metadata || ?::jsonb
            WHERE swap_uuid = ?
        ");
        
        $stmt->execute([
            $newRetryCount,
            json_encode(['last_failure' => $error, 'failed_at' => date('c')]),
            $swapRef
        ]);
    }
    
    /**
     * Mark that a free retry has been used
     */
    public function markFreeRetryUsed(string $clientIdentifier, string $originalSwapRef): void
    {
        $stmt = $this->db->prepare("
            UPDATE cashout_retry_tracking 
            SET free_retry_used = TRUE, updated_at = NOW()
            WHERE client_identifier = ? AND original_swap_ref = ?
        ");
        $stmt->execute([$clientIdentifier, $originalSwapRef]);
    }
    
    /**
     * Check if client is eligible for free retry
     */
    public function isEligibleForFreeRetry(string $clientIdentifier, string $originalSwapRef): bool
    {
        $info = $this->getRetryInfo($clientIdentifier, $originalSwapRef);
        
        // Eligible if: retry_count >= 1 (has failed before) AND free_retry_used == false
        return $info['retry_count'] >= 1 && !$info['free_retry_used'];
    }
    
    /**
     * Prepare a retry payload with new destination
     */
    public function prepareRetryPayload(
        string $originalSwapRef,
        string $newDestinationInstitution,
        array $newDestinationDetails
    ): array {
        // Get original swap details
        $stmt = $this->db->prepare("
            SELECT source_details, destination_details, amount, source_currency
            FROM swap_requests 
            WHERE swap_uuid = ?
        ");
        $stmt->execute([$originalSwapRef]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$original) {
            throw new \RuntimeException("Original swap not found: {$originalSwapRef}");
        }
        
        $sourceDetails = json_decode($original['source_details'], true);
        $oldDestination = json_decode($original['destination_details'], true);
        
        // Build new payload with same source, new destination
        return [
            'source' => $sourceDetails,
            'destination' => [
                'institution' => $newDestinationInstitution,
                'delivery_mode' => 'cashout',
                'cashout' => $newDestinationDetails,
                'currency' => $oldDestination['currency'] ?? $original['source_currency'] ?? 'BWP'
            ],
            'original_swap_reference' => $originalSwapRef,
            'is_retry' => true,
            'retry_metadata' => [
                'original_amount' => $original['amount'],
                'original_destination' => $oldDestination['institution'] ?? null
            ]
        ];
    }
    
    /**
     * Get retry statistics for a client
     */
    public function getClientRetryStats(string $clientIdentifier): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_failures,
                SUM(CASE WHEN free_retry_used THEN 1 ELSE 0 END) as free_retries_used,
                AVG(retry_count) as avg_retries
            FROM cashout_retry_tracking
            WHERE client_identifier = ?
        ");
        $stmt->execute([$clientIdentifier]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'client_identifier' => $clientIdentifier,
            'total_failures' => (int)($stats['total_failures'] ?? 0),
            'free_retries_used' => (int)($stats['free_retries_used'] ?? 0),
            'avg_retries_per_failure' => round((float)($stats['avg_retries'] ?? 0), 2)
        ];
    }
}
