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

    /** GET /vehicles */
    public static function index(\PDO $pdo, int $userId): void
    {
        $vehicle = new Vehicle();
        Response::json([
            'vehicles' => array_map([self::class, 'format'], $vehicle->getUserVehicles($userId)),
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
}
