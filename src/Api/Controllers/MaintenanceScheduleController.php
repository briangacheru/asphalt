<?php

namespace App\Api\Controllers;

use App\Api\Response;

/**
 * maintenance_schedule rows are entirely derived — created/updated only as
 * a side effect of a tracked-category expense (see ExpenseController /
 * PartMaintenanceSyncService), never directly. So there's no POST here,
 * only read (with the same overdue/due_soon/upcoming/ok status
 * computation maintenance-schedule.php uses) and an edit for the two
 * fields a user can actually tune: interval_km, interval_months, priority.
 */
class MaintenanceScheduleController
{
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** GET /vehicles/{vehicleId}/maintenance-schedule */
    public static function index(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $stmt = $pdo->prepare("
            SELECT ms.*, v.current_mileage
            FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.vehicle_id = ?
            ORDER BY ms.id DESC
        ");
        $stmt->execute([$vehicleId]);

        Response::json(['maintenance_schedule' => array_map([self::class, 'withStatus'], $stmt->fetchAll())]);
    }

    /** PUT /maintenance-schedule/{id} — only interval_km, interval_months, priority are editable. */
    public static function update(\PDO $pdo, int $userId, int $scheduleId, array $body): void
    {
        $stmt = $pdo->prepare("
            SELECT ms.* FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$scheduleId, $userId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Maintenance schedule item not found.', 404);
        }

        $intervalKm = isset($body['interval_km']) && $body['interval_km'] !== '' ? (int) $body['interval_km'] : null;
        $intervalMonths = isset($body['interval_months']) && $body['interval_months'] !== '' ? (int) $body['interval_months'] : null;
        $priority = in_array($body['priority'] ?? '', self::PRIORITIES, true) ? $body['priority'] : $existing['priority'];

        $nextDueMileage = ($existing['last_replaced_mileage'] && $intervalKm) ? $existing['last_replaced_mileage'] + $intervalKm : null;
        $nextDueDate = ($existing['last_replaced_date'] && $intervalMonths)
            ? date('Y-m-d', strtotime("+{$intervalMonths} months", strtotime($existing['last_replaced_date'])))
            : null;

        $pdo->prepare("
            UPDATE maintenance_schedule
            SET interval_km = ?, interval_months = ?, next_due_mileage = ?, next_due_date = ?, priority = ?
            WHERE id = ?
        ")->execute([$intervalKm, $intervalMonths, $nextDueMileage, $nextDueDate, $priority, $scheduleId]);

        $stmt = $pdo->prepare("
            SELECT ms.*, v.current_mileage
            FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ?
        ");
        $stmt->execute([$scheduleId]);
        Response::json(self::withStatus($stmt->fetch()));
    }

    /** Mirrors maintenance-schedule.php's overdue/due_soon/upcoming/ok thresholds (2000km / 5000km bands). */
    private static function withStatus(array $row): array
    {
        $kmOverdue = $row['next_due_mileage'] ? $row['current_mileage'] - $row['next_due_mileage'] : null;
        $dateOverdue = $row['next_due_date'] ? (strtotime($row['next_due_date']) < time()) : false;

        if (($kmOverdue !== null && $kmOverdue > 0) || $dateOverdue) {
            $row['status'] = 'overdue';
            $row['km_overdue'] = $kmOverdue;
        } elseif ($kmOverdue !== null && $kmOverdue > -2000) {
            $row['status'] = 'due_soon';
            $row['km_remaining'] = abs($kmOverdue);
        } elseif ($kmOverdue !== null && $kmOverdue > -5000) {
            $row['status'] = 'upcoming';
            $row['km_remaining'] = abs($kmOverdue);
        } else {
            $row['status'] = 'ok';
            $row['km_remaining'] = $kmOverdue !== null ? abs($kmOverdue) : null;
        }

        return $row;
    }

    private static function assertOwnsVehicle(\PDO $pdo, int $userId, int $vehicleId): void
    {
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);

        if (!$stmt->fetch()) {
            Response::error('Vehicle not found.', 404);
        }
    }
}
