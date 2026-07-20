<?php
$pageTitle = 'Maintenance Schedule';
require_once 'includes/header.php';

use App\Helpers\IdCodec;

$vehiclesStmt = $pdo->prepare("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 AND user_id = ? ORDER BY make, model");
$vehiclesStmt->execute([$userId]);
$vehicles = $vehiclesStmt->fetchAll();
$vehicleFilter = IdCodec::decode($_GET['vehicle_id'] ?? null);

// Default maintenance items with recommended intervals
$defaultItems = [
    'Engine Oil & Filter' => ['km' => 10000, 'months' => 12],
    'Air Filter' => ['km' => 20000, 'months' => 24],
    'Cabin Filter' => ['km' => 15000, 'months' => 12],
    'Spark Plugs' => ['km' => 40000, 'months' => 48],
    'Brake Fluid' => ['km' => 40000, 'months' => 24],
    'Coolant' => ['km' => 50000, 'months' => 36],
    'Transmission Fluid' => ['km' => 60000, 'months' => 48],
    'Power Steering Fluid' => ['km' => 50000, 'months' => 36],
    'Timing Belt' => ['km' => 100000, 'months' => 72],
    'Serpentine Belt' => ['km' => 80000, 'months' => 60],
    'Battery' => ['km' => null, 'months' => 48],
    'Front Brake Pads' => ['km' => 50000, 'months' => null],
    'Rear Brake Pads' => ['km' => 60000, 'months' => null],
    'Tires' => ['km' => 50000, 'months' => 48],
    'Wiper Blades' => ['km' => null, 'months' => 12],
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_schedule') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        $vehicle_id = (int)$_POST['vehicle_id'];
        $item_type = sanitize($_POST['item_type']);
        $interval_km = !empty($_POST['interval_km']) ? (int)$_POST['interval_km'] : null;
        $interval_months = !empty($_POST['interval_months']) ? (int)$_POST['interval_months'] : null;
        $last_replaced_date = $_POST['last_replaced_date'] ?: null;
        $last_replaced_mileage = !empty($_POST['last_replaced_mileage']) ? (int)$_POST['last_replaced_mileage'] : null;
        $priority = sanitize($_POST['priority'] ?? 'medium');
        $notes = sanitize($_POST['notes'] ?? '');

        // Calculate next due
        $next_due_mileage = $last_replaced_mileage && $interval_km ? $last_replaced_mileage + $interval_km : null;
        $next_due_date = $last_replaced_date && $interval_months ? date('Y-m-d', strtotime("+$interval_months months", strtotime($last_replaced_date))) : null;

        $ownStmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $ownStmt->execute([$vehicle_id, $userId]);

        if (!$ownStmt->fetch()) {
            setFlashMessage('danger', 'Vehicle not found.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO maintenance_schedule (vehicle_id, item_type, interval_km, interval_months, last_replaced_date, last_replaced_mileage, next_due_mileage, next_due_date, priority, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vehicle_id, $item_type, $interval_km, $interval_months, $last_replaced_date, $last_replaced_mileage, $next_due_mileage, $next_due_date, $priority, $notes]);
                setFlashMessage('success', 'Maintenance item added successfully!');
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error: ' . $e->getMessage());
            }
        }
        redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
    }

    if ($action === 'edit_schedule') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        $id = (int)$_POST['schedule_id'];
        $vehicle_id = (int)$_POST['vehicle_id'];
        $item_type = sanitize($_POST['item_type']);
        $interval_km = !empty($_POST['interval_km']) ? (int)$_POST['interval_km'] : null;
        $interval_months = !empty($_POST['interval_months']) ? (int)$_POST['interval_months'] : null;
        $last_replaced_date = $_POST['last_replaced_date'] ?: null;
        $last_replaced_mileage = !empty($_POST['last_replaced_mileage']) ? (int)$_POST['last_replaced_mileage'] : null;
        $priority = sanitize($_POST['priority'] ?? 'medium');
        $notes = sanitize($_POST['notes'] ?? '');

        // Recalculate next due
        $next_due_mileage = $last_replaced_mileage && $interval_km ? $last_replaced_mileage + $interval_km : null;
        $next_due_date = $last_replaced_date && $interval_months ? date('Y-m-d', strtotime("+$interval_months months", strtotime($last_replaced_date))) : null;

        $ownScheduleStmt = $pdo->prepare("
            SELECT ms.id FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ? AND v.user_id = ?
        ");
        $ownScheduleStmt->execute([$id, $userId]);

        $ownVehicleStmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $ownVehicleStmt->execute([$vehicle_id, $userId]);

        if (!$ownScheduleStmt->fetch() || !$ownVehicleStmt->fetch()) {
            setFlashMessage('danger', 'Maintenance item not found.');
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE maintenance_schedule SET vehicle_id = ?, item_type = ?, interval_km = ?, interval_months = ?, last_replaced_date = ?, last_replaced_mileage = ?, next_due_mileage = ?, next_due_date = ?, priority = ?, notes = ? WHERE id = ?");
                $stmt->execute([$vehicle_id, $item_type, $interval_km, $interval_months, $last_replaced_date, $last_replaced_mileage, $next_due_mileage, $next_due_date, $priority, $notes, $id]);
                setFlashMessage('success', 'Maintenance item updated successfully!');
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error: ' . $e->getMessage());
            }
        }
        redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
    }

    if ($action === 'mark_done') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        $id = (int)$_POST['schedule_id'];
        $new_mileage = (int)$_POST['new_mileage'];
        $new_date = date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT ms.* FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        $item = $stmt->fetch();

        if ($item) {
            $next_due_mileage = $item['interval_km'] ? $new_mileage + $item['interval_km'] : null;
            $next_due_date = $item['interval_months'] ? date('Y-m-d', strtotime("+{$item['interval_months']} months")) : null;

            $stmt = $pdo->prepare("UPDATE maintenance_schedule SET last_replaced_date = ?, last_replaced_mileage = ?, next_due_mileage = ?, next_due_date = ? WHERE id = ?");
            $stmt->execute([$new_date, $new_mileage, $next_due_mileage, $next_due_date, $id]);

            // Update vehicle mileage if higher
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")->execute([$new_mileage, $item['vehicle_id'], $userId]);

            setFlashMessage('success', 'Maintenance marked as completed!');
        } else {
            setFlashMessage('danger', 'Maintenance item not found.');
        }
        redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
    }

    if ($action === 'delete') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            setFlashMessage('danger', 'Invalid security token. Please try again.');
            redirect('maintenance-schedule' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        }

        $id = (int)$_POST['schedule_id'];
        $stmt = $pdo->prepare("
            DELETE ms FROM maintenance_schedule ms
            JOIN vehicles v ON ms.vehicle_id = v.id
            WHERE ms.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        setFlashMessage('success', 'Maintenance item deleted successfully.');
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
                <div class="col-md-auto mt-4 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
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

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
            <div class="card h-100">
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
            <div class="card h-100">
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
            <div class="card h-100">
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
            <div class="card h-100">
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
function renderScheduleSection($items, $title, $badgeColor, $icon) {
    if (empty($items)) return;
    global $pdo, $vehicles;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center bg-body-tertiary">
            <i class="fas fa-<?php echo $icon; ?> text-<?php echo $badgeColor; ?> me-2"></i>
            <strong><?php echo $title; ?></strong>
            <span class="badge bg-<?php echo $badgeColor; ?> ms-2"><?php echo count($items); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-responsive-sm mb-0 data-table fs-10" data-datatables='{"order": []}'>
                    <thead class="bg-200">
                    <tr >
                        <th>Vehicle</th>
                        <th>Item</th>
                        <th>Last Service</th>
                        <th>Next Due</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
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
                                    <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" data-bs-toggle="modal" data-bs-target="#markDoneModal<?php echo $s['id']; ?>" title="Mark as Done">
                                        <span class="fas fa-check"></span>
                                    </button>
                                    <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" data-bs-toggle="modal" data-bs-target="#editScheduleModal<?php echo $s['id']; ?>" title="Edit">
                                        <span class="fas fa-edit"></span>
                                    </button>
                                    <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $s['id']; ?>" title="Delete">
                                        <span class="fas fa-trash"></span>
                                    </button>
                                </div>
                                <div class="dropdown font-sans-serif btn-reveal-trigger">
                                    <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal-sm transition-none" type="button" id="crm-recent-leads-0" onclick="event.stopPropagation()" data-bs-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs-11"></span></button>
                                </div>

                                <!-- Mark Done Modal -->
                                <div class="modal fade" id="markDoneModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="markDoneModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="markDoneModalLabel<?php echo $s['id']; ?>">Mark as Done</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="mark_done">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
                                                    <p><strong><?php echo sanitize($s['item_type']); ?></strong> for <?php echo sanitize($s['make'] . ' ' . $s['model']); ?></p>
                                                    <div class="mb-3">
                                                        <label class="form-label">Current Mileage (km)</label>
                                                        <input type="number" name="new_mileage" class="form-control" value="<?php echo $s['current_mileage']; ?>" required>
                                                        <div class="form-text">This will update the vehicle's current mileage and calculate the next service date.</div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Mark as Done</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

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

                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                                            <select name="vehicle_id" class="form-control" required>
                                                                <?php foreach ($vehicles as $v): ?>
                                                                    <option value="<?php echo $v['id']; ?>" <?php echo $s['vehicle_id'] == $v['id'] ? 'selected' : ''; ?>>
                                                                        <?php echo sanitize($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Maintenance Item <span class="text-danger">*</span></label>
                                                            <input type="text" name="item_type" class="form-control" value="<?php echo sanitize($s['item_type']); ?>" required>
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
                                                            <input type="date" name="last_replaced_date" class="form-control" value="<?php echo $s['last_replaced_date']; ?>">
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Last Replaced Mileage (km)</label>
                                                            <input type="number" name="last_replaced_mileage" class="form-control" value="<?php echo $s['last_replaced_mileage']; ?>" placeholder="km">
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Notes</label>
                                                        <textarea name="notes" class="form-control" rows="3"><?php echo sanitize($s['notes']); ?></textarea>
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

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title" id="deleteModalLabel<?php echo $s['id']; ?>">
                                                    <i class="fas fa-exclamation-triangle"></i> Confirm Deletion
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
                                                    <p>Are you sure you want to delete this maintenance item?</p>
                                                    <div class="alert alert-warning">
                                                        <strong><?php echo sanitize($s['item_type']); ?></strong><br>
                                                        <small><?php echo sanitize($s['make'] . ' ' . $s['model']); ?></small>
                                                    </div>
                                                    <p class="text-danger mb-0"><i class="fas fa-exclamation-circle"></i> This action cannot be undone.</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

// Render schedule sections
renderScheduleSection($overdue, 'Overdue', 'danger', 'exclamation-circle');
renderScheduleSection($dueSoon, 'Due Soon', 'warning', 'exclamation-triangle');
renderScheduleSection($upcoming, 'Upcoming', 'info', 'clock');
renderScheduleSection($ok, 'On Track', 'success', 'check-circle');

// Empty state
if (empty($schedules)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state text-center py-5">
                <i class="fas fa-calendar-alt fa-4x text-muted mb-3"></i>
                <h3>No Maintenance Items</h3>
                <p class="text-muted">Add maintenance items to track their intervals and stay on top of vehicle servicing.</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
                    <i class="fas fa-plus"></i> Add First Item
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

    <!-- Add Schedule Modal -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1" aria-labelledby="addScheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addScheduleModalLabel">Add Maintenance Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add_schedule">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select name="vehicle_id" class="form-control" required>
                                    <option value="">Select Vehicle...</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?php echo $v['id']; ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>>
                                            <?php echo sanitize($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Maintenance Item <span class="text-danger">*</span></label>
                                <select name="item_type" id="itemTypeSelect" class="form-control" required>
                                    <option value="">Select or type custom...</option>
                                    <?php foreach ($defaultItems as $item => $intervals): ?>
                                        <option value="<?php echo $item; ?>"
                                                data-km="<?php echo $intervals['km'] ?? ''; ?>"
                                                data-months="<?php echo $intervals['months'] ?? ''; ?>">
                                            <?php echo $item; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">-- Custom Item --</option>
                                </select>
                                <input type="text" id="customItemInput" class="form-control mt-2" style="display:none;" placeholder="Enter custom item name">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Interval (km)</label>
                                <input type="number" name="interval_km" id="intervalKm" class="form-control" placeholder="e.g., 10000">
                                <div class="form-text">Service interval in kilometers</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Interval (months)</label>
                                <input type="number" name="interval_months" id="intervalMonths" class="form-control" placeholder="e.g., 12">
                                <div class="form-text">Service interval in months</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-control">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Replaced Date</label>
                                <input type="date" name="last_replaced_date" class="form-control">
                                <div class="form-text">When was this last serviced?</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Replaced Mileage (km)</label>
                                <input type="number" name="last_replaced_mileage" class="form-control" placeholder="km">
                                <div class="form-text">Vehicle mileage at last service</div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Add any additional notes or specifications..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Handle custom item type selection
        document.getElementById('itemTypeSelect').addEventListener('change', function() {
            const customInput = document.getElementById('customItemInput');
            const kmInput = document.getElementById('intervalKm');
            const monthsInput = document.getElementById('intervalMonths');

            if (this.value === '__custom__') {
                // Show custom input
                customInput.style.display = 'block';
                customInput.required = true;
                customInput.name = 'item_type';
                this.name = '';

                // Clear intervals
                kmInput.value = '';
                monthsInput.value = '';
            } else {
                // Hide custom input
                customInput.style.display = 'none';
                customInput.required = false;
                customInput.name = '';
                this.name = 'item_type';

                // Auto-fill intervals from data attributes
                const option = this.options[this.selectedIndex];
                if (option.dataset.km) {
                    kmInput.value = option.dataset.km;
                }
                if (option.dataset.months) {
                    monthsInput.value = option.dataset.months;
                }
            }
        });
    </script>

<?php require_once 'includes/footer.php'; ?>