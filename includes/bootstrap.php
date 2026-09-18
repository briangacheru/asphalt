<?php
/**
 * Bootstrap file for the refactored architecture
 * This provides backward compatibility while using new classes
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 7 * 24 * 3600; // 7 days
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_set_cookie_params($sessionLifetime);
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
ini_set('display_errors', 0); // Disabled for production - set to 1 for development only

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
 * Format an amount using the current user's preferred currency (Settings >
 * Preferences > Currency — users.default_currency). Purely a display label:
 * amounts are stored and summed as plain decimals throughout the app, there's
 * no live FX conversion between currencies.
 */
function currencySymbol(): string {
    static $symbol = null;

    if ($symbol === null) {
        $currency = 'KES';
        if (isLoggedIn()) {
            try {
                $stmt = getDBConnection()->prepare("SELECT default_currency FROM users WHERE id = ?");
                $stmt->execute([getCurrentUserId()]);
                $currency = $stmt->fetchColumn() ?: 'KES';
            } catch (PDOException $e) {
                // default_currency column may not exist yet on older schemas — fall back silently.
            }
        }

        $symbols = [
            'KES' => 'Ksh.',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'AU$',
        ];
        $symbol = $symbols[$currency] ?? ($currency . ' ');
    }

    return $symbol;
}

function money(int|float $amount, int $decimals = 2): string {
    $symbol = currencySymbol();
    $formatted = number_format($amount, $decimals);

    // Symbol-style currencies ($, €, £) sit flush against the number; "Ksh."
    // and bare ISO-code fallbacks keep a space, matching existing usage.
    return in_array($symbol, ['$', '€', '£', 'CA$', 'AU$'], true) ? $symbol . $formatted : $symbol . ' ' . $formatted;
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
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, phone, avatar, mileage_reminder_enabled FROM users WHERE id = ?");
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
