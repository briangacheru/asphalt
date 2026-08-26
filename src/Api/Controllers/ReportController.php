<?php

namespace App\Api\Controllers;

use App\Api\Response;

/**
 * Mirrors reports.php's summary queries (all-time totals, a selected
 * year's totals + monthly breakdown, per-vehicle breakdown, and expense
 * categories for that year). Expense rows with a non-null service_item_id
 * are excluded from expense totals — they mirror a service_items row
 * whose cost is already counted in service_cost, so including them would
 * double-count that spend, same as the web page.
 *
 * Not included here (out of v1 scope — see api/README.md): the service
 * items breakdown, per-category transaction drill-down, and Parts
 * Longevity (which substantially overlaps with GET /vehicles/{id}/maintenance-schedule).
 */
class ReportController
{
    /** GET /reports?year=YYYY&vehicle_id={id} — year and vehicle_id are both optional. */
    public static function index(\PDO $pdo, int $userId): void
    {
        $year = isset($_GET['year']) && ctype_digit((string) $_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
        $vehicleFilter = isset($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : null;

        if ($vehicleFilter !== null) {
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
            $stmt->execute([$vehicleFilter, $userId]);
            if (!$stmt->fetch()) {
                Response::error('Vehicle not found.', 404);
            }
        }

        $whereVehicle = "AND vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId})";
        if ($vehicleFilter !== null) {
            $whereVehicle .= " AND vehicle_id = {$vehicleFilter}";
        }

        $overall = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND user_id = {$userId}) as total_vehicles,
                (SELECT COUNT(*) FROM service_records WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId})) as total_services,
                (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId})) as total_service_cost,
                (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId})) as total_fuel_cost,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId}) AND service_item_id IS NULL) as total_expenses,
                (SELECT COALESCE(SUM(liters), 0) FROM fuel_log WHERE vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId})) as total_fuel
        ")->fetch();

        $yearSummary = $pdo->query("
            SELECT
                (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE YEAR(service_date) = {$year} {$whereVehicle}) as service_cost,
                (SELECT COUNT(*) FROM service_records WHERE YEAR(service_date) = {$year} {$whereVehicle}) as service_count,
                (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE YEAR(fill_date) = {$year} {$whereVehicle}) as fuel_cost,
                (SELECT COALESCE(SUM(liters), 0) FROM fuel_log WHERE YEAR(fill_date) = {$year} {$whereVehicle}) as fuel_liters,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE YEAR(expense_date) = {$year} {$whereVehicle} AND service_item_id IS NULL) as expense_cost
        ")->fetch();
        $yearSummary['total'] = $yearSummary['service_cost'] + $yearSummary['fuel_cost'] + $yearSummary['expense_cost'];

        $monthly = $pdo->query("
            SELECT
                months.month,
                COALESCE(services.cost, 0) as service_cost,
                COALESCE(fuel.cost, 0) as fuel_cost,
                COALESCE(expenses.cost, 0) as expense_cost
            FROM (
                SELECT 1 as month UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
                UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
            ) months
            LEFT JOIN (
                SELECT MONTH(service_date) as month, SUM(service_cost) as cost
                FROM service_records WHERE YEAR(service_date) = {$year} {$whereVehicle} GROUP BY MONTH(service_date)
            ) services ON months.month = services.month
            LEFT JOIN (
                SELECT MONTH(fill_date) as month, SUM(total_cost) as cost
                FROM fuel_log WHERE YEAR(fill_date) = {$year} {$whereVehicle} GROUP BY MONTH(fill_date)
            ) fuel ON months.month = fuel.month
            LEFT JOIN (
                SELECT MONTH(expense_date) as month, SUM(amount) as cost
                FROM expenses WHERE YEAR(expense_date) = {$year} {$whereVehicle} AND service_item_id IS NULL GROUP BY MONTH(expense_date)
            ) expenses ON months.month = expenses.month
            ORDER BY months.month
        ")->fetchAll();

        $vehicleStats = $pdo->query("
            SELECT
                v.id, v.make, v.model, v.year,
                COALESCE((SELECT SUM(service_cost) FROM service_records WHERE vehicle_id = v.id AND YEAR(service_date) = {$year}), 0) as service_cost,
                COALESCE((SELECT SUM(total_cost) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = {$year}), 0) as fuel_cost,
                COALESCE((SELECT SUM(amount) FROM expenses WHERE vehicle_id = v.id AND YEAR(expense_date) = {$year} AND service_item_id IS NULL), 0) as expense_cost,
                COALESCE((SELECT SUM(liters) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = {$year}), 0) as fuel_liters
            FROM vehicles v
            WHERE v.is_active = 1 AND v.user_id = {$userId}
            ORDER BY (
                COALESCE((SELECT SUM(service_cost) FROM service_records WHERE vehicle_id = v.id AND YEAR(service_date) = {$year}), 0) +
                COALESCE((SELECT SUM(total_cost) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = {$year}), 0) +
                COALESCE((SELECT SUM(amount) FROM expenses WHERE vehicle_id = v.id AND YEAR(expense_date) = {$year} AND service_item_id IS NULL), 0)
            ) DESC
        ")->fetchAll();

        $expenseCategoryFilter = $vehicleFilter !== null ? "AND e.vehicle_id = {$vehicleFilter}" : '';
        $expenseCategories = $pdo->query("
            SELECT ec.id, ec.name, ec.icon, COUNT(e.id) as count, COALESCE(SUM(e.amount), 0) as total
            FROM expense_categories ec
            LEFT JOIN expenses e ON ec.id = e.category_id AND YEAR(e.expense_date) = {$year}
                AND e.vehicle_id IN (SELECT id FROM vehicles WHERE user_id = {$userId}) {$expenseCategoryFilter}
            GROUP BY ec.id
            ORDER BY total DESC
        ")->fetchAll();

        Response::json([
            'year' => $year,
            'available_years' => range((int) date('Y'), (int) date('Y') - 5),
            'overall' => $overall,
            'year_summary' => $yearSummary,
            'monthly' => $monthly,
            'vehicles' => $vehicleStats,
            'expense_categories' => $expenseCategories,
        ]);
    }
}
