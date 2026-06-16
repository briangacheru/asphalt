<?php

namespace App\Helpers;

/**
 * Environment variable helper for managing configuration
 */
class Environment
{
    private static array $loaded = [];

    /**
     * Get an environment variable with optional default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Load .env file if not already loaded
        if (empty(self::$loaded)) {
            self::load();
        }

        // Check $_ENV first, then our loaded values
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, self::$loaded)) {
            return self::$loaded[$key];
        }

        // Fall back to getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Load .env file from project root
     */
    private static function load(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                // Skip comments
                if (trim($line) === '' || strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse key=value
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    
                    self::$loaded[$key] = $value;
                }
            }
        }
    }

    /**
     * Check if an environment variable exists
     */
    public static function has(string $key): bool
    {
        if (empty(self::$loaded)) {
            self::load();
        }

        return isset($_ENV[$key]) || array_key_exists($key, self::$loaded) || getenv($key) !== false;
    }

    /**
     * Clear loaded environment variables (useful for testing)
     */
    public static function clear(): void
    {
        self::$loaded = [];
    }
}
