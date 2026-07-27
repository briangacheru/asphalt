<?php
/**
 * Kicks off "Sign in with Google" — redirects to Google's consent screen.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\GoogleOAuthService;

if (AuthMiddleware::isLoggedIn()) {
    redirect(APP_URL . '/');
}

if (!GoogleOAuthService::isConfigured()) {
    setFlashMessage('danger', 'Google sign-in is not configured on this server.');
    redirect('login');
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . GoogleOAuthService::getAuthUrl($state));
exit;
