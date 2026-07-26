<?php

namespace App\Services;

use PDO;

/**
 * Dumps the entire database to a gzip-compressed .sql file using plain
 * PDO — no shelling out to the `mysqldump` binary, since its path varies
 * across installs and `exec()`/`shell_exec()` are routinely disabled on
 * shared hosting (the same reasoning IdCodec uses for picking openssl
 * over sodium). The restore path is a plain `gunzip | mysql`.
 */
class DatabaseBackupService
{
    private const BACKUP_DIR = 'backups/';
    private const FILE_PREFIX = 'db_backup_';
    private const RETENTION_COUNT = 14;
    private const ROWS_PER_INSERT = 500;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{filename:string, path:string, size_bytes:int, tables:int,
     *               rows:int, duration_seconds:float, deleted_old_backups:int}
     */
    public function createBackup(): array
    {
        $start = microtime(true);

        $dir = UPLOAD_DIR . self::BACKUP_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $filename = self::FILE_PREFIX . $dbName . '_' . date('Ymd_His') . '.sql.gz';
        $path = $dir . $filename;

        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new \RuntimeException("Could not open $path for writing.");
        }

        gzwrite($handle, "-- iVehicle database backup\n");
        gzwrite($handle, "-- Database: $dbName\n");
        gzwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        gzwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $totalRows = 0;

        foreach ($tables as $table) {
            $totalRows += $this->dumpTable($handle, $table);
        }

        gzwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($handle);

        $deleted = $this->pruneOldBackups($dir);

        return [
            'filename' => $filename,
            'path' => $path,
            'size_bytes' => filesize($path),
            'tables' => count($tables),
            'rows' => $totalRows,
            'duration_seconds' => round(microtime(true) - $start, 2),
            'deleted_old_backups' => $deleted,
        ];
    }

    /**
     * Writes one table's DROP/CREATE + INSERT statements. Returns the row count.
     */
    private function dumpTable($handle, string $table): int
    {
        $createRow = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        gzwrite($handle, "DROP TABLE IF EXISTS `$table`;\n" . $createRow[1] . ";\n\n");

        $rows = $this->pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return 0;
        }

        $columnList = '`' . implode('`, `', array_keys($rows[0])) . '`';

        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            $valueRows = [];
            foreach ($chunk as $row) {
                $values = array_map(
                    fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v),
                    $row
                );
                $valueRows[] = '(' . implode(', ', $values) . ')';
            }
            gzwrite($handle, "INSERT INTO `$table` ($columnList) VALUES\n" . implode(",\n", $valueRows) . ";\n");
        }
        gzwrite($handle, "\n");

        return count($rows);
    }

    /**
     * Keeps only the newest RETENTION_COUNT backup files, deletes the rest.
     */
    private function pruneOldBackups(string $dir): int
    {
        $files = glob($dir . self::FILE_PREFIX . '*.sql.gz') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        $toDelete = array_slice($files, self::RETENTION_COUNT);
        foreach ($toDelete as $file) {
            @unlink($file);
        }

        return count($toDelete);
    }
}
