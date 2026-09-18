<?php
/**
 * Document Expiry Reminder Cron Job
 *
 * Run daily via cron to send document expiry alert emails:
 * 0 8 * * * php /path/to/vehicle-service-tracker/cron/document-expiry-reminder-cron.php
 *
 * For every active vehicle, looks at uploaded documents that carry an
 * expiry date (inspection certificate, road tax, permits, etc.). If any
 * expires within DocumentExpiryService::REMINDER_WINDOW_DAYS (14) days, or
 * has already expired, the owner gets one digest email per vehicle listing
 * every affected document — re-sent once per calendar day, with no cutoff
 * after expiry — until the document is replaced with a later expiry date
 * or deleted. Mirrors cron/insurance-reminder-cron.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';
use App\Database\Database;
use App\Services\EmailService;
use App\Services\DocumentExpiryService;

$pdo = Database::getInstance()->getConnection();
$emailService = new EmailService($pdo);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║   Document Expiry Reminder Job - " . date('Y-m-d H:i:s') . "    ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$stats = [
    'expired_documents' => 0,
    'expiring_documents' => 0,
    'emails_sent' => 0,
    'emails_failed' => 0,
    'vehicles_processed' => 0,
];

$vehicles = DocumentExpiryService::documentsNeedingAttentionForAllVehicles($pdo);

echo "Found " . count($vehicles) . " vehicle(s) with documents expiring within "
    . DocumentExpiryService::REMINDER_WINDOW_DAYS . " days or already expired...\n\n";

foreach ($vehicles as $vehicle) {
    $stats['vehicles_processed']++;
    $vehicleName = $vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ')';

    echo "─────────────────────────────────────────────────────────\n";
    echo "Vehicle: {$vehicleName} (ID: {$vehicle['vehicle_id']})\n";
    echo "Owner: {$vehicle['first_name']} ({$vehicle['email']})\n";

    foreach ($vehicle['documents'] as $doc) {
        if ($doc['days_remaining'] < 0) {
            $stats['expired_documents']++;
            echo "  🚨 {$doc['title']} [{$doc['category_label']}] - EXPIRED " . abs($doc['days_remaining']) . " day(s) ago ({$doc['expiry_date']})\n";
        } else {
            $stats['expiring_documents']++;
            echo "  ⚠  {$doc['title']} [{$doc['category_label']}] - expires in {$doc['days_remaining']} day(s) ({$doc['expiry_date']})\n";
        }
    }

    if (!$vehicle['email_notifications_enabled']) {
        echo "Action: ℹ Owner has email notifications disabled - Skipping\n";
        continue;
    }

    if (!shouldSendToday($pdo, $vehicle['vehicle_id'])) {
        echo "Action: ℹ Reminder already sent today - Skipping\n";
        continue;
    }

    try {
        $sent = $emailService->sendDocumentExpiryEmail($vehicle['vehicle_id'], $vehicle['documents']);
        if ($sent) {
            echo "Action: ✓ Document alert email sent\n";
            $stats['emails_sent']++;
        } else {
            echo "Action: ✗ Failed to send document alert\n";
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
echo "Document Status Breakdown:\n";
echo "  🚨 Expired:   {$stats['expired_documents']} document(s)\n";
echo "  ⚠  Expiring:  {$stats['expiring_documents']} document(s)\n\n";
echo "Email Results:\n";
echo "  ✓ Sent:       {$stats['emails_sent']}\n";
echo "  ✗ Failed:     {$stats['emails_failed']}\n";
echo "\n";

logJobRun($pdo, $stats);

/**
 * One document digest per vehicle per calendar day, however many documents
 * are expiring or how long they've been expired.
 */
function shouldSendToday(PDO $pdo, int $vehicleId): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM email_log
        WHERE vehicle_id = ?
        AND email_type = 'document_expiry'
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
            'expired_documents' => $stats['expired_documents'],
            'expiring_documents' => $stats['expiring_documents'],
        ]);

        $stmt->execute([
            'document_expiry_reminder',
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
