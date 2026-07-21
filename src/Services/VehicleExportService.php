<?php

namespace App\Services;

use PDO;
use ZipArchive;

/**
 * Builds a self-contained ZIP export of one vehicle: every record that
 * references it (service history, fuel log, expenses, maintenance
 * schedule, mileage log, documents & photos) plus the actual uploaded
 * files, so ownership of a vehicle can be handed to another account —
 * or recovered after deletion — without losing anything.
 *
 * The ZIP has this shape:
 *   manifest.json
 *   files/vehicle_image.<ext>
 *   files/documents/<stored_filename>
 *   files/receipts/<stored_filename>
 *
 * manifest.json paths are always relative to the zip root, never to the
 * live filesystem, so an export is portable to a different install.
 */
class VehicleExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{path:string, filename:string}|null null if the vehicle
     *         doesn't exist or doesn't belong to $userId.
     */
    public function buildZip(int $vehicleId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicleId, $userId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            return null;
        }

        $manifest = [
            'export_version' => self::EXPORT_VERSION,
            'exported_at'    => date('c'),
            'vehicle'        => [
                'make'             => $vehicle['make'],
                'model'            => $vehicle['model'],
                'year'             => (int) $vehicle['year'],
                'license_plate'    => $vehicle['license_plate'],
                'vin'              => $vehicle['vin'],
                'color'            => $vehicle['color'],
                'fuel_type'        => $vehicle['fuel_type'],
                'transmission'     => $vehicle['transmission'],
                'engine_capacity'  => $vehicle['engine_capacity'],
                'purchase_date'    => $vehicle['purchase_date'],
                'purchase_mileage' => (int) $vehicle['purchase_mileage'],
                'current_mileage'  => (int) $vehicle['current_mileage'],
                'notes'            => $vehicle['notes'],
                'image_zip_path'   => null,
            ],
            'service_records'      => [],
            'fuel_log'              => [],
            'expenses'              => [],
            'maintenance_schedule'  => [],
            'mileage_log'           => [],
            'vehicle_documents'     => [],
        ];

        $zipTmpPath = tempnam(sys_get_temp_dir(), 'vexport_');
        $zip = new ZipArchive();
        if ($zip->open($zipTmpPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipTmpPath);
            return null;
        }

        // Vehicle image
        if (!empty($vehicle['image_path']) && file_exists(UPLOAD_DIR . $vehicle['image_path'])) {
            $ext = pathinfo($vehicle['image_path'], PATHINFO_EXTENSION);
            $zipPath = 'files/vehicle_image.' . $ext;
            $zip->addFile(UPLOAD_DIR . $vehicle['image_path'], $zipPath);
            $manifest['vehicle']['image_zip_path'] = $zipPath;
        }

        // Service records + their items
        $stmt = $this->pdo->prepare("SELECT * FROM service_records WHERE vehicle_id = ? ORDER BY id");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $record) {
            $itemsStmt = $this->pdo->prepare("SELECT * FROM service_items WHERE service_record_id = ? ORDER BY id");
            $itemsStmt->execute([$record['id']]);

            $dashboardImageZipPath = null;
            if (!empty($record['dashboard_image']) && file_exists(UPLOAD_DIR . $record['dashboard_image'])) {
                $ext = pathinfo($record['dashboard_image'], PATHINFO_EXTENSION);
                $dashboardImageZipPath = 'files/dashboard_images/' . $record['id'] . '.' . $ext;
                $zip->addFile(UPLOAD_DIR . $record['dashboard_image'], $dashboardImageZipPath);
            }

            $manifest['service_records'][] = [
                'service_date'          => $record['service_date'],
                'mileage'                => (int) $record['mileage'],
                'mileage_source'         => $record['mileage_source'],
                'oil_interval'           => (int) $record['oil_interval'],
                'next_service_mileage'   => (int) $record['next_service_mileage'],
                'service_cost'           => (float) $record['service_cost'],
                'service_location'       => $record['service_location'],
                'technician_name'        => $record['technician_name'],
                'notes'                  => $record['notes'],
                'dashboard_image_zip_path' => $dashboardImageZipPath,
                'items'                  => array_map(static fn ($item) => [
                    'item_type'   => $item['item_type'],
                    'item_name'   => $item['item_name'],
                    'brand'       => $item['brand'],
                    'part_number' => $item['part_number'],
                    'quantity'    => (int) $item['quantity'],
                    'cost'        => (float) $item['cost'],
                    'notes'       => $item['notes'],
                ], $itemsStmt->fetchAll()),
            ];
        }

        // Fuel log
        $stmt = $this->pdo->prepare("SELECT * FROM fuel_log WHERE vehicle_id = ? ORDER BY id");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $log) {
            $manifest['fuel_log'][] = [
                'fill_date'       => $log['fill_date'],
                'mileage'         => (int) $log['mileage'],
                'liters'          => (float) $log['liters'],
                'price_per_liter' => (float) $log['price_per_liter'],
                'total_cost'      => (float) $log['total_cost'],
                'fuel_type'       => $log['fuel_type'],
                'station_name'    => $log['station_name'],
                'full_tank'       => (int) $log['full_tank'],
            ];
        }

        // Expenses (category referenced by name, not id — ids aren't portable across installs)
        $stmt = $this->pdo->prepare("
            SELECT e.*, ec.name AS category_name
            FROM expenses e
            JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.vehicle_id = ?
            ORDER BY e.id
        ");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $expense) {
            // receipt_path is stored web-root-relative (e.g. "uploads/receipts/xxx.jpg"),
            // unlike image_path/file_path elsewhere which are bare filenames under UPLOAD_DIR
            $receiptZipPath = null;
            if (!empty($expense['receipt_path'])) {
                $receiptFsPath = dirname(UPLOAD_DIR) . '/' . $expense['receipt_path'];
                if (file_exists($receiptFsPath)) {
                    $ext = pathinfo($expense['receipt_path'], PATHINFO_EXTENSION);
                    $receiptZipPath = 'files/receipts/' . $expense['id'] . '.' . $ext;
                    $zip->addFile($receiptFsPath, $receiptZipPath);
                }
            }

            $manifest['expenses'][] = [
                'category_name'  => $expense['category_name'],
                'expense_date'   => $expense['expense_date'],
                'amount'         => (float) $expense['amount'],
                'description'    => $expense['description'],
                'item_type'      => $expense['item_type'],
                'item_name'      => $expense['item_name'],
                'brand'          => $expense['brand'],
                'part_number'    => $expense['part_number'],
                'quantity'       => $expense['quantity'] !== null ? (int) $expense['quantity'] : null,
                'cost_per_unit'  => $expense['cost_per_unit'] !== null ? (float) $expense['cost_per_unit'] : null,
                'item_notes'     => $expense['item_notes'],
                'receipt_zip_path' => $receiptZipPath,
            ];
        }

        // Maintenance schedule
        $stmt = $this->pdo->prepare("SELECT * FROM maintenance_schedule WHERE vehicle_id = ? ORDER BY id");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $item) {
            $manifest['maintenance_schedule'][] = [
                'item_type'             => $item['item_type'],
                'interval_km'           => $item['interval_km'] !== null ? (int) $item['interval_km'] : null,
                'interval_months'       => $item['interval_months'] !== null ? (int) $item['interval_months'] : null,
                'last_replaced_date'    => $item['last_replaced_date'],
                'last_replaced_mileage' => $item['last_replaced_mileage'] !== null ? (int) $item['last_replaced_mileage'] : null,
                'next_due_mileage'      => $item['next_due_mileage'] !== null ? (int) $item['next_due_mileage'] : null,
                'next_due_date'         => $item['next_due_date'],
                'priority'              => $item['priority'],
                'notes'                 => $item['notes'],
            ];
        }

        // Mileage log
        $stmt = $this->pdo->prepare("SELECT * FROM mileage_log WHERE vehicle_id = ? ORDER BY id");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $log) {
            $manifest['mileage_log'][] = [
                'mileage' => (int) $log['mileage'],
                'log_date' => $log['log_date'],
                'source'  => $log['source'],
                'notes'   => $log['notes'] ?? null,
            ];
        }

        // Documents & photos (category referenced by slug, with enough info to recreate it if missing)
        $stmt = $this->pdo->prepare("
            SELECT d.*, c.slug AS category_slug, c.label AS category_label, c.icon AS category_icon, c.color AS category_color
            FROM vehicle_documents d
            LEFT JOIN vehicle_document_categories c ON d.category = c.slug
            WHERE d.vehicle_id = ?
            ORDER BY d.id
        ");
        $stmt->execute([$vehicleId]);
        foreach ($stmt->fetchAll() as $doc) {
            $docZipPath = null;
            $docFile = UPLOAD_DIR . 'documents/' . $doc['file_path'];
            if (file_exists($docFile)) {
                $ext = pathinfo($doc['file_path'], PATHINFO_EXTENSION);
                $docZipPath = 'files/documents/' . $doc['id'] . '.' . $ext;
                $zip->addFile($docFile, $docZipPath);
            }

            $manifest['vehicle_documents'][] = [
                'category_slug'  => $doc['category'],
                'category_label' => $doc['category_label'] ?? ucfirst(str_replace('_', ' ', $doc['category'])),
                'category_icon'  => $doc['category_icon'] ?? 'fa-file',
                'category_color' => $doc['category_color'] ?? 'dark',
                'title'          => $doc['title'],
                'file_name'      => $doc['file_name'],
                'file_type'      => $doc['file_type'],
                'uploaded_at'    => $doc['uploaded_at'],
                'file_zip_path'  => $docZipPath,
            ];
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $vehicle['make'] . '_' . $vehicle['model']);
        $filename = trim($safeName, '_') . '_export_' . date('Ymd') . '.zip';

        return ['path' => $zipTmpPath, 'filename' => $filename];
    }
}
