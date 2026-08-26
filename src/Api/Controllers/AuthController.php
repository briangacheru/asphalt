<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Middleware\ApiAuthMiddleware;
use App\Services\ApiTokenService;
use App\Services\RateLimiterService;
use App\Services\SiteSettingsService;

class AuthController
{
    /** POST /auth/login — mirrors auth/login.php's checks (active, verified, maintenance mode). */
    public static function login(\PDO $pdo, array $body): void
    {
        $email = trim($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('email and password are required.', 422);
        }

        $rateLimitKey = 'api-login:' . RateLimiterService::clientIp() . ':' . strtolower($email);
        if (RateLimiterService::tooManyAttempts($pdo, $rateLimitKey, 5, 900)) {
            Response::error('Too many failed login attempts. Please wait 15 minutes and try again.', 429);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            RateLimiterService::recordAttempt($pdo, $rateLimitKey);
            Response::error('Invalid email or password.', 401);
        }

        RateLimiterService::clearAttempts($pdo, $rateLimitKey);

        if (!$user['is_verified']) {
            Response::error('Please verify your email address first.', 403);
        }

        $maintenanceMode = SiteSettingsService::get($pdo, 'maintenance_mode') === '1';
        if ($maintenanceMode && ($user['role'] ?? 'user') !== 'admin') {
            Response::error('The site is currently under maintenance. Please try again later.', 503);
        }

        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        $deviceName = trim($body['device_name'] ?? '') ?: null;
        $token = ApiTokenService::issue($pdo, (int) $user['id'], $deviceName);

        Response::json([
            'token' => $token,
            'user' => self::publicUser($user),
        ]);
    }

    /** POST /auth/logout — revokes the token used on this request. */
    public static function logout(\PDO $pdo): void
    {
        $token = ApiAuthMiddleware::extractToken();
        if ($token) {
            ApiTokenService::revoke($pdo, $token);
        }

        Response::json(['success' => true]);
    }

    /** GET /me */
    public static function me(\PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        Response::json(self::publicUser($user));
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
        ];
    }
}
