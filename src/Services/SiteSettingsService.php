<?php

namespace App\Services;

/**
 * Small key/value store for app-wide settings that aren't tied to a single
 * user — e.g. the login page background, which is shown before anyone is
 * authenticated. Table is created lazily on first use (cheap no-op once it
 * exists), the same way header.php lazily migrates other tables.
 */
class SiteSettingsService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function get(\PDO $pdo, string $key): ?string
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null && $value !== '' ? $value : null;
    }

    public static function set(\PDO $pdo, string $key, string $value, ?int $updatedBy = null): void
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([$key, $value, $updatedBy]);
    }

    public static function delete(\PDO $pdo, string $key): void
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("DELETE FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
    }
}
