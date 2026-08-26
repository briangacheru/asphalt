<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Models\Vehicle;

class VehicleController
{
    private const UPDATABLE_FIELDS = [
        'make', 'model', 'year', 'license_plate', 'vin', 'color',
        'fuel_type', 'transmission', 'engine_capacity',
        'purchase_date', 'purchase_mileage', 'current_mileage', 'notes',
    ];

    /**
     * GET /vehicles — each row includes a summary for the Vehicles list:
     * when the mileage was last updated, how far to the next service,
     * the latest fuel fill, and an aggregate maintenance status. show()/
     * store()/update() stay lean (the detail screen fetches those
     * separately), this is the one place worth the extra queries.
     */
    public static function index(\PDO $pdo, int $userId): void
    {
        $vehicle = new Vehicle();
        Response::json([
            'vehicles' => array_map(fn($row) => self::formatWithSummary($pdo, $row), $vehicle->getUserVehicles($userId)),
        ]);
    }

    /** GET /vehicles/{id} */
    public static function show(\PDO $pdo, int $userId, int $vehicleId): void
    {
        $vehicle = new Vehicle();

        if (!$vehicle->belongsToUser($vehicleId, $userId)) {
            Response::error('Vehicle not found.', 404);
        }

        Response::json(self::format($vehicle->find($vehicleId)));
    }

    /** POST /vehicles */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $make = trim($body['make'] ?? '');
        $model = trim($body['model'] ?? '');
        $year = (int) ($body['year'] ?? 0);

        if ($make === '' || $model === '' || $year < 1980 || $year > ((int) date('Y') + 1)) {
            Response::error('make, model, and a valid year are required.', 422);
        }

        $purchaseMileage = (int) ($body['purchase_mileage'] ?? 0);

        $vehicle = new Vehicle();
        $id = $vehicle->createVehicle([
            'user_id' => $userId,
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'license_plate' => trim($body['license_plate'] ?? '') ?: null,
            'vin' => trim($body['vin'] ?? '') ?: null,
            'color' => trim($body['color'] ?? '') ?: null,
            'fuel_type' => trim($body['fuel_type'] ?? '') ?: 'petrol',
            'transmission' => trim($body['transmission'] ?? '') ?: 'manual',
            'engine_capacity' => trim($body['engine_capacity'] ?? '') ?: null,
            'purchase_date' => $body['purchase_date'] ?? null,
            'purchase_mileage' => $purchaseMileage,
            'current_mileage' => (int) ($body['current_mileage'] ?? $purchaseMileage),
            'notes' => trim($body['notes'] ?? '') ?: null,
        ]);

        Response::json(self::format($vehicle->find((int) $id)), 201);
    }

    /** PUT /vehicles/{id} */
    public static function update(\PDO $pdo, int $userId, int $vehicleId, array $body): void
    {
        $vehicle = new Vehicle();

        if (!$vehicle->belongsToUser($vehicleId, $userId)) {
            Response::error('Vehicle not found.', 404);
        }

        $data = array_intersect_key($body, array_flip(self::UPDATABLE_FIELDS));

        if (empty($data)) {
            Response::error('No updatable fields provided.', 422);
        }

        $vehicle->updateVehicle($vehicleId, $data);
        Response::json(self::format($vehicle->find($vehicleId)));
    }

    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'make' => $row['make'],
            'model' => $row['model'],
            'year' => (int) $row['year'],
            'license_plate' => $row['license_plate'],
            'vin' => $row['vin'],
            'color' => $row['color'],
            'fuel_type' => $row['fuel_type'],
            'transmission' => $row['transmission'],
            'engine_capacity' => $row['engine_capacity'],
            'purchase_date' => $row['purchase_date'],
            'purchase_mileage' => isset($row['purchase_mileage']) ? (int) $row['purchase_mileage'] : null,
            'current_mileage' => (int) $row['current_mileage'],
            'notes' => $row['notes'],
        ];
    }

    private static function formatWithSummary(\PDO $pdo, array $row): array
    {
        $vehicleId = (int) $row['id'];
        $currentMileage = (int) $row['current_mileage'];
        $base = self::format($row);

        // Most recent mileage change from either source — mileage_log covers
        // manual/service/expense updates, fuel fill-ups only ever land in
        // fuel_log (see FuelLogController's doc comment) — same UNION
        // update-mileage.php's "Recent Updates" card uses.
        $stmt = $pdo->prepare("
            SELECT update_date FROM (
                SELECT log_date AS update_date, created_at FROM mileage_log WHERE vehicle_id = ?
                UNION ALL
                SELECT fill_date AS update_date, created_at FROM fuel_log WHERE vehicle_id = ?
            ) combined
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$vehicleId, $vehicleId]);
        $base['mileage_updated_at'] = $stmt->fetchColumn() ?: null;

        // Next service, from the latest service record (mirrors ServiceRecord::getUpcomingServices()).
        $stmt = $pdo->prepare("SELECT next_service_mileage FROM service_records WHERE vehicle_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$vehicleId]);
        $nextServiceMileage = $stmt->fetchColumn();
        $base['next_service_mileage'] = $nextServiceMileage !== false ? (int) $nextServiceMileage : null;
        $base['service_km_remaining'] = $nextServiceMileage !== false ? ((int) $nextServiceMileage - $currentMileage) : null;

        // Latest fuel fill.
        $stmt = $pdo->prepare("SELECT fill_date, liters, total_cost FROM fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC LIMIT 1");
        $stmt->execute([$vehicleId]);
        $lastFuel = $stmt->fetch();
        $base['last_fuel_fill_date'] = $lastFuel['fill_date'] ?? null;
        $base['last_fuel_liters'] = $lastFuel['liters'] ?? null;
        $base['last_fuel_total_cost'] = $lastFuel['total_cost'] ?? null;

        // Aggregate maintenance status — worst status across every tracked
        // part, or "none" if nothing's tracked yet for this vehicle.
        $stmt = $pdo->prepare("SELECT next_due_mileage, next_due_date FROM maintenance_schedule WHERE vehicle_id = ?");
        $stmt->execute([$vehicleId]);
        $scheduleRows = $stmt->fetchAll();

        $rank = ['ok' => 1, 'upcoming' => 2, 'due_soon' => 3, 'overdue' => 4];
        $worst = null;
        foreach ($scheduleRows as $scheduleRow) {
            $kmOverdue = $scheduleRow['next_due_mileage'] ? $currentMileage - (int) $scheduleRow['next_due_mileage'] : null;
            $status = MaintenanceScheduleController::statusFor($kmOverdue, $scheduleRow['next_due_date']);
            if ($worst === null || $rank[$status] > $rank[$worst]) {
                $worst = $status;
            }
        }
        $base['maintenance_status'] = $worst ?? 'none';

        return $base;
    }
}
