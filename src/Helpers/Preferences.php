<?php

namespace App\Helpers;

/**
 * Resolves and formats a user's display preferences (currency, distance unit,
 * volume unit, timezone) set on the Settings page. Values are cached per
 * request per user id to avoid repeat queries across a single page.
 */
class Preferences
{
    private static array $cache = [];
    private static bool $schemaChecked = false;

    private const CURRENCY_SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'CAD' => '$',
        'AUD' => '$',
        'KES' => 'KSh',
        'JPY' => '¥',
        'INR' => '₹',
        'ZAR' => 'R',
        'NGN' => '₦',
        'UGX' => 'USh',
        'TZS' => 'TSh',
        'CHF' => 'CHF',
        'CNY' => '¥',
    ];

    private const MI_PER_KM = 0.621371;
    private const GAL_PER_LITER = 0.264172;

    private const DEFAULTS = [
        'currency' => 'USD',
        'currency_symbol' => '$',
        'distance_unit' => 'km',
        'volume_unit' => 'L',
        'timezone' => 'UTC',
    ];

    /**
     * Built-in currency code => symbol map, for populating the settings dropdown.
     */
    public static function knownCurrencies(): array
    {
        return self::CURRENCY_SYMBOLS;
    }

    public static function symbolFor(string $code): ?string
    {
        return self::CURRENCY_SYMBOLS[strtoupper($code)] ?? null;
    }

    /**
     * Resolve preferences for a user id. Falls back to sane defaults if the
     * user can't be found or preference columns are empty.
     */
    public static function forUser(\PDO $pdo, ?int $userId): array
    {
        if ($userId === null) {
            return self::DEFAULTS;
        }
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        self::ensureSchema($pdo);

        $stmt = $pdo->prepare("SELECT default_currency, currency_symbol, default_distance_unit, default_volume_unit, timezone FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        $currency = $row['default_currency'] ?? self::DEFAULTS['currency'];
        $symbol = !empty($row['currency_symbol'])
            ? $row['currency_symbol']
            : (self::CURRENCY_SYMBOLS[$currency] ?? $currency);

        $prefs = [
            'currency' => $currency,
            'currency_symbol' => $symbol,
            'distance_unit' => $row['default_distance_unit'] ?? self::DEFAULTS['distance_unit'],
            'volume_unit' => $row['default_volume_unit'] ?? self::DEFAULTS['volume_unit'],
            'timezone' => $row['timezone'] ?? self::DEFAULTS['timezone'],
        ];

        self::$cache[$userId] = $prefs;
        return $prefs;
    }

    /**
     * Clear the cached preferences for a user (call after saving new ones).
     */
    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /**
     * The users table predates these preference columns and there's no
     * migration runner in this app, so make sure the custom-currency-symbol
     * column exists the first time it's needed.
     */
    private static function ensureSchema(\PDO $pdo): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'currency_symbol'");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN currency_symbol VARCHAR(10) NULL DEFAULT NULL AFTER default_currency");
            }
        } catch (\PDOException $e) {
            error_log('Preferences schema check failed: ' . $e->getMessage());
        }
    }

    /**
     * Format a monetary amount using the user's currency symbol.
     */
    public static function formatCurrency(float $amount, array $prefs, int $decimals = 2): string
    {
        $symbol = $prefs['currency_symbol'] ?? self::DEFAULTS['currency_symbol'];
        return $symbol . ' ' . number_format($amount, $decimals);
    }

    /**
     * Format a distance stored in km, converting to miles for display if preferred.
     * Storage/calculations must always keep using the raw km value.
     */
    public static function formatDistance(float $km, array $prefs, int $decimals = 0): string
    {
        if (self::distanceUnit($prefs) === 'mi') {
            return number_format($km * self::MI_PER_KM, $decimals) . ' mi';
        }
        return number_format($km, $decimals) . ' km';
    }

    /**
     * Format a fuel volume stored in liters, converting to gallons for display if preferred.
     */
    public static function formatVolume(float $liters, array $prefs, int $decimals = 2): string
    {
        if (self::volumeUnit($prefs) === 'gal') {
            return number_format($liters * self::GAL_PER_LITER, $decimals) . ' gal';
        }
        return number_format($liters, $decimals) . ' L';
    }

    /**
     * Format a price-per-liter rate, converting to a price-per-gallon rate for
     * display if preferred. Unlike formatVolume(), this converts a rate, not
     * a quantity, so the conversion factor is inverted.
     */
    public static function formatCurrencyPerVolume(float $pricePerLiter, array $prefs, int $decimals = 2): string
    {
        $symbol = $prefs['currency_symbol'] ?? self::DEFAULTS['currency_symbol'];
        if (self::volumeUnit($prefs) === 'gal') {
            $pricePerGallon = $pricePerLiter / self::GAL_PER_LITER;
            return $symbol . ' ' . number_format($pricePerGallon, $decimals) . '/gal';
        }
        return $symbol . ' ' . number_format($pricePerLiter, $decimals) . '/L';
    }

    public static function distanceUnit(array $prefs): string
    {
        return ($prefs['distance_unit'] ?? 'km') === 'mi' ? 'mi' : 'km';
    }

    public static function volumeUnit(array $prefs): string
    {
        return ($prefs['volume_unit'] ?? 'L') === 'gal' ? 'gal' : 'L';
    }

    /**
     * Format a real timestamp (DATETIME columns like created_at/updated_at/sent_at)
     * in the user's chosen timezone. Do NOT use this for DATE-only columns
     * (service_date, fill_date, expense_date, next_due_date, etc.) — those have
     * no time-of-day component, so a timezone shift could roll them onto the
     * wrong calendar day. Use formatDate() for those instead.
     */
    public static function formatDateTime(?string $datetime, array $prefs, string $format = 'M d, Y g:i A'): string
    {
        if (!$datetime) {
            return '';
        }
        try {
            $dt = new \DateTime($datetime, new \DateTimeZone(date_default_timezone_get()));
            $dt->setTimezone(new \DateTimeZone($prefs['timezone'] ?? date_default_timezone_get()));
            return $dt->format($format);
        } catch (\Exception $e) {
            return date($format, strtotime($datetime));
        }
    }
}
