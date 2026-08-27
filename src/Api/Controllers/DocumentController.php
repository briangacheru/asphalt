<?php

namespace App\Api\Controllers;

use App\Api\Response;
use App\Api\UploadHelper;

/**
 * General per-vehicle document/photo library (insurance papers, bill of
 * lading, receipts, etc.) — distinct from the specific sticker/scan/receipt
 * uploads on insurance, driving-license, and expenses. Mirrors
 * vehicle-documents.php.
 */
class DocumentController
{
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/heic' => 'heic',
        'image/heif' => 'heif', 'image/bmp' => 'bmp', 'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
    ];
    private const FORMATS_LABEL = 'JPG, PNG, GIF, WEBP, HEIC, HEIF, BMP, TIFF, PDF';

    /** GET /document-categories — admin-managed, shared across all users. */
    public static function categories(\PDO $pdo): void
    {
        $rows = $pdo->query("SELECT id, slug, label, icon, color FROM vehicle_document_categories ORDER BY label")->fetchAll();
        Response::json(['categories' => $rows]);
    }

    /** GET /vehicles/{id}/documents */
    public static function index(\PDO $pdo, int $userId, int $vehicleId): void
    {
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $stmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ? ORDER BY uploaded_at DESC, id DESC");
        $stmt->execute([$vehicleId]);

        Response::json(['documents' => array_map([self::class, 'format'], $stmt->fetchAll())]);
    }

    /** POST /documents — multipart/form-data with a "document" file field. */
    public static function store(\PDO $pdo, int $userId, array $body): void
    {
        $vehicleId = (int) ($body['vehicle_id'] ?? 0);
        self::assertOwnsVehicle($pdo, $userId, $vehicleId);

        $validCategories = $pdo->query("SELECT slug FROM vehicle_document_categories")->fetchAll(\PDO::FETCH_COLUMN);
        $category = in_array($body['category'] ?? '', $validCategories, true)
            ? $body['category']
            : ($validCategories[0] ?? 'other');

        $title = trim($body['title'] ?? '') ?: null;

        $file = UploadHelper::store('document', 'documents', 'doc', self::MIME_TO_EXT, MAX_UPLOAD_SIZE, self::FORMATS_LABEL);
        if ($file === null) {
            Response::error('A document file is required.', 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO vehicle_documents (vehicle_id, category, title, file_name, file_path, file_type, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId, $category, $title,
            $file['original_name'], $file['stored_filename'], $file['mime'], $_FILES['document']['size'],
        ]);

        $stmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE id = ?");
        $stmt->execute([(int) $pdo->lastInsertId()]);

        Response::json(self::format($stmt->fetch()), 201);
    }

    /** DELETE /documents/{id} */
    public static function destroy(\PDO $pdo, int $userId, int $documentId): void
    {
        $stmt = $pdo->prepare("
            SELECT d.* FROM vehicle_documents d
            JOIN vehicles v ON v.id = d.vehicle_id
            WHERE d.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$documentId, $userId]);
        $document = $stmt->fetch();

        if (!$document) {
            Response::error('Document not found.', 404);
        }

        $filePath = UPLOAD_DIR . 'documents/' . $document['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $pdo->prepare("DELETE FROM vehicle_documents WHERE id = ?")->execute([$documentId]);
        Response::json(['success' => true]);
    }

    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'category' => $row['category'],
            'title' => $row['title'],
            'file_name' => $row['file_name'],
            'file_path' => $row['file_path'],
            'file_type' => $row['file_type'],
            'file_size' => (int) $row['file_size'],
            'uploaded_at' => $row['uploaded_at'],
        ];
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
