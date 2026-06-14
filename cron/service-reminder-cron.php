<?php
/**
 * Service Reminder Cron Job
 *
 * This script should be run daily via cron to send service reminder emails:
 * 0 8 * * * php /path/to/vehicle-service-tracker/cron/service-reminder-cron.php
 *
 * This will run at 8:00 AM every day
 *
 * Sends emails for:
 * - Overdue services (past due date)
 * - Urgent services (< 500 km remaining)
 * - Upcoming services (500-1500 km remaining)
 * - Low mileage warnings (vehicle hasn't been driven much)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EmailHelper.php';

$pdo = getDBConnection();
$emailHelper = new EmailHelper($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     Service Reminder Email Job - " . date('Y-m-d H:i:s') . "     ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Statistics
$stats = [
    'overdue' => 0,
    'urgent' => 0,
    'upcoming' => 0,
    'low_mileage' => 0,
    'emails_sent' => 0,
    'emails_failed' => 0,
    'vehicles_processed' => 0
];

// Get all active vehicles with their latest service records
$stmt = $pdo->query("
    SELECT 
        v.id,
        v.make,
        v.model,
        v.year,
        v.current_mileage,
        v.updated_at as last_mileage_update,
        sr.next_service_mileage,
        sr.service_date as last_service_date,
        sr.mileage as last_service_mileage,
        (sr.next_service_mileage - v.current_mileage) as km_remaining,
        u.email,
        u.first_name,
        u.email_notifications_enabled,
        u.email_frequency
    FROM vehicles v
    JOIN users u ON v.user_id = u.id
    LEFT JOIN service_records sr ON v.id = sr.vehicle_id 
        AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
    WHERE v.is_active = 1
    AND u.email_notifications_enabled = 1
    ORDER BY km_remaining ASC
");

$vehicles = $stmt->fetchAll();

echo "Found " . count($vehicles) . " active vehicles to check...\n\n";

foreach ($vehicles as $vehicle) {
    $stats['vehicles_processed']++;
    $vehicleName = $vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ')';

    echo "─────────────────────────────────────────────────────────\n";
    echo "Vehicle: {$vehicleName} (ID: {$vehicle['id']})\n";
    echo "Owner: {$vehicle['first_name']} ({$vehicle['email']})\n";
    echo "Current Mileage: " . number_format($vehicle['current_mileage']) . " km\n";

    // Skip if no service records
    if (!$vehicle['next_service_mileage']) {
        echo "Status: ⚠ No service records - Skipping\n";
        continue;
    }

    echo "Next Service: " . number_format($vehicle['next_service_mileage']) . " km\n";
    echo "Remaining: " . number_format($vehicle['km_remaining']) . " km\n";

    // Check if we should send reminder based on user's email frequency preference
    $shouldSend = shouldSendReminder($pdo, $vehicle['id'], $vehicle['km_remaining'], $vehicle['email_frequency']);

    if (!$shouldSend) {
        echo "Status: ℹ Reminder already sent recently - Skipping\n";
        continue;
    }

    // Determine reminder type and send email
    $emailSent = false;

    // 1. OVERDUE - Service is past due
    if ($vehicle['km_remaining'] < 0) {
        $stats['overdue']++;
        echo "Status: 🚨 OVERDUE by " . number_format(abs($vehicle['km_remaining'])) . " km\n";

        try {
            $emailSent = $emailHelper->sendServiceReminderEmail($vehicle['id'], $vehicle['km_remaining']);
            if ($emailSent) {
                echo "Action: ✓ Overdue reminder email sent\n";
                $stats['emails_sent']++;
                updateLastReminderSent($pdo, $vehicle['id'], 'overdue');
            } else {
                echo "Action: ✗ Failed to send overdue reminder\n";
                $stats['emails_failed']++;
            }
        } catch (Exception $e) {
            echo "Action: ✗ Error - " . $e->getMessage() . "\n";
            $stats['emails_failed']++;
        }
    }

    // 2. URGENT - Less than 500 km remaining
    elseif ($vehicle['km_remaining'] <= 500) {
        $stats['urgent']++;
        echo "Status: ⚠ URGENT - Only " . number_format($vehicle['km_remaining']) . " km left\n";

        try {
            $emailSent = $emailHelper->sendServiceReminderEmail($vehicle['id'], $vehicle['km_remaining']);
            if ($emailSent) {
                echo "Action: ✓ Urgent reminder email sent\n";
                $stats['emails_sent']++;
                updateLastReminderSent($pdo, $vehicle['id'], 'urgent');
            } else {
                echo "Action: ✗ Failed to send urgent reminder\n";
                $stats['emails_failed']++;
            }
        } catch (Exception $e) {
            echo "Action: ✗ Error - " . $e->getMessage() . "\n";
            $stats['emails_failed']++;
        }
    }

    // 3. UPCOMING - Between 500-1500 km remaining
    elseif ($vehicle['km_remaining'] <= 1500) {
        $stats['upcoming']++;
        echo "Status: 📅 Upcoming - " . number_format($vehicle['km_remaining']) . " km remaining\n";

        // Only send if user wants upcoming reminders
        if ($vehicle['email_frequency'] === 'all' || $vehicle['email_frequency'] === 'important') {
            try {
                $emailSent = $emailHelper->sendServiceReminderEmail($vehicle['id'], $vehicle['km_remaining']);
                if ($emailSent) {
                    echo "Action: ✓ Upcoming reminder email sent\n";
                    $stats['emails_sent']++;
                    updateLastReminderSent($pdo, $vehicle['id'], 'upcoming');
                } else {
                    echo "Action: ✗ Failed to send upcoming reminder\n";
                    $stats['emails_failed']++;
                }
            } catch (Exception $e) {
                echo "Action: ✗ Error - " . $e->getMessage() . "\n";
                $stats['emails_failed']++;
            }
        } else {
            echo "Action: ℹ User preference set to not receive upcoming reminders\n";
        }
    }

    // 4. HEALTHY - More than 1500 km remaining
    else {
        echo "Status: ✓ Healthy - " . number_format($vehicle['km_remaining']) . " km remaining\n";
        echo "Action: No reminder needed\n";
    }

    // 5. LOW MILEAGE WARNING - Vehicle hasn't been driven much
    checkLowMileageWarning($pdo, $emailHelper, $vehicle, $stats);
}

// Print summary
echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║                      JOB SUMMARY                         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "Job completed at: " . date('Y-m-d H:i:s') . "\n\n";
echo "Vehicles Processed: {$stats['vehicles_processed']}\n\n";
echo "Service Status Breakdown:\n";
echo "  🚨 Overdue:        {$stats['overdue']} vehicle(s)\n";
echo "  ⚠  Urgent:         {$stats['urgent']} vehicle(s)\n";
echo "  📅 Upcoming:       {$stats['upcoming']} vehicle(s)\n";
echo "  🚗 Low Mileage:    {$stats['low_mileage']} vehicle(s)\n\n";
echo "Email Results:\n";
echo "  ✓ Sent:            {$stats['emails_sent']}\n";
echo "  ✗ Failed:          {$stats['emails_failed']}\n";
echo "\n";

// Log the job run
logJobRun($pdo, $stats);

/**
 * Check if we should send a reminder based on last sent time and frequency
 */
function shouldSendReminder($pdo, $vehicleId, $kmRemaining, $emailFrequency) {
    // Get last reminder sent for this vehicle
    $stmt = $pdo->prepare("
        SELECT created_at, email_type 
        FROM email_log 
        WHERE vehicle_id = ? 
        AND email_type = 'service_reminder'
        AND status = 'sent'
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$vehicleId]);
    $lastReminder = $stmt->fetch();

    // If never sent, send now
    if (!$lastReminder) {
        return true;
    }

    $daysSinceLastReminder = (time() - strtotime($lastReminder['created_at'])) / (60 * 60 * 24);

    // Overdue: Send daily
    if ($kmRemaining < 0) {
        return $daysSinceLastReminder >= 1;
    }

    // Urgent (< 500 km): Send every 3 days
    if ($kmRemaining <= 500) {
        return $daysSinceLastReminder >= 3;
    }

    // Upcoming (500-1500 km): Send weekly
    if ($kmRemaining <= 1500) {
        return $daysSinceLastReminder >= 7;
    }

    return false;
}

/**
 * Check for low mileage warning
 */
function checkLowMileageWarning($pdo, $emailHelper, $vehicle, &$stats) {
    // Skip if no last service date
    if (!$vehicle['last_service_date']) {
        return;
    }

    $daysSinceService = (time() - strtotime($vehicle['last_service_date'])) / (60 * 60 * 24);
    $kmDrivenSinceService = $vehicle['current_mileage'] - $vehicle['last_service_mileage'];

    // If more than 180 days (6 months) since service and less than 1000 km driven
    if ($daysSinceService > 180 && $kmDrivenSinceService < 1000) {
        echo "Warning: 🚗 Low mileage - Only " . number_format($kmDrivenSinceService) . " km in " . round($daysSinceService) . " days\n";

        // Check if we already sent this warning recently
        $stmt = $pdo->prepare("
            SELECT created_at 
            FROM email_log 
            WHERE vehicle_id = ? 
            AND email_type = 'low_mileage_warning'
            AND status = 'sent'
            AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            LIMIT 1
        ");
        $stmt->execute([$vehicle['id']]);
        $recentWarning = $stmt->fetch();

        if (!$recentWarning) {
            $stats['low_mileage']++;

            try {
                $sent = $emailHelper->sendLowMileageWarning($vehicle['id'], $kmDrivenSinceService, $daysSinceService);
                if ($sent) {
                    echo "Action: ✓ Low mileage warning email sent\n";
                    $stats['emails_sent']++;
                } else {
                    echo "Action: ✗ Failed to send low mileage warning\n";
                    $stats['emails_failed']++;
                }
            } catch (Exception $e) {
                echo "Action: ✗ Error - " . $e->getMessage() . "\n";
                $stats['emails_failed']++;
            }
        } else {
            echo "Action: ℹ Low mileage warning already sent this month\n";
        }
    }
}

/**
 * Update last reminder sent timestamp
 */
function updateLastReminderSent($pdo, $vehicleId, $reminderType) {
    // This is tracked via email_log table automatically
    // This function can be used for additional tracking if needed
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
            'overdue' => $stats['overdue'],
            'urgent' => $stats['urgent'],
            'upcoming' => $stats['upcoming'],
            'low_mileage' => $stats['low_mileage']
        ]);

        $stmt->execute([
            'service_reminder',
            $stats['vehicles_processed'],
            $stats['emails_sent'],
            $stats['emails_failed'],
            $details
        ]);

        echo "Job run logged to database.\n";
    } catch (Exception $e) {
        echo "Warning: Could not log job run - " . $e->getMessage() . "\n";
        echo "(This is optional - create cron_job_log table if you want logging)\n";
    }
}
?>
