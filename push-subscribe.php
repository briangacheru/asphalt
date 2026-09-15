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
$p256dh = trim($raw['keys']['p256dh'] ?? '');
$authToken = trim($raw['keys']['auth'] ?? '');

if ($endpoint === '' || $p256dh === '' || $authToken === '') {
    echo json_encode(['success' => false, 'message' => 'Incomplete subscription.']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$userId = AuthMiddleware::getCurrentUserId();

try {
    PushSubscriptionService::save($pdo, $userId, $endpoint, $p256dh, $authToken, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('Push subscribe failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save subscription.']);
}
