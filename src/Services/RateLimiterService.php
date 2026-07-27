<?php

namespace App\Services;

/**
 * Simple DB-backed rate limiter for sensitive, unauthenticated auth endpoints
 * (login, register, forgot-password, resend-verification) — none of which
 * had any throttling before this. Keyed by an arbitrary string the caller
 * builds (typically "action:ip" or "action:ip:email").
 */
class RateLimiterService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rate_key VARCHAR(191) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rate_key_time (rate_key, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function tooManyAttempts(\PDO $pdo, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        self::ensureTable($pdo);

        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND attempted_at > ?");
        $stmt->execute([$key, $windowStart]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public static function recordAttempt(\PDO $pdo, string $key): void
    {
        self::ensureTable($pdo);
        $pdo->prepare("INSERT INTO rate_limits (rate_key) VALUES (?)")->execute([$key]);

        // Best-effort cleanup so the table doesn't grow unbounded — no cron needed for this
        if (random_int(1, 20) === 1) {
            $pdo->prepare("DELETE FROM rate_limits WHERE attempted_at < ?")
                ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    public static function clearAttempts(\PDO $pdo, string $key): void
    {
        self::ensureTable($pdo);
        $pdo->prepare("DELETE FROM rate_limits WHERE rate_key = ?")->execute([$key]);
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
