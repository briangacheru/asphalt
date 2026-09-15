<?php
require_once __DIR__ . '/includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\PushSubscriptionService;

AuthMiddleware::check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = json_decode(file_get_contents('php://input'), true) ?: [];

if (!verifyCSRFToken($raw['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired form. Please refresh and try again.']);
    exit;
}

$endpoint = trim($raw['endpoint'] ?? '');
if ($endpoint === '') {
    echo json_encode(['success' => false, 'message' => 'Missing endpoint.']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$userId = AuthMiddleware::getCurrentUserId();

try {
    PushSubscriptionService::delete($pdo, $userId, $endpoint);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('Push unsubscribe failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not remove subscription.']);
}
