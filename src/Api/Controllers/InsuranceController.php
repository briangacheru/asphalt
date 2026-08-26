<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Services\InsuranceService;

/**
 * JSON-only insurance endpoints — sticker upload isn't supported here yet
 * (multipart handling is more involved with a bearer-token API); the web
 * page at insurance.php remains the way to attach a sticker image/PDF.
 */
class InsuranceController
{
    /** GET /insurance — current policy + status for every active vehicle. */
    public static function index(\PDO $pdo, int $userId): void
    {
        Response::json(['vehicles' => InsuranceService::statusForUser($pdo, $userId)]);
    }

    /** GET /insurance/vehicle/{vehicleId} — current policy + full history for one vehicle. */
    public static function showForVehicle(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        Response::json([
            'current' => InsuranceService::current($pdo, $vehicleId),
            'history' => InsuranceService::history($pdo, $vehicleId),
        ]);
    }

    /** POST /insurance — add/renew a policy for a vehicle the user owns. */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        InsuranceService::ensureTable($pdo);

        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $provider = trim($body['provider'] ?? '');
        $expiryDate = $body['expiry_date'] ?? '';

        if ($provider === '' || $expiryDate === '' || \DateTime::createFromFormat('Y-m-d', $expiryDate) === false) {
            Response::error('provider and a valid expiry_date (YYYY-MM-DD) are required.', 422);
        }

        $coverageType = array_key_exists($body['coverage_type'] ?? '', InsuranceService::COVERAGE_TYPES)
            ? $body['coverage_type']
            : 'comprehensive';

        $premium = isset($body['premium_amount']) && is_numeric($body['premium_amount'])
            ? (float) $body['premium_amount']
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO vehicle_insurance
                (vehicle_id, provider, policy_number, coverage_type, premium_amount, start_date, expiry_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId,
            $provider,
            trim($body['policy_number'] ?? '') ?: null,
            $coverageType,
            $premium,
            $body['start_date'] ?? null,
            $expiryDate,
            trim($body['notes'] ?? '') ?: null,
        ]);

        Response::json(InsuranceService::current($pdo, $vehicleId), 201);
    }

    /** DELETE /insurance/{id} */
    public static function destroy(\PDO $pdo, int $userId, int $policyId): void
    {
        $stmt = $pdo->prepare("
            SELECT vi.id FROM vehicle_insurance vi
            JOIN vehicles v ON v.id = vi.vehicle_id
            WHERE vi.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$policyId, $userId]);

        if (!$stmt->fetch()) {
            Response::error('Insurance record not found.', 404);
        }

        $pdo->prepare("DELETE FROM vehicle_insurance WHERE id = ?")->execute([$policyId]);
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
