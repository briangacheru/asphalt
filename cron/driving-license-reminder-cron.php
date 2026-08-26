<?php
/**
 * Driving Licence Reminder Cron Job
 *
 * Run daily via cron to send driving licence expiry alert emails:
 * 0 8 * * * php /path/to/vehicle-service-tracker/cron/driving-license-reminder-cron.php
 *
 * For every active user, looks at their current driving licence (the one
 * with the furthest-out expiry_date). If that licence expires within
 * DrivingLicenseService::REMINDER_WINDOW_DAYS (60) days, or has already
 * expired, an email is sent — and re-sent once per calendar day, with no
 * cutoff after expiry — until a renewed licence with a later expiry_date is
 * recorded.
 */

require_once __DIR__ . '/../vendor/autoload.php';
use App\Database\Database;
use App\Services\EmailService;
use App\Services\DrivingLicenseService;

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║ Driving Licence Reminder Email Job - " . date('Y-m-d H:i:s') . "  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$stats = [
    'expired' => 0,
    'expiring' => 0,
    'emails_sent' => 0,
    'emails_failed' => 0,
    'users_processed' => 0,
];

$users = DrivingLicenseService::statusForAllUsers($pdo);

echo "Found " . count($users) . " active user(s) to check...\n\n";

foreach ($users as $user) {
    $stats['users_processed']++;

    echo "─────────────────────────────────────────────────────────\n";
    echo "User: {$user['first_name']} ({$user['email']})\n";

    if ($user['status'] === 'none') {
        echo "Status: ⚠ No driving licence on file - Skipping\n";
        continue;
    }

    echo "Licence No: {$user['license_number']} | Expiry: {$user['expiry_date']}\n";

    if (!in_array($user['status'], ['expired', 'expiring'], true)) {
        echo "Status: ✓ Valid - " . $user['days_remaining'] . " day(s) remaining\n";
        continue;
    }

    if (!$user['email_notifications_enabled']) {
        echo "Status: ℹ User has email notifications disabled - Skipping\n";
        continue;
    }

    if ($user['status'] === 'expired') {
        $stats['expired']++;
        echo "Status: 🚨 EXPIRED " . abs($user['days_remaining']) . " day(s) ago\n";
    } else {
        $stats['expiring']++;
        echo "Status: ⚠ Expiring in " . $user['days_remaining'] . " day(s)\n";
    }

    if (!shouldSendToday($pdo, $user['email'])) {
        echo "Action: ℹ Reminder already sent today - Skipping\n";
        continue;
    }

    try {
        $sent = $emailService->sendDrivingLicenseExpiryEmail($user['user_id'], $user, $user['days_remaining']);
        if ($sent) {
            echo "Action: ✓ Driving licence alert email sent\n";
            $stats['emails_sent']++;
        } else {
            echo "Action: ✗ Failed to send driving licence alert\n";
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
echo "Users Processed: {$stats['users_processed']}\n\n";
echo "Driving Licence Status Breakdown:\n";
echo "  🚨 Expired:   {$stats['expired']} user(s)\n";
echo "  ⚠  Expiring:  {$stats['expiring']} user(s)\n\n";
echo "Email Results:\n";
echo "  ✓ Sent:       {$stats['emails_sent']}\n";
echo "  ✗ Failed:     {$stats['emails_failed']}\n";
echo "\n";

logJobRun($pdo, $stats);

/**
 * One driving licence alert per user per calendar day, regardless of how
 * many days the licence has been expired/expiring for.
 */
function shouldSendToday(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM email_log
        WHERE recipient_email = ?
        AND email_type = 'driving_license_expiry'
        AND status = 'sent'
        AND DATE(created_at) = CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$email]);
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
            'driving_license_reminder',
            $stats['users_processed'],
            $stats['emails_sent'],
            $stats['emails_failed'],
            $details,
        ]);

        echo "Job run logged to database.\n";
    } catch (Exception $e) {
        echo "Warning: Could not log job run - " . $e->getMessage() . "\n";
    }
}
