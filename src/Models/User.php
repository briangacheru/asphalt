<?php

namespace App\Models;

/**
 * User model for managing user accounts
 */
class User extends Model
{
    protected string $table = 'users';

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne("SELECT * FROM {$this->table} WHERE email = ?", [$email]);
    }

    /**
     * Find user by ID with active status check
     */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? AND is_active = 1", [$id]);
    }

    /**
     * Create a new user
     */
    public function createUser(array $data): string
    {
        $defaults = [
            'is_active' => 1,
            'is_verified' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'email_notifications_enabled' => 1,
            'email_frequency' => 'weekly'
        ];

        $userData = array_merge($defaults, $data);
        return $this->create($userData);
    }

    /**
     * Update user's last login timestamp
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, ['last_login' => date('Y-m-d H:i:s')]);
    }

    /**
     * Verify user credentials
     */
    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);

        if (!$user || !$user['is_active']) {
            return null;
        }

        if (!password_verify($password, $user['password'])) {
            return null;
        }

        // Remove password from returned data
        unset($user['password']);
        return $user;
    }

    /**
     * Check if user email exists
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE email = ?";
        $params = [$email];

        if ($excludeId) {
            $sql .= " AND {$this->primaryKey} != ?";
            $params[] = $excludeId;
        }

        $result = $this->fetchOne($sql, $params);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Get user statistics
     */
    public function getStatistics(int $userId): array
    {
        $stats = [];

        // Total vehicles
        $stats['total_vehicles'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM vehicles WHERE user_id = ? AND is_active = 1",
            [$userId]
        )['count'] ?? 0;

        // Total services
        $stats['total_services'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM service_records sr 
             JOIN vehicles v ON sr.vehicle_id = v.id 
             WHERE v.user_id = ?",
            [$userId]
        )['count'] ?? 0;

        // Total spent this year
        $stats['spent_this_year'] = $this->db->fetchOne(
            "SELECT COALESCE(SUM(sr.service_cost), 0) as total 
             FROM service_records sr
             JOIN vehicles v ON sr.vehicle_id = v.id
             WHERE v.user_id = ? AND YEAR(sr.service_date) = YEAR(CURDATE())",
            [$userId]
        )['total'] ?? 0;

        // Upcoming services
        $stats['upcoming_services'] = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT v.id) as count
             FROM vehicles v
             JOIN service_records sr ON v.id = sr.vehicle_id
             WHERE v.user_id = ? AND v.is_active = 1 
             AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
             AND (sr.next_service_mileage - v.current_mileage) <= 1000",
            [$userId]
        )['count'] ?? 0;

        return $stats;
    }

    /**
     * Enable or disable email notifications
     */
    public function toggleEmailNotifications(int $userId, bool $enabled): bool
    {
        return $this->update($userId, ['email_notifications_enabled' => $enabled ? 1 : 0]);
    }

    /**
     * Update email frequency preference
     */
    public function updateEmailFrequency(int $userId, string $frequency): bool
    {
        $allowedFrequencies = ['daily', 'weekly', 'monthly'];
        
        if (!in_array($frequency, $allowedFrequencies)) {
            return false;
        }

        return $this->update($userId, ['email_frequency' => $frequency]);
    }
}
