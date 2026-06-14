<?php
$pageTitle = 'Email History';
require_once 'includes/header.php';

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = [];
if ($typeFilter) $where[] = "el.email_type = '" . $pdo->quote($typeFilter) . "'";
if ($statusFilter) $where[] = "el.status = '" . $pdo->quote($statusFilter) . "'";
$whereClause = $where ? 'WHERE ' . implode(' AND ', str_replace("'", "", $where)) : '';

$emails = $pdo->query("
    SELECT el.*, v.make, v.model, v.year
    FROM email_log el
    LEFT JOIN vehicles v ON el.vehicle_id = v.id
    $whereClause
    ORDER BY el.created_at DESC
    LIMIT 100
")->fetchAll();

$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM email_log
")->fetch();

$emailTypes = [
    'service_reminder' => ['label' => 'Service Reminder', 'icon' => 'fa-bell', 'color' => 'warning'],
    'monthly_check' => ['label' => 'Monthly Check', 'icon' => 'fa-calendar', 'color' => 'info'],
    'service_details' => ['label' => 'Service Details', 'icon' => 'fa-wrench', 'color' => 'success'],
    'low_mileage_warning' => ['label' => 'Low Mileage Warning', 'icon' => 'fa-tachometer-alt', 'color' => 'danger']
];
?>

    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-1">Email History</h1>
                <p class="text-muted">View all sent email notifications</p>
            </div>
            <div class="col-auto">
                <a href="settings.php" class="btn btn-secondary">
                    <i class="fas fa-cog"></i> Email Settings
                </a>
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

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Email Type</label>
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($emailTypes as $key => $type): ?>
                                <option value="<?php echo $key; ?>" <?php echo $typeFilter === $key ? 'selected' : ''; ?>>
                                    <?php echo $type['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="sent" <?php echo $statusFilter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <?php if ($typeFilter || $statusFilter): ?>
                            <a href="email-history.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Email List -->
        <div class="card">
            <div class="card-body">
                <?php if (empty($emails)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-envelope fa-4x text-muted mb-3"></i>
                        <h3 class="h5">No Emails Found</h3>
                        <p class="text-muted">Email notifications will appear here once they are sent.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Vehicle</th>
                                <th>Subject</th>
                                <th>Recipient</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($emails as $email):
                                $type = $emailTypes[$email['email_type']] ?? ['label' => $email['email_type'], 'icon' => 'fa-envelope', 'color' => 'primary'];
                                ?>
                                <tr>
                                    <td>
                                        <div><?php echo date('M d, Y', strtotime($email['created_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($email['created_at'])); ?></small>
                                    </td>
                                    <td>
                                    <span class="badge bg-<?php echo $type['color']; ?>">
                                        <i class="fas <?php echo $type['icon']; ?>"></i> <?php echo $type['label']; ?>
                                    </span>
                                    </td>
                                    <td>
                                        <?php if ($email['make']): ?>
                                            <div><?php echo sanitize($email['make'] . ' ' . $email['model']); ?></div>
                                            <small class="text-muted"><?php echo $email['year']; ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                    <span class="d-inline-block text-truncate" max-wstyle="idth: 250px;" title="<?php echo sanitize($email['subject']); ?>">
                                        <?php echo sanitize($email['subject']); ?>
                                    </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo sanitize($email['recipient_email']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($email['status'] === 'sent'): ?>
                                            <div>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check"></i> Sent
                                            </span>
                                            </div>
                                            <?php if ($email['sent_at']): ?>
                                                <small class="text-muted d-block mt-1">
                                                    <?php echo date('M d, H:i', strtotime($email['sent_at'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php elseif ($email['status'] === 'failed'): ?>
                                            <span class="badge bg-danger">
                                            <i class="fas fa-times"></i> Failed
                                        </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td >
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#emailDetailModal<?php echo $email['id']; ?>"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <!-- Email Detail Modal -->
                                        <div class="modal fade" id="emailDetailModal<?php echo $email['id']; ?>" tabindex="-1" aria-labelledby="emailDetailModalLabel<?php echo $email['id']; ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="emailDetailModalLabel<?php echo $email['id']; ?>">
                                                            <i class="fas fa-envelope me-2"></i>Email Details
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <!-- Email Type Badge -->
                                                        <div class="mb-3">
                                                        <span class="badge bg-<?php echo $type['color']; ?> fs-6">
                                                            <i class="fas <?php echo $type['icon']; ?>"></i> <?php echo $type['label']; ?>
                                                        </span>
                                                            <?php if ($email['status'] === 'sent'): ?>
                                                                <span class="badge bg-success fs-6 ms-2">
                                                                <i class="fas fa-check"></i> Sent
                                                            </span>
                                                            <?php elseif ($email['status'] === 'failed'): ?>
                                                                <span class="badge bg-danger fs-6 ms-2">
                                                                <i class="fas fa-times"></i> Failed
                                                            </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning fs-6 ms-2">
                                                                <i class="fas fa-clock"></i> Pending
                                                            </span>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Email Info -->
                                                        <div class="row mb-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold text-muted">Recipient</label>
                                                                <p class="mb-0"><?php echo sanitize($email['recipient_email']); ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label fw-bold text-muted">Date Sent</label>
                                                                <p class="mb-0">
                                                                    <?php
                                                                    if ($email['sent_at']) {
                                                                        echo date('F d, Y \a\t H:i', strtotime($email['sent_at']));
                                                                    } else {
                                                                        echo date('F d, Y \a\t H:i', strtotime($email['created_at']));
                                                                    }
                                                                    ?>
                                                                </p>
                                                            </div>
                                                        </div>

                                                        <?php if ($email['make']): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold text-muted">Vehicle</label>
                                                                <p class="mb-0">
                                                                    <?php echo sanitize($email['make'] . ' ' . $email['model'] . ' (' . $email['year'] . ')'); ?>
                                                                </p>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold text-muted">Subject</label>
                                                            <p class="mb-0"><?php echo sanitize($email['subject']); ?></p>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold text-muted">Content Preview</label>
                                                            <div class="bg-light border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                                                                <?php
                                                                // Strip HTML for preview, show first 1000 chars
                                                                $preview = strip_tags($email['body'] ?? 'No content available.');
                                                                echo nl2br(sanitize($preview));
                                                                ?>
                                                            </div>
                                                        </div>

                                                        <?php if ($email['status'] === 'failed' && !empty($email['error_message'])): ?>
                                                            <div class="alert alert-danger mb-0">
                                                                <h6 class="alert-heading">
                                                                    <i class="fas fa-exclamation-triangle"></i> Error Message
                                                                </h6>
                                                                <p class="mb-0"><?php echo sanitize($email['error_message']); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="fas fa-times"></i> Close
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Info -->
                    <?php if (count($emails) >= 100): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="fas fa-info-circle"></i> Showing the most recent 100 emails. Use filters to narrow your search.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php require_once 'includes/footer.php'; ?>