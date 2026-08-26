<?php

namespace App\Api\Controllers;

use App\Api\Response;

/**
 * Mirrors fuel-log.php's create flow: total_cost is computed server-side
 * (liters × price_per_liter), fuel_type is pulled from the vehicle record
 * rather than user-entered, and full_tank is always 1 on create — same as
 * the web form. No mileage_log row is inserted here, matching the web
 * app's existing (if inconsistent) behavior for fuel entries.
 */
class FuelLogController
{
    /** GET /vehicles/{vehicleId}/fuel-logs */
    public static function index(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $stmt = $pdo->prepare("SELECT * FROM fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC");
        $stmt->execute([$vehicleId]);

        Response::json(['fuel_logs' => $stmt->fetchAll()]);
    }

    /** POST /fuel-logs */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $vehicleId = (int) ($body['vehicle_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        $fillDate = $body['fill_date'] ?? date('Y-m-d');
        $mileage = (int) ($body['mileage'] ?? 0);
        $liters = isset($body['liters']) && is_numeric($body['liters']) ? (float) $body['liters'] : 0;
        $pricePerLiter = isset($body['price_per_liter']) && is_numeric($body['price_per_liter']) ? (float) $body['price_per_liter'] : 0;

        if ($mileage <= 0 || $liters <= 0 || $pricePerLiter <= 0 || \DateTime::createFromFormat('Y-m-d', $fillDate) === false) {
            Response::error('mileage, liters, price_per_liter, and a valid fill_date (YYYY-MM-DD) are required.', 422);
        }

        $totalCost = $liters * $pricePerLiter;

        $stmt = $pdo->prepare("
            INSERT INTO fuel_log (vehicle_id, fill_date, mileage, liters, price_per_liter, total_cost, fuel_type, station_name, full_tank)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $vehicleId, $fillDate, $mileage, $liters, $pricePerLiter, $totalCost,
            $vehicle['fuel_type'], trim($body['station_name'] ?? '') ?: null,
        ]);
        $fuelLogId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")
            ->execute([$mileage, $vehicleId, $userId]);

        $stmt = $pdo->prepare("SELECT * FROM fuel_log WHERE id = ?");
        $stmt->execute([$fuelLogId]);
        Response::json($stmt->fetch(), 201);
    }

    /** PUT /fuel-logs/{id} — vehicle_id may be changed, same as the web edit form. */
    public static function update(\PDO $pdo, int $userId, int $fuelLogId, array $body): void
    {
        $stmt = $pdo->prepare("
            SELECT fl.id FROM fuel_log fl
            JOIN vehicles v ON fl.vehicle_id = v.id
            WHERE fl.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$fuelLogId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Fuel log entry not found.', 404);
        }

        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        $fillDate = $body['fill_date'] ?? date('Y-m-d');
        $mileage = (int) ($body['mileage'] ?? 0);
        $liters = isset($body['liters']) && is_numeric($body['liters']) ? (float) $body['liters'] : 0;
        $pricePerLiter = isset($body['price_per_liter']) && is_numeric($body['price_per_liter']) ? (float) $body['price_per_liter'] : 0;
        $fullTank = isset($body['full_tank']) ? (int) $body['full_tank'] : 1;

        if ($mileage <= 0 || $liters <= 0 || $pricePerLiter <= 0 || \DateTime::createFromFormat('Y-m-d', $fillDate) === false) {
            Response::error('mileage, liters, price_per_liter, and a valid fill_date (YYYY-MM-DD) are required.', 422);
        }

        $totalCost = $liters * $pricePerLiter;

        $pdo->prepare("
            UPDATE fuel_log
            SET vehicle_id = ?, fill_date = ?, mileage = ?, liters = ?, price_per_liter = ?,
                total_cost = ?, fuel_type = ?, station_name = ?, full_tank = ?
            WHERE id = ?
        ")->execute([
            $vehicleId, $fillDate, $mileage, $liters, $pricePerLiter, $totalCost,
            $vehicle['fuel_type'], trim($body['station_name'] ?? '') ?: null, $fullTank, $fuelLogId,
        ]);

        $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")
            ->execute([$mileage, $vehicleId, $userId]);

        $stmt = $pdo->prepare("SELECT * FROM fuel_log WHERE id = ?");
        $stmt->execute([$fuelLogId]);
        Response::json($stmt->fetch());
    }

    /** DELETE /fuel-logs/{id} */
    public static function destroy(\PDO $pdo, int $userId, int $fuelLogId): void
    {
        $stmt = $pdo->prepare("
            DELETE fl FROM fuel_log fl
            JOIN vehicles v ON fl.vehicle_id = v.id
            WHERE fl.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$fuelLogId, $userId]);

        if ($stmt->rowCount() === 0) {
            Response::error('Fuel log entry not found.', 404);
        }

        Response::json(['success' => true]);
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
