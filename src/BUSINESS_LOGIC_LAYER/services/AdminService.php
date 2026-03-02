<?php
namespace BUSINESS_LOGIC_LAYER\services;

use PDO;
use PDOException;
use Exception; // Use base Exception for general errors

class AdminService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // --- ADMIN AUTHENTICATION ---

    /**
     * Admin login handler
     */
    public function login(string $username, string $password): array
{
    try {
        // Updated query to use 'country_code' and 'admin_id'
        $stmt = $this->db->prepare("
            SELECT a.admin_id, a.username, a.password_hash, a.email, a.role_id, a.country_code, r.role_name
            FROM admins a
            LEFT JOIN roles r ON a.role_id = r.role_id
            WHERE a.username = :username OR a.email = :username
            LIMIT 1
        ");
        
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) return ['success' => false, 'message' => 'Admin not found.'];
        
        if (!password_verify($password, $admin['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid password.'];
        }

        unset($admin['password_hash']);

        return [
            'success' => true, 
            'user' => [
                'id'       => $admin['admin_id'],
                'username' => $admin['username'],
                'role'     => $admin['role_name'],
                'country'  => $admin['country_code'] // Mapping database 'country_code' to app 'country'
            ]
        ];

    } catch (PDOException $e) {
        error_log("AdminService Login Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'System error during login.'];
    }
}


    // --- ADMIN MANAGEMENT (CRUD) ---

    /**
     * Create a new admin user.
     * Uses prepared statements with array execute for cleaner code (from the second block).
     */
    public function createAdmin(array $data): bool
    {
        try {
            // Basic required fields check (removed complex role mapping logic, assuming 'role_id' is passed)
            if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
                throw new Exception("Missing required fields (username, email, password).");
            }
            
            $hashedPassword = password_hash($data['password'], VM_HASH_ALGO, VM_HASH_OPTIONS);
            $roleId = $data['role_id'] ?? 2; // Default to 'admin' (ID 2)

            $stmt = $this->db->prepare("
                INSERT INTO admins (username, email, password_hash, role_id, country_code)
                VALUES (:username, :email, :password_hash, :role_id, :country_code)
            ");
            return $stmt->execute([
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':password_hash' => $hashedPassword,
                ':role_id' => $roleId,
                ':phone' => $data['phone'] ?? null,
                ':mfa_enabled' => $data['mfa_enabled'] ?? 0
            ]);
        } catch (\Throwable $e) {
            error_log("AdminService::createAdmin ERROR: ".$e->getMessage());
            return false;
        }
    }

    /**
     * Get all admins with their roles.
     * Includes JOIN to fetch 'role_name' as 'role' (from the second block).
     */
    public function getAllAdmins(): array
    {
        try {
            $sql = "
                SELECT a.admin_id AS id, a.username, a.email, a.phone, a.role_id,
                       r.role_name AS role, a.mfa_enabled, a.created_at, a.updated_at
                FROM admins a
                LEFT JOIN roles r ON a.role_id = r.role_id
                WHERE a.role_id BETWEEN 2 AND 5
                ORDER BY a.created_at DESC
            ";
            $stmt = $this->db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("AdminService::getAllAdmins ERROR: ".$e->getMessage());
            return [];
        }
    }
    
    /**
     * Update an existing admin user's details (excluding password).
     */
    public function updateAdmin(int $id, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE admins
                SET username=:username, email=:email, phone=:phone, role_id=:role_id,
                    mfa_enabled=:mfa_enabled, updated_at=NOW()
                WHERE admin_id=:id
            ");
            return $stmt->execute([
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':phone' => $data['phone'] ?? null,
                ':role_id' => $data['role_id'],
                ':mfa_enabled' => $data['mfa_enabled'] ?? 0,
                ':id' => $id
            ]);
        } catch (\Throwable $e) {
            error_log("AdminService::updateAdmin ERROR: ".$e->getMessage());
            return false;
        }
    }

    /**
     * Delete an admin user by ID.
     */
    public function deleteAdmin(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM admins WHERE admin_id=:id");
            return $stmt->execute([':id'=>$id]);
        } catch (\Throwable $e) {
            error_log("AdminService::deleteAdmin ERROR: ".$e->getMessage());
            return false;
        }
    }

    /**
     * Reset an admin's password with a new one.
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE admins SET password_hash=:hash, updated_at=NOW() WHERE admin_id=:id");
            return $stmt->execute([':hash'=>$hash, ':id'=>$id]);
        } catch (\Throwable $e) {
            error_log("AdminService::resetPassword ERROR: ".$e->getMessage());
            return false;
        }
    }

    /**
     * Fetch all available roles from the 'roles' table for use in dropdowns/forms.
     */
    public function getRoles(): array
    {
        try {
            $stmt = $this->db->query("SELECT role_id, role_name FROM roles ORDER BY role_id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("AdminService::getRoles ERROR: ".$e->getMessage());
            return [];
        }
    }

    // --- DASHBOARD METRICS ---

    /** Total Users Count */
    public function getTotalUsers(): int
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users");
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log("AdminService::getTotalUsers Error: " . $e->getMessage());
            return 0;
        }
    }

    /** Total Transactions Count */
    public function getTotalTransactions(): int
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM transactions");
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log("AdminService::getTotalTransactions Error: " . $e->getMessage());
            return 0;
        }
    }

    /** Wallet Balance (Sum of all admin + system wallets) */
    public function getTotalWalletBalance(): float
    {
        try {
            $stmt = $this->db->query("SELECT SUM(balance) FROM wallets");
            return (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log("AdminService::getTotalWalletBalance Error: " . $e->getMessage());
            return 0.0;
        }
    }
}
