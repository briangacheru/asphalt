<?php

namespace App\Middleware;

/**
 * Authentication middleware for protecting routes
 */
class AuthMiddleware
{
    /**
     * Check if user is authenticated
     */
    public static function check(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            // Store the intended destination
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            
            // Set flash message
            self::setFlashMessage('warning', 'Please log in to continue.');
            
            // Redirect to login
            header('Location: ' . APP_URL . '/auth/login');
            exit;
        }
    }

    /**
     * Check if user is a guest (not logged in)
     */
    public static function checkGuest(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            header('Location: ' . APP_URL . '/');
            exit;
        }
    }

    /**
     * Get current user ID
     */
    public static function getCurrentUserId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Check if the current user has the 'admin' role. Role is cached in the
     * session at login (see auth/login.php); sessions started before roles
     * existed fall back to a one-time DB lookup, cached here for the rest
     * of the session.
     */
    public static function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!self::isLoggedIn()) {
            return false;
        }

        if (!isset($_SESSION['user_role'])) {
            $pdo = \App\Database\Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $_SESSION['user_role'] = $stmt->fetchColumn() ?: 'user';
        }

        return $_SESSION['user_role'] === 'admin';
    }

    /**
     * Require the current user to be an admin; redirects home otherwise.
     */
    public static function requireAdmin(): void
    {
        self::check();

        if (!self::isAdmin()) {
            self::setFlashMessage('danger', 'You do not have permission to access that page.');
            header('Location: ' . APP_URL . '/');
            exit;
        }
    }

    /**
     * Set flash message
     */
    private static function setFlashMessage(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Regenerate CSRF token
     */
    public static function regenerateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
