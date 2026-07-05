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
function sanitize(?string $input): string {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
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
 * Get the current logged-in user's display preferences (currency, distance
 * unit, volume unit, timezone), cached per request. Falls back to app
 * defaults for guests or if a preference isn't set.
 */
function getUserPreferences(): array {
    return \App\Helpers\Preferences::forUser(getDBConnection(), getCurrentUserId());
}

/**
 * Format a monetary amount using the given (or current user's) currency preference.
 */
function formatCurrency(int|float $amount, ?array $prefs = null, int $decimals = 2): string {
    return \App\Helpers\Preferences::formatCurrency((float)$amount, $prefs ?? getUserPreferences(), $decimals);
}

/**
 * Format a distance stored in km, converting to the user's preferred unit for display.
 * Never use this for stored values or calculations — those must stay in raw km.
 */
function formatDistance(int|float $km, ?array $prefs = null, int $decimals = 0): string {
    return \App\Helpers\Preferences::formatDistance((float)$km, $prefs ?? getUserPreferences(), $decimals);
}

/**
 * Format a fuel volume stored in liters, converting to the user's preferred unit for display.
 */
function formatVolume(int|float $liters, ?array $prefs = null, int $decimals = 2): string {
    return \App\Helpers\Preferences::formatVolume((float)$liters, $prefs ?? getUserPreferences(), $decimals);
}

/**
 * Format a price-per-liter rate, converting to a price-per-gallon rate for display if preferred.
 */
function formatPricePerVolume(int|float $pricePerLiter, ?array $prefs = null, int $decimals = 2): string {
    return \App\Helpers\Preferences::formatCurrencyPerVolume((float)$pricePerLiter, $prefs ?? getUserPreferences(), $decimals);
}

/**
 * The unit label only ("km"/"mi"), for places that build their own string.
 */
function distanceUnitLabel(?array $prefs = null): string {
    return \App\Helpers\Preferences::distanceUnit($prefs ?? getUserPreferences());
}

/**
 * The unit label only ("L"/"gal"), for places that build their own string.
 */
function volumeUnitLabel(?array $prefs = null): string {
    return \App\Helpers\Preferences::volumeUnit($prefs ?? getUserPreferences());
}

/**
 * Format a real timestamp (DATETIME columns, e.g. created_at/updated_at/sent_at)
 * in the user's chosen timezone. Do NOT use for DATE-only columns like
 * service_date/fill_date/expense_date/next_due_date — use formatDate() for those.
 */
function formatDateTimeForUser(?string $datetime, ?array $prefs = null, string $format = 'M d, Y g:i A'): string {
    return \App\Helpers\Preferences::formatDateTime($datetime, $prefs ?? getUserPreferences(), $format);
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
