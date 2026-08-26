<?php
$pageTitle = 'Email History';
require_once 'includes/header.php';

// Scoped to the current user: only emails where they are the recipient.
// (Previously unscoped — any logged-in user could see every user's emails.)
$emailsStmt = $pdo->prepare("
    SELECT el.*, v.make, v.model, v.year
    FROM email_log el
    LEFT JOIN vehicles v ON el.vehicle_id = v.id
    WHERE el.recipient_email = ?
    ORDER BY el.id DESC
    LIMIT 100
");
$emailsStmt->execute([$currentUser['email']]);
$emails = $emailsStmt->fetchAll();

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM email_log
    WHERE recipient_email = ?
");
$statsStmt->execute([$currentUser['email']]);
$stats = $statsStmt->fetch();

$emailTypes = [
    'service_reminder' => ['label' => 'Service Reminder', 'icon' => 'fa-bell', 'color' => 'warning'],
    'monthly_check' => ['label' => 'Monthly Check', 'icon' => 'fa-calendar', 'color' => 'info'],
    'service_details' => ['label' => 'Service Details', 'icon' => 'fa-wrench', 'color' => 'success'],
    'low_mileage_warning' => ['label' => 'Low Mileage Warning', 'icon' => 'fa-tachometer-alt', 'color' => 'danger'],
    'maintenance_due' => ['label' => 'Maintenance Due', 'icon' => 'fa-tools', 'color' => 'warning'],
    'insurance_expiry' => ['label' => 'Insurance Expiry', 'icon' => 'fa-shield-alt', 'color' => 'danger'],
    'driving_license_expiry' => ['label' => 'Driving Licence Expiry', 'icon' => 'fa-id-card', 'color' => 'danger'],
    'test_email' => ['label' => 'Test Email', 'icon' => 'fa-envelope', 'color' => 'primary'],
    'database_backup' => ['label' => 'Database Backup', 'icon' => 'fa-database', 'color' => 'dark'],
    'vehicle_export' => ['label' => 'Export File', 'icon' => 'fa-file-archive', 'color' => 'success'],
];
?>

    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-1">Email History</h1>
                <p class="text-muted">View all sent email notifications</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-primary bg-opacity-10 text-primary rounded p-3">
                                    <i class="fas fa-envelope fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Total Emails</h6>
                                <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 border-start border-success border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-success bg-opacity-10 text-success rounded p-3">
                                    <i class="fas fa-check fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Sent</h6>
                                <h3 class="mb-0"><?php echo $stats['sent']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 border-start border-danger border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-danger bg-opacity-10 text-danger rounded p-3">
                                    <i class="fas fa-times fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Failed</h6>
                                <h3 class="mb-0"><?php echo $stats['failed']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card h-100 border-start border-warning border-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-warning bg-opacity-10 text-warning rounded p-3">
                                    <i class="fas fa-clock fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Pending</h6>
                                <h3 class="mb-0"><?php echo $stats['pending']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email List -->
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($emails)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-envelope fa-4x text-muted mb-3"></i>
                        <h3 class="h5">No Emails Found</h3>
                        <p class="text-muted">Email notifications will appear here once they are sent.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="emailHistoryTable" class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th class="ps-3">Date</th>
                                <th>Type</th>
                                <th>Vehicle</th>
                                <th>Subject</th>
                                <th>Recipient</th>
                                <th class="pe-3">Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($emails as $email):
                                $type = $emailTypes[$email['email_type']] ?? ['label' => $email['email_type'], 'icon' => 'fa-envelope', 'color' => 'primary'];
                                $vehicle = $email['make'] ? sanitize($email['make'] . ' ' . $email['model'] . ' (' . $email['year'] . ')') : '';
                                $sentDate = $email['sent_at'] ?? $email['created_at'];
                                ?>
                                <tr class="view-email-row" role="button"
                                    data-type-label="<?php echo htmlspecialchars($type['label']); ?>"
                                    data-type-color="<?php echo $type['color']; ?>"
                                    data-type-icon="<?php echo $type['icon']; ?>"
                                    data-status="<?php echo $email['status']; ?>"
                                    data-recipient="<?php echo htmlspecialchars($email['recipient_email']); ?>"
                                    data-date="<?php echo date('F d, Y \a\t H:i', strtotime($sentDate)); ?>"
                                    data-vehicle="<?php echo $vehicle; ?>"
                                    data-subject="<?php echo htmlspecialchars($email['subject']); ?>"
                                    data-body="<?php echo htmlspecialchars($email['body'] ?? ''); ?>">
                                    <td class="ps-3 text-nowrap">
                                        <div class="small"><?php echo date('M d, Y', strtotime($email['created_at'])); ?></div>
                                        <div class="text-muted" style="font-size:0.75rem"><?php echo date('H:i', strtotime($email['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $type['color']; ?>">
                                            <i class="fas <?php echo $type['icon']; ?>"></i> <?php echo $type['label']; ?>
                                        </span>
                                    </td>
                                    <td class="small">
                                        <?php if ($email['make']): ?>
                                            <?php echo sanitize($email['make'] . ' ' . $email['model']); ?>
                                            <span class="text-muted"><?php echo $email['year']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:220px">
                                        <span class="d-block text-truncate small" title="<?php echo htmlspecialchars($email['subject']); ?>">
                                            <?php echo sanitize($email['subject']); ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted text-nowrap"><?php echo sanitize($email['recipient_email']); ?></td>
                                    <td class="pe-3">
                                        <?php if ($email['status'] === 'sent'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Sent</span>
                                        <?php elseif ($email['status'] === 'failed'): ?>
                                            <span class="badge bg-danger"><i class="fas fa-times"></i> Failed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($emails) >= 100): ?>
                        <div class="alert alert-info m-3 mb-2">
                            <i class="fas fa-info-circle"></i> Showing the most recent 100 emails. Use the search box above the table to narrow it down.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<!-- Shared Email Detail Modal -->
<div class="modal fade" id="emailDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Email Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-3">
                    <span id="modalTypeBadge" class="badge"></span>
                    <span id="modalStatusBadge" class="badge"></span>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p class="text-muted small mb-1 fw-bold">Recipient</p>
                        <p id="modalRecipient" class="mb-0"></p>
                    </div>
                    <div class="col-md-6">
                        <p class="text-muted small mb-1 fw-bold">Date Sent</p>
                        <p id="modalDate" class="mb-0"></p>
                    </div>
                </div>
                <div id="modalVehicleRow" class="mb-3 d-none">
                    <p class="text-muted small mb-1 fw-bold">Vehicle</p>
                    <p id="modalVehicle" class="mb-0"></p>
                </div>
                <div class="mb-3">
                    <p class="text-muted small mb-1 fw-bold">Subject</p>
                    <p id="modalSubject" class="mb-0"></p>
                </div>
                <div>
                    <p class="text-muted small mb-1 fw-bold">Email Body</p>
                    <iframe id="modalBodyFrame" srcdoc="" style="width:100%;height:420px;border:1px solid #dee2e6;border-radius:4px;" sandbox="allow-same-origin"></iframe>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.view-email-row').forEach(row => {
    row.addEventListener('click', function () {
        const d = this.dataset;

        document.getElementById('modalTypeBadge').className = `badge bg-${d.typeColor}`;
        document.getElementById('modalTypeBadge').innerHTML = `<i class="fas ${d.typeIcon}"></i> ${d.typeLabel}`;

        const statusMap = { sent: ['bg-success','fa-check','Sent'], failed: ['bg-danger','fa-times','Failed'], pending: ['bg-warning','fa-clock','Pending'] };
        const [sc, si, sl] = statusMap[d.status] ?? ['bg-secondary','fa-question', d.status];
        document.getElementById('modalStatusBadge').className = `badge ${sc}`;
        document.getElementById('modalStatusBadge').innerHTML = `<i class="fas ${si}"></i> ${sl}`;

        document.getElementById('modalRecipient').textContent = d.recipient;
        document.getElementById('modalDate').textContent = d.date;
        document.getElementById('modalSubject').textContent = d.subject;

        const vehicleRow = document.getElementById('modalVehicleRow');
        if (d.vehicle) {
            document.getElementById('modalVehicle').textContent = d.vehicle;
            vehicleRow.classList.remove('d-none');
        } else {
            vehicleRow.classList.add('d-none');
        }

        document.getElementById('modalBodyFrame').srcdoc = d.body || '<p style="color:#999;padding:1rem">No content available.</p>';

        new bootstrap.Modal(document.getElementById('emailDetailModal')).show();
    });
});

jQuery(function ($) {
    if ($.fn.DataTable && $('#emailHistoryTable tbody tr').length) {
        $('#emailHistoryTable').DataTable({
            order: [],
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100]
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>