<?php
/**
 * Database Backup Cron Job
 *
 * This script should be run daily via cron to back up the entire database
 * to a gzip-compressed .sql file:
 * 0 2 * * * php /path/to/asphalt/cron/database-backup-cron.php >> /path/to/asphalt/cron/backup.log 2>&1
 *
 * This will run at 2:00 AM every day.
 *
 * Backups are written to uploads/backups/ as db_backup_<db>_<timestamp>.sql.gz.
 * Only the most recent 14 backups are kept (see DatabaseBackupService::RETENTION_COUNT);
 * older ones are deleted automatically. Restore with:
 *   gunzip -c db_backup_....sql.gz | mysql -u <user> -p <database>
 *
 * ADMIN_EMAIL (see .env) gets a notification either way — success with file
 * details, or failure with the error — so a broken backup doesn't fail silently.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use App\Helpers\Config;
use App\Services\DatabaseBackupService;
use App\Services\EmailService;

Config::init();

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║        Database Backup Job - " . date('Y-m-d H:i:s') . "        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

try {
    $backupService = new DatabaseBackupService($pdo);
    $details = $backupService->createBackup();

    echo "Status: ✓ Backup completed\n";
    echo "File:              {$details['filename']}\n";
    echo "Size:              " . number_format($details['size_bytes'] / 1024 / 1024, 2) . " MB\n";
    echo "Tables:            {$details['tables']}\n";
    echo "Rows:              " . number_format($details['rows']) . "\n";
    echo "Duration:          {$details['duration_seconds']} sec\n";
    echo "Old backups purged: {$details['deleted_old_backups']}\n\n";

    $emailSent = $emailService->sendDatabaseBackupEmail(true, $details);
    echo $emailSent ? "Notification email sent.\n" : "Warning: notification email failed to send.\n";

    logJobRun($pdo, true, $details, $emailSent);
} catch (\Throwable $e) {
    echo "Status: ✗ Backup failed\n";
    echo "Error: " . $e->getMessage() . "\n\n";

    $errorDetails = ['error' => $e->getMessage()];
    $emailSent = $emailService->sendDatabaseBackupEmail(false, $errorDetails);
    echo $emailSent ? "Failure notification email sent.\n" : "Warning: failure notification email failed to send.\n";

    logJobRun($pdo, false, $errorDetails, $emailSent);

    exit(1);
}

echo "\nJob completed at: " . date('Y-m-d H:i:s') . "\n";

/**
 * Log the cron job run (best-effort — matches the other cron scripts' convention)
 */
function logJobRun(\PDO $pdo, bool $success, array $details, bool $emailSent): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cron_job_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(100) NOT NULL,
            vehicles_processed INT NOT NULL DEFAULT 0,
            emails_sent INT NOT NULL DEFAULT 0,
            emails_failed INT NOT NULL DEFAULT 0,
            details TEXT NULL,
            run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("
            INSERT INTO cron_job_log
            (job_name, vehicles_processed, emails_sent, emails_failed, details, run_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            'database_backup',
            0,
            $emailSent ? 1 : 0,
            $emailSent ? 0 : 1,
            json_encode(array_merge(['success' => $success], $details)),
        ]);

        echo "Job run logged to database.\n";
    } catch (\Exception $e) {
        echo "Warning: Could not log job run - " . $e->getMessage() . "\n";
    }
}
