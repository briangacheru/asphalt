<?php
/**
 * Insurance Reminder Cron Job
 *
 * Run daily via cron to send insurance expiry alert emails:
 * 0 8 * * * php /path/to/vehicle-service-tracker/cron/insurance-reminder-cron.php
 *
 * For every active vehicle, looks at its current insurance policy (the one
 * with the furthest-out expiry_date). If that policy expires within
 * InsuranceService::REMINDER_WINDOW_DAYS (14) days, or has already expired,
 * an email is sent — and re-sent once per calendar day, with no cutoff after
 * expiry — until a renewed policy with a later expiry_date is recorded.
 */

require_once __DIR__ . '/../vendor/autoload.php';
use App\Database\Database;
use App\Services\EmailService;
use App\Services\InsuranceService;

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Insurance Reminder Email Job - " . date('Y-m-d H:i:s') . "    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$stats = [
    'expired' => 0,
    'expiring' => 0,
    'emails_sent' => 0,
    'emails_failed' => 0,
    'vehicles_processed' => 0,
];

$vehicles = InsuranceService::statusForAllVehicles($pdo);

echo "Found " . count($vehicles) . " active vehicles to check...\n\n";

foreach ($vehicles as $vehicle) {
    $stats['vehicles_processed']++;
    $vehicleName = $vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ')';

    echo "─────────────────────────────────────────────────────────\n";
    echo "Vehicle: {$vehicleName} (ID: {$vehicle['vehicle_id']})\n";
    echo "Owner: {$vehicle['first_name']} ({$vehicle['email']})\n";

    if ($vehicle['status'] === 'none') {
        echo "Status: ⚠ No insurance on file - Skipping\n";
        continue;
    }

    echo "Provider: {$vehicle['provider']} | Expiry: {$vehicle['expiry_date']}\n";

    if (!in_array($vehicle['status'], ['expired', 'expiring'], true)) {
        echo "Status: ✓ Insured - " . $vehicle['days_remaining'] . " day(s) remaining\n";
        continue;
    }

    if (!$vehicle['email_notifications_enabled']) {
        echo "Status: ℹ Owner has email notifications disabled - Skipping\n";
        continue;
    }

    if ($vehicle['status'] === 'expired') {
        $stats['expired']++;
        echo "Status: 🚨 EXPIRED " . abs($vehicle['days_remaining']) . " day(s) ago\n";
    } else {
        $stats['expiring']++;
        echo "Status: ⚠ Expiring in " . $vehicle['days_remaining'] . " day(s)\n";
    }

    if (!shouldSendToday($pdo, $vehicle['vehicle_id'])) {
        echo "Action: ℹ Reminder already sent today - Skipping\n";
        continue;
    }

    try {
        $sent = $emailService->sendInsuranceExpiryEmail($vehicle['vehicle_id'], $vehicle, $vehicle['days_remaining']);
        if ($sent) {
            echo "Action: ✓ Insurance alert email sent\n";
            $stats['emails_sent']++;
        } else {
            echo "Action: ✗ Failed to send insurance alert\n";
            $stats['emails_failed']++;
        }
    } catch (Exception $e) {
        echo "Action: ✗ Error - " . $e->getMessage() . "\n";
        $stats['emails_failed']++;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║                      JOB SUMMARY                         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "Job completed at: " . date('Y-m-d H:i:s') . "\n\n";
echo "Vehicles Processed: {$stats['vehicles_processed']}\n\n";
echo "Insurance Status Breakdown:\n";
echo "  🚨 Expired:   {$stats['expired']} vehicle(s)\n";
echo "  ⚠  Expiring:  {$stats['expiring']} vehicle(s)\n\n";
echo "Email Results:\n";
echo "  ✓ Sent:       {$stats['emails_sent']}\n";
echo "  ✗ Failed:     {$stats['emails_failed']}\n";
echo "\n";

logJobRun($pdo, $stats);

/**
 * One insurance alert per vehicle per calendar day, regardless of how many
 * days the policy has been expired/expiring for.
 */
function shouldSendToday(PDO $pdo, int $vehicleId): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM email_log
        WHERE vehicle_id = ?
        AND email_type = 'insurance_expiry'
        AND status = 'sent'
        AND DATE(created_at) = CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$vehicleId]);
    return $stmt->fetchColumn() === false;
}

function logJobRun(PDO $pdo, array $stats): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cron_job_log
            (job_name, vehicles_processed, emails_sent, emails_failed, details, run_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $details = json_encode([
            'expired' => $stats['expired'],
            'expiring' => $stats['expiring'],
        ]);

        $stmt->execute([
            'insurance_reminder',
            $stats['vehicles_processed'],
            $stats['emails_sent'],
            $stats['emails_failed'],
            $details,
        ]);

        echo "Job run logged to database.\n";
    } catch (Exception $e) {
        echo "Warning: Could not log job run - " . $e->getMessage() . "\n";
    }
}
