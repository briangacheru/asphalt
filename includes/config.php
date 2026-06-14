<?php

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'vehicle_service_tracker');
define('DB_USER', 'root');
define('DB_PASS', '');

// Email Configuration (PHPMailer SMTP)
define('SMTP_HOST', 'mail.monkbrian.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl'); // 'tls' or 'ssl'
define('SMTP_USER', 'support@monkbrian.com');
define('SMTP_PASS', 'EDU+pass.'); // Use App Password for Gmail
define('ADMIN_EMAIL', 'bryo4419@gmail.com');
define('FROM_EMAIL', 'support@monkbrian.com');
define('FROM_NAME', 'iVehicle');

// Application Settings
define('APP_NAME', 'iVehicle');
define('APP_URL', 'http://localhost/iVehicle');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

// Security Settings
define('PASSWORD_RESET_EXPIRY', 3600); // 1 hour in seconds
define('REMEMBER_ME_EXPIRY', 30 * 24 * 3600); // 30 days
define('MIN_PASSWORD_LENGTH', 8);

// Oil Service Intervals (in km)
define('OIL_INTERVALS', serialize([7000, 7500, 8000, 8500, 9000, 9500, 10000]));

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database Connection
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// ============ Authentication Functions ============

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, phone, avatar FROM users WHERE id = ?");
    $stmt->execute([getCurrentUserId()]);
    return $stmt->fetch();
}

/**
 * Require authentication - redirect to login if not logged in
 */
function requireAuth() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        setFlashMessage('warning', 'Please log in to continue.');
        redirect(APP_URL . '/auth/login.php');
    }
}

/**
 * Generate secure random token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ============ Helper Functions ============

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    // Check if headers already sent
    if (headers_sent($file, $line)) {
        // Headers already sent, use JavaScript redirect
        echo "<script type='text/javascript'>";
        echo "window.location.href = '" . addslashes($url) . "';";
        echo "</script>";
        echo "<noscript>";
        echo "<meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url) . "'>";
        echo "</noscript>";
        exit;
    }
    // Headers not sent yet, use normal redirect
    header("Location: $url");
    exit;
}

function setFlashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function formatNumber($number) {
    return number_format($number, 0, '.', ',');
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
?>
