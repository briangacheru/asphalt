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
