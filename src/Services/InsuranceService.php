<?php

namespace App\Services;

/**
 * Insurance policies for a vehicle. A vehicle can accumulate multiple policy
 * rows over time (renewals); the "current" one is always the row with the
 * furthest-out expiry_date, so adding a fresh renewal automatically
 * supersedes an expiring/expired policy and clears any alert for it.
 *
 * Table is created lazily on first use, the same way other services
 * (SiteSettingsService, EmailVerificationService) self-migrate.
 */
class InsuranceService
{
    // Sticky/email alerts start this many days before expiry, and keep
    // firing every day afterwards (no upper bound) until a newer policy
    // with a later expiry_date is added for the vehicle.
    public const REMINDER_WINDOW_DAYS = 14;

    public const COVERAGE_TYPES = [
        'comprehensive' => 'Comprehensive',
        'third_party' => 'Third Party',
        'third_party_fire_theft' => 'Third Party, Fire & Theft',
    ];

    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_insurance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            provider VARCHAR(150) NOT NULL,
            policy_number VARCHAR(100) NULL,
            coverage_type VARCHAR(50) NOT NULL DEFAULT 'comprehensive',
            premium_amount DECIMAL(10,2) NULL,
            start_date DATE NULL,
            expiry_date DATE NOT NULL,
            sticker_file_name VARCHAR(255) NULL,
            sticker_file_path VARCHAR(255) NULL,
            sticker_file_type VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vehicle_expiry (vehicle_id, expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** The current (latest-expiry) policy for a vehicle, or null if none on file. */
    public static function current(\PDO $pdo, int $vehicleId): ?array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT * FROM vehicle_insurance WHERE vehicle_id = ? ORDER BY expiry_date DESC, id DESC LIMIT 1");
        $stmt->execute([$vehicleId]);

        $row = $stmt->fetch();
        return $row ? self::withStatus($row) : null;
    }

    /** Full policy history for a vehicle, most recent expiry first. */
    public static function history(\PDO $pdo, int $vehicleId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT * FROM vehicle_insurance WHERE vehicle_id = ? ORDER BY expiry_date DESC, id DESC");
        $stmt->execute([$vehicleId]);

        return $stmt->fetchAll();
    }

    /**
     * The current policy (if any) for every active vehicle a user owns, each
     * tagged with a 'status' of 'expired' | 'expiring' | 'ok' | 'none' and a
     * signed 'days_remaining'. Used for the sidebar badge, the sticky banner,
     * and the insurance overview page.
     */
    public static function statusForUser(\PDO $pdo, int $userId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("
            SELECT v.id AS vehicle_id, v.make, v.model, v.year,
                   vi.id AS insurance_id, vi.provider, vi.policy_number, vi.coverage_type,
                   vi.premium_amount, vi.start_date, vi.expiry_date,
                   vi.sticker_file_name, vi.sticker_file_path, vi.sticker_file_type
            FROM vehicles v
            LEFT JOIN vehicle_insurance vi ON vi.id = (
                SELECT id FROM vehicle_insurance WHERE vehicle_id = v.id ORDER BY expiry_date DESC, id DESC LIMIT 1
            )
            WHERE v.user_id = ? AND v.is_active = 1
            ORDER BY v.make, v.model
        ");
        $stmt->execute([$userId]);

        return array_map([self::class, 'withStatus'], $stmt->fetchAll());
    }

    /** Same as statusForUser() but across every active vehicle/owner — used by the cron job. */
    public static function statusForAllVehicles(\PDO $pdo): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->query("
            SELECT v.id AS vehicle_id, v.make, v.model, v.year,
                   u.id AS user_id, u.email, u.first_name, u.email_notifications_enabled,
                   vi.id AS insurance_id, vi.provider, vi.policy_number, vi.coverage_type,
                   vi.premium_amount, vi.start_date, vi.expiry_date,
                   vi.sticker_file_name, vi.sticker_file_path, vi.sticker_file_type
            FROM vehicles v
            JOIN users u ON v.user_id = u.id
            LEFT JOIN vehicle_insurance vi ON vi.id = (
                SELECT id FROM vehicle_insurance WHERE vehicle_id = v.id ORDER BY expiry_date DESC, id DESC LIMIT 1
            )
            WHERE v.is_active = 1
        ");

        return array_map([self::class, 'withStatus'], $stmt->fetchAll());
    }

    /** Vehicles needing sticky-banner/email attention: expiring within the window, or already expired. */
    public static function vehiclesNeedingAttention(\PDO $pdo, int $userId): array
    {
        return array_values(array_filter(
            self::statusForUser($pdo, $userId),
            fn($v) => in_array($v['status'], ['expiring', 'expired'], true)
        ));
    }

    private static function withStatus(array $row): array
    {
        if (empty($row['expiry_date'])) {
            $row['status'] = 'none';
            $row['days_remaining'] = null;
            return $row;
        }

        $daysRemaining = (int) floor((strtotime($row['expiry_date']) - strtotime('today')) / 86400);

        $row['days_remaining'] = $daysRemaining;
        if ($daysRemaining < 0) {
            $row['status'] = 'expired';
        } elseif ($daysRemaining <= self::REMINDER_WINDOW_DAYS) {
            $row['status'] = 'expiring';
        } else {
            $row['status'] = 'ok';
        }

        return $row;
    }
}
