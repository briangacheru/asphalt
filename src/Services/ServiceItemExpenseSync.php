<?php

namespace App\Services;

use PDO;

/**
 * Mirrors service_items rows (parts changed during a service) into the
 * expenses table so they show up in the unified spend view and Parts
 * Longevity tracking, without double-counting: the mirrored row is tagged
 * via expenses.service_item_id, and reports.php excludes tagged rows from
 * its combined service+expense totals since service_records.service_cost
 * already accounts for that money.
 *
 * One-directional and idempotent, same pattern as PartMaintenanceSyncService.
 */
class ServiceItemExpenseSync
{
    public const CATEGORY_NAME = 'Service Parts';
    public const CATEGORY_ICON = 'fa-wrench';

    /**
     * Create/update/remove the expense row mirroring a service_items row.
     *
     * @param array $serviceItem service_items row: id, item_name, brand, part_number, quantity, cost, notes
     * @param array $service     parent service_records row: vehicle_id, service_date, mileage
     */
    public static function upsert(PDO $pdo, array $serviceItem, array $service, string $itemLabel, ?int $itemTypeId = null): void
    {
        $cost = (float) $serviceItem['cost'];
        $quantity = (int) $serviceItem['quantity'];

        if ($cost <= 0) {
            self::remove($pdo, (int) $serviceItem['id']);
            return;
        }

        $categoryId = self::ensureCategory($pdo);
        $itemTypeId = $itemTypeId ?? self::resolveItemTypeId($pdo, $itemLabel);
        $amount = $cost * max(1, $quantity);

        $existing = $pdo->prepare("SELECT id FROM expenses WHERE service_item_id = ?");
        $existing->execute([(int) $serviceItem['id']]);
        $existingId = $existing->fetchColumn();

        $params = [
            $service['vehicle_id'],
            $categoryId,
            $service['service_date'],
            $amount,
            $itemLabel,
            $itemTypeId,
            $serviceItem['item_name'] ?: null,
            $serviceItem['brand'] ?: null,
            $serviceItem['part_number'] ?: null,
            $quantity ?: null,
            $cost ?: null,
            $serviceItem['notes'] ?: null,
            $service['mileage'] ?? null,
        ];

        if ($existingId) {
            $stmt = $pdo->prepare("
                UPDATE expenses
                SET vehicle_id = ?, category_id = ?, expense_date = ?, amount = ?, description = ?,
                    item_type_id = ?, item_name = ?, brand = ?, part_number = ?, quantity = ?,
                    cost_per_unit = ?, item_notes = ?, mileage = ?
                WHERE id = ?
            ");
            $stmt->execute(array_merge($params, [$existingId]));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO expenses (
                    vehicle_id, category_id, expense_date, amount, description,
                    item_type_id, item_name, brand, part_number, quantity,
                    cost_per_unit, item_notes, mileage, service_item_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array_merge($params, [(int) $serviceItem['id']]));
        }
    }

    public static function remove(PDO $pdo, int $serviceItemId): void
    {
        $pdo->prepare("DELETE FROM expenses WHERE service_item_id = ?")->execute([$serviceItemId]);
    }

    private static function ensureCategory(PDO $pdo): int
    {
        $stmt = $pdo->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([self::CATEGORY_NAME]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $pdo->prepare("INSERT INTO expense_categories (name, icon) VALUES (?, ?)")
            ->execute([self::CATEGORY_NAME, self::CATEGORY_ICON]);
        return (int) $pdo->lastInsertId();
    }

    private static function resolveItemTypeId(PDO $pdo, string $label): ?int
    {
        $stmt = $pdo->prepare("SELECT id FROM item_types WHERE LOWER(name) = LOWER(?)");
        $stmt->execute([$label]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }
}
