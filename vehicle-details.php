<?php
require_once 'includes/bootstrap.php';
\App\Middleware\AuthMiddleware::check();
use App\Helpers\IdCodec;
$pdo = \App\Database\Database::getInstance()->getConnection();
$userId = \App\Middleware\AuthMiddleware::getCurrentUserId();

$vehicleId = IdCodec::decode($_GET['id'] ?? null) ?? IdCodec::decode($_POST['vehicle_id'] ?? null) ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['pin', 'unpin'], true) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        if ($action === 'pin') {
            $pdo->prepare("UPDATE vehicles SET is_pinned = 1, pinned_at = NOW() WHERE id = ? AND user_id = ?")->execute([$vehicleId, $userId]);
            setFlashMessage('success', 'Vehicle pinned to your dashboard.');
        } else {
            $pdo->prepare("UPDATE vehicles SET is_pinned = 0, pinned_at = NULL WHERE id = ? AND user_id = ?")->execute([$vehicleId, $userId]);
            setFlashMessage('success', 'Vehicle unpinned from your dashboard.');
        }
    }

    redirect('vehicle-details?id=' . IdCodec::encode($vehicleId));
}

$pageTitle = 'Vehicle Details';
require_once 'includes/header.php';

if (!$vehicleId) {
    setFlashMessage('danger', 'Invalid vehicle.');
    redirect('vehicles');
}

// Get vehicle
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$vehicleId, $userId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setFlashMessage('danger', 'Vehicle not found.');
    redirect('vehicles');
}

// Get service history
$stmt = $pdo->prepare("
    SELECT sr.*, 
           (SELECT COUNT(*) FROM service_items WHERE service_record_id = sr.id) as item_count
    FROM service_records sr 
    WHERE sr.vehicle_id = ? 
    ORDER BY sr.service_date DESC
");
$stmt->execute([$vehicleId]);
$services = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_services' => count($services),
];

// --- Month-over-month comparisons: KM driven and total spend ---
$firstDayThisMonth = date('Y-m-01');
$firstDayNextMonth = date('Y-m-01', strtotime($firstDayThisMonth . ' +1 month'));
$firstDayLastMonth = date('Y-m-01', strtotime($firstDayThisMonth . ' -1 month'));
$thisMonthAbbr = date('M', strtotime($firstDayThisMonth));
$lastMonthAbbr = date('M', strtotime($firstDayLastMonth));

/**
 * Km driven within [$start, $end): the latest recorded mileage in that
 * window minus the earliest, pooling every source that records an odometer
 * reading — manual mileage updates, service records, and fuel log fill-ups.
 * Null when the vehicle has no mileage record at all in that window.
 */
function kmDrivenInRange(PDO $pdo, int $vehicleId, string $start, string $end): ?int
{
    $stmt = $pdo->prepare("
        SELECT mileage FROM (
            SELECT log_date AS record_date, mileage FROM mileage_log WHERE vehicle_id = ? AND log_date >= ? AND log_date < ?
            UNION ALL
            SELECT service_date AS record_date, mileage FROM service_records WHERE vehicle_id = ? AND service_date >= ? AND service_date < ? AND mileage IS NOT NULL
            UNION ALL
            SELECT fill_date AS record_date, mileage FROM fuel_log WHERE vehicle_id = ? AND fill_date >= ? AND fill_date < ?
        ) combined
        ORDER BY record_date ASC, mileage ASC
    ");
    $stmt->execute([$vehicleId, $start, $end, $vehicleId, $start, $end, $vehicleId, $start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        return null;
    }

    return max(0, (int) end($rows) - (int) $rows[0]);
}

$kmThisMonth = kmDrivenInRange($pdo, $vehicleId, $firstDayThisMonth, $firstDayNextMonth);
$kmLastMonth = kmDrivenInRange($pdo, $vehicleId, $firstDayLastMonth, $firstDayThisMonth);

/**
 * Most recent date a mileage-changing event was actually recorded for this
 * vehicle (manual update, service, or fuel fill-up) — unlike vehicles.updated_at,
 * this isn't bumped by unrelated edits (pinning, renaming, etc.), so it
 * genuinely answers "when was the mileage last updated."
 */
function lastMileageUpdateDate(PDO $pdo, int $vehicleId): ?string
{
    $stmt = $pdo->prepare("
        SELECT record_date FROM (
            SELECT log_date AS record_date FROM mileage_log WHERE vehicle_id = ?
            UNION ALL
            SELECT service_date AS record_date FROM service_records WHERE vehicle_id = ? AND mileage IS NOT NULL
            UNION ALL
            SELECT fill_date AS record_date FROM fuel_log WHERE vehicle_id = ?
        ) combined
        ORDER BY record_date DESC
        LIMIT 1
    ");
    $stmt->execute([$vehicleId, $vehicleId, $vehicleId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

/**
 * Humanized "time ago" string. Date-only inputs (log_date/service_date/
 * fill_date have no time component) collapse same-day results to "Today"
 * rather than implying false hour/minute precision.
 */
function timeAgo(string $datetime): string
{
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60) {
        return 'just now';
    }

    if (!str_contains($datetime, ':') && date('Y-m-d', $timestamp) === date('Y-m-d', $now)) {
        return 'Today';
    }

    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400 * 30) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400 * 365) {
        $months = (int) floor($diff / (86400 * 30));
        return $months . ' month' . ($months === 1 ? '' : 's') . ' ago';
    }
    $years = (int) floor($diff / (86400 * 365));
    return $years . ' year' . ($years === 1 ? '' : 's') . ' ago';
}

$lastMileageUpdate = lastMileageUpdateDate($pdo, $vehicleId) ?? $vehicle['updated_at'] ?? null;

/**
 * Every cost incurred for this vehicle within [$start, $end): services + other
 * expenses + fuel.
 */
function monthlyTotalSpent(PDO $pdo, int $vehicleId, string $start, string $end): float
{
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE vehicle_id = ? AND service_date >= ? AND service_date < ?) +
            (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE vehicle_id = ? AND expense_date >= ? AND expense_date < ?) +
            (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE vehicle_id = ? AND fill_date >= ? AND fill_date < ?)
        AS total
    ");
    $stmt->execute([$vehicleId, $start, $end, $vehicleId, $start, $end, $vehicleId, $start, $end]);
    return (float) $stmt->fetchColumn();
}

$spentThisMonth = monthlyTotalSpent($pdo, $vehicleId, $firstDayThisMonth, $firstDayNextMonth);
$spentLastMonth = monthlyTotalSpent($pdo, $vehicleId, $firstDayLastMonth, $firstDayThisMonth);

/**
 * Builds "vs last month" badge data. Returns null when there isn't enough
 * data on both sides to compare.
 */
function monthComparisonBadge(?float $current, ?float $previous): ?array
{
    if ($current === null || $previous === null) {
        return null;
    }

    $diff = $current - $previous;
    $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');

    if ($direction === 'flat') {
        $text = 'No change';
    } elseif ((float) $previous === 0.0) {
        $text = ($direction === 'up' ? '+' : '-') . formatNumber(abs($diff));
    } else {
        $pct = ($diff / $previous) * 100;
        $text = ($direction === 'up' ? '+' : '-') . number_format(abs($pct), 0) . '%';
    }

    return ['direction' => $direction, 'text' => $text];
}

$kmBadge = monthComparisonBadge($kmThisMonth, $kmLastMonth);
$spentBadge = monthComparisonBadge($spentThisMonth, $spentLastMonth);

// Service count this/last month — gives Total Services the same "vs last
// month" treatment the other three stat cards already have.
function monthlyServiceCount(PDO $pdo, int $vehicleId, string $start, string $end): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM service_records WHERE vehicle_id = ? AND service_date >= ? AND service_date < ?");
    $stmt->execute([$vehicleId, $start, $end]);
    return (int) $stmt->fetchColumn();
}

$servicesThisMonth = monthlyServiceCount($pdo, $vehicleId, $firstDayThisMonth, $firstDayNextMonth);
$servicesLastMonth = monthlyServiceCount($pdo, $vehicleId, $firstDayLastMonth, $firstDayThisMonth);
$servicesBadge = monthComparisonBadge((float) $servicesThisMonth, (float) $servicesLastMonth);

// Cost per km this/last month — ties the spend and distance cards together
// into a single cost-of-ownership figure.
$costPerKmThisMonth = ($kmThisMonth !== null && $kmThisMonth > 0) ? $spentThisMonth / $kmThisMonth : null;
$costPerKmLastMonth = ($kmLastMonth !== null && $kmLastMonth > 0) ? $spentLastMonth / $kmLastMonth : null;
$costPerKmBadge = monthComparisonBadge($costPerKmThisMonth, $costPerKmLastMonth);

// Last service info
$lastService = $services[0] ?? null;
$pageTitle = $vehicle['make'] . ' ' . $vehicle['model'];

// Get maintenance schedule for this vehicle
$stmt = $pdo->prepare("SELECT * FROM maintenance_schedule WHERE vehicle_id = ? ORDER BY id DESC");
$stmt->execute([$vehicleId]);
$maintenanceItems = $stmt->fetchAll();

foreach ($maintenanceItems as &$item) {
    $kmOverdue = $item['next_due_mileage'] ? $vehicle['current_mileage'] - $item['next_due_mileage'] : null;
    $dateOverdue = $item['next_due_date'] ? (strtotime($item['next_due_date']) < time()) : false;

    if (($kmOverdue !== null && $kmOverdue > 0) || $dateOverdue) {
        $item['status'] = 'overdue';
        $item['remaining'] = $kmOverdue;
    } elseif ($kmOverdue !== null && $kmOverdue > -2000) {
        $item['status'] = 'due_soon';
        $item['remaining'] = abs($kmOverdue);
    } elseif ($kmOverdue !== null && $kmOverdue > -5000) {
        $item['status'] = 'upcoming';
        $item['remaining'] = abs($kmOverdue);
    } else {
        $item['status'] = 'ok';
        $item['remaining'] = $kmOverdue !== null ? abs($kmOverdue) : null;
    }
}
unset($item);

$maintenanceOverdueCount = count(array_filter($maintenanceItems, fn($i) => $i['status'] === 'overdue'));
$maintenanceDueSoonCount = count(array_filter($maintenanceItems, fn($i) => $i['status'] === 'due_soon'));

// Extra info for stat cards
$daysSinceLastService = $lastService ? round((time() - strtotime($lastService['service_date'])) / 86400) : null;

// Get recent fuel logs
$stmt = $pdo->prepare("SELECT * FROM fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC LIMIT 5");
$stmt->execute([$vehicleId]);
$recentFuelLogs = $stmt->fetchAll();

// Get recent expenses
$stmt = $pdo->prepare("
    SELECT e.*, ec.name as category_name, ec.icon as category_icon
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.vehicle_id = ?
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 5
");
$stmt->execute([$vehicleId]);
$recentExpenses = $stmt->fetchAll();

// Get recent documents & photos
$stmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ? ORDER BY uploaded_at DESC, id DESC LIMIT 5");
$stmt->execute([$vehicleId]);
$recentDocuments = $stmt->fetchAll();

// Document categories are shared across all users and managed by admins only
// (Admin Dashboard > Document Categories) — same lookup vehicle-documents.php uses
$documentCategories = [];
foreach ($pdo->query("SELECT slug, label, icon, color FROM vehicle_document_categories ORDER BY label")->fetchAll() as $row) {
    $documentCategories[$row['slug']] = $row;
}

// Current insurance policy (the one with the furthest-out expiry_date)
$currentInsurance = \App\Services\InsuranceService::current($pdo, $vehicleId);
$insuranceStatusMeta = [
    'expired'  => ['label' => 'Expired', 'color' => 'danger', 'icon' => 'fa-exclamation-triangle'],
    'expiring' => ['label' => 'Expiring Soon', 'color' => 'warning', 'icon' => 'fa-exclamation-circle'],
    'ok'       => ['label' => 'Insured', 'color' => 'success', 'icon' => 'fa-check-circle'],
];
?>

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
                        <h4 class="fs-6"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h4>
                        <p class="mb-0 fs-10 text-1000"><?php echo $vehicle['year']; ?>
                            <?php if ($vehicle['license_plate']): ?>
                                &bull; <?php echo sanitize($vehicle['license_plate']); ?>
                            <?php endif; ?>
                            <?php if ($vehicle['color']): ?>
                                &bull; <?php echo sanitize($vehicle['color']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
                <form method="POST" class="d-inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="<?php echo $vehicle['is_pinned'] ? 'unpin' : 'pin'; ?>">
                    <input type="hidden" name="vehicle_id" value="<?php echo IdCodec::encode($vehicleId); ?>">
                    <button type="submit" class="btn btn-sm <?php echo $vehicle['is_pinned'] ? 'btn-warning' : 'btn-outline-warning'; ?>">
                        <i class="fas fa-thumbtack"></i> <?php echo $vehicle['is_pinned'] ? 'Unpin' : 'Pin to Dashboard'; ?>
                    </button>
                </form>
                <a href="edit-vehicle?id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="add-service?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm  btn-outline-success">
                    <i class="fas fa-wrench"></i> Add Service
                </a>
            </div>
        </div>
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

<div class="row g-3">
    <div class="col-lg-4 col-xl-4">
        <div class="sticky-sidebar top-navbar-height">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-6 col-lg-12 text-center">
                            <?php if ($vehicle['image_path'] && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                                <img class="img-fluid rounded-3" src="uploads/<?php echo $vehicle['image_path']; ?>" alt="<?php echo sanitize($vehicle['make']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-car" style="font-size: 5rem;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 col-lg-12">
                            <div >
                                <table class="table fs-10 mt-3" style="margin: 0;">
                                    <tr >
                                        <td class="bg-100" style="width: 20%;">Make</td>
                                        <td class="text-1000"><strong><?php echo sanitize($vehicle['make']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="bg-100" style="width: 20%;">Model</td>
                                        <td class="text-1000"><strong><?php echo sanitize($vehicle['model']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="bg-100" style="width: 20%;">Year</td>
                                        <td class="text-1000"><strong><?php echo $vehicle['year']; ?></strong></td>
                                    </tr>
                                    <?php if ($vehicle['color']): ?>
                                        <tr>
                                            <td class="bg-100" style="width: 20%;">Color</td>
                                            <td class="text-1000"><strong><?php echo sanitize($vehicle['color']); ?></strong></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ($vehicle['license_plate']): ?>
                                        <tr>
                                            <td class="bg-100" style="width: 20%;">License Plate</td>
                                            <td class="text-1000"><strong><?php echo sanitize($vehicle['license_plate']); ?></strong></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ($vehicle['vin']): ?>
                                        <tr>
                                            <td class="bg-100" style="width: 20%;">VIN</td>
                                            <td class="text-1000"><small><?php echo sanitize($vehicle['vin']); ?></small></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td class="bg-100" style="width: 20%;">Fuel Type</td>
                                        <td class="text-1000"><?php echo ucfirst($vehicle['fuel_type']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="bg-100" style="width: 20%;">Transmission</td>
                                        <td class="text-1000"><?php echo ucfirst($vehicle['transmission']); ?></td>
                                    </tr>
                                    <?php if ($vehicle['engine_capacity']): ?>
                                        <tr>
                                            <td class="bg-100" style="width: 20%;">Engine</td>
                                            <td class="text-1000"><?php echo sanitize($vehicle['engine_capacity']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8 col-xl-8">
        <div class="row g-3 row-cols-1 row-cols-sm-2">
            <div class="col">
                <div class="card h-100 border-0 shadow-sm hover-lift">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="icon-box bg-primary bg-opacity-10 rounded-3 p-2">
                                <i class="fas fa-tachometer-alt text-primary"></i>
                            </div>
                        </div>
                        <h6 class="text-muted mb-1 fw-normal fs-10">Current Mileage</h6>
                        <h4 class="fs-6 fw-bold mb-1"><?php echo formatNumber($vehicle['current_mileage']); ?> <small class="fs-11 text-muted">km</small></h4>
                        <p class="fs-11 text-muted mb-0">
                            <?php if ($lastMileageUpdate): ?>
                                Updated <?php echo timeAgo($lastMileageUpdate); ?>
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 border-0 shadow-sm hover-lift">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="icon-box bg-info bg-opacity-10 rounded-3 p-2">
                                <i class="fas fa-road text-info"></i>
                            </div>
                            <?php if ($kmBadge): ?>
                                <span class="badge badge-subtle-secondary fs-11">
                                    <i class="fas fa-<?php echo $kmBadge['direction'] === 'up' ? 'arrow-up' : ($kmBadge['direction'] === 'down' ? 'arrow-down' : 'minus'); ?> me-1"></i><?php echo $kmBadge['text']; ?> vs <?php echo $lastMonthAbbr; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h6 class="text-muted mb-1 fw-normal fs-10">KM Driven &bull; <?php echo $thisMonthAbbr; ?></h6>
                        <h4 class="fs-6 fw-bold mb-1"><?php echo $kmThisMonth !== null ? formatNumber($kmThisMonth) : '—'; ?> <small class="fs-11 text-muted">km</small></h4>
                        <p class="fs-11 text-muted mb-0">
                            <?php if ($kmLastMonth !== null): ?>
                                <?php echo $lastMonthAbbr; ?>: <?php echo formatNumber($kmLastMonth); ?> km
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 border-0 shadow-sm hover-lift">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="icon-box bg-warning bg-opacity-10 rounded-3 p-2">
                                <i class="fas fa-money-bill-wave text-warning"></i>
                            </div>
                            <?php if ($spentBadge): ?>
                                <span class="badge <?php echo $spentBadge['direction'] === 'up' ? 'badge-subtle-danger' : ($spentBadge['direction'] === 'down' ? 'badge-subtle-success' : 'badge-subtle-secondary'); ?> fs-11">
                                    <i class="fas fa-<?php echo $spentBadge['direction'] === 'up' ? 'arrow-up' : ($spentBadge['direction'] === 'down' ? 'arrow-down' : 'minus'); ?> me-1"></i><?php echo $spentBadge['text']; ?> vs <?php echo $lastMonthAbbr; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h6 class="text-muted mb-1 fw-normal fs-10">Total Spent &bull; <?php echo $thisMonthAbbr; ?></h6>
                        <h4 class="fs-6 fw-bold mb-1">Ksh. <?php echo formatNumber($spentThisMonth); ?></h4>
                        <p class="fs-11 text-muted mb-0">
                            <?php echo $lastMonthAbbr; ?>: Ksh. <?php echo formatNumber($spentLastMonth); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 border-0 shadow-sm hover-lift">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="icon-box bg-dark bg-opacity-10 rounded-3 p-2">
                                <i class="fas fa-coins text-dark"></i>
                            </div>
                            <?php if ($costPerKmBadge): ?>
                                <span class="badge <?php echo $costPerKmBadge['direction'] === 'up' ? 'badge-subtle-danger' : ($costPerKmBadge['direction'] === 'down' ? 'badge-subtle-success' : 'badge-subtle-secondary'); ?> fs-11">
                                    <i class="fas fa-<?php echo $costPerKmBadge['direction'] === 'up' ? 'arrow-up' : ($costPerKmBadge['direction'] === 'down' ? 'arrow-down' : 'minus'); ?> me-1"></i><?php echo $costPerKmBadge['text']; ?> vs <?php echo $lastMonthAbbr; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h6 class="text-muted mb-1 fw-normal fs-10">Cost per KM &bull; <?php echo $thisMonthAbbr; ?></h6>
                        <h4 class="fs-6 fw-bold mb-1"><?php echo $costPerKmThisMonth !== null ? 'Ksh. ' . number_format($costPerKmThisMonth, 2) : '—'; ?></h4>
                        <p class="fs-11 text-muted mb-0">
                            <?php if ($costPerKmLastMonth !== null): ?>
                                <?php echo $lastMonthAbbr; ?>: Ksh. <?php echo number_format($costPerKmLastMonth, 2); ?>
                            <?php else: ?>
                                &nbsp;
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($maintenanceOverdueCount + $maintenanceDueSoonCount > 0): ?>
            <a href="#maintenance-schedule-section" class="text-decoration-none">
                <div class="card mt-3 border-0 shadow-sm hover-lift <?php echo $maintenanceOverdueCount > 0 ? 'border-start border-danger border-4' : 'border-start border-warning border-4'; ?>">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box <?php echo $maintenanceOverdueCount > 0 ? 'bg-danger' : 'bg-warning'; ?> bg-opacity-10 rounded-3 p-2 me-3">
                                <i class="fas fa-exclamation-triangle <?php echo $maintenanceOverdueCount > 0 ? 'text-danger' : 'text-warning'; ?>"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1 fw-normal fs-10">Maintenance Alerts</h6>
                                <p class="fs-11 mb-0 text-800">
                                    <?php if ($maintenanceOverdueCount > 0): ?>
                                        <strong class="text-danger"><?php echo $maintenanceOverdueCount; ?> overdue</strong>
                                    <?php endif; ?>
                                    <?php if ($maintenanceOverdueCount > 0 && $maintenanceDueSoonCount > 0): ?> &bull; <?php endif; ?>
                                    <?php if ($maintenanceDueSoonCount > 0): ?>
                                        <strong class="text-warning"><?php echo $maintenanceDueSoonCount; ?> due soon</strong>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down text-muted"></i>
                    </div>
                </div>
            </a>
        <?php endif; ?>

        <!-- Insurance -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-shield-alt"></i> Insurance</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="insurance?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-shield-alt"></i> Manage</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$currentInsurance): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-shield-alt empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No insurance on file!</h6>
                        <p class="fs-10 mb-3">Record the policy and upload the sticker for this vehicle.</p>
                        <a href="insurance?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Insurance
                        </a>
                    </div>
                <?php else: ?>
                    <?php $meta = $insuranceStatusMeta[$currentInsurance['status']] ?? $insuranceStatusMeta['ok']; ?>
                    <div class="row g-3 align-items-center">
                        <div class="col">
                            <span class="badge badge-subtle-<?php echo $meta['color']; ?> fs-11 mb-1"><i class="fas <?php echo $meta['icon']; ?> me-1"></i><?php echo $meta['label']; ?></span>
                            <p class="fs-10 text-800 mb-0"><strong><?php echo sanitize($currentInsurance['provider']); ?></strong><?php if ($currentInsurance['policy_number']): ?> &bull; #<?php echo sanitize($currentInsurance['policy_number']); ?><?php endif; ?></p>
                            <p class="fs-11 text-500 mb-0">
                                Expires <?php echo formatDate($currentInsurance['expiry_date']); ?>
                                <?php if ($currentInsurance['status'] === 'expired'): ?>
                                    <span class="text-danger">(<?php echo abs($currentInsurance['days_remaining']); ?> day(s) ago)</span>
                                <?php elseif ($currentInsurance['status'] === 'expiring'): ?>
                                    <span class="text-warning">(<?php echo $currentInsurance['days_remaining']; ?> day(s) left)</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Service Status -->
        <?php
        if ($lastService) {
            $kmRemaining  = $lastService['next_service_mileage'] - $vehicle['current_mileage'];
            $kmUsed       = $vehicle['current_mileage'] - $lastService['mileage']; // km driven since last service
            $progress     = min(100, max(0, ($kmUsed / $lastService['oil_interval']) * 100));

            if ($kmRemaining <= 0) {
                $progressClass = 'bg-danger';
                $textClass     = 'text-danger';
                $badgeClass    = 'badge-subtle-danger';
                $badgeLabel    = 'OVERDUE';
                $statusIcon    = 'fa-exclamation-triangle';
            } elseif ($kmRemaining <= 500) {
                $progressClass = 'bg-danger';
                $textClass     = 'text-danger';
                $badgeClass    = 'badge-subtle-danger';
                $badgeLabel    = 'DUE SOON';
                $statusIcon    = 'fa-exclamation-circle';
            } elseif ($kmRemaining <= 1000) {
                $progressClass = 'bg-warning';
                $textClass     = 'text-warning';
                $badgeClass    = 'badge-subtle-warning';
                $badgeLabel    = 'URGENT';
                $statusIcon    = 'fa-exclamation-circle';
            } elseif ($kmRemaining <= 2000) {
                $progressClass = 'bg-primary';
                $textClass     = 'text-primary';
                $badgeClass    = 'badge-subtle-primary';
                $badgeLabel    = 'COMING UP';
                $statusIcon    = 'fa-clock';
            } else {
                $progressClass = 'bg-success';
                $textClass     = 'text-success';
                $badgeClass    = 'badge-subtle-success';
                $badgeLabel    = 'OK';
                $statusIcon    = 'fa-check-circle';
            }
        }
        ?>
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary d-flex flex-between-center py-2">
                <h5 class="mb-0">
                    <i class="fas fa-oil-can me-2"></i>Service Status
                </h5>
                <?php if ($lastService): ?>
                    <span class="badge rounded-pill <?php echo $badgeClass; ?>">
                        <i class="fas <?php echo $statusIcon; ?> me-1"></i><?php echo $badgeLabel; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="d-flex flex-between-center mb-3 pb-3 border-bottom">
                    <div>
                        <small class="text-muted">Total Services</small><br>
                        <strong class="fs-6"><?php echo $stats['total_services']; ?></strong>
                        <?php if ($daysSinceLastService !== null): ?>
                            <span class="text-muted fs-11"> &bull; last <?php echo $daysSinceLastService; ?> day<?php echo $daysSinceLastService == 1 ? '' : 's'; ?> ago</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($servicesBadge): ?>
                        <span class="badge badge-subtle-secondary fs-11">
                            <i class="fas fa-<?php echo $servicesBadge['direction'] === 'up' ? 'arrow-up' : ($servicesBadge['direction'] === 'down' ? 'arrow-down' : 'minus'); ?> me-1"></i><?php echo $servicesBadge['text']; ?> vs <?php echo $lastMonthAbbr; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($lastService): ?>
                    <div class="d-flex flex-between-center mb-3">
                        <div>
                            <small class="text-muted">Last service</small><br>
                            <strong><?php echo formatDate($lastService['service_date']); ?></strong>
                            <span class="text-muted"> at <?php echo formatNumber($lastService['mileage']); ?> km</span>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">Next service due</small><br>
                            <strong class="<?php echo $textClass; ?>"><?php echo formatNumber($lastService['next_service_mileage']); ?> km</strong>
                        </div>
                    </div>

                    <div class="progress" role="progressbar"
                         aria-valuenow="<?php echo $progress; ?>"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         style="height: 12px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $progressClass; ?>"
                             style="width: <?php echo $progress; ?>%">
                        </div>
                    </div>

                    <p class="text-center mt-2 mb-0">
                        <i class="fas <?php echo $statusIcon; ?> me-1 <?php echo $textClass; ?>"></i>
                        <?php if ($kmRemaining <= 0): ?>
                            <span class="<?php echo $textClass; ?>"><strong><?php echo formatNumber(abs($kmRemaining)); ?> km overdue</strong></span>
                        <?php else: ?>
                            <span class="<?php echo $textClass; ?>"><strong><?php echo formatNumber($kmRemaining); ?> km remaining</strong></span>
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <div class="empty-state text-center py-3">
                        <i class="fas fa-wrench empty-state-icon fs-3 text-300 mb-2"></i>
                        <p class="fs-10 text-muted mb-0">No services recorded yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <a href="add-service?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary w-100">
                    <i class="fas fa-wrench me-1"></i>Record New Service
                </a>
            </div>
        </div>

        <!-- Maintenance Schedule -->
        <div class="card mt-3" id="maintenance-schedule-section">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-calendar-check"></i> Maintenance Schedule
                            <?php if ($maintenanceOverdueCount > 0): ?>
                                <span class="badge badge-subtle-danger rounded-pill ms-2"><?php echo $maintenanceOverdueCount; ?> overdue</span>
                            <?php elseif ($maintenanceDueSoonCount > 0): ?>
                                <span class="badge badge-subtle-warning rounded-pill ms-2"><?php echo $maintenanceDueSoonCount; ?> due soon</span>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="maintenance-schedule?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-cog"></i> Manage</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($maintenanceItems)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-calendar-alt empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No maintenance items scheduled!</h6>
                        <p class="fs-10 mb-3">Track intervals like oil changes, filters, and brake pads.</p>
                        <a href="maintenance-schedule?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Maintenance Item
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($maintenanceItems as $item):
                            switch ($item['status']) {
                                case 'overdue':
                                    $mColor = 'danger'; $mIcon = 'fa-exclamation-circle'; $mLabel = 'Overdue';
                                    break;
                                case 'due_soon':
                                    $mColor = 'warning'; $mIcon = 'fa-exclamation-triangle'; $mLabel = 'Due Soon';
                                    break;
                                case 'upcoming':
                                    $mColor = 'info'; $mIcon = 'fa-clock'; $mLabel = 'Upcoming';
                                    break;
                                default:
                                    $mColor = 'success'; $mIcon = 'fa-check-circle'; $mLabel = 'OK';
                            }
                        ?>
                            <div class="row g-3 timeline timeline-<?php echo $mColor; ?> timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <div class="icon-item icon-item-sm rounded-circle bg-soft-<?php echo $mColor; ?> shadow-none">
                                            <i class="fas <?php echo $mIcon; ?> text-<?php echo $mColor; ?>"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row gx-0 border-bottom pb-x1">
                                        <div class="col">
                                            <h6 class="text-800 mb-1"><?php echo sanitize($item['item_type']); ?>
                                                <span class="badge rounded-pill ms-2 badge-subtle-<?php echo $mColor; ?>"><?php echo $mLabel; ?></span>
                                            </h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <?php
                                                $interval = [];
                                                if ($item['interval_km']) $interval[] = number_format($item['interval_km']) . ' km';
                                                if ($item['interval_months']) $interval[] = $item['interval_months'] . ' months';
                                                echo $interval ? implode(' / ', $interval) . ' interval' : 'No interval set';
                                                ?>
                                                <?php if ($item['remaining']): ?>
                                                    &bull; <?php echo number_format($item['remaining']); ?> km <?php echo $item['status'] === 'overdue' ? 'overdue' : 'left'; ?>
                                                <?php endif; ?>
                                                <br>
                                                <?php if ($item['last_replaced_date'] || $item['last_replaced_mileage']): ?>
                                                    Last done:
                                                    <?php if ($item['last_replaced_date']): ?><?php echo date('M d, Y', strtotime($item['last_replaced_date'])); ?><?php endif; ?>
                                                    <?php if ($item['last_replaced_mileage']): ?> at <?php echo number_format($item['last_replaced_mileage']); ?> km<?php endif; ?>
                                                <?php else: ?>
                                                    Not recorded yet
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="col-auto">
                                            <p class="fs-11 text-500 mb-0 text-end">
                                                <?php if ($item['next_due_date']): ?>
                                                    <?php echo date('M d, Y', strtotime($item['next_due_date'])); ?><br>
                                                <?php endif; ?>
                                                <?php if ($item['next_due_mileage']): ?>
                                                    <?php echo number_format($item['next_due_mileage']); ?> km
                                                <?php endif; ?>
                                                <?php if (!$item['next_due_date'] && !$item['next_due_mileage']): ?>
                                                    Not set
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Services -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-history"></i> Recent Services</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="service-history?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($services)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-wrench empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No services yet!</h6>
                        <p class="fs-10 mb-0">Record your first service to get started.</p>
                        <a href="add-service?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Service
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach (array_slice($services, 0, 5) as $service): ?>
                            <div class="row g-3 timeline timeline-primary timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <div class="icon-item icon-item-sm rounded-circle bg-soft-primary shadow-none">
                                            <i class="fas fa-wrench text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row gx-0 border-bottom pb-x1">
                                        <div class="col">
                                            <h6 class="text-800 mb-1"><?php echo formatNumber($service['mileage']); ?> km
                                                <?php if ($service['item_count'] > 0): ?>
                                                    <span class="badge rounded-pill ms-2 badge-subtle-info"><?php echo $service['item_count']; ?> items</span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <?php if ($service['service_cost'] > 0): ?>
                                                    Ksh. <?php echo number_format($service['service_cost'], 2); ?>
                                                <?php endif; ?>
                                                <?php if ($service['service_location']): ?>
                                                    &bull; <?php echo sanitize($service['service_location']); ?>
                                                <?php endif; ?>
                                                <br>
                                                <a href="service-items?service_id=<?php echo IdCodec::encode($service['id']); ?>" class="btn rounded-sm-pill btn-sm btn-outline-secondary mt-1">
                                                    View Details
                                                </a>
                                            </p>
                                        </div>
                                        <div class="col-auto">
                                            <p class="fs-11 text-500 mb-0"><?php echo formatDate($service['service_date']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Fuel Logs -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-gas-pump"></i> Recent Fuel Logs</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="fuel-log?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($recentFuelLogs)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-gas-pump empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No fuel records yet!</h6>
                        <p class="fs-10 mb-3">Record your first fueling to get started.</p>
                        <a href="fuel-log?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Fuel Record
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($recentFuelLogs as $f): ?>
                            <div class="row g-3 timeline timeline-info timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <div class="icon-item icon-item-sm rounded-circle bg-soft-info shadow-none">
                                            <i class="fas fa-gas-pump text-info"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row gx-0 border-bottom pb-x1">
                                        <div class="col">
                                            <h6 class="text-800 mb-1"><?php echo formatNumber($f['mileage']); ?> km
                                                <span class="badge rounded-pill ms-2 badge-subtle-info"><?php echo number_format($f['liters'], 2); ?> L</span>
                                            </h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <strong>Ksh. <?php echo number_format($f['total_cost'], 2); ?></strong>
                                                &bull; Ksh. <?php echo number_format($f['price_per_liter'], 2); ?>/L
                                                <?php if ($f['station_name']): ?>
                                                    &bull; <?php echo sanitize($f['station_name']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="col-auto">
                                            <p class="fs-11 text-500 mb-0"><?php echo formatDate($f['fill_date']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Expenses -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-receipt"></i> Recent Expenses</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="expenses?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($recentExpenses)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-receipt empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No expenses yet!</h6>
                        <p class="fs-10 mb-3">Record your first expense to get started.</p>
                        <a href="expenses?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Expense
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($recentExpenses as $e): ?>
                            <div class="row g-3 timeline timeline-warning timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <div class="icon-item icon-item-sm rounded-circle bg-soft-warning shadow-none">
                                            <i class="fas <?php echo $e['category_icon'] ? htmlspecialchars($e['category_icon']) : 'fa-tag'; ?> text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row gx-0 border-bottom pb-x1">
                                        <div class="col">
                                            <h6 class="text-800 mb-1">Ksh. <?php echo number_format($e['amount'], 2); ?>
                                                <span class="badge rounded-pill ms-2 badge-subtle-warning"><?php echo sanitize($e['category_name']); ?></span>
                                            </h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <?php echo sanitize($e['description']) ?: sanitize($e['item_name']); ?>
                                            </p>
                                        </div>
                                        <div class="col-auto">
                                            <p class="fs-11 text-500 mb-0"><?php echo formatDate($e['expense_date']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Documents & Photos -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-images"></i> Documents & Photos</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="vehicle-documents?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($recentDocuments)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-images empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No documents yet!</h6>
                        <p class="fs-10 mb-3">Store insurance, bill of lading, receipts, and photos here.</p>
                        <a href="vehicle-documents?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-upload"></i> Upload Document
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php
                        $fallbackDocCategory = ['label' => 'Uncategorized', 'icon' => 'fa-file', 'color' => 'dark'];
                        foreach ($recentDocuments as $doc):
                            $cat = $documentCategories[$doc['category']] ?? $fallbackDocCategory;
                            $isImage = str_starts_with($doc['file_type'], 'image/');
                            $fileUrl = 'uploads/documents/' . rawurlencode($doc['file_path']);
                            $displayTitle = $doc['title'] ?: $doc['file_name'];
                        ?>
                            <a href="vehicle-documents?vehicle_id=<?php echo IdCodec::encode($vehicleId); ?>" class="text-decoration-none">
                                <div class="row g-3 align-items-center pb-x1 mb-2 border-bottom">
                                    <div class="col-auto">
                                        <?php if ($isImage): ?>
                                            <img src="<?php echo $fileUrl; ?>" class="rounded-3 border" style="width:48px; height:48px; object-fit:cover;" alt="">
                                        <?php else: ?>
                                            <div class="rounded-3 border bg-body-tertiary d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                                                <i class="fas fa-file-pdf text-danger"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col">
                                        <span class="badge badge-subtle-<?php echo sanitize($cat['color']); ?> fs-11 mb-1"><?php echo sanitize($cat['label']); ?></span>
                                        <p class="fs-10 text-800 mb-0 text-truncate" title="<?php echo sanitize($displayTitle); ?>"><?php echo sanitize($displayTitle); ?></p>
                                        <p class="fs-11 text-500 mb-0"><?php echo formatDate($doc['uploaded_at']); ?></p>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once 'includes/footer.php'; ?>