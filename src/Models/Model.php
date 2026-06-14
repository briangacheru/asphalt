<?php

namespace App\Models;

use App\Database\Database;
use PDOException;

/**
 * Base model class providing common database operations
 */
abstract class Model
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find a record by ID
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Get all records with optional conditions
     */
    public function all(string $orderBy = null, int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        return $this->db->fetchAll($sql);
    }

    /**
     * Insert a new record
     */
    public function create(array $data): string
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        $this->db->query($sql, array_values($data));
        
        return $this->db->lastInsertId();
    }

    /**
     * Update a record by ID
     */
    public function update(int $id, array $data): bool
    {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$this->table} SET {$set} WHERE {$this->primaryKey} = ?";
        
        $params = array_values($data);
        $params[] = $id;
        
        return $this->db->query($sql, $params)->rowCount() > 0;
    }

    /**
     * Delete a record by ID
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        return $this->db->query($sql, [$id])->rowCount() > 0;
    }

    /**
     * Count records with optional conditions
     */
    public function count(string $where = null, array $params = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        
        if ($where) {
            $sql .= " WHERE {$where}";
        }

        $result = $this->db->fetchOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Execute a custom query
     */
    protected function query(string $sql, array $params = []): \PDOStatement
    {
        return $this->db->query($sql, $params);
    }

    /**
     * Fetch one result from custom query
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Fetch all results from custom query
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Begin a database transaction
     */
    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        return $this->db->commit();
    }

    /**
     * Rollback the current transaction
     */
    public function rollback(): bool
    {
        return $this->db->rollback();
    }
}
