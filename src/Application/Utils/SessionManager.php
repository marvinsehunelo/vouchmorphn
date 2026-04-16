<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// APP_LAYER/utils/session_manager.php
namespace APP_LAYER\utils;

/**
 * SessionManager
 * -----------------
 * Secure and centralized session control for PrestagedSWAP.
 */
class SessionManager
{
    /**
     * Start session securely (once).
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }
    }

    /**
     * Store logged-in user data in session.
     */
    public static function setUser(array $userData): void
    {
        self::start();
        $_SESSION['user'] = $userData;
    }

    /**
     * Retrieve current user session data.
     */
    public static function getUser(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    /**
     * Retrieve only username for display.
     */
    public static function getUserName(): string
    {
        $user = self::getUser();
        return $user['username'] ?? $user['name'] ?? 'Unknown';
    }

    /**
     * Retrieve current user role.
     */
    public static function getRole(): ?string
    {
        $user = self::getUser();
        return $user['role'] ?? null;
    }

    /**
     * Check if session user exists.
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        return isset($_SESSION['user']);
    }

    /**
     * Require login before allowing access.
     */
    public static function requireLogin(string $redirectTo = '../auth/admin_login.php'): void
    {
        if (!self::isLoggedIn()) {
            header("Location: {$redirectTo}");
            exit();
        }
    }

    /**
     * End the session completely.
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }
}
