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

    /** PUT /me — mirrors settings.php's update_profile/update_email_settings/update_mileage_reminder/update_preferences actions, combined into one partial update. */
    public static function updateMe(\PDO $pdo, int $userId, array $body): void
    {
        $fields = [];
        $params = [];

        if (array_key_exists('first_name', $body)) {
            $fields[] = 'first_name = ?';
            $params[] = trim((string) $body['first_name']);
        }
        if (array_key_exists('last_name', $body)) {
            $fields[] = 'last_name = ?';
            $params[] = trim((string) $body['last_name']) ?: null;
        }
        if (array_key_exists('phone', $body)) {
            $fields[] = 'phone = ?';
            $params[] = trim((string) $body['phone']) ?: null;
        }
        if (array_key_exists('email', $body)) {
            $email = trim((string) $body['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('A valid email is required.', 422);
            }
            $fields[] = 'email = ?';
            $params[] = $email;
        }
        if (array_key_exists('email_notifications_enabled', $body)) {
            $fields[] = 'email_notifications_enabled = ?';
            $params[] = $body['email_notifications_enabled'] ? 1 : 0;
        }
        if (array_key_exists('email_frequency', $body)) {
            if (!in_array($body['email_frequency'], ['all', 'important', 'critical'], true)) {
                Response::error('email_frequency must be one of: all, important, critical.', 422);
            }
            $fields[] = 'email_frequency = ?';
            $params[] = $body['email_frequency'];
        }
        if (array_key_exists('mileage_reminder_enabled', $body)) {
            $fields[] = 'mileage_reminder_enabled = ?';
            $params[] = $body['mileage_reminder_enabled'] ? 1 : 0;
        }
        if (array_key_exists('default_currency', $body)) {
            if (!in_array($body['default_currency'], ['USD', 'KES', 'EUR', 'GBP', 'CAD', 'AUD'], true)) {
                Response::error('default_currency must be one of: USD, KES, EUR, GBP, CAD, AUD.', 422);
            }
            $fields[] = 'default_currency = ?';
            $params[] = $body['default_currency'];
        }
        if (array_key_exists('default_distance_unit', $body)) {
            if (!in_array($body['default_distance_unit'], ['km', 'mi'], true)) {
                Response::error('default_distance_unit must be km or mi.', 422);
            }
            $fields[] = 'default_distance_unit = ?';
            $params[] = $body['default_distance_unit'];
        }
        if (array_key_exists('default_volume_unit', $body)) {
            if (!in_array($body['default_volume_unit'], ['L', 'gal'], true)) {
                Response::error('default_volume_unit must be L or gal.', 422);
            }
            $fields[] = 'default_volume_unit = ?';
            $params[] = $body['default_volume_unit'];
        }
        if (array_key_exists('timezone', $body)) {
            if (!in_array($body['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                Response::error('timezone must be a valid IANA timezone identifier.', 422);
            }
            $fields[] = 'timezone = ?';
            $params[] = $body['timezone'];
        }

        if (!empty($fields)) {
            $params[] = $userId;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        Response::json(self::publicUser($stmt->fetch()));
    }

    /** POST /me/change-password — mirrors settings.php's change_password action. */
    public static function changePassword(\PDO $pdo, int $userId, array $body): void
    {
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            Response::error('New password must be at least 6 characters long.', 422);
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            Response::error('Current password is incorrect.', 422);
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $userId]);

        Response::json(['success' => true]);
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'email_notifications_enabled' => (bool) ($user['email_notifications_enabled'] ?? true),
            'email_frequency' => $user['email_frequency'] ?? 'all',
            'mileage_reminder_enabled' => (bool) ($user['mileage_reminder_enabled'] ?? true),
            'default_currency' => $user['default_currency'] ?? 'USD',
            'default_distance_unit' => $user['default_distance_unit'] ?? 'km',
            'default_volume_unit' => $user['default_volume_unit'] ?? 'L',
            'timezone' => $user['timezone'] ?? 'UTC',
        ];
    }
}
