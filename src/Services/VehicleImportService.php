<?php

namespace App\Services;

use PDO;
use ZipArchive;

/**
 * Imports a ZIP built by VehicleExportService, recreating the vehicle and
 * every related record under a (possibly different) user's account with
 * fresh IDs. Treats the ZIP as untrusted input end to end: entry names,
 * counts, and sizes are bounded before extraction; every referenced file
 * is re-validated by real MIME type (never trusted extension/manifest
 * claims) and copied under a brand-new server-generated filename; all
 * database writes happen in one transaction so a bad record can't leave a
 * half-imported vehicle behind.
 */
class VehicleImportService
{
    private const MAX_ZIP_SIZE = 50 * 1024 * 1024; // 50MB
    private const MAX_ZIP_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_SIZE = 200 * 1024 * 1024; // 200MB
    private const MAX_RECORDS_PER_TABLE = 5000;
    private const SUPPORTED_EXPORT_VERSION = 1;

    private const IMAGE_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/bmp'  => 'bmp',
        'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
    ];

    private array $copiedFiles = [];
    private ?string $extractDir = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{success:bool, vehicleId:?int, error:?string}
     */
    public function importZip(string $zipPath, int $userId): array
    {
        if (!file_exists($zipPath) || filesize($zipPath) > self::MAX_ZIP_SIZE) {
            return $this->fail('File is missing or exceeds the ' . (self::MAX_ZIP_SIZE / 1024 / 1024) . 'MB import limit.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->fail('That file is not a valid ZIP archive.');
        }

        $entryCount = $zip->numFiles;
        if ($entryCount > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            return $this->fail('Archive contains too many files.');
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = $stat['name'];
            // Reject path traversal / absolute paths outright
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, "\0")) {
                $zip->close();
                return $this->fail('Archive contains an unsafe file path.');
            }
            $totalUncompressed += $stat['size'];
        }
        if ($totalUncompressed > self::MAX_UNCOMPRESSED_SIZE) {
            $zip->close();
            return $this->fail('Archive is too large once decompressed.');
        }

        $this->extractDir = sys_get_temp_dir() . '/vimport_' . bin2hex(random_bytes(8));
        if (!mkdir($this->extractDir, 0755, true)) {
            $zip->close();
            return $this->fail('Could not prepare a workspace for the import.');
        }

        $zip->extractTo($this->extractDir);
        $zip->close();

        $manifestPath = $this->extractDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            $this->cleanup();
            return $this->fail('Archive is missing manifest.json — this does not look like a vehicle export.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['vehicle']) || !is_array($manifest['vehicle'])) {
            $this->cleanup();
            return $this->fail('manifest.json is malformed.');
        }
        if ((int) ($manifest['export_version'] ?? 0) !== self::SUPPORTED_EXPORT_VERSION) {
            $this->cleanup();
            return $this->fail('This export was created by an incompatible version of the app.');
        }

        $vehicleData = $manifest['vehicle'];
        if (empty($vehicleData['make']) || empty($vehicleData['model'])) {
            $this->cleanup();
            return $this->fail('manifest.json is missing required vehicle details.');
        }

        foreach (['service_records', 'fuel_log', 'expenses', 'maintenance_schedule', 'mileage_log', 'vehicle_documents'] as $key) {
            if (isset($manifest[$key]) && is_array($manifest[$key]) && count($manifest[$key]) > self::MAX_RECORDS_PER_TABLE) {
                $this->cleanup();
                return $this->fail("Archive contains an implausible number of $key records.");
            }
        }

        try {
            $this->pdo->beginTransaction();

            $imagePath = $this->importVehicleImage($vehicleData['image_zip_path'] ?? null);

            $stmt = $this->pdo->prepare("
                INSERT INTO vehicles (user_id, make, model, year, license_plate, vin, color, fuel_type,
                                     transmission, engine_capacity, purchase_date, purchase_mileage,
                                     current_mileage, image_path, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                sanitize($vehicleData['make']),
                sanitize($vehicleData['model']),
                (int) ($vehicleData['year'] ?? date('Y')),
                sanitize($vehicleData['license_plate'] ?? ''),
                sanitize($vehicleData['vin'] ?? ''),
                sanitize($vehicleData['color'] ?? ''),
                sanitize($vehicleData['fuel_type'] ?? 'petrol'),
                sanitize($vehicleData['transmission'] ?? 'manual'),
                sanitize($vehicleData['engine_capacity'] ?? ''),
                $vehicleData['purchase_date'] ?: null,
                (int) ($vehicleData['purchase_mileage'] ?? 0),
                (int) ($vehicleData['current_mileage'] ?? 0),
                $imagePath,
                $vehicleData['notes'] ?? null,
            ]);
            $newVehicleId = (int) $this->pdo->lastInsertId();

            $this->importServiceRecords($newVehicleId, $manifest['service_records'] ?? []);
            $this->importFuelLog($newVehicleId, $manifest['fuel_log'] ?? []);
            $this->importExpenses($newVehicleId, $manifest['expenses'] ?? []);
            $this->importMaintenanceSchedule($newVehicleId, $manifest['maintenance_schedule'] ?? []);
            $this->importMileageLog($newVehicleId, $manifest['mileage_log'] ?? []);
            $this->importDocuments($newVehicleId, $manifest['vehicle_documents'] ?? []);

            $this->pdo->commit();
            $this->cleanup();

            return ['success' => true, 'vehicleId' => $newVehicleId, 'error' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($this->copiedFiles as $path) {
                @unlink($path);
            }
            error_log('Vehicle import failed: ' . $e->getMessage());
            $this->cleanup();
            return $this->fail('Import failed: the archive may be corrupted or incompatible.');
        }
    }

    private function importVehicleImage(?string $zipRelativePath): ?string
    {
        $realPath = $this->resolveAndValidateFile($zipRelativePath);
        if (!$realPath) {
            return null;
        }

        $ext = self::IMAGE_MIME_TO_EXT[mime_content_type($realPath)] ?? null;
        if (!$ext) {
            return null;
        }

        $filename = 'vehicle_' . time() . '_' . uniqid() . '.' . $ext;
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }
        if (!copy($realPath, UPLOAD_DIR . $filename)) {
            return null;
        }
        $this->copiedFiles[] = UPLOAD_DIR . $filename;

        return $filename;
    }

    private function importServiceRecords(int $vehicleId, array $records): void
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $dashboardImage = $this->importGenericImage($record['dashboard_image_zip_path'] ?? null);

            $stmt = $this->pdo->prepare("
                INSERT INTO service_records (vehicle_id, service_date, mileage, dashboard_image,
                                            mileage_source, oil_interval, next_service_mileage,
                                            service_cost, service_location, technician_name, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                $record['service_date'] ?? date('Y-m-d'),
                (int) ($record['mileage'] ?? 0),
                $dashboardImage,
                sanitize($record['mileage_source'] ?? 'manual'),
                (int) ($record['oil_interval'] ?? 7500),
                (int) ($record['next_service_mileage'] ?? 0),
                (float) ($record['service_cost'] ?? 0),
                sanitize($record['service_location'] ?? ''),
                sanitize($record['technician_name'] ?? ''),
                $record['notes'] ?? null,
            ]);
            $newRecordId = (int) $this->pdo->lastInsertId();

            foreach (($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $stmt = $this->pdo->prepare("
                    INSERT INTO service_items (service_record_id, item_type, item_name, brand, part_number, quantity, cost, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $newRecordId,
                    sanitize($item['item_type'] ?? 'other'),
                    sanitize($item['item_name'] ?? ''),
                    sanitize($item['brand'] ?? ''),
                    sanitize($item['part_number'] ?? ''),
                    (int) ($item['quantity'] ?? 1),
                    (float) ($item['cost'] ?? 0),
                    $item['notes'] ?? null,
                ]);
            }
        }
    }

    private function importFuelLog(int $vehicleId, array $logs): void
    {
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_log (vehicle_id, fill_date, mileage, liters, price_per_liter, total_cost, fuel_type, station_name, full_tank)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                $log['fill_date'] ?? date('Y-m-d'),
                (int) ($log['mileage'] ?? 0),
                (float) ($log['liters'] ?? 0),
                (float) ($log['price_per_liter'] ?? 0),
                (float) ($log['total_cost'] ?? 0),
                sanitize($log['fuel_type'] ?? ''),
                sanitize($log['station_name'] ?? ''),
                (int) ($log['full_tank'] ?? 1),
            ]);
        }
    }

    private function importExpenses(int $vehicleId, array $expenses): void
    {
        foreach ($expenses as $expense) {
            if (!is_array($expense)) {
                continue;
            }

            $categoryId = $this->resolveExpenseCategoryId($expense['category_name'] ?? '');
            $receiptPath = $this->importReceipt($expense['receipt_zip_path'] ?? null);

            $stmt = $this->pdo->prepare("
                INSERT INTO expenses (vehicle_id, category_id, expense_date, amount, description, item_type, item_name, brand, part_number, quantity, cost_per_unit, item_notes, receipt_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                $categoryId,
                $expense['expense_date'] ?? date('Y-m-d'),
                (float) ($expense['amount'] ?? 0),
                $expense['description'] ?? null,
                $expense['item_type'] ? sanitize($expense['item_type']) : null,
                $expense['item_name'] ? sanitize($expense['item_name']) : null,
                $expense['brand'] ? sanitize($expense['brand']) : null,
                $expense['part_number'] ? sanitize($expense['part_number']) : null,
                $expense['quantity'] !== null ? (int) $expense['quantity'] : null,
                $expense['cost_per_unit'] !== null ? (float) $expense['cost_per_unit'] : null,
                $expense['item_notes'] ?? null,
                $receiptPath,
            ]);
        }
    }

    private function importMaintenanceSchedule(int $vehicleId, array $items): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO maintenance_schedule (vehicle_id, item_type, interval_km, interval_months, last_replaced_date, last_replaced_mileage, next_due_mileage, next_due_date, priority, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                sanitize($item['item_type'] ?? 'Other'),
                $item['interval_km'] !== null ? (int) $item['interval_km'] : null,
                $item['interval_months'] !== null ? (int) $item['interval_months'] : null,
                $item['last_replaced_date'] ?: null,
                $item['last_replaced_mileage'] !== null ? (int) $item['last_replaced_mileage'] : null,
                $item['next_due_mileage'] !== null ? (int) $item['next_due_mileage'] : null,
                $item['next_due_date'] ?: null,
                sanitize($item['priority'] ?? 'medium'),
                $item['notes'] ?? null,
            ]);
        }
    }

    private function importMileageLog(int $vehicleId, array $logs): void
    {
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO mileage_log (vehicle_id, mileage, log_date, source, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                (int) ($log['mileage'] ?? 0),
                $log['log_date'] ?? date('Y-m-d'),
                sanitize($log['source'] ?? 'manual'),
                $log['notes'] ?? null,
            ]);
        }
    }

    private function importDocuments(int $vehicleId, array $documents): void
    {
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $filename = $this->importDocumentFile($doc['file_zip_path'] ?? null);
            if (!$filename) {
                continue; // No usable file survived validation — skip this entry rather than fail the whole import
            }

            $slug = $this->resolveDocumentCategorySlug(
                $doc['category_slug'] ?? 'other',
                $doc['category_label'] ?? 'Other',
                $doc['category_icon'] ?? 'fa-file',
                $doc['category_color'] ?? 'dark'
            );

            $stmt = $this->pdo->prepare("
                INSERT INTO vehicle_documents (vehicle_id, category, title, file_name, file_path, file_type, file_size)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicleId,
                $slug,
                $doc['title'] ? sanitize($doc['title']) : null,
                sanitize($doc['file_name'] ?? $filename),
                $filename,
                mime_content_type(UPLOAD_DIR . 'documents/' . $filename),
                filesize(UPLOAD_DIR . 'documents/' . $filename),
            ]);
        }
    }

    private function importDocumentFile(?string $zipRelativePath): ?string
    {
        $realPath = $this->resolveAndValidateFile($zipRelativePath);
        if (!$realPath) {
            return null;
        }

        $ext = self::IMAGE_MIME_TO_EXT[mime_content_type($realPath)] ?? null;
        if (!$ext) {
            return null;
        }

        $dir = UPLOAD_DIR . 'documents/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'doc_' . time() . '_' . uniqid() . '.' . $ext;
        if (!copy($realPath, $dir . $filename)) {
            return null;
        }
        $this->copiedFiles[] = $dir . $filename;

        return $filename;
    }

    private function importGenericImage(?string $zipRelativePath): ?string
    {
        return $this->importVehicleImage($zipRelativePath);
    }

    private function importReceipt(?string $zipRelativePath): ?string
    {
        $realPath = $this->resolveAndValidateFile($zipRelativePath);
        if (!$realPath) {
            return null;
        }

        $ext = self::IMAGE_MIME_TO_EXT[mime_content_type($realPath)] ?? null;
        if (!$ext) {
            return null;
        }

        $dir = dirname(UPLOAD_DIR) . '/uploads/receipts/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = uniqid('receipt_') . '.' . $ext;
        if (!copy($realPath, $dir . $filename)) {
            return null;
        }
        $this->copiedFiles[] = $dir . $filename;

        return 'uploads/receipts/' . $filename;
    }

    /**
     * Resolves a manifest-declared zip-relative path to a real extracted file,
     * refusing anything that escapes the extraction directory.
     */
    private function resolveAndValidateFile(?string $zipRelativePath): ?string
    {
        if (!$zipRelativePath || !$this->extractDir) {
            return null;
        }

        $candidate = realpath($this->extractDir . '/' . $zipRelativePath);
        $base = realpath($this->extractDir);
        if ($candidate === false || $base === false || !str_starts_with($candidate, $base)) {
            return null;
        }

        return is_file($candidate) ? $candidate : null;
    }

    private function resolveExpenseCategoryId(string $categoryName): int
    {
        if ($categoryName !== '') {
            $stmt = $this->pdo->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$categoryName]);
            $id = $stmt->fetchColumn();
            if ($id) {
                return (int) $id;
            }
        }

        $stmt = $this->pdo->query("SELECT id FROM expense_categories WHERE LOWER(name) = 'other' LIMIT 1");
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        return (int) $this->pdo->query("SELECT id FROM expense_categories ORDER BY id LIMIT 1")->fetchColumn();
    }

    private function resolveDocumentCategorySlug(string $slug, string $label, string $icon, string $color): string
    {
        $stmt = $this->pdo->prepare("SELECT slug FROM vehicle_document_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn()) {
            return $slug;
        }

        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-file';
        $allowedColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
        $color = in_array($color, $allowedColors, true) ? $color : 'dark';

        try {
            $this->pdo->prepare("INSERT INTO vehicle_document_categories (slug, label, icon, color) VALUES (?, ?, ?, ?)")
                ->execute([$slug, sanitize($label) ?: 'Other', $icon, $color]);
            return $slug;
        } catch (\PDOException $e) {
            return 'other';
        }
    }

    private function cleanup(): void
    {
        if ($this->extractDir && is_dir($this->extractDir)) {
            $this->removeDirectory($this->extractDir);
        }
        $this->extractDir = null;
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'vehicleId' => null, 'error' => $message];
    }
}
