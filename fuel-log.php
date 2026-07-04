<?php
$pageTitle = 'Fuel Log';
require_once 'includes/header.php';

$vehicles = $pdo->query("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 ORDER BY make, model")->fetchAll();
$vehicleFilter = $_GET['vehicle_id'] ?? '';

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $fill_date = $_POST['fill_date'] ?? date('Y-m-d');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $liters = (float)($_POST['liters'] ?? 0);
    $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
    $total_cost = $liters * $price_per_liter;
    $station_name = sanitize($_POST['station_name'] ?? '');
    $full_tank = 1;

    if ($vehicle_id && $mileage && $liters && $price_per_liter) {
        try {
            // Fuel type comes from the vehicle's own profile, not re-entered here
            $vStmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ?");
            $vStmt->execute([$vehicle_id]);
            $fuel_type = $vStmt->fetchColumn() ?: '';

            $stmt = $pdo->prepare("INSERT INTO fuel_log (vehicle_id, fill_date, mileage, liters, price_per_liter, total_cost, fuel_type, station_name, full_tank) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicle_id, $fill_date, $mileage, $liters, $price_per_liter, $total_cost, $fuel_type, $station_name, $full_tank]);

            // Update vehicle mileage if higher
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ?")->execute([$mileage, $vehicle_id]);

            setFlashMessage('success', 'Fuel record added!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Handle Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $fuel_id = (int)($_POST['fuel_id'] ?? 0);
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $fill_date = $_POST['fill_date'] ?? date('Y-m-d');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $liters = (float)($_POST['liters'] ?? 0);
    $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
    $total_cost = $liters * $price_per_liter;
    $station_name = sanitize($_POST['station_name'] ?? '');
    $full_tank = isset($_POST['full_tank']) ? (int)$_POST['full_tank'] : 1;

    if ($fuel_id && $vehicle_id && $mileage && $liters && $price_per_liter) {
        try {
            // Fuel type comes from the vehicle's own profile, not re-entered here
            $vStmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ?");
            $vStmt->execute([$vehicle_id]);
            $fuel_type = $vStmt->fetchColumn() ?: '';

            $stmt = $pdo->prepare("UPDATE fuel_log SET vehicle_id = ?, fill_date = ?, mileage = ?, liters = ?, price_per_liter = ?, total_cost = ?, fuel_type = ?, station_name = ?, full_tank = ? WHERE id = ?");
            $stmt->execute([$vehicle_id, $fill_date, $mileage, $liters, $price_per_liter, $total_cost, $fuel_type, $station_name, $full_tank, $fuel_id]);

            // Update vehicle mileage if higher
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ?")->execute([$mileage, $vehicle_id]);

            setFlashMessage('success', 'Fuel record updated!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $fuel_id = (int)($_POST['fuel_id'] ?? 0);

    if ($fuel_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM fuel_log WHERE id = ?");
            $stmt->execute([$fuel_id]);
            setFlashMessage('success', 'Fuel record deleted!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Get fuel logs
$where = $vehicleFilter ? "WHERE fl.vehicle_id = $vehicleFilter" : "";
$logs = $pdo->query("
    SELECT fl.*, v.make, v.model, v.year 
    FROM fuel_log fl 
    JOIN vehicles v ON fl.vehicle_id = v.id 
    $where
    ORDER BY fl.id DESC
    LIMIT 100
")->fetchAll();

// Calculate this month vs last month stats
$monthWhere = $vehicleFilter ? "WHERE vehicle_id = $vehicleFilter" : "";
$monthStats = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END), 0) as this_count,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m') THEN 1 ELSE 0 END), 0) as last_count,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN liters ELSE 0 END), 0) as this_liters,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m') THEN liters ELSE 0 END), 0) as last_liters,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN total_cost ELSE 0 END), 0) as this_spent,
        COALESCE(SUM(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m') THEN total_cost ELSE 0 END), 0) as last_spent,
        COALESCE(AVG(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN price_per_liter END), 0) as this_avg_price,
        COALESCE(AVG(CASE WHEN DATE_FORMAT(fill_date, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m') THEN price_per_liter END), 0) as last_avg_price
    FROM fuel_log
    $monthWhere
")->fetch();

$thisMonthLabel = date('F Y');
$lastMonthLabel = date('F Y', strtotime('first day of last month'));

// Returns ['dir' => up|down|flat|new, 'pct' => float|null]
function monthTrend($current, $previous) {
    if ($previous == 0) {
        return $current > 0 ? ['dir' => 'new', 'pct' => null] : ['dir' => 'flat', 'pct' => 0];
    }
    $pct = (($current - $previous) / $previous) * 100;
    if (abs($pct) < 0.5) {
        return ['dir' => 'flat', 'pct' => $pct];
    }
    return ['dir' => $pct > 0 ? 'up' : 'down', 'pct' => $pct];
}

// Renders the trend badge; $goodDir = 'down' means a decrease is a positive outcome (e.g. spend, price)
function renderTrendBadge($trend, $goodDir = null) {
    if ($trend['dir'] === 'new') {
        return '<span class="badge badge-subtle-info rounded-pill"><i class="fas fa-star"></i> New</span>';
    }
    if ($trend['dir'] === 'flat') {
        return '<span class="badge badge-subtle-secondary rounded-pill"><i class="fas fa-minus"></i> No change</span>';
    }
    $isGood = $goodDir ? ($trend['dir'] !== $goodDir ? false : true) : null;
    if ($goodDir === null) {
        $badgeClass = 'badge-subtle-secondary';
    } else {
        $badgeClass = $isGood ? 'badge-subtle-success' : 'badge-subtle-danger';
    }
    $icon = $trend['dir'] === 'up' ? 'fa-arrow-up' : 'fa-arrow-down';
    return sprintf('<span class="badge %s rounded-pill"><i class="fas %s"></i> %s%%</span>', $badgeClass, $icon, number_format(abs($trend['pct']), 1));
}

$fillTrend = monthTrend($monthStats['this_count'], $monthStats['last_count']);
$literTrend = monthTrend($monthStats['this_liters'], $monthStats['last_liters']);
$spentTrend = monthTrend($monthStats['this_spent'], $monthStats['last_spent']);
$priceTrend = monthTrend($monthStats['this_avg_price'], $monthStats['last_avg_price']);
?>

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


    <div class="card mb-3">
        <div class="card-body">
            <div class="row justify-content-between align-items-center">
                <div class="col-md">
                    <div class="d-flex">
                        <div class="calendar me-2"><span class="calendar-month">
                                <?php
                                $currentMonth = date('M');
                                $currentDay = date('d');
                                echo $currentMonth;?>
                        </span><span class="calendar-day"><?php echo $currentDay; ?> </span></div>
                        <div class="flex-1">
                            <h4 class="fs-6">Fuel Log</h4>
                            <p class="mb-0 fs-10">Track fuel consumption and costs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#add-fuel-modal">
                        <i class="fas fa-plus"></i> Add Fuel Record
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <select name="vehicle_id" class="form-control" style="width: auto; min-width: 200px;" onchange="this.form.submit()">
                    <option value="">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?php echo $v['id']; ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($vehicleFilter): ?><a href="fuel-log" class="btn btn-outline"><i class="fas fa-times"></i></a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift" data-bs-toggle="tooltip" title="<?php echo $lastMonthLabel; ?>: <?php echo (int)$monthStats['last_count']; ?> fill-up(s)">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-warning bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-gas-pump text-warning"></i>
                        </div>
                        <?php echo renderTrendBadge($fillTrend); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Fill-ups &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1"><?php echo (int)$monthStats['this_count']; ?></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: <?php echo (int)$monthStats['last_count']; ?></p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift" data-bs-toggle="tooltip" title="<?php echo $lastMonthLabel; ?>: <?php echo number_format($monthStats['last_liters'], 1); ?>L">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-info bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-tint text-info"></i>
                        </div>
                        <?php echo renderTrendBadge($literTrend); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Total Fuel &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1"><?php echo number_format($monthStats['this_liters'], 1); ?>L</h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: <?php echo number_format($monthStats['last_liters'], 1); ?>L</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift" data-bs-toggle="tooltip" title="<?php echo $lastMonthLabel; ?>: Ksh. <?php echo number_format($monthStats['last_spent'], 2); ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-success bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-money-bill-wave text-success"></i>
                        </div>
                        <?php echo renderTrendBadge($spentTrend, 'down'); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Total Spent &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1">Ksh. <?php echo number_format($monthStats['this_spent'], 2); ?></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: Ksh. <?php echo number_format($monthStats['last_spent'], 2); ?></p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift" data-bs-toggle="tooltip" title="<?php echo $lastMonthLabel; ?>: Ksh. <?php echo number_format($monthStats['last_avg_price'], 2); ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-primary bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-tag text-primary"></i>
                        </div>
                        <?php echo renderTrendBadge($priceTrend, 'down'); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Avg Price/L &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1">Ksh. <?php echo number_format($monthStats['this_avg_price'], 2); ?></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: Ksh. <?php echo number_format($monthStats['last_avg_price'], 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-0">
        <div class="card">
            <div class="card-body">
                <?php if (empty($logs)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-gas-pump empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No Fuel Records!</h6>
                        <p class="fs-10 mb-3">Record your first fueling to get started.</p>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#add-fuel-modal">
                            <i class="fas fa-plus"></i> Add First Record
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-responsive-sm mb-0 data-table fs-10" data-datatables='{"order": []}'>
                            <thead class="bg-200">
                            <tr>
                                <th class="text-900 sort">Date</th>
                                <th class="text-900 sort">Vehicle</th>
                                <th class="text-900 sort">Mileage</th>
                                <th class="text-900 sort">Liters</th>
                                <th class="text-900 sort">Price/L</th>
                                <th class="text-900 sort">Total</th>
                                <th class="text-900 sort">Station</th>
                                <th class="text-900">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover-actions-trigger btn-reveal-trigger hover-bg-100 cursor-pointer" onclick="editFuel(<?php echo htmlspecialchars(json_encode($l)); ?>)">
                                    <td><?php echo formatDate($l['fill_date']); ?></td>
                                    <td><?php echo sanitize($l['make'] . ' ' . $l['model']); ?></td>
                                    <td><?php echo formatNumber($l['mileage']); ?> km</td>
                                    <td><?php echo number_format($l['liters'], 2); ?> L</td>
                                    <td>Ksh. <?php echo number_format($l['price_per_liter'], 2); ?></td>
                                    <td><strong>Ksh. <?php echo number_format($l['total_cost'], 2); ?></strong></td>
                                    <td><?php echo $l['station_name'] ? sanitize($l['station_name']) : '-'; ?></td>
                                    <td class="align-middle white-space-nowrap text-end position-relative">
                                        <div class="hover-actions bg-100">
                                            <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" onclick="event.stopPropagation(); editFuel(<?php echo htmlspecialchars(json_encode($l)); ?>)">
                                                <span class="fas fa-edit"></span>
                                            </button>
                                            <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" onclick="event.stopPropagation(); deleteFuel(<?php echo $l['id']; ?>, '<?php echo sanitize($l['make'] . ' ' . $l['model']); ?>', '<?php echo formatDate($l['fill_date']); ?>')">
                                                <span class="fas fa-trash"></span>
                                            </button>
                                        </div>
                                        <div class="dropdown font-sans-serif btn-reveal-trigger">
                                            <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal-sm transition-none" type="button" id="crm-recent-leads-0" onclick="event.stopPropagation()" data-bs-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs-11"></span></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Fuel Modal -->
    <div class="modal fade" id="add-fuel-modal" tabindex="-1" aria-labelledby="addFuelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addFuelModalLabel">Add Fuel Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                            <select name="vehicle_id" id="add_vehicle_id" class="form-select" required>
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="fill_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mileage (km) <span class="text-danger">*</span></label>
                                <input type="number" name="mileage" id="add_mileage" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Price per Liter <span class="text-danger">*</span></label>
                                <input type="number" name="price_per_liter" id="add_price_per_liter" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Liters <span class="text-danger">*</span></label>
                                <input type="number" name="liters" id="add_liters" step="0.01" class="form-control" required>
                                <div class="form-text">Enter this or the total amount</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Total Amount (Ksh)</label>
                                <input type="number" id="add_total_amount" step="0.01" class="form-control">
                                <div class="form-text">Auto-fills liters</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Station</label>
                            <input type="text" name="station_name" class="form-control" placeholder="Station name">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Fuel Modal -->
    <div class="modal fade" id="edit-fuel-modal" tabindex="-1" aria-labelledby="editFuelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFuelModalLabel">Edit Fuel Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="fuel_id" id="edit_fuel_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                            <select name="vehicle_id" id="edit_vehicle_id" class="form-select" required>
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="fill_date" id="edit_fill_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mileage (km) <span class="text-danger">*</span></label>
                                <input type="number" name="mileage" id="edit_mileage" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Price per Liter <span class="text-danger">*</span></label>
                                <input type="number" name="price_per_liter" id="edit_price_per_liter" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Liters <span class="text-danger">*</span></label>
                                <input type="number" name="liters" id="edit_liters" step="0.01" class="form-control" required>
                                <div class="form-text">Enter this or the total amount</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Total Amount (Ksh)</label>
                                <input type="number" id="edit_total_amount" step="0.01" class="form-control">
                                <div class="form-text">Auto-fills liters</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Station</label>
                            <input type="text" name="station_name" id="edit_station_name" class="form-control" placeholder="Station name">
                        </div>
                        <input type="hidden" name="full_tank" id="edit_full_tank">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Fuel Modal -->
    <div class="modal fade" id="delete-fuel-modal" tabindex="-1" aria-labelledby="deleteFuelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteFuelModalLabel">Delete Fuel Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="fuel_id" id="delete_fuel_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this fuel record?</p>
                        <p class="text-muted" id="delete_fuel_details"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialize Bootstrap modal
        document.addEventListener('DOMContentLoaded', function() {
            const addFuelModal = document.getElementById('add-fuel-modal');
            const vehicleSelect = addFuelModal.querySelector('select[name="vehicle_id"]');
            const mileageInput = addFuelModal.querySelector('input[name="mileage"]');

            // Vehicle data with current mileage
            const vehicleData = {
            <?php foreach ($vehicles as $v): ?>
            <?php echo $v['id']; ?>: {
                make: '<?php echo addslashes($v['make']); ?>',
                    model: '<?php echo addslashes($v['model']); ?>',
                    currentMileage: <?php
                $vStmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ?");
                $vStmt->execute([$v['id']]);
                $vData = $vStmt->fetch();
                echo $vData['current_mileage'] ?? 0;
                ?>
            },
            <?php endforeach; ?>
        };

            // Update mileage when vehicle changes in ADD modal
            vehicleSelect.addEventListener('change', function() {
                const vehicleId = this.value;

                if (vehicleId && vehicleData[vehicleId]) {
                    const currentMileage = vehicleData[vehicleId].currentMileage;
                    mileageInput.value = currentMileage;
                    mileageInput.min = currentMileage;
                    mileageInput.placeholder = 'Must be ≥ ' + currentMileage.toLocaleString() + ' km';
                } else {
                    mileageInput.value = '';
                    mileageInput.min = 0;
                    mileageInput.placeholder = 'Enter mileage';
                }
            });

            // Validate mileage input
            mileageInput.addEventListener('input', function() {
                const vehicleId = vehicleSelect.value;
                if (vehicleId && vehicleData[vehicleId]) {
                    const currentMileage = vehicleData[vehicleId].currentMileage;
                    const enteredMileage = parseInt(this.value) || 0;

                    if (enteredMileage < currentMileage) {
                        this.setCustomValidity('Mileage cannot be less than current mileage (' + currentMileage.toLocaleString() + ' km)');
                    } else {
                        this.setCustomValidity('');
                    }
                }
            });

            if (addFuelModal) {
                addFuelModal.addEventListener('hidden.bs.modal', function () {
                    // Reset form when modal closes
                    this.querySelector('form').reset();
                    mileageInput.min = 0;
                    mileageInput.placeholder = 'Enter mileage';
                    mileageInput.setCustomValidity('');
                });

                // Initialize mileage when modal opens if vehicle is pre-selected
                addFuelModal.addEventListener('shown.bs.modal', function () {
                    if (vehicleSelect.value) {
                        vehicleSelect.dispatchEvent(new Event('change'));
                    }
                });
            }

            // Wire up price/liters/total-amount auto-calc for both modals
            wireFuelCalc(
                document.getElementById('add_price_per_liter'),
                document.getElementById('add_liters'),
                document.getElementById('add_total_amount')
            );
            wireFuelCalc(
                document.getElementById('edit_price_per_liter'),
                document.getElementById('edit_liters'),
                document.getElementById('edit_total_amount')
            );
        });

        // Keeps Liters and Total Amount in sync using Price per Liter, so the
        // user only needs to type in whichever one they know
        function wireFuelCalc(priceEl, litersEl, amountEl) {
            function fromLiters() {
                const price = parseFloat(priceEl.value);
                const liters = parseFloat(litersEl.value);
                if (price > 0 && liters > 0) {
                    amountEl.value = (price * liters).toFixed(2);
                }
            }
            function fromAmount() {
                const price = parseFloat(priceEl.value);
                const amount = parseFloat(amountEl.value);
                if (price > 0 && amount > 0) {
                    litersEl.value = (amount / price).toFixed(2);
                }
            }
            litersEl.addEventListener('input', fromLiters);
            amountEl.addEventListener('input', fromAmount);
            priceEl.addEventListener('input', function() {
                if (litersEl.value) {
                    fromLiters();
                } else if (amountEl.value) {
                    fromAmount();
                }
            });
        }

        // Edit Fuel Function
        function editFuel(fuel) {
            document.getElementById('edit_fuel_id').value = fuel.id;
            document.getElementById('edit_vehicle_id').value = fuel.vehicle_id;
            document.getElementById('edit_fill_date').value = fuel.fill_date;
            document.getElementById('edit_liters').value = fuel.liters;
            document.getElementById('edit_price_per_liter').value = fuel.price_per_liter;
            document.getElementById('edit_total_amount').value = fuel.total_cost;
            document.getElementById('edit_mileage').value = fuel.mileage;
            document.getElementById('edit_station_name').value = fuel.station_name || '';
            document.getElementById('edit_full_tank').value = fuel.full_tank;

            const editModal = new bootstrap.Modal(document.getElementById('edit-fuel-modal'));
            editModal.show();
        }

        // Delete Fuel Function
        function deleteFuel(id, vehicle, date) {
            document.getElementById('delete_fuel_id').value = id;
            document.getElementById('delete_fuel_details').textContent = 'Vehicle: ' + vehicle + ' | Date: ' + date;

            const deleteModal = new bootstrap.Modal(document.getElementById('delete-fuel-modal'));
            deleteModal.show();
        }
    </script>

<?php require_once 'includes/footer.php'; ?>