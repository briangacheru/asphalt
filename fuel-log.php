<?php
$pageTitle = 'Fuel Log';
require_once 'includes/header.php';

use App\Helpers\IdCodec;

$vehicles = $pdo->prepare("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 AND user_id = ? ORDER BY make, model");
$vehicles->execute([$userId]);
$vehicles = $vehicles->fetchAll();
$vehicleFilter = IdCodec::decode($_GET['vehicle_id'] ?? null);

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
            // Fuel type comes from the vehicle's own profile, not re-entered here.
            // This SELECT also verifies the vehicle belongs to the current user.
            $vStmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ? AND user_id = ?");
            $vStmt->execute([$vehicle_id, $userId]);
            $vRow = $vStmt->fetch();

            if (!$vRow) {
                setFlashMessage('danger', 'Vehicle not found.');
                redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
            }
            $fuel_type = $vRow['fuel_type'] ?: '';

            $stmt = $pdo->prepare("INSERT INTO fuel_log (vehicle_id, fill_date, mileage, liters, price_per_liter, total_cost, fuel_type, station_name, full_tank) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicle_id, $fill_date, $mileage, $liters, $price_per_liter, $total_cost, $fuel_type, $station_name, $full_tank]);

            // Update vehicle mileage if higher
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")->execute([$mileage, $vehicle_id, $userId]);

            setFlashMessage('success', 'Fuel record added!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
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
            // Verify the record being edited belongs to one of the user's own vehicles
            $ownStmt = $pdo->prepare("
                SELECT fl.id FROM fuel_log fl
                JOIN vehicles v ON fl.vehicle_id = v.id
                WHERE fl.id = ? AND v.user_id = ?
            ");
            $ownStmt->execute([$fuel_id, $userId]);
            if (!$ownStmt->fetch()) {
                setFlashMessage('danger', 'Fuel record not found.');
                redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
            }

            // Fuel type comes from the vehicle's own profile, not re-entered here.
            // This SELECT also verifies the (possibly reassigned) vehicle belongs to the current user.
            $vStmt = $pdo->prepare("SELECT fuel_type FROM vehicles WHERE id = ? AND user_id = ?");
            $vStmt->execute([$vehicle_id, $userId]);
            $vRow = $vStmt->fetch();

            if (!$vRow) {
                setFlashMessage('danger', 'Vehicle not found.');
                redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
            }
            $fuel_type = $vRow['fuel_type'] ?: '';

            $stmt = $pdo->prepare("UPDATE fuel_log SET vehicle_id = ?, fill_date = ?, mileage = ?, liters = ?, price_per_liter = ?, total_cost = ?, fuel_type = ?, station_name = ?, full_tank = ? WHERE id = ?");
            $stmt->execute([$vehicle_id, $fill_date, $mileage, $liters, $price_per_liter, $total_cost, $fuel_type, $station_name, $full_tank, $fuel_id]);

            // Update vehicle mileage if higher
            $pdo->prepare("UPDATE vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ? AND user_id = ?")->execute([$mileage, $vehicle_id, $userId]);

            setFlashMessage('success', 'Fuel record updated!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
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
            $stmt = $pdo->prepare("
                DELETE fl FROM fuel_log fl
                JOIN vehicles v ON fl.vehicle_id = v.id
                WHERE fl.id = ? AND v.user_id = ?
            ");
            $stmt->execute([$fuel_id, $userId]);
            setFlashMessage('success', 'Fuel record deleted!');
            redirect('fuel-log' . ($vehicleFilter ? '?vehicle_id=' . IdCodec::encode($vehicleFilter) : ''));
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

// Last-used price per liter (per vehicle, plus the most recent overall) to pre-fill the
// Add Fuel modal, and the distinct station names this user has used, most-used first,
// to suggest as they type. Both scoped to the current user's own vehicles.
$lastPriceByVehicle = [];
$lastPriceOverall = null;
$stmt = $pdo->prepare("
    SELECT fl.vehicle_id, fl.price_per_liter
    FROM fuel_log fl
    JOIN vehicles v ON v.id = fl.vehicle_id
    WHERE v.user_id = ? AND fl.price_per_liter > 0
    ORDER BY fl.id DESC
");
$stmt->execute([$userId]);
foreach ($stmt->fetchAll() as $row) {
    $lastPriceOverall ??= (float) $row['price_per_liter'];
    $lastPriceByVehicle[(int) $row['vehicle_id']] ??= (float) $row['price_per_liter'];
}

$stmt = $pdo->prepare("
    SELECT fl.station_name, COUNT(*) AS uses
    FROM fuel_log fl
    JOIN vehicles v ON v.id = fl.vehicle_id
    WHERE v.user_id = ? AND fl.station_name IS NOT NULL AND TRIM(fl.station_name) <> ''
    GROUP BY fl.station_name
    ORDER BY uses DESC, MAX(fl.id) DESC
    LIMIT 50
");
$stmt->execute([$userId]);
// station_name is stored HTML-escaped (sanitize()), so decode for display in the list;
// it is re-escaped on save like any typed value.
$stationSuggestions = array_map(fn($r) => html_entity_decode($r['station_name'], ENT_QUOTES, 'UTF-8'), $stmt->fetchAll());

// Get fuel logs — always scoped to the current user's own vehicles
$where = "WHERE v.user_id = " . (int)$userId;
if ($vehicleFilter) {
    $where .= " AND fl.vehicle_id = " . (int)$vehicleFilter;
}
$logs = $pdo->query("
    SELECT fl.*, v.make, v.model, v.year
    FROM fuel_log fl
    JOIN vehicles v ON fl.vehicle_id = v.id
    $where
    ORDER BY fl.id DESC
    LIMIT 100
")->fetchAll();

// Calculate this month vs last month stats — also scoped to the current user
$monthWhere = "WHERE v.user_id = " . (int)$userId;
if ($vehicleFilter) {
    $monthWhere .= " AND fl.vehicle_id = " . (int)$vehicleFilter;
}
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
    FROM fuel_log fl
    JOIN vehicles v ON fl.vehicle_id = v.id
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

// Fuel economy (km/L): km driven this/last month (from consecutive fuel_log
// odometer readings, summed per vehicle) divided by liters used in that month.
$firstDayThisMonth = date('Y-m-01');
$firstDayNextMonth = date('Y-m-01', strtotime($firstDayThisMonth . ' +1 month'));
$firstDayLastMonth = date('Y-m-01', strtotime($firstDayThisMonth . ' -1 month'));

function fuelMileageAsOf(PDO $pdo, int $vehicleId, string $beforeDate): ?int
{
    $stmt = $pdo->prepare("SELECT mileage FROM fuel_log WHERE vehicle_id = ? AND fill_date < ? ORDER BY fill_date DESC, id DESC LIMIT 1");
    $stmt->execute([$vehicleId, $beforeDate]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int) $val : null;
}

$vehicleIdsInView = $vehicleFilter ? [$vehicleFilter] : array_column($vehicles, 'id');
$kmThisMonthTotal = 0;
$kmLastMonthTotal = 0;

foreach ($vehicleIdsInView as $vid) {
    $mileageNow = fuelMileageAsOf($pdo, $vid, $firstDayNextMonth);
    $mileageStartOfThisMonth = fuelMileageAsOf($pdo, $vid, $firstDayThisMonth);
    $mileageStartOfLastMonth = fuelMileageAsOf($pdo, $vid, $firstDayLastMonth);

    if ($mileageNow !== null && $mileageStartOfThisMonth !== null) {
        $kmThisMonthTotal += max(0, $mileageNow - $mileageStartOfThisMonth);
    }
    if ($mileageStartOfThisMonth !== null && $mileageStartOfLastMonth !== null) {
        $kmLastMonthTotal += max(0, $mileageStartOfThisMonth - $mileageStartOfLastMonth);
    }
}

$thisEconomy = $monthStats['this_liters'] > 0 ? $kmThisMonthTotal / $monthStats['this_liters'] : 0;
$lastEconomy = $monthStats['last_liters'] > 0 ? $kmLastMonthTotal / $monthStats['last_liters'] : 0;
$economyTrend = monthTrend($thisEconomy, $lastEconomy);

// 6-month trend for the fuel economy / price-per-litre chart.
$trendMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $trendMonths[] = date('Y-m-01', strtotime("-$i months", strtotime($firstDayThisMonth)));
}
$trendLabels = array_map(fn($m) => date('M', strtotime($m)), $trendMonths);
$trendEconomy = [];
$trendPrice = [];
foreach ($trendMonths as $monthStart) {
    $monthEnd = date('Y-m-01', strtotime($monthStart . ' +1 month'));

    $kmTotal = 0;
    foreach ($vehicleIdsInView as $vid) {
        $mEnd = fuelMileageAsOf($pdo, $vid, $monthEnd);
        $mStart = fuelMileageAsOf($pdo, $vid, $monthStart);
        if ($mEnd !== null && $mStart !== null) {
            $kmTotal += max(0, $mEnd - $mStart);
        }
    }

    $litersStmt = $pdo->prepare("
        SELECT COALESCE(SUM(liters), 0) AS liters, COALESCE(AVG(price_per_liter), 0) AS avg_price
        FROM fuel_log
        WHERE vehicle_id IN (" . implode(',', array_fill(0, max(1, count($vehicleIdsInView)), '?')) . ")
        AND fill_date >= ? AND fill_date < ?
    ");
    $litersStmt->execute(array_merge($vehicleIdsInView ?: [0], [$monthStart, $monthEnd]));
    $monthRow = $litersStmt->fetch();

    $trendEconomy[] = $monthRow['liters'] > 0 ? round($kmTotal / $monthRow['liters'], 1) : 0;
    $trendPrice[] = round((float) $monthRow['avg_price'], 2);
}
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
                        <option value="<?php echo IdCodec::encode($v['id']); ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($vehicleFilter): ?><a href="fuel-log" class="btn btn-outline"><i class="fas fa-times"></i></a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5">
        <div class="col">
            <div class="card h-100 border-0 shadow-sm hover-lift">
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
        <div class="col">
            <div class="card h-100 border-0 shadow-sm hover-lift">
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
        <div class="col">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-success bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-money-bill-wave text-success"></i>
                        </div>
                        <?php echo renderTrendBadge($spentTrend, 'down'); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Total Spent &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1"><?php echo money($monthStats['this_spent']); ?></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: <?php echo money($monthStats['last_spent']); ?></p>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-primary bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-tag text-primary"></i>
                        </div>
                        <?php echo renderTrendBadge($priceTrend, 'down'); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Avg Price/L &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1"><?php echo money($monthStats['this_avg_price']); ?></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: <?php echo money($monthStats['last_avg_price']); ?></p>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="icon-box bg-secondary bg-opacity-10 rounded-3 p-2">
                            <i class="fas fa-leaf text-secondary"></i>
                        </div>
                        <?php echo renderTrendBadge($economyTrend, 'up'); ?>
                    </div>
                    <h6 class="text-muted mb-1 fw-normal fs-10">Fuel Economy &bull; <?php echo $thisMonthLabel; ?></h6>
                    <h4 class="fs-6 fw-bold mb-1"><?php echo number_format($thisEconomy, 1); ?> <small class="fs-11 text-muted">km/L</small></h4>
                    <p class="fs-11 text-muted mb-0">vs <?php echo $lastMonthLabel; ?>: <?php echo number_format($lastEconomy, 1); ?> km/L</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-body-tertiary">
            <h6 class="mb-0"><i class="fas fa-chart-line"></i> 6-Month Trend</h6>
        </div>
        <div class="card-body">
            <div id="fuelTrendChart"></div>
        </div>
    </div>

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
                                    <td><?php echo money($l['price_per_liter']); ?></td>
                                    <td><strong><?php echo money($l['total_cost']); ?></strong></td>
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

    <!-- Add Fuel Modal -->
    <datalist id="stationSuggestions">
        <?php foreach ($stationSuggestions as $stationName): ?>
            <option value="<?php echo htmlspecialchars($stationName, ENT_QUOTES); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <?php
    // Add Fuel modal defaults: preselect the filtered vehicle, or the only vehicle.
    $addModalDefaultVehicle = null;
    foreach ($vehicles as $v) {
        if ($vehicleFilter && (int) $v['id'] === (int) $vehicleFilter) {
            $addModalDefaultVehicle = (int) $v['id'];
        }
    }
    if ($addModalDefaultVehicle === null && count($vehicles) === 1) {
        $addModalDefaultVehicle = (int) $vehicles[0]['id'];
    }
    $topStations = array_slice($stationSuggestions, 0, 5);
    $addCurrency = currencySymbol();
    ?>
    <div class="modal fade" id="add-fuel-modal" tabindex="-1" aria-labelledby="addFuelModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0 align-items-start">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px;height:46px;">
                            <span class="fas fa-gas-pump"></span>
                        </div>
                        <div>
                            <h5 class="modal-title mb-0" id="addFuelModalLabel">Add Fuel Record</h5>
                            <p class="fs-10 text-muted mb-0">Log a fill-up in a few taps</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addFuelForm">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body pt-3">
                        <div class="mb-3">
                            <label class="form-label fw-semi-bold" for="add_vehicle_id">Vehicle <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><span class="fas fa-car"></span></span>
                                <select name="vehicle_id" id="add_vehicle_id" class="form-select" required>
                                    <option value="">Select vehicle...</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?php echo $v['id']; ?>"<?php echo $addModalDefaultVehicle === (int) $v['id'] ? ' selected' : ''; ?>><?php echo sanitize($v['make'] . ' ' . $v['model']); ?> (<?php echo (int) $v['year']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-semi-bold mb-0" for="add_fill_date">Date</label>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Quick dates">
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" data-date-offset="0">Today</button>
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" data-date-offset="-1">Yesterday</button>
                                    </div>
                                </div>
                                <input type="date" name="fill_date" id="add_fill_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semi-bold mb-1" for="add_mileage">Odometer <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" name="mileage" id="add_mileage" class="form-control" inputmode="numeric" placeholder="Enter mileage" required>
                                    <span class="input-group-text">km</span>
                                </div>
                                <div class="form-text" id="add_mileage_hint"></div>
                            </div>
                        </div>

                        <div class="rounded-3 border bg-body-tertiary p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-baseline mb-2">
                                <span class="fs-10 text-uppercase fw-bold text-muted">Fill-up details</span>
                                <span class="fs-11 text-muted">Enter liters <em>or</em> the total &mdash; the other is calculated</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label mb-1" for="add_price_per_liter">Price per liter <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $addCurrency; ?></span>
                                        <input type="number" name="price_per_liter" id="add_price_per_liter" step="0.01" min="0" inputmode="decimal" class="form-control" required<?php echo $lastPriceOverall !== null ? ' value="' . htmlspecialchars((string) $lastPriceOverall) . '"' : ''; ?>>
                                        <span class="input-group-text">/ L</span>
                                    </div>
                                    <div class="form-text" id="add_price_hint"><?php echo $lastPriceOverall !== null ? 'Pre-filled with your last price — edit if it changed' : ''; ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label mb-1" for="add_liters">Liters <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="liters" id="add_liters" step="0.01" min="0" inputmode="decimal" class="form-control" required>
                                        <span class="input-group-text">L</span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label mb-1" for="add_total_amount">Total amount</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text"><?php echo $addCurrency; ?></span>
                                        <input type="number" id="add_total_amount" step="0.01" min="0" inputmode="decimal" class="form-control" placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="form-label fw-semi-bold mb-1" for="add_station_name">Station <span class="text-muted fw-normal">(optional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><span class="fas fa-map-marker-alt"></span></span>
                                <input type="text" name="station_name" id="add_station_name" class="form-control" placeholder="e.g. Shell Westlands" list="stationSuggestions" autocomplete="off" maxlength="100">
                            </div>
                            <?php if (!empty($topStations)): ?>
                                <div class="d-flex flex-wrap gap-2 mt-2" id="add_station_chips">
                                    <?php foreach ($topStations as $chipName): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill py-0 px-3" data-station="<?php echo htmlspecialchars($chipName, ENT_QUOTES); ?>"><?php echo htmlspecialchars($chipName); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 justify-content-between flex-nowrap">
                        <div class="me-2">
                            <div class="fs-11 text-uppercase fw-bold text-muted">Total</div>
                            <div class="fw-bold lh-1" style="font-size:1.35rem;" id="add_summary_total">&mdash;</div>
                            <div class="fs-11 text-muted" id="add_summary_detail">&nbsp;</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary px-4" id="add_fuel_submit"><span class="fas fa-save me-1"></span>Save</button>
                        </div>
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
                                <label class="form-label">Total Amount (<?php echo currencySymbol(); ?>)</label>
                                <input type="number" id="edit_total_amount" step="0.01" class="form-control">
                                <div class="form-text">Auto-fills liters</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Station</label>
                            <input type="text" name="station_name" id="edit_station_name" class="form-control" placeholder="Station name" list="stationSuggestions" autocomplete="off">
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
        // Deep-linked quick-add (e.g. from the site-wide quick-add button): open
        // the Add Fuel modal automatically and drop the param so a refresh doesn't reopen it.
        document.addEventListener('DOMContentLoaded', function () {
            if (new URLSearchParams(window.location.search).get('quickadd') === '1') {
                var modalEl = document.getElementById('add-fuel-modal');
                if (modalEl && window.bootstrap) {
                    new bootstrap.Modal(modalEl).show();
                }
                var url = new URL(window.location.href);
                url.searchParams.delete('quickadd');
                window.history.replaceState({}, '', url);
            }
        });

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
                $vStmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ? AND user_id = ?");
                $vStmt->execute([$v['id'], $userId]);
                $vData = $vStmt->fetch();
                echo $vData['current_mileage'] ?? 0;
                ?>
            },
            <?php endforeach; ?>
        };

            // Last-used price per liter, per vehicle (falls back to the most recent overall,
            // which is already in the field on load). Stops auto-filling once the user has
            // typed their own price so a changed pump price is never overwritten.
            const lastPriceByVehicle = <?php echo json_encode((object) $lastPriceByVehicle); ?>;
            const lastPriceOverall = <?php echo json_encode($lastPriceOverall); ?>;
            const priceInput = document.getElementById('add_price_per_liter');
            const priceHint = document.getElementById('add_price_hint');
            let priceEditedByUser = false;
            priceInput.addEventListener('input', function (e) {
                if (e.isTrusted) {
                    priceEditedByUser = true;
                    priceHint.textContent = '';
                }
            });
            function applyLastPrice(vehicleId) {
                if (priceEditedByUser) return;
                const price = lastPriceByVehicle[vehicleId] ?? lastPriceOverall;
                if (price == null) return;
                priceInput.value = price;
                priceHint.textContent = lastPriceByVehicle[vehicleId] != null
                    ? 'Pre-filled with this vehicle\'s last price — edit if it changed'
                    : 'Pre-filled with your last price — edit if it changed';
                priceInput.dispatchEvent(new Event('input')); // let the liters/total calc refresh
            }

            // Update mileage when vehicle changes in ADD modal
            vehicleSelect.addEventListener('change', function() {
                const vehicleId = this.value;
                if (vehicleId) applyLastPrice(vehicleId);

                if (vehicleId && vehicleData[vehicleId]) {
                    const currentMileage = vehicleData[vehicleId].currentMileage;
                    mileageInput.value = currentMileage;
                    mileageInput.min = currentMileage;
                    mileageInput.placeholder = 'Must be ≥ ' + currentMileage.toLocaleString() + ' km';
                    document.getElementById('add_mileage_hint').textContent = 'Current odometer: ' + currentMileage.toLocaleString() + ' km';
                } else {
                    mileageInput.value = '';
                    mileageInput.min = 0;
                    mileageInput.placeholder = 'Enter mileage';
                    document.getElementById('add_mileage_hint').textContent = '';
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
                    priceEditedByUser = false;
                    document.getElementById('add_mileage_hint').textContent = '';
                    refreshAddSummary();
                    syncStationChips();
                    resetSubmitButton();
                    priceHint.textContent = lastPriceOverall != null ? 'Pre-filled with your last price — edit if it changed' : '';
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

            // ---- Add Fuel modal polish ----
            const addForm = document.getElementById('addFuelForm');
            const litersInput = document.getElementById('add_liters');
            const totalInput = document.getElementById('add_total_amount');
            const stationInput = document.getElementById('add_station_name');
            const submitBtn = document.getElementById('add_fuel_submit');
            const currencySymbolJs = <?php echo json_encode($addCurrency); ?>;

            // Live "Total" readout in the footer
            function refreshAddSummary() {
                const price = parseFloat(priceInput.value);
                const liters = parseFloat(litersInput.value);
                const total = parseFloat(totalInput.value);
                const out = document.getElementById('add_summary_total');
                const detail = document.getElementById('add_summary_detail');
                if (total > 0) {
                    out.textContent = currencySymbolJs + ' ' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const bits = [];
                    if (liters > 0) bits.push(liters.toFixed(2) + ' L');
                    if (price > 0) bits.push('@ ' + price.toFixed(2));
                    detail.textContent = bits.join(' ') || ' ';
                } else {
                    out.textContent = '—';
                    detail.textContent = ' ';
                }
            }
            // The form-level listener runs after the calculator's own field listeners,
            // so it always sees the freshly computed values.
            addForm.addEventListener('input', refreshAddSummary);
            refreshAddSummary();

            // Today / Yesterday quick dates (local date, not UTC)
            addForm.querySelectorAll('[data-date-offset]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const d = new Date();
                    d.setDate(d.getDate() + parseInt(this.dataset.dateOffset, 10));
                    const pad = function (n) { return String(n).padStart(2, '0'); };
                    document.getElementById('add_fill_date').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
                });
            });

            // Station quick-pick chips
            function syncStationChips() {
                document.querySelectorAll('#add_station_chips [data-station]').forEach(function (chip) {
                    const active = chip.dataset.station === stationInput.value;
                    chip.classList.toggle('btn-primary', active);
                    chip.classList.toggle('btn-outline-secondary', !active);
                });
            }
            document.querySelectorAll('#add_station_chips [data-station]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    stationInput.value = this.dataset.station;
                    syncStationChips();
                });
            });
            stationInput.addEventListener('input', syncStationChips);

            // Validation styling + double-submit guard
            function resetSubmitButton() {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="fas fa-save me-1"></span>Save';
                addForm.classList.remove('was-validated');
            }
            addForm.addEventListener('submit', function (e) {
                if (!addForm.checkValidity()) {
                    addForm.classList.add('was-validated');
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving…';
            });
            window.addEventListener('pageshow', resetSubmitButton); // back-button safety

            // Land the cursor where the user needs to type next
            addFuelModal.addEventListener('shown.bs.modal', function () {
                if (vehicleSelect.value) {
                    mileageInput.focus();
                    mileageInput.select();
                } else {
                    vehicleSelect.focus();
                }
            });
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

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.querySelector('#fuelTrendChart');
            if (!el || typeof ApexCharts === 'undefined') return;

            var chart = new ApexCharts(el, {
                chart: { type: 'line', height: 300, toolbar: { show: false }, zoom: { enabled: false } },
                series: [
                    { name: 'Fuel Economy (km/L)', data: <?php echo json_encode($trendEconomy); ?> },
                    { name: 'Avg Price/L (<?php echo currencySymbol(); ?>)', data: <?php echo json_encode($trendPrice); ?> }
                ],
                xaxis: { categories: <?php echo json_encode($trendLabels); ?> },
                colors: ['#198754', '#0dcaf0'],
                stroke: { curve: 'smooth', width: 3 },
                markers: { size: 4 },
                legend: { position: 'top', horizontalAlign: 'left' },
                grid: { borderColor: '#e7e7e7', strokeDashArray: 4 },
                tooltip: { shared: true }
            });
            chart.render();
        });
    </script>

<?php require_once 'includes/footer.php'; ?>