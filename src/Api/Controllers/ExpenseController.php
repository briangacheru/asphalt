<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Services\ItemTypeService;
use App\Services\PartMaintenanceSyncService;

/**
 * Mirrors expenses.php's create flow: amount is auto-recomputed from
 * quantity × cost_per_unit for every category except "Mechanic", and a
 * tracked item type (anything but Mechanic/Accessories) feeds
 * PartMaintenanceSyncService the same way the web form does. Receipt
 * upload isn't in v1 — same JSON-only scope as insurance/licence/service.
 */
class ExpenseController
{
    /** GET /expense-categories — categories are admin-managed, not a fixed enum. */
    public static function categories(\PDO $pdo): void
    {
        $rows = $pdo->query("SELECT DISTINCT id, name, icon FROM expense_categories ORDER BY name")->fetchAll();
        Response::json(['categories' => $rows]);
    }

    /** GET /vehicles/{vehicleId}/expenses */
    public static function index(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $stmt = $pdo->prepare("
            SELECT e.*, ec.name AS category_name, ec.icon AS category_icon
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            WHERE e.vehicle_id = ?
            ORDER BY e.expense_date DESC, e.id DESC
        ");
        $stmt->execute([$vehicleId]);

        Response::json(['expenses' => $stmt->fetchAll()]);
    }

    /** POST /expenses */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        $categoryId = (int) ($body['category_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Vehicle not found.', 404);
        }

        $catStmt = $pdo->prepare("SELECT name FROM expense_categories WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $categoryName = $catStmt->fetchColumn();
        if ($categoryName === false) {
            Response::error('Category not found.', 404);
        }
        $categoryName = strtolower(trim($categoryName));

        $expenseDate = $body['expense_date'] ?? date('Y-m-d');
        if (\DateTime::createFromFormat('Y-m-d', $expenseDate) === false) {
            Response::error('expense_date must be a valid YYYY-MM-DD date.', 422);
        }

        $amount = isset($body['amount']) && is_numeric($body['amount']) ? (float) $body['amount'] : 0;
        $itemTypeId = (int) ($body['item_type_id'] ?? 0) ?: null;
        $quantity = (int) ($body['quantity'] ?? 0) ?: null;
        $costPerUnit = isset($body['cost_per_unit']) && is_numeric($body['cost_per_unit']) ? (float) $body['cost_per_unit'] : null;
        $mileage = isset($body['mileage']) && $body['mileage'] !== '' ? (int) $body['mileage'] : null;

        $isItemCategory = $categoryName !== 'mechanic';
        if ($isItemCategory && $quantity && $costPerUnit) {
            $amount = $quantity * $costPerUnit;
        }

        if (!$vehicleId || !$categoryId || $amount <= 0) {
            Response::error('vehicle_id, category_id, and a positive amount (or quantity + cost_per_unit) are required.', 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO expenses
                (vehicle_id, category_id, expense_date, amount, description, item_type_id,
                 item_name, brand, part_number, quantity, cost_per_unit, item_notes, mileage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId, $categoryId, $expenseDate, $amount,
            trim($body['description'] ?? '') ?: null,
            $itemTypeId,
            trim($body['item_name'] ?? '') ?: null,
            trim($body['brand'] ?? '') ?: null,
            trim($body['part_number'] ?? '') ?: null,
            $quantity, $costPerUnit,
            trim($body['item_notes'] ?? '') ?: null,
            $mileage,
        ]);
        $expenseId = (int) $pdo->lastInsertId();

        if ($mileage !== null) {
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")
                ->execute([$mileage, $vehicleId, $userId]);
            $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source) VALUES (?, ?, ?, 'expense')")
                ->execute([$vehicleId, $mileage, $expenseDate]);
        }

        if ($itemTypeId && ItemTypeService::isTrackedCategory($categoryName)) {
            try {
                PartMaintenanceSyncService::syncFromExpense($pdo, $vehicleId, $itemTypeId, $expenseDate, $mileage);
            } catch (\PDOException $e) {
                // Non-fatal — the expense itself already saved successfully.
            }
        }

        $stmt = $pdo->prepare("
            SELECT e.*, ec.name AS category_name, ec.icon AS category_icon
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.category_id
            WHERE e.id = ?
        ");
        $stmt->execute([$expenseId]);
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
