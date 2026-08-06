<?php

namespace App\Services;

/**
 * Keeps maintenance_schedule in sync with part-tagged expenses, so the existing
 * cron/maintenance-reminder-cron.php alerts fire automatically without a
 * separate alerting system. Matches by (vehicle_id, item type name) since
 * maintenance_schedule.item_type is free text, not an FK to item_types —
 * renaming an item type in Admin won't retroactively re-link older schedule rows.
 */
class PartMaintenanceSyncService
{
    public static function syncFromExpense(\PDO $pdo, int $vehicleId, int $itemTypeId, string $expenseDate, ?int $mileage): void
    {
        $itemStmt = $pdo->prepare("SELECT name, km_interval, months_interval FROM item_types WHERE id = ?");
        $itemStmt->execute([$itemTypeId]);
        $itemType = $itemStmt->fetch();
        if (!$itemType) {
            return;
        }

        $existingStmt = $pdo->prepare("
            SELECT * FROM maintenance_schedule
            WHERE vehicle_id = ? AND LOWER(TRIM(item_type)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $existingStmt->execute([$vehicleId, $itemType['name']]);
        $existing = $existingStmt->fetch();

        // Don't let an older/backfilled expense push a more recent schedule backward
        if ($existing && $existing['last_replaced_date'] && $expenseDate < $existing['last_replaced_date']) {
            return;
        }

        if ($existing) {
            // Respect whatever interval is already configured on this schedule row
            // (may have been hand-tuned in Maintenance Schedule) rather than the
            // item type's default — same rule the existing "Mark Done" flow uses.
            $intervalKm = $existing['interval_km'];
            $intervalMonths = $existing['interval_months'];
        } else {
            $intervalKm = $itemType['km_interval'];
            $intervalMonths = $itemType['months_interval'];
        }

        $nextDueMileage = ($intervalKm && $mileage !== null) ? $mileage + $intervalKm : null;
        $nextDueDate = $intervalMonths ? date('Y-m-d', strtotime("+{$intervalMonths} months", strtotime($expenseDate))) : null;

        if ($existing) {
            $pdo->prepare("
                UPDATE maintenance_schedule
                SET last_replaced_date = ?,
                    last_replaced_mileage = COALESCE(?, last_replaced_mileage),
                    next_due_mileage = ?,
                    next_due_date = ?
                WHERE id = ?
            ")->execute([$expenseDate, $mileage, $nextDueMileage, $nextDueDate, $existing['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO maintenance_schedule
                    (vehicle_id, item_type, interval_km, interval_months, last_replaced_date, last_replaced_mileage, next_due_mileage, next_due_date, priority, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'medium', ?)
            ")->execute([
                $vehicleId,
                $itemType['name'],
                $intervalKm,
                $intervalMonths,
                $expenseDate,
                $mileage,
                $nextDueMileage,
                $nextDueDate,
                'Auto-created from a logged expense — edit the interval or priority as needed.',
            ]);
        }
    }
}
