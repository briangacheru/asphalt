<?php
$pageTitle = 'Maintenance Schedule';
require_once 'includes/header.php';

use App\Helpers\IdCodec;
use App\Services\PartMaintenanceSyncService;

$vehiclesStmt = $pdo->prepare("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 AND user_id = ? ORDER BY make, model");
$vehiclesStmt->execute([$userId]);
$vehicles = $vehiclesStmt->fetchAll();
$vehicleFilter = IdCodec::decode($_GET['vehicle_id'] ?? null);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_schedule') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        $id = (int)$_POST['schedule_id'];
        $interval_km = !empty($_POST['interval_km']) ? (int)$_POST['interval_km'] : null;
        $interval_months = !empty($_POST['interval_months']) ? (int)$_POST['interval_months'] : null;
        $priority = sanitize($_POST['priority'] ?? 'medium');

        // Vehicle, item type, last-replaced info, and notes come from Expenses/the
        // auto-sync and aren't editable here — only interval and priority are, so
        // those other fields are read from the existing row rather than trusted from POST.
        $ownScheduleStmt = $pdo->prepare("
            SELECT ms.* FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ? AND v.user_id = ?
        ");
        $ownScheduleStmt->execute([$id, $userId]);
        $existing = $ownScheduleStmt->fetch();

        if (!$existing) {
            setFlashMessage('danger', 'Maintenance item not found.');
        } else {
            $next_due_mileage = $existing['last_replaced_mileage'] && $interval_km ? $existing['last_replaced_mileage'] + $interval_km : null;
            $next_due_date = $existing['last_replaced_date'] && $interval_months ? date('Y-m-d', strtotime("+$interval_months months", strtotime($existing['last_replaced_date']))) : null;

            try {
                $stmt = $pdo->prepare("UPDATE maintenance_schedule SET interval_km = ?, interval_months = ?, next_due_mileage = ?, next_due_date = ?, priority = ? WHERE id = ?");
                $stmt->execute([$interval_km, $interval_months, $next_due_mileage, $next_due_date, $priority, $id]);
                setFlashMessage('success', 'Maintenance item updated successfully!');
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error: ' . $e->getMessage());
            }
        }
        redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
    }

    if ($action === 'sync_from_expenses') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        try {
            $syncedCount = PartMaintenanceSyncService::syncAllForUser($pdo, $userId);
            setFlashMessage('success', $syncedCount > 0
                ? "Synced {$syncedCount} part(s) from your expense history."
                : 'Nothing to sync — no part-tagged expenses found.');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Sync failed: ' . $e->getMessage());
        }
        redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
    }

}

// Get schedules — scoped to the current user's own vehicles
$where = "WHERE v.user_id = " . (int)$userId;
if ($vehicleFilter) {
    $where .= " AND ms.vehicle_id = " . (int)$vehicleFilter;
}
$schedules = $pdo->query("
    SELECT ms.*, v.make, v.model, v.year, v.current_mileage
    FROM maintenance_schedule ms
    JOIN vehicles v ON ms.vehicle_id = v.id
    $where
    ORDER BY ms.id DESC
")->fetchAll();

// Categorize by status
$overdue = [];
$dueSoon = [];
$upcoming = [];
$ok = [];

foreach ($schedules as $s) {
    $kmOverdue = $s['next_due_mileage'] ? $s['current_mileage'] - $s['next_due_mileage'] : null;
    $dateOverdue = $s['next_due_date'] ? (strtotime($s['next_due_date']) < time()) : false;

    if (($kmOverdue !== null && $kmOverdue > 0) || $dateOverdue) {
        $s['status'] = 'overdue';
        $s['km_overdue'] = $kmOverdue;
        $overdue[] = $s;
    } elseif ($kmOverdue !== null && $kmOverdue > -2000) {
        $s['status'] = 'due_soon';
        $s['km_remaining'] = abs($kmOverdue);
        $dueSoon[] = $s;
    } elseif ($kmOverdue !== null && $kmOverdue > -5000) {
        $s['status'] = 'upcoming';
        $s['km_remaining'] = abs($kmOverdue);
        $upcoming[] = $s;
    } else {
        $s['status'] = 'ok';
        $s['km_remaining'] = $kmOverdue !== null ? abs($kmOverdue) : null;
        $ok[] = $s;
    }
}

// Single combined list for the unified table, most urgent first
$allAnnotated = array_merge($overdue, $dueSoon, $upcoming, $ok);
?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row justify-content-between align-items-center">
                <div class="col-md">
                    <div class="d-flex">
                        <div class="calendar me-2">
                            <span class="calendar-month"><?php echo date('M'); ?></span>
                            <span class="calendar-day"><?php echo date('d'); ?></span>
                        </div>
                        <div class="flex-1">
                            <h4 class="fs-6">Maintenance Schedule</h4>
                            <p class="mb-0 fs-10">Track all maintenance items and their intervals</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0 d-flex gap-2">
                    <form method="POST" onsubmit="return confirm('Sync part-tagged expenses (Tires, Brake Pads, etc.) into this schedule? Safe to run anytime.');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="sync_from_expenses">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Catch up historical expenses that predate auto-sync">
                            <i class="fas fa-sync"></i> Sync from Expenses
                        </button>
                    </form>
                </div>
            </div>

            <!-- Vehicle Filter -->
            <?php if (count($vehicles) > 1): ?>
                <div class="row mt-3">
                    <div class="col-md-4">
                        <form method="GET" class="d-flex gap-2">
                            <select name="vehicle_id" class="form-control" onchange="this.form.submit()">
                                <option value="">All Vehicles</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?php echo IdCodec::encode($v['id']); ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($vehicleFilter): ?>
                                <a href="maintenance-schedule" class="btn btn-sm btn-outline-secondary">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
// Display flash messages
$flash = getFlashMessage();
if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
        <span><?php echo $flash['message']; ?></span>
        <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

    <div class="row g-3 mb-3" id="statusFilterCards">
        <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
            <div class="card h-100 status-filter-card" data-status-filter="overdue" role="button" tabindex="0">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">Overdue</h6>
                            <i class="fas fa-exclamation-circle text-danger fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-danger"><?php echo count($overdue); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
            <div class="card h-100 status-filter-card" data-status-filter="due_soon" role="button" tabindex="0">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">Due Soon</h6>
                            <i class="fas fa-exclamation-triangle text-warning fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-warning"><?php echo count($dueSoon); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
            <div class="card h-100 status-filter-card" data-status-filter="upcoming" role="button" tabindex="0">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">Upcoming</h6>
                            <i class="fas fa-clock text-primary fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-primary"><?php echo count($upcoming); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
            <div class="card h-100 status-filter-card" data-status-filter="ok" role="button" tabindex="0">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">OK</h6>
                            <i class="fas fa-check-circle text-success fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-success"><?php echo count($ok); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
// Function to render schedule sections
function renderScheduleTable($items) {
    global $pdo, $vehicles;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between bg-body-tertiary">
            <div>
                <i class="fas fa-clipboard-check me-2"></i>
                <strong>Maintenance Items</strong>
                <span class="badge bg-secondary ms-2"><?php echo count($items); ?></span>
            </div>
            <a href="#" id="clearStatusFilter" class="small d-none">
                <i class="fas fa-times-circle me-1"></i>Clear filter
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="maintenanceScheduleTable" class="table table-responsive-sm mb-0 data-table fs-10"
                       data-datatables='{"order": [], "columnDefs": [{"targets": 7, "visible": false, "searchable": true}]}'>
                    <thead class="bg-200">
                    <tr>
                        <th>Vehicle</th>
                        <th>Item</th>
                        <th>Last Service</th>
                        <th>Next Due</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Actions</th>
                        <th>StatusKey</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $deferredModals = ''; ?>
                    <?php foreach ($items as $s): ?>
                        <tr class="hover-actions-trigger btn-reveal-trigger hover-bg-100 cursor-pointer"
                            data-bs-toggle="modal" data-bs-target="#editScheduleModal<?php echo $s['id']; ?>">
                            <td>
                                <strong><?php echo sanitize($s['make'] . ' ' . $s['model']); ?></strong><br>
                                <small class="text-muted"><?php echo number_format($s['current_mileage']); ?> km</small>
                            </td>
                            <td>
                                <strong><?php echo sanitize($s['item_type']); ?></strong><br>
                                <small class="text-muted">
                                    <?php
                                    $interval = [];
                                    if ($s['interval_km']) $interval[] = number_format($s['interval_km']) . ' km';
                                    if ($s['interval_months']) $interval[] = $s['interval_months'] . ' months';
                                    echo implode(' / ', $interval);
                                    ?>
                                </small>
                            </td>
                            <td>
                                <?php if ($s['last_replaced_date'] || $s['last_replaced_mileage']): ?>
                                    <?php if ($s['last_replaced_date']): ?>
                                        <?php echo date('M d, Y', strtotime($s['last_replaced_date'])); ?><br>
                                    <?php endif; ?>
                                    <?php if ($s['last_replaced_mileage']): ?>
                                        <small class="text-muted"><?php echo number_format($s['last_replaced_mileage']); ?> km</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['next_due_date'] || $s['next_due_mileage']): ?>
                                    <?php if ($s['next_due_date']): ?>
                                        <?php echo date('M d, Y', strtotime($s['next_due_date'])); ?><br>
                                    <?php endif; ?>
                                    <?php if ($s['next_due_mileage']): ?>
                                        <small class="text-muted"><?php echo number_format($s['next_due_mileage']); ?> km</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $priorityColors = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'critical' => 'danger'];
                                $priorityColor = $priorityColors[$s['priority']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $priorityColor; ?>"><?php echo ucfirst($s['priority']); ?></span>
                            </td>
                            <td>
                                <?php if ($s['status'] === 'overdue'): ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Overdue <?php echo $s['km_overdue'] > 0 ? '(' . number_format($s['km_overdue']) . ' km)' : ''; ?>
                                    </span>
                                <?php elseif ($s['status'] === 'due_soon'): ?>
                                    <span class="badge bg-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Due Soon (<?php echo number_format($s['km_remaining']); ?> km left)
                                    </span>
                                <?php elseif ($s['status'] === 'upcoming'): ?>
                                    <span class="badge bg-info">
                                        <i class="fas fa-clock"></i>
                                        Upcoming (<?php echo number_format($s['km_remaining']); ?> km left)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle"></i>
                                        OK <?php echo $s['km_remaining'] ? '(' . number_format($s['km_remaining']) . ' km left)' : ''; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle white-space-nowrap position-relative">
                                <div class="hover-actions bg-100">
                                    <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" data-bs-toggle="modal" data-bs-target="#editScheduleModal<?php echo $s['id']; ?>" title="Edit">
                                        <span class="fas fa-edit"></span>
                                    </button>
                                </div>
                                <div class="dropdown font-sans-serif btn-reveal-trigger">
                                    <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal-sm transition-none" type="button" id="crm-recent-leads-0" onclick="event.stopPropagation()" data-bs-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs-11"></span></button>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($s['status']); ?></td>
                        </tr>
                        <?php
                        // Modals are rendered after </table> instead of inline: a fixed-position
                        // modal nested inside .table-responsive's overflow-x:auto gets clipped to
                        // that container on mobile browsers instead of covering the viewport.
                        ob_start();
                        ?>
                                <!-- Edit Modal -->
                                <div class="modal fade" id="editScheduleModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="editScheduleModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editScheduleModalLabel<?php echo $s['id']; ?>">Edit Maintenance Item</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="edit_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">

                                                    <p class="text-muted fs-11 mb-3"><i class="fas fa-lock me-1"></i>Vehicle, item, last-replaced info, and notes come from Expenses and can only be changed there. Only the interval and priority are editable here.</p>

                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Vehicle</label>
                                                            <select class="form-control" disabled>
                                                                <?php foreach ($vehicles as $v): ?>
                                                                    <option <?php echo $s['vehicle_id'] == $v['id'] ? 'selected' : ''; ?>>
                                                                        <?php echo sanitize($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Maintenance Item</label>
                                                            <input type="text" class="form-control" value="<?php echo sanitize($s['item_type']); ?>" readonly>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Interval (km)</label>
                                                            <input type="number" name="interval_km" class="form-control" value="<?php echo $s['interval_km']; ?>" placeholder="e.g., 10000">
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Interval (months)</label>
                                                            <input type="number" name="interval_months" class="form-control" value="<?php echo $s['interval_months']; ?>" placeholder="e.g., 12">
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label">Priority</label>
                                                            <select name="priority" class="form-control">
                                                                <option value="low" <?php echo $s['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                                                <option value="medium" <?php echo $s['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                                <option value="high" <?php echo $s['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                                                <option value="critical" <?php echo $s['priority'] === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Last Replaced Date</label>
                                                            <input type="date" class="form-control" value="<?php echo $s['last_replaced_date']; ?>" readonly>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Last Replaced Mileage (km)</label>
                                                            <input type="number" class="form-control" value="<?php echo $s['last_replaced_mileage']; ?>" placeholder="km" readonly>
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Notes</label>
                                                        <textarea class="form-control" rows="3" readonly><?php echo sanitize($s['notes']); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Item</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                        <?php
                        $deferredModals .= ob_get_clean();
                    endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php echo $deferredModals; ?>
    <?php
}

// Render schedule sections
if (!empty($allAnnotated)) {
    renderScheduleTable($allAnnotated);
}

// Empty state
if (empty($schedules)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state text-center py-5">
                <i class="fas fa-calendar-alt fa-4x text-muted mb-3"></i>
                <h3>No Maintenance Items</h3>
                <p class="text-muted">Maintenance items are created automatically from tagged expenses — log a part replacement on the Expenses page, or use "Sync from Expenses" above to catch up on existing ones.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

    <style>
        .status-filter-card { cursor: pointer; transition: box-shadow .15s ease; }
        .status-filter-card:hover { box-shadow: 0 0 0 2px rgba(var(--falcon-primary-rgb), .25); }
        .status-filter-card.active { box-shadow: 0 0 0 2px var(--falcon-primary); }
    </style>

    <script>
        // Stat cards double as filters for the maintenance table's hidden StatusKey column
        document.addEventListener('DOMContentLoaded', function () {
            var $table = $('#maintenanceScheduleTable');
            if (!$table.length) return;

            function wireStatusFilters() {
                var dt = $table.DataTable();
                var cards = document.querySelectorAll('.status-filter-card');
                var clearLink = document.getElementById('clearStatusFilter');

                function applyFilter(status) {
                    dt.column(7).search(status ? '^' + status + '$' : '', true, false).draw();
                    cards.forEach(function (c) {
                        c.classList.toggle('active', c.dataset.statusFilter === status);
                    });
                    if (clearLink) clearLink.classList.toggle('d-none', !status);
                }

                cards.forEach(function (card) {
                    card.addEventListener('click', function () {
                        var status = this.dataset.statusFilter;
                        applyFilter(this.classList.contains('active') ? null : status);
                    });
                    card.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            this.click();
                        }
                    });
                });

                if (clearLink) {
                    clearLink.addEventListener('click', function (e) {
                        e.preventDefault();
                        applyFilter(null);
                    });
                }
            }

            if ($.fn.DataTable.isDataTable('#maintenanceScheduleTable')) {
                wireStatusFilters();
            } else {
                $table.one('init.dt', wireStatusFilters);
            }
        });
    </script>

<?php require_once 'includes/footer.php'; ?>