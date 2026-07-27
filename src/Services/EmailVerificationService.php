<?php

namespace App\Services;

/**
 * Issues and checks expiring email-verification tokens. Tokens are stored
 * as a SHA-256 hash (fast, indexed lookup) rather than the users.verification_token
 * column, which never expired — a stale link should stop working.
 */
class EmailVerificationService
{
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_id (user_id),
            KEY idx_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Issues a fresh token for the given user, invalidating any previous
     * pending one. Returns the plaintext token to embed in the email link.
     */
    public static function issue(\PDO $pdo, int $userId): string
    {
        self::ensureTable($pdo);

        $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + EMAIL_VERIFICATION_EXPIRY);

        $pdo->prepare("INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
            ->execute([$userId, hash('sha256', $token), $expires]);

        return $token;
    }

    /**
     * @return array{status:string, email?:string} status is one of:
     *   'success' | 'already_verified' | 'expired' | 'invalid'
     */
    public static function verify(\PDO $pdo, string $token): array
    {
        self::ensureTable($pdo);

        if ($token === '') {
            return ['status' => 'invalid'];
        }

        $stmt = $pdo->prepare("
            SELECT ev.id AS verification_id, ev.expires_at, u.id AS user_id, u.email, u.is_verified
            FROM email_verifications ev
            JOIN users u ON u.id = ev.user_id
            WHERE ev.token_hash = ?
            ORDER BY ev.id DESC LIMIT 1
        ");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'invalid'];
        }

        if ($row['is_verified']) {
            return ['status' => 'already_verified', 'email' => $row['email']];
        }

        if (strtotime($row['expires_at']) < time()) {
            return ['status' => 'expired', 'email' => $row['email']];
        }

        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$row['user_id']]);
        $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$row['user_id']]);

        return ['status' => 'success', 'email' => $row['email']];
    }
}
