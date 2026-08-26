<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Api\UploadHelper;
use App\Services\SiteSettingsService;

/**
 * Mirrors add-service.php's create flow (mileage validation, oil-interval
 * allow-list, next_service_mileage computation, mileage_log side effect).
 * POST /service-records accepts either a JSON body or multipart/form-data
 * — send multipart with a "dashboard_image" file field to attach the
 * odometer photo in the same request. The "service recorded" confirmation
 * email isn't sent here (out of scope — no side-channel notifications
 * from this API, matching insurance/licence).
 */
class ServiceRecordController
{
    private const DASHBOARD_IMAGE_MIME_TO_EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    ];
    private const DASHBOARD_IMAGE_FORMATS_LABEL = 'JPG, PNG, GIF, WEBP';

    /** GET /vehicles/{vehicleId}/service-records */
    public static function index(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $stmt = $pdo->prepare("SELECT * FROM service_records WHERE vehicle_id = ? ORDER BY service_date DESC, id DESC");
        $stmt->execute([$vehicleId]);

        Response::json(['service_records' => $stmt->fetchAll()]);
    }

    /** POST /service-records */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $vehicleId = (int) ($body['vehicle_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
        }

        $serviceDate = $body['service_date'] ?? date('Y-m-d');
        $mileage = (int) ($body['mileage'] ?? 0);
        $oilInterval = (int) ($body['oil_interval'] ?? 0);

        $oilIntervalsRaw = SiteSettingsService::get($pdo, 'oil_intervals_km');
        $oilIntervals = $oilIntervalsRaw ? (json_decode($oilIntervalsRaw, true) ?: unserialize(OIL_INTERVALS)) : unserialize(OIL_INTERVALS);

        if ($mileage <= 0 || \DateTime::createFromFormat('Y-m-d', $serviceDate) === false) {
            Response::error('mileage and a valid service_date (YYYY-MM-DD) are required.', 422);
        }
        if ($mileage < $vehicle['current_mileage']) {
            Response::error('mileage cannot be less than the vehicle\'s current mileage (' . $vehicle['current_mileage'] . ' km).', 422);
        }
        if (!in_array($oilInterval, $oilIntervals, true)) {
            Response::error('oil_interval must be one of: ' . implode(', ', $oilIntervals), 422);
        }

        $nextServiceMileage = $mileage + $oilInterval;

        $dashboardImage = UploadHelper::store(
            'dashboard_image', '', 'dashboard',
            self::DASHBOARD_IMAGE_MIME_TO_EXT, MAX_UPLOAD_SIZE, self::DASHBOARD_IMAGE_FORMATS_LABEL
        );

        $stmt = $pdo->prepare("
            INSERT INTO service_records
                (vehicle_id, service_date, mileage, dashboard_image, mileage_source, oil_interval, next_service_mileage,
                 service_cost, service_location, technician_name, notes)
            VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId,
            $serviceDate,
            $mileage,
            $dashboardImage['stored_filename'] ?? null,
            $oilInterval,
            $nextServiceMileage,
            isset($body['service_cost']) && is_numeric($body['service_cost']) ? (float) $body['service_cost'] : 0,
            trim($body['service_location'] ?? '') ?: null,
            trim($body['technician_name'] ?? '') ?: null,
            trim($body['notes'] ?? '') ?: null,
        ]);
        $serviceRecordId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ? AND user_id = ?")
            ->execute([$mileage, $vehicleId, $userId]);
        $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source) VALUES (?, ?, ?, 'service')")
            ->execute([$vehicleId, $mileage, $serviceDate]);

        $stmt = $pdo->prepare("SELECT * FROM service_records WHERE id = ?");
        $stmt->execute([$serviceRecordId]);
        Response::json($stmt->fetch(), 201);
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
