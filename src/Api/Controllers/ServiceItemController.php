<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Services\ItemTypeService;
use App\Services\ServiceItemExpenseSync;

/**
 * Mirrors service-items.php's add/edit/delete flows: item_type is stored
 * as a slugified label alongside the item_type_id FK (legacy column, kept
 * for parity with older rows), service_records.service_cost is
 * recomputed as SUM(cost * quantity) after every change, and each change
 * is mirrored into expenses via ServiceItemExpenseSync so it shows up in
 * the unified spend view — same as the web app.
 */
class ServiceItemController
{
    /** GET /item-types — the admin-managed catalog used to populate item_type_id. */
    public static function itemTypes(\PDO $pdo): void
    {
        Response::json(['item_types' => ItemTypeService::all($pdo)]);
    }

    /** GET /service-records/{serviceRecordId}/items */
    public static function index(\PDO $pdo, int $userId, int $serviceRecordId): void
    {
        self::assertOwnsServiceRecord($pdo, $userId, $serviceRecordId);

        $stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_record_id = ? ORDER BY id DESC");
        $stmt->execute([$serviceRecordId]);

        Response::json(['service_items' => $stmt->fetchAll()]);
    }

    /** POST /service-records/{serviceRecordId}/items */
    public static function store(\PDO $pdo, int $userId, int $serviceRecordId, array $body): void
    {
        $service = self::assertOwnsServiceRecord($pdo, $userId, $serviceRecordId);
        [$itemTypeId, $itemLabel] = self::resolveItemType($pdo, $body);

        $quantity = (int) ($body['quantity'] ?? 1) ?: 1;
        $cost = isset($body['cost']) && is_numeric($body['cost']) ? (float) $body['cost'] : 0;
        $itemName = trim($body['item_name'] ?? '') ?: null;
        $brand = trim($body['brand'] ?? '') ?: null;
        $partNumber = trim($body['part_number'] ?? '') ?: null;
        $notes = trim($body['notes'] ?? '') ?: null;

        $stmt = $pdo->prepare("
            INSERT INTO service_items (service_record_id, item_type, item_type_id, item_name, brand, part_number, quantity, cost, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$serviceRecordId, self::slugify($itemLabel), $itemTypeId, $itemName, $brand, $partNumber, $quantity, $cost, $notes]);
        $itemId = (int) $pdo->lastInsertId();

        self::recomputeServiceCost($pdo, $serviceRecordId);

        try {
            ServiceItemExpenseSync::upsert($pdo, [
                'id' => $itemId, 'item_name' => $itemName, 'brand' => $brand,
                'part_number' => $partNumber, 'quantity' => $quantity, 'cost' => $cost, 'notes' => $notes,
            ], $service, $itemLabel, $itemTypeId);
        } catch (\PDOException $e) {
            // Non-fatal — the item itself already saved successfully.
        }

        $stmt = $pdo->prepare("SELECT * FROM service_items WHERE id = ?");
        $stmt->execute([$itemId]);
        Response::json($stmt->fetch(), 201);
    }

    /** PUT /service-items/{id} */
    public static function update(\PDO $pdo, int $userId, int $itemId, array $body): void
    {
        $stmt = $pdo->prepare("
            SELECT si.*, sr.vehicle_id, sr.service_date, sr.mileage
            FROM service_items si
            JOIN service_records sr ON si.service_record_id = sr.id
            JOIN vehicles v ON sr.vehicle_id = v.id
            WHERE si.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$itemId, $userId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            Response::error('Service item not found.', 404);
        }

        [$itemTypeId, $itemLabel] = self::resolveItemType($pdo, $body);
        $quantity = (int) ($body['quantity'] ?? 1) ?: 1;
        $cost = isset($body['cost']) && is_numeric($body['cost']) ? (float) $body['cost'] : 0;
        $itemName = trim($body['item_name'] ?? '') ?: null;
        $brand = trim($body['brand'] ?? '') ?: null;
        $partNumber = trim($body['part_number'] ?? '') ?: null;
        $notes = trim($body['notes'] ?? '') ?: null;

        $pdo->prepare("
            UPDATE service_items
            SET item_type = ?, item_type_id = ?, item_name = ?, brand = ?, part_number = ?, quantity = ?, cost = ?, notes = ?
            WHERE id = ?
        ")->execute([self::slugify($itemLabel), $itemTypeId, $itemName, $brand, $partNumber, $quantity, $cost, $notes, $itemId]);

        self::recomputeServiceCost($pdo, (int) $existing['service_record_id']);

        try {
            ServiceItemExpenseSync::upsert($pdo, [
                'id' => $itemId, 'item_name' => $itemName, 'brand' => $brand,
                'part_number' => $partNumber, 'quantity' => $quantity, 'cost' => $cost, 'notes' => $notes,
            ], $existing, $itemLabel, $itemTypeId);
        } catch (\PDOException $e) {
            // Non-fatal — the item itself already saved successfully.
        }

        $stmt = $pdo->prepare("SELECT * FROM service_items WHERE id = ?");
        $stmt->execute([$itemId]);
        Response::json($stmt->fetch());
    }

    /** DELETE /service-items/{id} */
    public static function destroy(\PDO $pdo, int $userId, int $itemId): void
    {
        $stmt = $pdo->prepare("
            SELECT si.service_record_id FROM service_items si
            JOIN service_records sr ON si.service_record_id = sr.id
            JOIN vehicles v ON sr.vehicle_id = v.id
            WHERE si.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$itemId, $userId]);
        $serviceRecordId = $stmt->fetchColumn();
        if ($serviceRecordId === false) {
            Response::error('Service item not found.', 404);
        }

        $pdo->prepare("DELETE FROM service_items WHERE id = ?")->execute([$itemId]);
        self::recomputeServiceCost($pdo, (int) $serviceRecordId);

        try {
            ServiceItemExpenseSync::remove($pdo, $itemId);
        } catch (\PDOException $e) {
            // Non-fatal — the item itself already deleted successfully.
        }

        Response::json(['success' => true]);
    }

    /**
     * Validates item_type_id against the catalog and returns [id, label].
     *
     * @return array{0: int, 1: string}
     */
    private static function resolveItemType(\PDO $pdo, array $body): array
    {
        $itemTypeId = (int) ($body['item_type_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT name FROM item_types WHERE id = ?");
        $stmt->execute([$itemTypeId]);
        $label = $stmt->fetchColumn();

        if ($label === false) {
            Response::error('item_type_id must reference a valid item type.', 422);
        }

        return [$itemTypeId, $label];
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim($slug, '_');
    }

    private static function recomputeServiceCost(\PDO $pdo, int $serviceRecordId): void
    {
        $pdo->prepare("
            UPDATE service_records
            SET service_cost = (SELECT COALESCE(SUM(cost * quantity), 0) FROM service_items WHERE service_record_id = ?)
            WHERE id = ?
        ")->execute([$serviceRecordId, $serviceRecordId]);
    }

    /** Returns the parent service_records row (with vehicle_id/service_date/mileage) if owned by the user. */
    private static function assertOwnsServiceRecord(\PDO $pdo, int $userId, int $serviceRecordId): array
    {
        $stmt = $pdo->prepare("
            SELECT sr.* FROM service_records sr
            JOIN vehicles v ON sr.vehicle_id = v.id
            WHERE sr.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$serviceRecordId, $userId]);
        $service = $stmt->fetch();

        if (!$service) {
            Response::error('Service record not found.', 404);
        }

        return $service;
    }
}
