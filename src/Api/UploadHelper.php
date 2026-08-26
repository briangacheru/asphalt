<?php

namespace App\Api;

/**
 * Shared multipart file-upload handling for the API — same server-side
 * MIME detection (never trust the client-supplied Content-Type) and
 * size-limit pattern used by insurance.php / driving-license.php /
 * expenses.php's handleReceiptUpload(). add-service.php's dashboard photo
 * upload trusted the client's filename extension instead; this applies
 * the same server-side detection there too as a strict safety
 * improvement — the storage convention (bare filename under UPLOAD_DIR)
 * is unchanged, so the web app still finds the file the same way.
 */
class UploadHelper
{
    /**
     * Validates and stores $_FILES[$fieldName] under UPLOAD_DIR.$subdir/,
     * generating a collision-safe filename as "{$prefix}_" . time() . '_' . uniqid() . ".ext".
     * Returns null if no file was submitted for this field (upload is optional).
     * Ends the request with a 422/413 JSON error (via Response::error) on validation failure.
     *
     * @param array<string,string> $mimeToExt e.g. ['image/jpeg' => 'jpg', 'application/pdf' => 'pdf']
     * @return array{original_name: string, stored_filename: string, mime: string}|null
     */
    public static function store(
        string $fieldName,
        string $subdir,
        string $prefix,
        array $mimeToExt,
        int $maxSize,
        string $acceptedFormatsLabel
    ): ?array {
        $file = $_FILES[$fieldName] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error("Failed to upload {$fieldName}.", 422);
        }

        if ($file['size'] > $maxSize) {
            Response::error("{$fieldName} is too large. Maximum size: " . (int) ($maxSize / 1024 / 1024) . 'MB.', 413);
        }

        $detectedMime = mime_content_type($file['tmp_name']);
        if (!array_key_exists($detectedMime, $mimeToExt)) {
            Response::error("Unsupported {$fieldName} file type. Allowed: {$acceptedFormatsLabel}.", 422);
        }

        $uploadDir = UPLOAD_DIR . ($subdir !== '' ? $subdir . '/' : '');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = $mimeToExt[$detectedMime];
        $storedFilename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $storedFilename)) {
            Response::error("Failed to save {$fieldName}.", 500);
        }

        return [
            'original_name' => $file['name'],
            'stored_filename' => $storedFilename,
            'mime' => $detectedMime,
        ];
    }
}
