<?php

namespace App\Models;

/**
 * ServiceRecord model for managing vehicle service records
 */
class ServiceRecord extends Model
{
    protected string $table = 'service_records';

    /**
     * Get service records for a vehicle
     */
    public function getByVehicle(int $vehicleId, int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE vehicle_id = ? ORDER BY service_date DESC";
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }
        
        return $this->fetchAll($sql, [$vehicleId]);
    }

    /**
     * Get service record with vehicle and user details
     */
    public function findWithDetails(int $id): ?array
    {
        $sql = "SELECT sr.*, v.make, v.model, v.year, v.user_id,
                       u.email as user_email, u.first_name, u.last_name
                FROM {$this->table} sr
                JOIN vehicles v ON sr.vehicle_id = v.id
                JOIN users u ON v.user_id = u.id
                WHERE sr.{$this->primaryKey} = ?";
        
        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Create a new service record
     */
    public function createService(array $data): string
    {
        $defaults = [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $serviceData = array_merge($defaults, $data);
        return $this->create($serviceData);
    }

    /**
     * Update service record
     */
    public function updateService(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($id, $data);
    }

    /**
     * Get upcoming services for a user
     */
    public function getUpcomingServices(int $userId, int $threshold = 1000): array
    {
        $sql = "SELECT v.*, sr.next_service_mileage, 
                       (sr.next_service_mileage - v.current_mileage) as km_remaining,
                       sr.service_date as last_service_date
                FROM {$this->table} sr
                JOIN vehicles v ON sr.vehicle_id = v.id
                WHERE v.user_id = ? AND v.is_active = 1
                AND sr.id = (SELECT MAX(id) FROM {$this->table} WHERE vehicle_id = v.id)
                AND (sr.next_service_mileage - v.current_mileage) <= ?
                ORDER BY km_remaining ASC";
        
        return $this->fetchAll($sql, [$userId, $threshold]);
    }

    /**
     * Get overdue services for a user
     */
    public function getOverdueServices(int $userId): array
    {
        $sql = "SELECT v.*, sr.next_service_mileage, 
                       (sr.next_service_mileage - v.current_mileage) as km_remaining,
                       sr.service_date as last_service_date
                FROM {$this->table} sr
                JOIN vehicles v ON sr.vehicle_id = v.id
                WHERE v.user_id = ? AND v.is_active = 1
                AND sr.id = (SELECT MAX(id) FROM {$this->table} WHERE vehicle_id = v.id)
                AND (sr.next_service_mileage - v.current_mileage) < 0
                ORDER BY km_remaining ASC";
        
        return $this->fetchAll($sql, [$userId]);
    }

    /**
     * Get service records by date range
     */
    public function getByDateRange(int $userId, string $startDate, string $endDate): array
    {
        $sql = "SELECT sr.*, v.make, v.model, v.year
                FROM {$this->table} sr
                JOIN vehicles v ON sr.vehicle_id = v.id
                WHERE v.user_id = ? 
                AND sr.service_date BETWEEN ? AND ?
                ORDER BY sr.service_date DESC";
        
        return $this->fetchAll($sql, [$userId, $startDate, $endDate]);
    }

    /**
     * Calculate total service costs for a vehicle
     */
    public function getTotalCosts(int $vehicleId): float
    {
        $result = $this->fetchOne(
            "SELECT COALESCE(SUM(service_cost), 0) as total FROM {$this->table} WHERE vehicle_id = ?",
            [$vehicleId]
        );
        
        return (float) ($result['total'] ?? 0);
    }

    /**
     * Get service records with items count
     */
    public function getWithItemsCount(int $vehicleId): array
    {
        $sql = "SELECT sr.*, 
                       (SELECT COUNT(*) FROM service_items si WHERE si.service_record_id = sr.id) as items_count
                FROM {$this->table} sr
                WHERE sr.vehicle_id = ?
                ORDER BY sr.service_date DESC";
        
        return $this->fetchAll($sql, [$vehicleId]);
    }

    /**
     * Check if service record belongs to user's vehicle
     */
    public function belongsToUser(int $serviceId, int $userId): bool
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count 
             FROM {$this->table} sr
             JOIN vehicles v ON sr.vehicle_id = v.id
             WHERE sr.{$this->primaryKey} = ? AND v.user_id = ?",
            [$serviceId, $userId]
        );
        
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Get average cost per service for a vehicle
     */
    public function getAverageCost(int $vehicleId): float
    {
        $result = $this->fetchOne(
            "SELECT AVG(service_cost) as avg FROM {$this->table} 
             WHERE vehicle_id = ? AND service_cost > 0",
            [$vehicleId]
        );
        
        return (float) ($result['avg'] ?? 0);
    }

    /**
     * Get service frequency (average days between services)
     */
    public function getServiceFrequency(int $vehicleId): ?int
    {
        $records = $this->getByVehicle($vehicleId, 10);
        
        if (count($records) < 2) {
            return null;
        }

        $totalDays = 0;
        $count = 0;

        for ($i = 0; $i < count($records) - 1; $i++) {
            $days = strtotime($records[$i]['service_date']) - strtotime($records[$i + 1]['service_date']);
            $totalDays += $days;
            $count++;
        }

        return $count > 0 ? (int) ($totalDays / $count / 86400) : null;
    }
}
