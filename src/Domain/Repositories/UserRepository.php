<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// DATA_PERSISTENCE_LAYER/repositories/UserRepository.php

namespace DATA_PERSISTENCE_LAYER\Repositories;

/**
 * UserRepository
 * * Handles all database operations specifically related to the 'users' table.
 */
class UserRepository
{
    private $dbConnection;

    /**
     * Constructor
     * * @param DBConnection $dbConnection An instance of the database connection wrapper.
     */
    public function __construct($dbConnection)
    {
        // $dbConnection should be an instance of DATA_PERSISTENCE_LAYER\Config\DBConnection
        $this->dbConnection = $dbConnection;
    }

    /**
     * Retrieves a user record by their unique user_id.
     * * @param int $userId The ID of the user to retrieve.
     * @return array|false The user record as an associative array, or false if not found.
     */
    public function findById(int $userId): array|false
    {
        $pdo = $this->dbConnection->getConnection();
        $sql = "SELECT user_id, username, email, phone, verified, role_id, kyc_verified, mfa_enabled, created_at
                FROM users 
                WHERE user_id = :user_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Retrieves all user IDs and device tokens for users that have a token set.
     * This is used primarily by the NotificationService for broadcasting.
     * * @return array A list of user records containing user_id and device_token_hash.
     */
    public function getAllUsersWithDeviceTokens(): array
    {
        $pdo = $this->dbConnection->getConnection();
        
        // Select user_id and device_token_hash where a token is present
        $sql = "SELECT user_id, device_token_hash 
                FROM users 
                WHERE device_token_hash IS NOT NULL AND device_token_hash != ''";
        
        $stmt = $pdo->query($sql);
        // Ensure no exception is thrown if the query fails (handled by DBConnection wrapper)
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Updates the KYC verification status for a specific user.
     * * @param int $userId The ID of the user to update.
     * @param bool $isVerified The new verification status (true/false).
     * @return bool True on success, false on failure.
     */
    public function updateKycStatus(int $userId, bool $isVerified): bool
    {
        $pdo = $this->dbConnection->getConnection();
        $sql = "UPDATE users SET kyc_verified = :kyc_verified, updated_at = NOW() WHERE user_id = :user_id";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':kyc_verified' => $isVerified ? 1 : 0,
            ':user_id'      => $userId
        ]);
    }

    // --- Other essential repository methods would be added here ---
    
    /**
     * Retrieves a paginated list of all users.
     * * @param int $limit Max number of records to return.
     * @param int $offset Starting position for the records.
     * @return array List of user records.
     */
    public function findAll(int $limit = 20, int $offset = 0): array
    {
        $pdo = $this->dbConnection->getConnection();
        $sql = "SELECT user_id, username, email, phone, kyc_verified, mfa_enabled, created_at 
                FROM users 
                ORDER BY user_id DESC 
                LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        // Use bindValue for LIMIT/OFFSET as they must be treated as integers
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
?>