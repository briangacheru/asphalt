<?php
/**
 * JSON API front controller for the iOS app. Every request under /api/ is
 * rewritten here by .htaccess with the remaining path in $_GET['__path']
 * (e.g. "vehicles/123"). Auth is a bearer token (see ApiTokenService),
 * separate from the web app's session-cookie auth.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Api\Response;
use App\Api\Controllers\AuthController;
use App\Api\Controllers\VehicleController;
use App\Api\Controllers\InsuranceController;
use App\Api\Controllers\DrivingLicenseController;
use App\Api\Controllers\ServiceRecordController;
use App\Api\Controllers\ServiceItemController;
use App\Api\Controllers\FuelLogController;
use App\Api\Controllers\ExpenseController;
use App\Api\Controllers\MaintenanceScheduleController;
use App\Api\Controllers\ReportController;
use App\Database\Database;
use App\Middleware\ApiAuthMiddleware;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = trim((string) ($_GET['__path'] ?? ''), '/');
$segments = $path === '' ? [] : explode('/', $path);

try {
    $pdo = Database::getInstance()->getConnection();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            Response::error('Invalid JSON body.', 400);
        }
    } else {
        $body = $_POST;
    }

    // Only auth/login is reachable without a token.
    $isPublicRoute = $method === 'POST' && $segments === ['auth', 'login'];
    $userId = $isPublicRoute ? null : ApiAuthMiddleware::authenticate($pdo);

    $matched = true;

    switch (true) {
        case $method === 'POST' && $segments === ['auth', 'login']:
            AuthController::login($pdo, $body);
            break;
        case $method === 'POST' && $segments === ['auth', 'logout']:
            AuthController::logout($pdo);
            break;
        case $method === 'GET' && $segments === ['me']:
            AuthController::me($pdo, $userId);
            break;

        case $method === 'GET' && $segments === ['vehicles']:
            VehicleController::index($pdo, $userId);
            break;
        case $method === 'POST' && $segments === ['vehicles']:
            VehicleController::store($pdo, $userId, $body);
            break;
        case $method === 'GET' && count($segments) === 2 && $segments[0] === 'vehicles':
            VehicleController::show($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'PUT' && count($segments) === 2 && $segments[0] === 'vehicles':
            VehicleController::update($pdo, $userId, (int) $segments[1], $body);
            break;

        case $method === 'GET' && $segments === ['insurance']:
            InsuranceController::index($pdo, $userId);
            break;
        case $method === 'POST' && $segments === ['insurance']:
            InsuranceController::store($pdo, $userId, $body);
            break;
        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'insurance' && $segments[1] === 'vehicle':
            InsuranceController::showForVehicle($pdo, $userId, (int) $segments[2]);
            break;
        case $method === 'DELETE' && count($segments) === 2 && $segments[0] === 'insurance':
            InsuranceController::destroy($pdo, $userId, (int) $segments[1]);
            break;

        case $method === 'GET' && $segments === ['driving-license']:
            DrivingLicenseController::index($pdo, $userId);
            break;
        case $method === 'POST' && $segments === ['driving-license']:
            DrivingLicenseController::store($pdo, $userId, $body);
            break;
        case $method === 'DELETE' && count($segments) === 2 && $segments[0] === 'driving-license':
            DrivingLicenseController::destroy($pdo, $userId, (int) $segments[1]);
            break;

        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'vehicles' && $segments[2] === 'service-records':
            ServiceRecordController::index($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'POST' && $segments === ['service-records']:
            ServiceRecordController::store($pdo, $userId, $body);
            break;

        case $method === 'GET' && $segments === ['item-types']:
            ServiceItemController::itemTypes($pdo);
            break;
        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'service-records' && $segments[2] === 'items':
            ServiceItemController::index($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'POST' && count($segments) === 3 && $segments[0] === 'service-records' && $segments[2] === 'items':
            ServiceItemController::store($pdo, $userId, (int) $segments[1], $body);
            break;
        case $method === 'PUT' && count($segments) === 2 && $segments[0] === 'service-items':
            ServiceItemController::update($pdo, $userId, (int) $segments[1], $body);
            break;
        case $method === 'DELETE' && count($segments) === 2 && $segments[0] === 'service-items':
            ServiceItemController::destroy($pdo, $userId, (int) $segments[1]);
            break;

        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'vehicles' && $segments[2] === 'fuel-logs':
            FuelLogController::index($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'POST' && $segments === ['fuel-logs']:
            FuelLogController::store($pdo, $userId, $body);
            break;
        case $method === 'PUT' && count($segments) === 2 && $segments[0] === 'fuel-logs':
            FuelLogController::update($pdo, $userId, (int) $segments[1], $body);
            break;
        case $method === 'DELETE' && count($segments) === 2 && $segments[0] === 'fuel-logs':
            FuelLogController::destroy($pdo, $userId, (int) $segments[1]);
            break;

        case $method === 'GET' && $segments === ['expense-categories']:
            ExpenseController::categories($pdo);
            break;
        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'vehicles' && $segments[2] === 'expenses':
            ExpenseController::index($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'POST' && $segments === ['expenses']:
            ExpenseController::store($pdo, $userId, $body);
            break;
        case $method === 'PUT' && count($segments) === 2 && $segments[0] === 'expenses':
            ExpenseController::update($pdo, $userId, (int) $segments[1], $body);
            break;
        case $method === 'DELETE' && count($segments) === 2 && $segments[0] === 'expenses':
            ExpenseController::destroy($pdo, $userId, (int) $segments[1]);
            break;

        case $method === 'GET' && count($segments) === 3 && $segments[0] === 'vehicles' && $segments[2] === 'maintenance-schedule':
            MaintenanceScheduleController::index($pdo, $userId, (int) $segments[1]);
            break;
        case $method === 'PUT' && count($segments) === 2 && $segments[0] === 'maintenance-schedule':
            MaintenanceScheduleController::update($pdo, $userId, (int) $segments[1], $body);
            break;

        case $method === 'GET' && $segments === ['reports']:
            ReportController::index($pdo, $userId);
            break;

        default:
            $matched = false;
    }

    if (!$matched) {
        Response::error('Not found.', 404);
    }
} catch (\Throwable $e) {
    error_log('API error [' . $method . ' /' . $path . ']: ' . $e->getMessage());
    Response::error('Server error.', 500);
}
