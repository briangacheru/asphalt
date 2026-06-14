<?php

namespace App\Models;

/**
 * Vehicle model for managing vehicle records
 */
class Vehicle extends Model
{
    protected string $table = 'vehicles';

    /**
     * Get all vehicles for a user
     */
    public function getUserVehicles(int $userId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE user_id = ?";
        
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        
        $sql .= " ORDER BY make, model, year";
        
        return $this->fetchAll($sql, [$userId]);
    }

    /**
     * Get vehicle with latest service record
     */
    public function findWithServiceRecord(int $id): ?array
    {
        $sql = "SELECT v.*, 
                       sr.service_date as last_service_date,
                       sr.next_service_mileage,
                       sr.mileage as last_service_mileage
                FROM {$this->table} v
                LEFT JOIN service_records sr ON v.id = sr.vehicle_id
                    AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
                WHERE v.{$this->primaryKey} = ?";
        
        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Get vehicles needing service soon
     */
    public function getVehiclesNeedingService(int $userId, int $threshold = 2000): array
    {
        $sql = "SELECT v.*, sr.next_service_mileage, 
                       (sr.next_service_mileage - v.current_mileage) as km_remaining
                FROM {$this->table} v
                JOIN service_records sr ON v.id = sr.vehicle_id
                WHERE v.user_id = ? AND v.is_active = 1 
                AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
                AND (sr.next_service_mileage - v.current_mileage) <= ?
                ORDER BY km_remaining ASC";
        
        return $this->fetchAll($sql, [$userId, $threshold]);
    }

    /**
     * Create a new vehicle
     */
    public function createVehicle(array $data): string
    {
        $defaults = [
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $vehicleData = array_merge($defaults, $data);
        return $this->create($vehicleData);
    }

    /**
     * Update vehicle and set updated_at timestamp
     */
    public function updateVehicle(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update($id, $data);
    }

    /**
     * Soft delete a vehicle
     */
    public function softDelete(int $id): bool
    {
        return $this->update($id, ['is_active' => 0]);
    }

    /**
     * Update vehicle mileage
     */
    public function updateMileage(int $id, int $mileage): bool
    {
        return $this->update($id, [
            'current_mileage' => $mileage,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get vehicle statistics
     */
    public function getStatistics(int $vehicleId): array
    {
        $stats = [];

        // Total service records
        $stats['total_services'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM service_records WHERE vehicle_id = ?",
            [$vehicleId]
        )['count'] ?? 0;

        // Total fuel logs
        $stats['total_fuel_logs'] = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM fuel_logs WHERE vehicle_id = ?",
            [$vehicleId]
        )['count'] ?? 0;

        // Total expenses
        $stats['total_expenses'] = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE vehicle_id = ?",
            [$vehicleId]
        )['total'] ?? 0;

        // Average fuel consumption
        $stats['avg_fuel_consumption'] = $this->db->fetchOne(
            "SELECT AVG(fuel_consumption) as avg FROM fuel_logs WHERE vehicle_id = ? AND fuel_consumption > 0",
            [$vehicleId]
        )['avg'] ?? 0;

        return $stats;
    }

    /**
     * Check if vehicle belongs to user
     */
    public function belongsToUser(int $vehicleId, int $userId): bool
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM {$this->table} 
             WHERE {$this->primaryKey} = ? AND user_id = ?",
            [$vehicleId, $userId]
        );
        
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Get vehicles by fuel type for a user
     */
    public function getByFuelType(int $userId, string $fuelType): array
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} 
             WHERE user_id = ? AND fuel_type = ? AND is_active = 1 
             ORDER BY make, model",
            [$userId, $fuelType]
        );
    }

    /**
     * Search vehicles
     */
    public function search(int $userId, string $query): array
    {
        $searchTerm = "%{$query}%";
        
        return $this->fetchAll(
            "SELECT * FROM {$this->table} 
             WHERE user_id = ? AND is_active = 1 
             AND (make LIKE ? OR model LIKE ? OR license_plate LIKE ? OR vin LIKE ?)
             ORDER BY make, model",
            [$userId, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
        );
    }
}
