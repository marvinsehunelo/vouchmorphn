<?php
// BUSINESS_LOGIC_LAYER/services/NotificationService.php

namespace BUSINESS_LOGIC_LAYER\Services;

require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/DBConnection.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/repositories/UserRepository.php'; // Assuming a UserRepository exists

use DATA_PERSISTENCE_LAYER\Config\DBConnection;
use DATA_PERSISTENCE_LAYER\Repositories\UserRepository;

/**
 * Handles the broadcasting and management of system notifications.
 */
class NotificationService
{
    private $dbConnection;
    private $userRepository;
    private $dbConfigKey = 'swap'; // Assuming the relevant DB is 'swap_system' (mapped to 'swap' in config)

    public function __construct()
    {
        // Establish connection to the 'swap_system' database
        $this->dbConnection = DBConnection::getInstance($this->dbConfigKey);
        $this->userRepository = new UserRepository($this->dbConnection);
    }

    /**
     * Broadcasts a message to all users.
     * In a real system, this would queue the message for a background push notification worker.
     *
     * @param string $message The notification content.
     * @param string $type The type of notification (e.g., 'global', 'alert', 'info').
     * @return bool True on successful initiation (or simulation), false otherwise.
     */
    public function broadcast(string $message, string $type = 'global'): bool
    {
        if (empty($message)) {
            throw new \InvalidArgumentException("Notification message cannot be empty.");
        }

        // --- 1. Log the Broadcast Event (Audit Trail) ---
        // This is crucial for accountability and history.
        try {
            $logAdminId = $_SESSION['admin_id'] ?? 0; // Get the Admin ID from session
            $this->logBroadcastAction($message, $logAdminId);
        } catch (\Throwable $e) {
            // Log the error but continue, as failing to log shouldn't stop the broadcast attempt
            error_log("Failed to log broadcast action: " . $e->getMessage());
        }

        // --- 2. Retrieve All Active User Tokens for Push Notification ---
        // We'll fetch the device tokens from the 'users' table
        try {
            // Get all user device tokens that are not null
            $usersWithTokens = $this->userRepository->getAllUsersWithDeviceTokens();
            
            // NOTE: In a real system, $usersWithTokens would be passed to a
            // dedicated Push Notification Queue/Worker.
            $count = count($usersWithTokens);

            // --- 3. Simulated Push Notification Logic ---
            // Simulate the push notification process
            if ($count > 0) {
                // $pushService->send($usersWithTokens, $message, $type);
                error_log("NotificationService: Successfully initiated broadcast to {$count} users. Message: " . $message);
                return true;
            } else {
                error_log("NotificationService: Broadcast initiated, but no active users with device tokens found.");
                return true; // Still considered successful if no users exist
            }

        } catch (\Throwable $e) {
            error_log("Notification Service Broadcast Error: " . $e->getMessage());
            // Re-throw for the controller to catch and display an error message
            throw new \Exception("A critical error occurred during the notification broadcast process.");
        }
    }

    /**
     * Logs the broadcast action into the audit_logs table.
     * * @param string $message The message content.
     * @param int $adminId The ID of the admin performing the action.
     */
    private function logBroadcastAction(string $message, int $adminId)
    {
        $pdo = $this->dbConnection->getConnection();
        $sql = "INSERT INTO audit_logs (entity, entity_id, action, category, severity, new_value, performed_by, performed_at)
                VALUES (:entity, :entity_id, :action, :category, :severity, :new_value, :performed_by, NOW())";
        
        $stmt = $pdo->prepare($sql);
        
        // Prepare a truncated message for the log
        $logMessage = substr($message, 0, 255);

        $stmt->execute([
            ':entity'      => 'SYSTEM',
            ':entity_id'   => 0, // Entity ID is 0 for system-wide action
            ':action'      => 'BROADCAST_NOTIFICATION',
            ':category'    => 'ADMIN_ACTION',
            ':severity'    => 'MEDIUM',
            ':new_value'   => "Global message sent: " . $logMessage,
            ':performed_by' => $adminId,
        ]);
    }

    // You could add other methods here, like:
    // public function sendToUser(int $userId, string $message, string $type = 'personal')
    // public function getUnreadCount(int $userId)
}

// NOTE: You must also create the UserRepository.php file 
// to avoid fatal errors when this service is initialized.

?>
