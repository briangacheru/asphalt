<?php

namespace App\Services;

/**
 * Admin-managed catalog of replaceable part types (Tires, Oil Filter, Battery, ...),
 * used by the expense Item Details section and the Parts Longevity report card.
 * Table is created and seeded lazily on first use, same pattern as SiteSettingsService.
 */
class ItemTypeService
{
    private static array $defaultSeed = [
        'Oil Filter'           => ['km' => 8000,   'months' => 6],
        'Cabin Filter'         => ['km' => 15000,  'months' => 12],
        'Air Filter'           => ['km' => 20000,  'months' => 12],
        'Front Brake Pads'     => ['km' => 40000,  'months' => 36],
        'Rear Brake Pads'      => ['km' => 50000,  'months' => 48],
        'Spark Plugs'          => ['km' => 40000,  'months' => 36],
        'Coolant'              => ['km' => 40000,  'months' => 24],
        'Transmission Fluid'   => ['km' => 60000,  'months' => 36],
        'Brake Fluid'          => ['km' => 40000,  'months' => 24],
        'Power Steering Fluid' => ['km' => 60000,  'months' => 36],
        'Timing Belt'          => ['km' => 100000, 'months' => 60],
        'Serpentine Belt'      => ['km' => 80000,  'months' => 48],
        'Battery'              => ['km' => null,   'months' => 48],
        'Tires'                => ['km' => 45000,  'months' => 60],
        'Wipers'               => ['km' => null,   'months' => 12],
        'Other'                => ['km' => null,   'months' => null],
    ];

    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            km_interval INT NULL,
            months_interval INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int) $pdo->query("SELECT COUNT(*) FROM item_types")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("INSERT INTO item_types (name, km_interval, months_interval) VALUES (?, ?, ?)");
            foreach (self::$defaultSeed as $name => $interval) {
                $stmt->execute([$name, $interval['km'], $interval['months']]);
            }
        }
    }

    /** All item types, ordered by name. Each row: id, name, km_interval, months_interval, created_at. */
    public static function all(\PDO $pdo): array
    {
        self::ensureTable($pdo);
        return $pdo->query("SELECT * FROM item_types ORDER BY name")->fetchAll();
    }
}
