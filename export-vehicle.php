<?php
require_once 'includes/bootstrap.php';

use App\Helpers\IdCodec;
use App\Services\VehicleExportService;

\App\Middleware\AuthMiddleware::check();
$pdo = \App\Database\Database::getInstance()->getConnection();
$userId = \App\Middleware\AuthMiddleware::getCurrentUserId();

$vehicleId = IdCodec::decode($_GET['vehicle_id'] ?? null) ?? 0;

if (!$vehicleId) {
    setFlashMessage('danger', 'Invalid vehicle.');
    redirect('vehicles');
}

$export = (new VehicleExportService($pdo))->buildZip($vehicleId, $userId);

if (!$export) {
    setFlashMessage('danger', 'Vehicle not found.');
    redirect('vehicles');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
header('Content-Length: ' . filesize($export['path']));
header('Cache-Control: no-cache, no-store, must-revalidate');

readfile($export['path']);
@unlink($export['path']);
exit;
