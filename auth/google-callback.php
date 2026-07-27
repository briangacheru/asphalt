<?php
/**
 * Google OAuth callback — exchanges the auth code for a profile, then either
 * logs in a matching account, links Google to an existing password account
 * by email, or (if allowed) creates a new account. Respects the same
 * maintenance-mode / registrations-enabled gates as the password signup form.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\GoogleOAuthService;
use App\Services\SiteSettingsService;

if (AuthMiddleware::isLoggedIn()) {
    redirect(APP_URL . '/');
}

$pdo = Database::getInstance()->getConnection();

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$sessionState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if (!GoogleOAuthService::isConfigured() || $state === '' || $code === '' || !hash_equals($sessionState, $state)) {
    setFlashMessage('danger', 'Google sign-in failed. Please try again.');
    redirect('login');
}

$profile = GoogleOAuthService::fetchProfile($code);
if (!$profile) {
    setFlashMessage('danger', 'Could not verify your Google account. Please try again.');
    redirect('login');
}

$googleId = $profile['sub'];
$email = $profile['email'];

// Match an existing account by google_id first, then by email — the email
// match lets someone who registered with a password link their Google account.
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
$stmt->execute([$googleId, $email]);
$user = $stmt->fetch();

$maintenanceMode = SiteSettingsService::get($pdo, 'maintenance_mode') === '1';
$registrationsEnabled = SiteSettingsService::get($pdo, 'registrations_enabled') !== '0';

if (!$user) {
    if ($maintenanceMode) {
        setFlashMessage('danger', 'The site is currently under maintenance. New accounts cannot be created right now.');
        redirect('login');
    }
    if (!$registrationsEnabled) {
        setFlashMessage('danger', 'New registrations are currently closed.');
        redirect('login');
    }

    $firstName = $profile['given_name'] ?? explode(' ', $profile['name'] ?? 'Google')[0];
    $lastName = $profile['family_name'] ?? '';

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, first_name, last_name, google_id, is_verified)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $email,
        hashPassword(bin2hex(random_bytes(32))),
        $firstName,
        $lastName,
        $googleId,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int) $pdo->lastInsertId()]);
    $user = $stmt->fetch();
} elseif (!$user['google_id']) {
    $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?")->execute([$googleId, $user['id']]);
}

if (!$user['is_active']) {
    setFlashMessage('danger', 'This account has been disabled. Contact an administrator.');
    redirect('login');
}

if ($maintenanceMode && ($user['role'] ?? 'user') !== 'admin') {
    setFlashMessage('danger', 'The site is currently under maintenance. Please try again later.');
    redirect('login');
}

// Google has already confirmed this address, so clear any unverified state
// left over from an original password-based registration.
if (!$user['is_verified']) {
    $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$user['id']]);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name'] = $user['first_name'];
$_SESSION['user_role'] = $user['role'] ?? 'user';

$pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

setFlashMessage('success', 'Welcome, ' . $user['first_name'] . '!');
redirect(APP_URL . '/');
