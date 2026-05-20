<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use DateTimeImmutable;

/**
 * MessageRouter - Simple routing between participants
 * 
 * Handles:
 * - Which participant to send messages to
 * - Which corridor to use for cross-border
 * - Retry logic for failed messages
 */
class MessageRouter
{
    private PDO $db;
    private array $participants;
    
    public function __construct(PDO $db, array $participants)
    {
        $this->db = $db;
        $this->participants = $participants;
    }
    
    /**
     * Route a message to the appropriate participant endpoint
     */
    public function route(string $participantId, string $action, array $payload): array
    {
        $participant = $this->getParticipant($participantId);
        
        if (!$participant) {
            throw new \RuntimeException("Participant not found: {$participantId}");
        }
        
        $endpoint = $participant['endpoints'][$action] ?? null;
        
        if (!$endpoint) {
            throw new \RuntimeException("No endpoint for {$action} for participant {$participantId}");
        }
        
        // Log routing decision
        $this->logRoute($participantId, $action, $endpoint);
        
        // Return routing info (actual HTTP call happens in GenericBankClient)
        return [
            'participant_id' => $participantId,
            'participant_name' => $participant['name'] ?? $participantId,
            'endpoint' => $endpoint,
            'payload' => $payload,
            'method' => $participant['method'][$action] ?? 'POST',
            'headers' => $participant['headers'] ?? ['Content-Type: application/json'],
            'timeout' => $participant['timeout'] ?? 30
        ];
    }
    
    /**
     * Determine if cross-border routing is needed
     */
    public function needsCrossBorder(string $sourceInstitution, string $destInstitution): bool
    {
        $sourceCountry = $this->getInstitutionCountry($sourceInstitution);
        $destCountry = $this->getInstitutionCountry($destInstitution);
        
        return $sourceCountry !== $destCountry;
    }
    
    /**
     * Get optimal corridor for cross-border transfer
     */
    public function getOptimalCorridor(string $sourceInstitution, string $destInstitution): ?array
    {
        $sourceCountry = $this->getInstitutionCountry($sourceInstitution);
        $destCountry = $this->getInstitutionCountry($destInstitution);
        
        if ($sourceCountry === $destCountry) {
            return null; // No corridor needed
        }
        
        // Check if direct corridor exists
        $directCorridor = $this->getCorridorConfig($sourceCountry, $destCountry);
        
        if ($directCorridor) {
            return $directCorridor;
        }
        
        // Check if there's a hub country (e.g., route through Kenya)
        $hubCorridor = $this->findHubCorridor($sourceCountry, $destCountry);
        
        return $hubCorridor;
    }
    
    /**
     * Queue a message for retry if delivery fails
     */
    public function queueForRetry(string $messageId, string $participantId, array $payload, string $error): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO message_retry_queue 
            (message_id, participant_id, payload, error, retry_count, created_at)
            VALUES (?, ?, ?::jsonb, ?, 0, NOW())
        ");
        
        $stmt->execute([$messageId, $participantId, json_encode($payload), $error]);
    }
    
    /**
     * Get pending retry messages
     */
    public function getPendingRetries(int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM message_retry_queue 
            WHERE status = 'PENDING' AND retry_count < 5
            ORDER BY created_at ASC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    private function getParticipant(string $participantId): ?array
    {
        $key = strtolower($participantId);
        
        if (isset($this->participants[$key])) {
            return $this->participants[$key];
        }
        
        // Try to find by provider_code
        foreach ($this->participants as $participant) {
            if (strtolower($participant['provider_code'] ?? '') === $key) {
                return $participant;
            }
        }
        
        return null;
    }
    
    private function getInstitutionCountry(string $institution): string
    {
        $participant = $this->getParticipant($institution);
        return $participant['country_code'] ?? 'BW'; // Default to Botswana
    }
    
    private function getCorridorConfig(string $sourceCountry, string $destCountry): ?array
    {
        // Query corridor config from database or cache
        // For now, return null (use dynamic resolution)
        return null;
    }
    
    private function findHubCorridor(string $sourceCountry, string $destCountry): ?array
    {
        // Find a hub country (e.g., Kenya) that connects both
        // This would be configurable per region
        $hubCountries = ['KE', 'ZA', 'NG']; // Kenya, South Africa, Nigeria as hubs
        
        foreach ($hubCountries as $hub) {
            $sourceToHub = $this->getCorridorConfig($sourceCountry, $hub);
            $hubToDest = $this->getCorridorConfig($hub, $destCountry);
            
            if ($sourceToHub && $hubToDest) {
                return [
                    'type' => 'hub',
                    'hub_country' => $hub,
                    'first_leg' => $sourceToHub,
                    'second_leg' => $hubToDest
                ];
            }
        }
        
        return null;
    }
    
    private function logRoute(string $participantId, string $action, string $endpoint): void
    {
        $log = [
            'timestamp' => date('c'),
            'participant' => $participantId,
            'action' => $action,
            'endpoint' => $endpoint
        ];
        
        file_put_contents('/tmp/message_router.log', json_encode($log) . PHP_EOL, FILE_APPEND);
    }
}
