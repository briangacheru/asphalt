<?php

namespace App\Api\Controllers;

use App\Api\Response;

/**
 * Mirrors update-mileage.php's manual mileage update — global across all
 * of the user's vehicles (not scoped to one), same as the web page. The
 * "service due within 1000km" reminder email that page sends isn't sent
 * here (out of scope — no side-channel notifications from this API,
 * matching service-records/insurance/licence).
 */
class MileageUpdateController
{
    /** POST /mileage-updates — vehicle_id, mileage required; notes optional. */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        $newMileage = (int) ($body['mileage'] ?? -1);
        $notes = trim((string) ($body['notes'] ?? '')) ?: null;

        $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }
        if ($newMileage < (int) $vehicle['current_mileage']) {
            Response::error('New mileage cannot be less than the current mileage (' . (int) $vehicle['current_mileage'] . ' km).', 422);
        }

        $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ? AND user_id = ?")
            ->execute([$newMileage, $vehicleId, $userId]);

        $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source, notes) VALUES (?, ?, CURDATE(), 'manual', ?)")
            ->execute([$vehicleId, $newMileage, $notes]);

        Response::json(['success' => true, 'vehicle_id' => $vehicleId, 'mileage' => $newMileage], 201);
    }

    /**
     * GET /mileage-updates/recent?limit=15 — mileage_log ∪ fuel_log across
     * every vehicle the user owns, newest activity first (by created_at,
     * i.e. when the row was entered — matches the web page's "Recent
     * Updates" activity feed, not the record's own date).
     */
    public static function recent(\PDO $pdo, int $userId, int $limit): void
    {
        $limit = max(1, min($limit, 50));

        $stmt = $pdo->prepare("
            SELECT mileage, update_date, source, vehicle_id, make, model FROM (
                SELECT ml.mileage, ml.log_date AS update_date, ml.source, ml.created_at,
                       v.id AS vehicle_id, v.make, v.model
                FROM mileage_log ml
                JOIN vehicles v ON v.id = ml.vehicle_id
                WHERE v.user_id = ?

                UNION ALL

                SELECT fl.mileage, fl.fill_date AS update_date, 'fuel' AS source, fl.created_at,
                       v.id AS vehicle_id, v.make, v.model
                FROM fuel_log fl
                JOIN vehicles v ON v.id = fl.vehicle_id
                WHERE v.user_id = ?
            ) combined
            ORDER BY created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId, $userId]);

        Response::json(['updates' => $stmt->fetchAll()]);
    }
}
