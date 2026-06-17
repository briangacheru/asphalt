<?php
/**
 * Bootstrap file for the refactored architecture
 * This provides backward compatibility while using new classes
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load Composer autoloader
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Initialize configuration (provides legacy define() constants)
\App\Helpers\Config::init();

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============ Legacy Function Aliases ============
// These provide backward compatibility with old code

/**
 * Get database connection (legacy function)
 * @deprecated Use App\Database\Database::getInstance()->getConnection() instead
 */
function getDBConnection(): PDO {
    return \App\Database\Database::getInstance()->getConnection();
}

/**
 * Check if user is logged in (legacy function)
 * @deprecated Use App\Middleware\AuthMiddleware::isLoggedIn() instead
 */
function isLoggedIn(): bool {
    return \App\Middleware\AuthMiddleware::isLoggedIn();
}

/**
 * Get current user ID (legacy function)
 * @deprecated Use App\Middleware\AuthMiddleware::getCurrentUserId() instead
 */
function getCurrentUserId(): ?int {
    return \App\Middleware\AuthMiddleware::getCurrentUserId();
}

/**
 * Require authentication (legacy function)
 * @deprecated Use App\Middleware\AuthMiddleware::check() instead
 */
function requireAuth(): void {
    \App\Middleware\AuthMiddleware::check();
}

/**
 * Generate secure random token (legacy function)
 */
function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Hash password (legacy function)
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password (legacy function)
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Sanitize input (legacy function)
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect (legacy function)
 */
function redirect(string $url): void {
    if (headers_sent($file, $line)) {
        echo "<script type='text/javascript'>";
        echo "window.location.href = '" . addslashes($url) . "';";
        echo "</script>";
        echo "<noscript>";
        echo "<meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url) . "'>";
        echo "</noscript>";
        exit;
    }
    header("Location: $url");
    exit;
}

/**
 * Set flash message (legacy function)
 */
function setFlashMessage(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get flash message (legacy function)
 */
function getFlashMessage(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Format date (legacy function)
 */
function formatDate(string $date): string {
    return date('M d, Y', strtotime($date));
}

/**
 * Format number (legacy function)
 */
function formatNumber(int|float $number): string {
    return number_format($number, 0, '.', ',');
}

/**
 * Validate email (legacy function)
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Get current user data (legacy function)
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, phone, avatar FROM users WHERE id = ?");
    $stmt->execute([getCurrentUserId()]);
    return $stmt->fetch() ?: null;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token field HTML
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}
