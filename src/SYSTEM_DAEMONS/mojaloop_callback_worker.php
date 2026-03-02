<?php
/**
 * Mojaloop Callback Worker Daemon
 * Handles sending asynchronous callbacks to the Testing Toolkit
 */

require_once __DIR__ . '/../bootstrap.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

class MojaloopCallbackWorker
{
    private $db;
    private $ttkCallbackUrl;
    private $maxRetries = 3;
    private $retryDelay = 2; // seconds

   public function __construct()
{
    require_once __DIR__ . '/../bootstrap.php';
    $this->ttkCallbackUrl = $GLOBALS['CALLBACK_URL'] ?? 'http://172.17.0.1:5050/callback';
    $this->db = $GLOBALS['swapService'] ? null : new DBConnection($GLOBALS['dbConfig'] ?? []);
}

    /**
     * Process pending callbacks from queue
     */
    public function processQueue(): void
    {
        while (true) {
            try {
                // Get pending callbacks from database queue
                $pending = $this->getPendingCallbacks();
                
                foreach ($pending as $callback) {
                    $this->sendCallback($callback);
                }
                
                // Sleep to prevent CPU overload
                sleep(1);
                
            } catch (\Exception $e) {
                error_log("Callback worker error: " . $e->getMessage());
                sleep(5);
            }
        }
    }

    /**
     * Send a single callback to TTK
     */
    private function sendCallback(array $callback): bool
    {
        $url = $this->ttkCallbackUrl . $callback['path'];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($callback['payload']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . ($callback['content_type'] ?? 'application/vnd.interoperability.parties+json;version=1.0'),
            'FSPIOP-Source: ' . ($callback['source'] ?? 'VOUCHMORPHN'),
            'FSPIOP-Destination: ' . ($callback['destination'] ?? 'TTK')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Callback failed: $error - URL: $url");
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->markCallbackSent($callback['id']);
            error_log("Callback sent successfully to $url - HTTP $httpCode");
            return true;
        } else {
            error_log("Callback failed with HTTP $httpCode - URL: $url");
            return false;
        }
    }

    /**
     * Get pending callbacks from database
     */
    private function getPendingCallbacks(): array
    {
        // Implement your database logic here
        // This is a placeholder - adjust based on your DB structure
        $stmt = $this->db->prepare("
            SELECT * FROM callback_queue 
            WHERE status = 'pending' 
            AND attempts < :maxRetries
            ORDER BY created_at ASC
            LIMIT 10
        ");
        $stmt->execute([':maxRetries' => $this->maxRetries]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark callback as sent
     */
    private function markCallbackSent(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE callback_queue 
            SET status = 'sent', sent_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }
}

// Run the worker if called directly
if (php_sapi_name() === 'cli') {
    $worker = new MojaloopCallbackWorker();
    echo "Starting Mojaloop Callback Worker...\n";
    echo "Sending callbacks to: " . $worker->ttkCallbackUrl . "\n";
    $worker->processQueue();
}
