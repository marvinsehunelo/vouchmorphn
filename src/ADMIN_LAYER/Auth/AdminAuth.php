<?php
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
            // Check admins table first
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
                    a.last_login,
                    a.login_attempts,
                    a.locked_until,
                    a.created_at,
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
                WHERE a.username = ? AND a.status = 'ACTIVE'
            ");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                return $this->failedLogin('Invalid username or password');
            }

            // Check if account is locked
            if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
                return $this->failedLogin('Account locked. Try again later.');
            }

            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                $this->incrementLoginAttempts($admin['admin_id'], $admin['login_attempts'] + 1);
                return $this->failedLogin('Invalid username or password');
            }

            // Check if MFA is required
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
            WHERE a.admin_id = ? AND a.status = 'ACTIVE'
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
        // Reset login attempts
        $this->resetLoginAttempts($admin['admin_id']);

        // Set session data
        $_SESSION['admin'] = [
            'id' => $admin['admin_id'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'role_id' => $admin['role_id'],
            'role_name' => $admin['role_name'],
            'role_level' => $admin['role_level'],
            'permissions' => json_decode($admin['permissions'] ?? '[]', true),
            'country' => $countryCode,
            'login_time' => time(),
            'ip' => $_SERVER['REMOTE_ADDR']
        ];

        // Update last login
        $stmt = $this->db->prepare("
            UPDATE admins 
            SET last_login = NOW(), login_attempts = 0 
            WHERE admin_id = ?
        ");
        $stmt->execute([$admin['admin_id']]);

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

        // Check specific permission flags
        $flagMap = [
            'manage_admins' => 'can_manage_admins',
            'view_transactions' => 'can_view_transactions',
            'edit_config' => 'can_edit_config',
            'broadcast' => 'can_broadcast',
            'trigger_cron' => 'can_trigger_cron',
            'generate_reports' => 'can_generate_reports',
            'export_data' => 'can_export_data',
            'view_audit_logs' => 'can_view_audit_logs'
        ];

        if (isset($flagMap[$permission])) {
            $flag = $flagMap[$permission];
            // Check if this flag exists in session (you'd need to store these)
            if (isset($admin[$flag]) && $admin[$flag]) {
                return true;
            }
        }

        // Check permissions JSON
        return in_array($permission, $admin['permissions'] ?? []);
    }

    private function incrementLoginAttempts(int $adminId, int $attempts): void
    {
        if ($attempts >= 5) {
            // Lock account for 30 minutes
            $stmt = $this->db->prepare("
                UPDATE admins 
                SET login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                WHERE admin_id = ?
            ");
            $stmt->execute([$attempts, $adminId]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE admins SET login_attempts = ? WHERE admin_id = ?
            ");
            $stmt->execute([$attempts, $adminId]);
        }
    }

    private function resetLoginAttempts(int $adminId): void
    {
        $stmt = $this->db->prepare("
            UPDATE admins SET login_attempts = 0, locked_until = NULL WHERE admin_id = ?
        ");
        $stmt->execute([$adminId]);
    }

    private function logAction(int $adminId, string $action, string $status): void
    {
        try {
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
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        // Implement TOTP verification using your preferred library
        // For now, return true for testing
        return true;
    }

    private function failedLogin(string $message): array
    {
        return [
            'success' => false,
            'message' => $message
        ];
    }
}
