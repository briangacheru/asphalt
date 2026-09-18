<?php

namespace App\Services;

/**
 * Browser push subscriptions (one row per device/browser a user has enabled
 * notifications on). Table is created lazily on first use, same pattern as
 * SiteSettingsService/InsuranceService. Endpoints are the unique identity —
 * a device re-subscribing (e.g. after clearing storage) just upserts.
 */
class PushSubscriptionService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_endpoint (user_id, endpoint(191)),
            INDEX idx_push_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function save(\PDO $pdo, int $userId, string $endpoint, string $p256dh, string $authToken, ?string $userAgent): void
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_token, user_agent)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth_token = VALUES(auth_token), user_agent = VALUES(user_agent)
        ");
        $stmt->execute([$userId, $endpoint, $p256dh, $authToken, $userAgent]);
    }

    public static function delete(\PDO $pdo, int $userId, string $endpoint): void
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
        $stmt->execute([$userId, $endpoint]);
    }

    /** Remove a subscription by endpoint only — used when the push service reports it's gone (410/404). */
    public static function deleteByEndpoint(\PDO $pdo, string $endpoint): void
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $stmt->execute([$endpoint]);
    }

    public static function forUser(\PDO $pdo, int $userId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }
}
