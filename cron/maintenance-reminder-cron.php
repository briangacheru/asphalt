<?php
/**
 * Maintenance Schedule Reminder Cron Job
 *
 * This script should be run daily via cron to alert users when a
 * maintenance_schedule item's "Next Due" is almost due or overdue:
 * 0 8 * * * php /path/to/vehicle-service-tracker/cron/maintenance-reminder-cron.php
 *
 * One consolidated email is sent per vehicle listing every item that is
 * overdue or due soon (rather than one email per item), throttled via
 * the email_log table so the same vehicle isn't emailed too often.
 */

require_once __DIR__ . '/../vendor/autoload.php';
use App\Database\Database;
use App\Services\EmailService;
use App\Helpers\Preferences;

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Maintenance Schedule Reminder Job - " . date('Y-m-d H:i:s') . "   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$stats = [
    'vehicles_processed' => 0,
    'vehicles_alerted' => 0,
    'items_overdue' => 0,
    'items_due_soon' => 0,
    'emails_sent' => 0,
    'emails_failed' => 0,
];

// km/days thresholds that define "almost due"
const DUE_SOON_KM_WINDOW = 1000;
const DUE_SOON_DAYS_WINDOW = 30;

$stmt = $pdo->query("
    SELECT ms.*, v.make, v.model, v.year, v.current_mileage, v.user_id,
           u.email_notifications_enabled
    FROM maintenance_schedule ms
    JOIN vehicles v ON ms.vehicle_id = v.id
    JOIN users u ON v.user_id = u.id
    WHERE v.is_active = 1 AND u.email_notifications_enabled = 1
    ORDER BY ms.vehicle_id, ms.id DESC
");
$schedules = $stmt->fetchAll();

// Group due/overdue items by vehicle
$byVehicle = [];
foreach ($schedules as $item) {
    $kmOverdue = $item['next_due_mileage'] ? $item['current_mileage'] - $item['next_due_mileage'] : null;
    $daysUntilDue = $item['next_due_date'] ? (strtotime($item['next_due_date']) - time()) / 86400 : null;

    $isOverdue = ($kmOverdue !== null && $kmOverdue > 0) || ($daysUntilDue !== null && $daysUntilDue < 0);
    $isDueSoon = !$isOverdue && (
        ($kmOverdue !== null && $kmOverdue > -DUE_SOON_KM_WINDOW) ||
        ($daysUntilDue !== null && $daysUntilDue <= DUE_SOON_DAYS_WINDOW)
    );

    if (!$isOverdue && !$isDueSoon) {
        continue;
    }

    $ownerPrefs = Preferences::forUser($pdo, (int)$item['user_id']);

    if ($kmOverdue !== null) {
        $remainingLabel = $kmOverdue > 0
            ? Preferences::formatDistance($kmOverdue, $ownerPrefs) . ' overdue'
            : Preferences::formatDistance(abs($kmOverdue), $ownerPrefs) . ' remaining';
    } elseif ($daysUntilDue !== null) {
        $remainingLabel = $daysUntilDue < 0
            ? number_format(abs(round($daysUntilDue))) . ' day(s) overdue'
            : number_format(round($daysUntilDue)) . ' day(s) remaining';
    } else {
        $remainingLabel = '-';
    }

    $item['status'] = $isOverdue ? 'overdue' : 'due_soon';
    $item['remaining_label'] = $remainingLabel;

    $byVehicle[$item['vehicle_id']]['vehicle_name'] = $item['make'] . ' ' . $item['model'] . ' (' . $item['year'] . ')';
    $byVehicle[$item['vehicle_id']]['items'][] = $item;

    if ($isOverdue) {
        $stats['items_overdue']++;
    } else {
        $stats['items_due_soon']++;
    }
}

$stats['vehicles_processed'] = count($schedules) ? count(array_unique(array_column($schedules, 'vehicle_id'))) : 0;

echo "Found " . count($byVehicle) . " vehicle(s) with due/overdue maintenance items...\n\n";

foreach ($byVehicle as $vehicleId => $data) {
    $items = $data['items'];
    $hasOverdue = in_array('overdue', array_column($items, 'status'), true);

    echo "─────────────────────────────────────────────────────────\n";
    echo "Vehicle: {$data['vehicle_name']} (ID: {$vehicleId})\n";
    echo "Items due: " . count($items) . " (" . ($hasOverdue ? "includes overdue" : "due soon only") . ")\n";

    if (!shouldSendMaintenanceReminder($pdo, $vehicleId, $hasOverdue)) {
        echo "Status: ℹ Reminder already sent recently - Skipping\n";
        continue;
    }

    try {
        $sent = $emailService->sendMaintenanceDueEmail($vehicleId, $items);
        if ($sent) {
            echo "Action: ✓ Maintenance due email sent\n";
            $stats['emails_sent']++;
            $stats['vehicles_alerted']++;
        } else {
            echo "Action: ✗ Failed to send maintenance due email\n";
            $stats['emails_failed']++;
        }
    } catch (Exception $e) {
        echo "Action: ✗ Error - " . $e->getMessage() . "\n";
        $stats['emails_failed']++;
    }
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║                      JOB SUMMARY                          ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "Job completed at: " . date('Y-m-d H:i:s') . "\n\n";
echo "Vehicles Processed:   {$stats['vehicles_processed']}\n";
echo "Vehicles Alerted:     {$stats['vehicles_alerted']}\n";
echo "Items Overdue:        {$stats['items_overdue']}\n";
echo "Items Due Soon:       {$stats['items_due_soon']}\n";
echo "Emails Sent:          {$stats['emails_sent']}\n";
echo "Emails Failed:        {$stats['emails_failed']}\n";

logJobRun($pdo, $stats);

/**
 * Throttle: overdue vehicles get a reminder daily, due-soon-only vehicles weekly
 */
function shouldSendMaintenanceReminder($pdo, $vehicleId, $hasOverdue) {
    $stmt = $pdo->prepare("
        SELECT created_at
        FROM email_log
        WHERE vehicle_id = ?
        AND email_type = 'maintenance_due'
        AND status = 'sent'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$vehicleId]);
    $lastSent = $stmt->fetch();

    if (!$lastSent) {
        return true;
    }

    $daysSinceLastSent = (time() - strtotime($lastSent['created_at'])) / (60 * 60 * 24);

    return $hasOverdue ? $daysSinceLastSent >= 1 : $daysSinceLastSent >= 7;
}

/**
 * Log the cron job run
 */
function logJobRun($pdo, $stats) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cron_job_log
            (job_name, vehicles_processed, emails_sent, emails_failed, details, run_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $details = json_encode([
            'vehicles_alerted' => $stats['vehicles_alerted'],
            'items_overdue' => $stats['items_overdue'],
            'items_due_soon' => $stats['items_due_soon'],
        ]);

        $stmt->execute([
            'maintenance_reminder',
            $stats['vehicles_processed'],
            $stats['emails_sent'],
            $stats['emails_failed'],
            $details,
        ]);

        echo "\nJob run logged to database.\n";
    } catch (Exception $e) {
        echo "\nWarning: Could not log job run - " . $e->getMessage() . "\n";
    }
}
