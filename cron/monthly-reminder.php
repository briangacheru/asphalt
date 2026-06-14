<?php
/**
 * Monthly Email Cron Job
 *
 * This script should be run monthly via cron:
 * 0 9 1 * * php /path/to/vehicle-service-tracker/cron/monthly-reminder.php
 *
 * This will run at 9:00 AM on the 1st of every month
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EmailHelper.php';

$pdo = getDBConnection();
$emailHelper = new EmailHelper($pdo);

echo "Starting monthly email job at " . date('Y-m-d H:i:s') . "\n";

// Get all active vehicles
$stmt = $pdo->query("SELECT id, make, model FROM vehicles WHERE is_active = 1");
$vehicles = $stmt->fetchAll();

$emailsSent = 0;
$emailsFailed = 0;

foreach ($vehicles as $vehicle) {
    echo "Processing vehicle: {$vehicle['make']} {$vehicle['model']} (ID: {$vehicle['id']})\n";

    try {
        $result = $emailHelper->sendMonthlyCheckEmail($vehicle['id']);
        if ($result) {
            $emailsSent++;
            echo "  ✓ Monthly check email sent\n";
        } else {
            $emailsFailed++;
            echo "  ✗ Failed to send monthly check email\n";
        }
    } catch (Exception $e) {
        $emailsFailed++;
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\nJob completed at " . date('Y-m-d H:i:s') . "\n";
echo "Emails sent: $emailsSent\n";
echo "Emails failed: $emailsFailed\n";
?>