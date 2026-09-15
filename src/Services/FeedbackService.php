<?php

namespace App\Services;

/**
 * Lightweight in-app feedback box, reachable from the user menu's "Feedback"
 * link. Table is created lazily on first use, the same way SiteSettingsService
 * and the insurance/driving-licence tables are — no separate migration step.
 */
class FeedbackService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'general',
            message TEXT NOT NULL,
            page_url VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_user (user_id),
            INDEX idx_feedback_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function create(\PDO $pdo, int $userId, string $category, string $message, ?string $pageUrl): int
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("INSERT INTO feedback (user_id, category, message, page_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $category, $message, $pageUrl]);

        return (int) $pdo->lastInsertId();
    }

    /** Recent feedback across all users — for a future admin view. */
    public static function recent(\PDO $pdo, int $limit = 50): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare("
            SELECT f.*, u.first_name, u.last_name, u.email
            FROM feedback f
            JOIN users u ON u.id = f.user_id
            ORDER BY f.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
