<?php

namespace App\Services;

use App\Helpers\IdCodec;

/**
 * Global topbar search: looks across a user's vehicles, expenses, service
 * records/maintenance reminders, email history, and synthesizes links into
 * the reports page. Every query is scoped to the given user_id (directly or
 * via a vehicles join, since most of these tables hang off vehicle_id only).
 */
class SearchService
{
    public static function search(\PDO $pdo, int $userId, string $query, int $limitPerCategory = 10): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }

        $like = '%' . self::escapeLike($query) . '%';

        $groups = [
            'vehicles' => ['label' => 'Vehicles', 'items' => self::searchVehicles($pdo, $userId, $like, $limitPerCategory)],
            'expenses' => ['label' => 'Expenses', 'items' => self::searchExpenses($pdo, $userId, $like, $limitPerCategory)],
            'service' => ['label' => 'Service & Reminders', 'items' => self::searchServiceRecords($pdo, $userId, $like, $limitPerCategory)],
            'reports' => ['label' => 'Reports', 'items' => self::searchReports($pdo, $userId, $like, $query, $limitPerCategory)],
            'emails' => ['label' => 'Email History', 'items' => self::searchEmails($pdo, $userId, $like, $limitPerCategory)],
        ];

        return array_filter($groups, fn(array $group): bool => !empty($group['items']));
    }

    private static function searchVehicles(\PDO $pdo, int $userId, string $like, int $limit): array
    {
        $limit = (int) $limit;
        $stmt = $pdo->prepare("
            SELECT id, make, model, year, license_plate, vin
            FROM vehicles
            WHERE user_id = ? AND is_active = 1
              AND (make LIKE ? OR model LIKE ? OR license_plate LIKE ? OR vin LIKE ? OR CONCAT(make, ' ', model) LIKE ?)
            ORDER BY make, model
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like, $like, $like, $like]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $subtitle = $row['license_plate'] ? 'Plate: ' . $row['license_plate'] : ($row['vin'] ? 'VIN: ' . $row['vin'] : '');
            $items[] = [
                'title' => trim($row['make'] . ' ' . $row['model'] . ' (' . $row['year'] . ')'),
                'subtitle' => $subtitle,
                'url' => 'vehicle-details?id=' . IdCodec::encode($row['id']),
                'icon' => 'fa-car',
            ];
        }

        return $items;
    }

    private static function searchExpenses(\PDO $pdo, int $userId, string $like, int $limit): array
    {
        $limit = (int) $limit;
        $stmt = $pdo->prepare("
            SELECT e.vehicle_id, e.expense_date, e.amount, e.description, e.item_name, e.brand,
                   v.make, v.model
            FROM expenses e
            JOIN vehicles v ON e.vehicle_id = v.id
            WHERE v.user_id = ?
              AND (e.description LIKE ? OR e.item_name LIKE ? OR e.brand LIKE ? OR e.part_number LIKE ?)
            ORDER BY e.expense_date DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like, $like, $like]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = $row['item_name'] ?: ($row['description'] ?: 'Expense');
            $vehicleLabel = trim($row['make'] . ' ' . $row['model']);
            $items[] = [
                'title' => $title,
                'subtitle' => $vehicleLabel . ' · $' . number_format((float) $row['amount'], 2) . ' · ' . date('M d, Y', strtotime($row['expense_date'])),
                'url' => 'expenses?vehicle_id=' . IdCodec::encode($row['vehicle_id']),
                'icon' => 'fa-receipt',
            ];
        }

        return $items;
    }

    private static function searchServiceRecords(\PDO $pdo, int $userId, string $like, int $limit): array
    {
        $limit = (int) $limit;
        $items = [];

        $stmt = $pdo->prepare("
            SELECT sr.vehicle_id, sr.service_date, sr.service_location, sr.technician_name,
                   v.make, v.model
            FROM service_records sr
            JOIN vehicles v ON sr.vehicle_id = v.id
            WHERE v.user_id = ?
              AND (sr.notes LIKE ? OR sr.service_location LIKE ? OR sr.technician_name LIKE ?)
            ORDER BY sr.service_date DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'title' => 'Service: ' . trim($row['make'] . ' ' . $row['model']),
                'subtitle' => ($row['service_location'] ?: 'Service record') . ' · ' . date('M d, Y', strtotime($row['service_date'])),
                'url' => 'service-history?vehicle_id=' . IdCodec::encode($row['vehicle_id']),
                'icon' => 'fa-wrench',
            ];
        }

        $stmt = $pdo->prepare("
            SELECT ms.vehicle_id, ms.item_type, ms.next_due_date,
                   v.make, v.model
            FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE v.user_id = ?
              AND (ms.item_type LIKE ? OR ms.notes LIKE ?)
            ORDER BY ms.next_due_date ASC
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'title' => 'Reminder: ' . ($row['item_type'] ?: 'Maintenance') . ' — ' . trim($row['make'] . ' ' . $row['model']),
                'subtitle' => $row['next_due_date'] ? 'Due ' . date('M d, Y', strtotime($row['next_due_date'])) : '',
                'url' => 'maintenance-schedule?vehicle_id=' . IdCodec::encode($row['vehicle_id']),
                'icon' => 'fa-bell',
            ];
        }

        return array_slice($items, 0, $limit);
    }

    private static function searchReports(\PDO $pdo, int $userId, string $like, string $rawQuery, int $limit): array
    {
        $limit = (int) $limit;
        $items = [];

        $stmt = $pdo->prepare("
            SELECT id, make, model
            FROM vehicles
            WHERE user_id = ? AND is_active = 1
              AND (make LIKE ? OR model LIKE ? OR CONCAT(make, ' ', model) LIKE ?)
            ORDER BY make, model
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'title' => 'Report: ' . trim($row['make'] . ' ' . $row['model']),
                'subtitle' => 'View cost & service report',
                'url' => 'reports?vehicle_id=' . IdCodec::encode($row['id']),
                'icon' => 'fa-chart-bar',
            ];
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $rawQuery, $m)) {
            $items[] = [
                'title' => 'Report: ' . $m[0],
                'subtitle' => 'View yearly cost report',
                'url' => 'reports?year=' . urlencode($m[0]),
                'icon' => 'fa-chart-bar',
            ];
        }

        return array_slice($items, 0, $limit);
    }

    private static function searchEmails(\PDO $pdo, int $userId, string $like, int $limit): array
    {
        $limit = (int) $limit;
        $stmt = $pdo->prepare("
            SELECT el.email_type, el.subject, el.created_at,
                   v.make, v.model
            FROM email_log el
            JOIN vehicles v ON el.vehicle_id = v.id
            WHERE v.user_id = ?
              AND (el.subject LIKE ? OR el.recipient_email LIKE ? OR el.email_type LIKE ?)
            ORDER BY el.created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId, $like, $like, $like]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleLabel = trim($row['make'] . ' ' . $row['model']);
            $items[] = [
                'title' => $row['subject'] ?: ucwords(str_replace('_', ' ', $row['email_type'])),
                'subtitle' => $vehicleLabel . ' · ' . date('M d, Y', strtotime($row['created_at'])),
                'url' => 'email-history',
                'icon' => 'fa-envelope',
            ];
        }

        return $items;
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
