<?php

namespace App\Services;

/**
 * Bearer tokens for the JSON API (iOS app). A user can hold several tokens
 * at once (one per device); each is a random 64-char hex string shown to
 * the client exactly once at issue time — only its SHA-256 hash is stored,
 * the same way password reset tokens are handled.
 *
 * Table is created lazily on first use, the same way other services
 * (InsuranceService, RateLimiterService) self-migrate.
 */
class ApiTokenService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_name VARCHAR(100) NULL,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_token_hash (token_hash),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Issues a new token for a user; returns the plaintext token (shown once, never stored). */
    public static function issue(\PDO $pdo, int $userId, ?string $deviceName = null): string
    {
        self::ensureTable($pdo);

        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name) VALUES (?, ?, ?)");
        $stmt->execute([$userId, hash('sha256', $token), $deviceName]);

        return $token;
    }

    /** Resolves a bearer token to a user_id, or null if it's invalid/unknown. Touches last_used_at on success. */
    public static function resolve(\PDO $pdo, string $token): ?int
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT id, user_id FROM api_tokens WHERE token_hash = ?");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$row['id']]);

        return (int) $row['user_id'];
    }

    /** Revokes (deletes) the token record matching a plaintext token. */
    public static function revoke(\PDO $pdo, string $token): void
    {
        self::ensureTable($pdo);
        $pdo->prepare("DELETE FROM api_tokens WHERE token_hash = ?")->execute([hash('sha256', $token)]);
    }
}
