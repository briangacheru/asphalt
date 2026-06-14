<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Database connection manager using singleton pattern
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Get the singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish database connection
     */
    private function connect(): void
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=utf8mb4",
                DB_HOST,
                DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new \RuntimeException("Database connection failed. Please check your configuration.");
        }
    }

    /**
     * Get the PDO connection instance
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        return $this->getConnection()->rollBack();
    }

    /**
     * Execute a query and return the result
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }
}
