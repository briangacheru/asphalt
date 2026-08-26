<?php

namespace App\Services;

/**
 * Driving licence on file for a user. A user can accumulate multiple rows
 * over time (renewals); the "current" one is always the row with the
 * furthest-out expiry_date, so recording a fresh renewal automatically
 * supersedes an expiring/expired licence and clears any alert for it.
 *
 * Table is created lazily on first use, the same way other services
 * (InsuranceService, SiteSettingsService) self-migrate.
 */
class DrivingLicenseService
{
    // Sticky/email alerts start this many days before expiry, and keep
    // firing every day afterwards (no upper bound) until a newer licence
    // with a later expiry_date is added for the user.
    public const REMINDER_WINDOW_DAYS = 60;

    // NTSA (Kenya) driving licence vehicle categories.
    public const CATEGORIES = [
        'A'  => 'Motorcycle',
        'A1' => 'Motorcycle (under 125cc)',
        'B1' => 'Tricycle',
        'B2' => 'Light Vehicle (Personal)',
        'B3' => 'Light Vehicle (For Hire)',
        'C'  => 'Heavy Goods Vehicle',
        'C1' => 'Medium Goods Vehicle',
        'D1' => 'Light Bus (up to 25 seats)',
        'D2' => 'Medium Bus (26-33 seats)',
        'D3' => 'Heavy Bus (over 33 seats)',
        'E'  => 'Articulated Heavy Vehicle',
        'F'  => 'Agricultural/Special Vehicle',
        'G'  => 'Road Roller',
        'J'  => 'Forklift',
    ];

    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS driving_licenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            surname VARCHAR(100) NOT NULL,
            other_names VARCHAR(150) NOT NULL,
            national_id VARCHAR(50) NULL,
            license_number VARCHAR(50) NOT NULL,
            date_of_birth DATE NULL,
            sex VARCHAR(20) NULL,
            blood_group VARCHAR(10) NULL,
            county_of_residence VARCHAR(100) NULL,
            serial_number VARCHAR(50) NULL,
            categories VARCHAR(150) NULL,
            issue_date DATE NULL,
            expiry_date DATE NOT NULL,
            scan_file_name VARCHAR(255) NULL,
            scan_file_path VARCHAR(255) NULL,
            scan_file_type VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_expiry (user_id, expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Safety net for installs where the table was created before the
        // categories column existed. "ADD COLUMN IF NOT EXISTS" isn't
        // supported on every MySQL version (only MySQL 8.0.29+ / MariaDB
        // 10.0+), so check via SHOW COLUMNS instead, which works everywhere.
        $columnExists = $pdo->query("SHOW COLUMNS FROM driving_licenses LIKE 'categories'")->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE driving_licenses ADD COLUMN categories VARCHAR(150) NULL AFTER serial_number");
        }
    }

    /** The current (latest-expiry) licence for a user, or null if none on file. */
    public static function current(\PDO $pdo, int $userId): ?array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT * FROM driving_licenses WHERE user_id = ? ORDER BY expiry_date DESC, id DESC LIMIT 1");
        $stmt->execute([$userId]);

        $row = $stmt->fetch();
        return $row ? self::withStatus($row) : null;
    }

    /** Full licence history for a user, most recent expiry first. */
    public static function history(\PDO $pdo, int $userId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("SELECT * FROM driving_licenses WHERE user_id = ? ORDER BY expiry_date DESC, id DESC");
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * The current licence for a user, tagged with a 'status' of
     * 'expired' | 'expiring' | 'ok' | 'none' and a signed 'days_remaining'.
     * Used for the sidebar badge, the sticky banner, and the licence page.
     */
    public static function statusForUser(\PDO $pdo, int $userId): array
    {
        return self::current($pdo, $userId) ?? ['status' => 'none', 'days_remaining' => null];
    }

    /** True if the user's licence needs sticky-banner/email attention. */
    public static function needsAttention(\PDO $pdo, int $userId): bool
    {
        return in_array(self::statusForUser($pdo, $userId)['status'], ['expiring', 'expired'], true);
    }

    /** The current licence (if any) for every active user — used by the cron job. */
    public static function statusForAllUsers(\PDO $pdo): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->query("
            SELECT u.id AS user_id, u.email, u.first_name, u.email_notifications_enabled,
                   dl.id AS license_id, dl.license_number, dl.surname, dl.other_names,
                   dl.issue_date, dl.expiry_date,
                   dl.scan_file_name, dl.scan_file_path, dl.scan_file_type
            FROM users u
            LEFT JOIN driving_licenses dl ON dl.id = (
                SELECT id FROM driving_licenses WHERE user_id = u.id ORDER BY expiry_date DESC, id DESC LIMIT 1
            )
            WHERE u.is_active = 1
        ");

        return array_map([self::class, 'withStatus'], $stmt->fetchAll());
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
