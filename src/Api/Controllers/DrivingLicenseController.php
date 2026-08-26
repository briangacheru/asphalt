<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Services\DrivingLicenseService;

/**
 * JSON-only driving licence endpoints — scan upload isn't supported here
 * yet (multipart handling is more involved with a bearer-token API); the
 * web page at driving-license.php remains the way to attach a scan.
 */
class DrivingLicenseController
{
    /** GET /driving-license — current licence (with status) + full history. */
    public static function index(\PDO $pdo, int $userId): void
    {
        Response::json([
            'current' => DrivingLicenseService::statusForUser($pdo, $userId),
            'history' => DrivingLicenseService::history($pdo, $userId),
        ]);
    }

    /** POST /driving-license — add/renew the current user's licence. */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        DrivingLicenseService::ensureTable($pdo);

        $surname = trim($body['surname'] ?? '');
        $otherNames = trim($body['other_names'] ?? '');
        $licenseNumber = trim($body['license_number'] ?? '');
        $expiryDate = $body['expiry_date'] ?? '';

        if ($surname === '' || $otherNames === '' || $licenseNumber === ''
            || $expiryDate === '' || \DateTime::createFromFormat('Y-m-d', $expiryDate) === false) {
            Response::error('surname, other_names, license_number, and a valid expiry_date (YYYY-MM-DD) are required.', 422);
        }

        $categories = [];
        if (isset($body['categories']) && is_array($body['categories'])) {
            $categories = array_values(array_intersect($body['categories'], array_keys(DrivingLicenseService::CATEGORIES)));
        }

        $stmt = $pdo->prepare("
            INSERT INTO driving_licenses
                (user_id, surname, other_names, national_id, license_number, date_of_birth, sex, blood_group,
                 county_of_residence, serial_number, categories, issue_date, expiry_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $surname,
            $otherNames,
            trim($body['national_id'] ?? '') ?: null,
            $licenseNumber,
            $body['date_of_birth'] ?? null,
            trim($body['sex'] ?? '') ?: null,
            trim($body['blood_group'] ?? '') ?: null,
            trim($body['county_of_residence'] ?? '') ?: null,
            trim($body['serial_number'] ?? '') ?: null,
            $categories ? implode(',', $categories) : null,
            $body['issue_date'] ?? null,
            $expiryDate,
            trim($body['notes'] ?? '') ?: null,
        ]);

        Response::json(DrivingLicenseService::current($pdo, $userId), 201);
    }

    /** DELETE /driving-license/{id} */
    public static function destroy(\PDO $pdo, int $userId, int $licenseId): void
    {
        $stmt = $pdo->prepare("SELECT id FROM driving_licenses WHERE id = ? AND user_id = ?");
        $stmt->execute([$licenseId, $userId]);

        if (!$stmt->fetch()) {
            Response::error('Driving licence record not found.', 404);
        }

        $pdo->prepare("DELETE FROM driving_licenses WHERE id = ?")->execute([$licenseId]);
        Response::json(['success' => true]);
    }
}
