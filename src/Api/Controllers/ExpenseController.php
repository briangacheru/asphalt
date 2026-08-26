<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Api\UploadHelper;
use App\Services\ItemTypeService;
use App\Services\PartMaintenanceSyncService;

/**
 * Mirrors expenses.php's create flow: amount is auto-recomputed from
 * quantity × cost_per_unit for every category except "Mechanic", and a
 * tracked item type (anything but Mechanic/Accessories) feeds
 * PartMaintenanceSyncService the same way the web form does.
 *
 * POST /expenses accepts either a JSON body or multipart/form-data — send
 * multipart with a "receipt" file field to attach a receipt in the same
 * request. PUT /expenses/{id} stays JSON-only: PHP only populates
 * $_FILES for POST requests, so replacing a receipt on an existing
 * expense isn't supported here — use the web app for that.
 */
class ExpenseController
{
    private const RECEIPT_MIME_TO_EXT = [
        'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];
    private const RECEIPT_FORMATS_LABEL = 'JPG, PNG, GIF, WEBP, PDF';
    private const RECEIPT_MAX_SIZE = 5 * 1024 * 1024;

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

        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Vehicle not found.', 404);
        }

        [$categoryId, $categoryName, $expenseDate, $amount, $itemTypeId, $quantity, $costPerUnit, $mileage]
            = self::resolveFields($pdo, $body);

        $receipt = UploadHelper::store('receipt', 'receipts', 'receipt', self::RECEIPT_MIME_TO_EXT, self::RECEIPT_MAX_SIZE, self::RECEIPT_FORMATS_LABEL);
        $receiptPath = $receipt ? 'uploads/receipts/' . $receipt['stored_filename'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO expenses
                (vehicle_id, category_id, expense_date, amount, description, item_type_id,
                 item_name, brand, part_number, quantity, cost_per_unit, item_notes, receipt_path, mileage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $receiptPath,
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

    /**
     * PUT /expenses/{id} — locked (404) if the expense mirrors a service
     * item (service_item_id NOT NULL), same as the web edit form, which
     * only ever queries for service_item_id IS NULL rows to begin with.
     */
    public static function update(\PDO $pdo, int $userId, int $expenseId, array $body): void
    {
        $stmt = $pdo->prepare("
            SELECT e.id FROM expenses e
            JOIN vehicles v ON e.vehicle_id = v.id
            WHERE e.id = ? AND v.user_id = ? AND e.service_item_id IS NULL
        ");
        $stmt->execute([$expenseId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Expense not found, or it mirrors a service item and can\'t be edited directly.', 404);
        }

        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Vehicle not found.', 404);
        }

        [$categoryId, $categoryName, $expenseDate, $amount, $itemTypeId, $quantity, $costPerUnit, $mileage]
            = self::resolveFields($pdo, $body);

        $pdo->prepare("
            UPDATE expenses
            SET vehicle_id = ?, category_id = ?, expense_date = ?, amount = ?, description = ?,
                item_type_id = ?, item_name = ?, brand = ?, part_number = ?, quantity = ?,
                cost_per_unit = ?, item_notes = ?, mileage = ?
            WHERE id = ?
        ")->execute([
            $vehicleId, $categoryId, $expenseDate, $amount,
            trim($body['description'] ?? '') ?: null,
            $itemTypeId,
            trim($body['item_name'] ?? '') ?: null,
            trim($body['brand'] ?? '') ?: null,
            trim($body['part_number'] ?? '') ?: null,
            $quantity, $costPerUnit,
            trim($body['item_notes'] ?? '') ?: null,
            $mileage,
            $expenseId,
        ]);

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
        Response::json($stmt->fetch());
    }

    /** DELETE /expenses/{id} — same service_item_id lock as update(). */
    public static function destroy(\PDO $pdo, int $userId, int $expenseId): void
    {
        $stmt = $pdo->prepare("
            DELETE e FROM expenses e
            JOIN vehicles v ON e.vehicle_id = v.id
            WHERE e.id = ? AND v.user_id = ? AND e.service_item_id IS NULL
        ");
        $stmt->execute([$expenseId, $userId]);

        if ($stmt->rowCount() === 0) {
            Response::error('Expense not found, or it mirrors a service item and can\'t be deleted directly.', 404);
        }

        Response::json(['success' => true]);
    }

    /**
     * Shared category/amount resolution for store() and update(): validates
     * category_id, computes amount from quantity × cost_per_unit unless the
     * category is "Mechanic" (same rule expenses.php uses both times).
     *
     * @return array{0: int, 1: string, 2: string, 3: float, 4: ?int, 5: ?int, 6: ?float, 7: ?int}
     */
    private static function resolveFields(\PDO $pdo, array $body): array
    {
        $categoryId = (int) ($body['category_id'] ?? 0);

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

        if ($categoryName !== 'mechanic' && $quantity && $costPerUnit) {
            $amount = $quantity * $costPerUnit;
        }

        if (!$categoryId || $amount <= 0) {
            Response::error('category_id and a positive amount (or quantity + cost_per_unit) are required.', 422);
        }

        return [$categoryId, $categoryName, $expenseDate, $amount, $itemTypeId, $quantity, $costPerUnit, $mileage];
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
