<?php

namespace App\Services;

/**
 * Optional expiry tracking for vehicle documents (inspection certificates,
 * road tax/sticker, etc.) — feeds the same header bell/notification system
 * used by insurance and driving licence expiry. The vehicle_documents table
 * itself predates this app's lazy-table-creation convention (it's assumed to
 * already exist), so this only adds the one column it needs, defensively.
 */
class DocumentExpiryService
{
    public const REMINDER_WINDOW_DAYS = 14;

    public static function ensureExpiryColumn(\PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $columnExists = $pdo->query("SHOW COLUMNS FROM vehicle_documents LIKE 'expiry_date'")->fetch();
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE vehicle_documents ADD COLUMN expiry_date DATE NULL AFTER title");
            }
            $checked = true;
        } catch (\PDOException $e) {
            // vehicle_documents itself may not exist on a fresh install that hasn't
            // been through the setup flow that creates it — nothing to do here.
        }
    }

    /**
     * Documents expiring within REMINDER_WINDOW_DAYS days, or already expired,
     * for one user's vehicles — used by the header notification bell.
     */
    public static function documentsNeedingAttention(\PDO $pdo, int $userId): array
    {
        self::ensureExpiryColumn($pdo);

        try {
            $stmt = $pdo->prepare("
                SELECT d.id, d.title, d.category, d.expiry_date, v.id AS vehicle_id, v.make, v.model,
                       DATEDIFF(d.expiry_date, CURDATE()) AS days_remaining
                FROM vehicle_documents d
                JOIN vehicles v ON v.id = d.vehicle_id
                WHERE v.user_id = ? AND v.is_active = 1
                AND d.expiry_date IS NOT NULL
                AND DATEDIFF(d.expiry_date, CURDATE()) <= ?
                ORDER BY d.expiry_date ASC
            ");
            $stmt->execute([$userId, self::REMINDER_WINDOW_DAYS]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }
}
