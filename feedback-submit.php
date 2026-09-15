<?php
require_once __DIR__ . '/includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\FeedbackService;
use App\Services\EmailService;

AuthMiddleware::check();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired form. Please refresh and try again.']);
    exit;
}

$message = trim($_POST['message'] ?? '');
$category = in_array($_POST['category'] ?? '', ['bug', 'idea', 'general'], true) ? $_POST['category'] : 'general';
$pageUrl = substr(trim($_POST['page_url'] ?? ''), 0, 255);

if ($message === '' || mb_strlen($message) < 5) {
    echo json_encode(['success' => false, 'message' => 'Please enter a bit more detail.']);
    exit;
}
if (mb_strlen($message) > 4000) {
    $message = mb_substr($message, 0, 4000);
}

$pdo = Database::getInstance()->getConnection();
$userId = AuthMiddleware::getCurrentUserId();
$user = getCurrentUser();

try {
    FeedbackService::create($pdo, $userId, $category, $message, $pageUrl ?: null);

    // Best-effort admin notification — feedback is still saved even if email fails.
    try {
        $emailService = new EmailService($pdo);
        $subject = 'New ' . $category . ' feedback from ' . ($user['first_name'] ?? 'a user');
        $html = '<p><strong>' . htmlspecialchars($user['first_name'] . ' ' . ($user['last_name'] ?? '')) . '</strong> ('
            . htmlspecialchars($user['email'] ?? '') . ') submitted ' . htmlspecialchars($category) . ' feedback'
            . ($pageUrl ? ' from <code>' . htmlspecialchars($pageUrl) . '</code>' : '') . ':</p>'
            . '<p>' . nl2br(htmlspecialchars($message)) . '</p>';
        $emailService->send(ADMIN_EMAIL, $subject, $html);
    } catch (\Throwable $e) {
        error_log('Feedback admin email failed: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Thanks — your feedback has been sent.']);
} catch (\Throwable $e) {
    error_log('Feedback submit failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
