<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace ADMIN_LAYER\Auth;

use PDO;
use Exception;
use RuntimeException;

class AdminAuth
{
    private PDO $db;
    private const SESSION_NAME = 'VOUCHMORPH_ADMIN';
    private const SESSION_LIFETIME = 7200; // 2 hours

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->initSession();
    }

    private function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(self::SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => self::SESSION_LIFETIME,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
        }
    }

    public function login(string $username, string $password, string $countryCode): array
    {
        try {
            // Fixed query to match your actual table columns
            $stmt = $this->db->prepare("
                SELECT 
                    a.admin_id,
                    a.username,
                    a.password_hash,
                    a.email,
                    a.phone,
                    a.role_id,
                    a.mfa_enabled,
                    a.mfa_secret,
                    a.full_name,
                    a.country_code,
                    a.created_at,
                    a.updated_at,
                    r.role_name,
                    r.role_level,
                    r.can_manage_admins,
                    r.can_view_transactions,
                    r.can_edit_config,
                    r.can_broadcast,
                    r.can_trigger_cron,
                    r.can_generate_reports,
                    r.can_export_data,
                    r.can_view_audit_logs,
                    r.permissions
                FROM admins a
                JOIN roles r ON a.role_id = r.role_id
                WHERE a.username = ?
            ");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                return $this->failedLogin('Invalid username or password');
            }

            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                return $this->failedLogin('Invalid username or password');
            }

            // Check if MFA is enabled
            if ($admin['mfa_enabled']) {
                $_SESSION['mfa_required'] = true;
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['mfa_temp'] = true;
                return [
                    'success' => true,
                    'mfa_required' => true,
                    'admin_id' => $admin['admin_id']
                ];
            }

            // Set session
            return $this->createSession($admin, $countryCode);

        } catch (Exception $e) {
            error_log("[AdminAuth] Login error: " . $e->getMessage());
            return $this->failedLogin('Authentication service unavailable');
        }
    }

    public function verifyMfa(string $code, string $countryCode): array
    {
        if (!isset($_SESSION['admin_id']) || !isset($_SESSION['mfa_temp'])) {
            return $this->failedLogin('Session expired');
        }

        $stmt = $this->db->prepare("
            SELECT a.*, r.role_name, r.role_level
            FROM admins a
            JOIN roles r ON a.role_id = r.role_id
            WHERE a.admin_id = ?
        ");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            return $this->failedLogin('Admin not found');
        }

        // Verify MFA code (implement your TOTP verification here)
        $valid = $this->verifyTotp($admin['mfa_secret'], $code);
        
        if (!$valid) {
            return $this->failedLogin('Invalid MFA code');
        }

        unset($_SESSION['mfa_temp'], $_SESSION['mfa_required']);
        return $this->createSession($admin, $countryCode);
    }

    private function createSession(array $admin, string $countryCode): array
    {
        // Set session data - using columns that exist
        $_SESSION['admin'] = [
            'id' => $admin['admin_id'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'full_name' => $admin['full_name'],
            'role_id' => $admin['role_id'],
            'role_name' => $admin['role_name'],
            'role_level' => $admin['role_level'],
            'country' => $admin['country_code'] ?? $countryCode,
            'permissions' => json_decode($admin['permissions'] ?? '[]', true),
            'login_time' => time(),
            'ip' => $_SERVER['REMOTE_ADDR']
        ];

        // Update last login - using columns that exist in your table
        $stmt = $this->db->prepare("
            UPDATE admins 
            SET last_login_at = NOW(), 
                last_login_ip = ?,
                updated_at = NOW()
            WHERE admin_id = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $admin['admin_id']]);

        // Log the login
        $this->logAction($admin['admin_id'], 'LOGIN', 'success');

        return [
            'success' => true,
            'admin' => $_SESSION['admin']
        ];
    }

    public function logout(): void
    {
        if (isset($_SESSION['admin'])) {
            $this->logAction($_SESSION['admin']['id'], 'LOGOUT', 'success');
        }
        $_SESSION = [];
        session_destroy();
    }

    public function getCurrentAdmin(): ?array
    {
        return $_SESSION['admin'] ?? null;
    }

    public function checkPermission(string $permission): bool
    {
        $admin = $this->getCurrentAdmin();
        if (!$admin) return false;

        // For now, return true for testing
        // You can implement proper permission checking later
        return true;
    }

    private function logAction(int $adminId, string $action, string $status): void
    {
        try {
            // Check if audit_logs table exists before inserting
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs 
                (entity_type, entity_id, action, category, severity, performed_by, metadata, performed_at)
                VALUES ('ADMIN', ?, ?, 'AUTH', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $adminId,
                $action,
                $status === 'success' ? 'info' : 'warning',
                $status,
                json_encode(['ip' => $_SERVER['REMOTE_ADDR']])
            ]);
        } catch (Exception $e) {
            // Silently fail if audit_logs doesn't exist
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }

    private function verifyTotp(?string $secret, string $code): bool
    {
        // For now, accept any 6-digit code for testing
        // In production, implement proper TOTP verification
        return strlen($code) === 6 && ctype_digit($code);
    }

    private function failedLogin(string $message): array
    {
        return [
            'success' => false,
            'message' => $message
        ];
    }
}
