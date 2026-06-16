<?php

namespace App\Helpers;

/**
 * Configuration helper that provides backward compatibility with legacy define() constants
 * while using the new Environment helper internally
 */
class Config
{
    private static bool $initialized = false;

    /**
     * Initialize configuration constants for backward compatibility
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Database Configuration
        if (!defined('DB_HOST')) {
            define('DB_HOST', Environment::get('DB_HOST', 'localhost'));
        }
        if (!defined('DB_NAME')) {
            define('DB_NAME', Environment::get('DB_NAME', 'your_database_name'));
        }
        if (!defined('DB_USER')) {
            define('DB_USER', Environment::get('DB_USER', 'your_database_user'));
        }
        if (!defined('DB_PASS')) {
            define('DB_PASS', Environment::get('DB_PASS', 'your_database_password'));
        }

        // Email Configuration
        if (!defined('SMTP_HOST')) {
            define('SMTP_HOST', Environment::get('SMTP_HOST', 'smtp.example.com'));
        }
        if (!defined('SMTP_PORT')) {
            define('SMTP_PORT', (int) Environment::get('SMTP_PORT', 587));
        }
        if (!defined('SMTP_SECURE')) {
            define('SMTP_SECURE', Environment::get('SMTP_SECURE', 'tls'));
        }
        if (!defined('SMTP_USER')) {
            define('SMTP_USER', Environment::get('SMTP_USER', 'your_email@example.com'));
        }
        if (!defined('SMTP_PASS')) {
            define('SMTP_PASS', Environment::get('SMTP_PASS', 'your_email_password'));
        }
        if (!defined('ADMIN_EMAIL')) {
            define('ADMIN_EMAIL', Environment::get('ADMIN_EMAIL', 'admin@example.com'));
        }
        if (!defined('FROM_EMAIL')) {
            define('FROM_EMAIL', Environment::get('FROM_EMAIL', 'noreply@example.com'));
        }
        if (!defined('FROM_NAME')) {
            define('FROM_NAME', Environment::get('FROM_NAME', 'iVehicle'));
        }

        // Application Settings
        if (!defined('APP_NAME')) {
            define('APP_NAME', Environment::get('APP_NAME', 'iVehicle'));
        }
        if (!defined('APP_URL')) {
            define('APP_URL', Environment::get('APP_URL', 'http://localhost/iVehicle'));
        }
        if (!defined('UPLOAD_DIR')) {
            define('UPLOAD_DIR', dirname(__DIR__, 2) . '/uploads/');
        }
        if (!defined('MAX_UPLOAD_SIZE')) {
            define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
        }

        // Security Settings
        if (!defined('PASSWORD_RESET_EXPIRY')) {
            define('PASSWORD_RESET_EXPIRY', 3600); // 1 hour
        }
        if (!defined('REMEMBER_ME_EXPIRY')) {
            define('REMEMBER_ME_EXPIRY', 30 * 24 * 3600); // 30 days
        }
        if (!defined('MIN_PASSWORD_LENGTH')) {
            define('MIN_PASSWORD_LENGTH', 8);
        }

        // Oil Service Intervals
        if (!defined('OIL_INTERVALS')) {
            define('OIL_INTERVALS', serialize([7000, 7500, 8000, 8500, 9000, 9500, 10000]));
        }

        // Timezone
        if (!ini_get('date.timezone')) {
            date_default_timezone_set('Africa/Nairobi');
        }

        self::$initialized = true;
    }

    /**
     * Get a configuration value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::init();
        
        if (defined($key)) {
            return constant($key);
        }
        
        return $default;
    }

    /**
     * Check if a configuration key exists
     */
    public static function has(string $key): bool
    {
        self::init();
        return defined($key);
    }
}
