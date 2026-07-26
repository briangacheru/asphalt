<?php
require_once __DIR__ . '/includes/bootstrap.php';

use App\Middleware\AuthMiddleware;

AuthMiddleware::requireAdmin();

$requested = basename((string) ($_GET['file'] ?? ''));

if (!preg_match('/^db_backup_[A-Za-z0-9_]+\.sql\.gz$/', $requested)) {
    http_response_code(400);
    exit('Invalid backup filename.');
}

$path = UPLOAD_DIR . 'backups/' . $requested;

if (!is_file($path)) {
    http_response_code(404);
    exit('Backup not found.');
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $requested . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
