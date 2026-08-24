<?php
$pageTitle = 'Dashboard';
require_once 'includes/header.php';

use App\Helpers\IdCodec;
use App\Services\SiteSettingsService;
use App\Services\InsuranceService;

// Recent services
$stmt = $pdo->prepare("
    SELECT sr.*, v.make, v.model, v.year
    FROM service_records sr
    JOIN vehicles v ON sr.vehicle_id = v.id
    WHERE v.user_id = ?
    ORDER BY sr.service_date DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentServices = $stmt->fetchAll();

// Vehicles needing service soon
$stmt = $pdo->prepare("
    SELECT v.*, sr.next_service_mileage,
           (sr.next_service_mileage - v.current_mileage) as km_remaining
    FROM vehicles v
    JOIN service_records sr ON v.id = sr.vehicle_id
    WHERE v.user_id = ? AND v.is_active = 1
    AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
    AND (sr.next_service_mileage - v.current_mileage) <= 2000
    ORDER BY km_remaining ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$vehiclesNeedingService = $stmt->fetchAll();

// All active vehicles for current user
$stmt = $pdo->prepare("
    SELECT v.*,
           (SELECT service_date FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service,
           (SELECT mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service_mileage,
           (SELECT next_service_mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as next_service,
           (SELECT oil_interval FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service_oil_interval,
           (SELECT fill_date FROM fuel_log WHERE vehicle_id = v.id ORDER BY fill_date DESC LIMIT 1) as last_fuel_date,
           (SELECT mileage FROM fuel_log WHERE vehicle_id = v.id ORDER BY fill_date DESC LIMIT 1) as last_fuel_mileage
    FROM vehicles v
    WHERE v.user_id = ? AND v.is_active = 1
    ORDER BY v.make, v.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

$pinnedVehicles = array_values(array_filter($vehicles, fn($v) => (int)$v['is_pinned'] === 1));

// Most urgent maintenance schedule item per pinned vehicle (overdue or due soon)
// Uses the same admin-configurable thresholds as the maintenance reminder cron
// (Admin Dashboard > Reminder & Maintenance Settings)
$defaultThresholds = ['maintenance_due_soon_km' => 1000, 'maintenance_due_soon_days' => 30];
$thresholdsRaw = SiteSettingsService::get($pdo, 'reminder_thresholds');
$thresholds = array_merge($defaultThresholds, $thresholdsRaw ? (json_decode($thresholdsRaw, true) ?: []) : []);
$dueSoonKmWindow = (int) $thresholds['maintenance_due_soon_km'];
$dueSoonDaysWindow = (int) $thresholds['maintenance_due_soon_days'];

$vehicleMaintenanceAlert = [];
foreach ($pinnedVehicles as $v) {
    $items = $pdo->prepare("SELECT * FROM maintenance_schedule WHERE vehicle_id = ?");
    $items->execute([$v['id']]);
    $due = [];
    foreach ($items->fetchAll() as $item) {
        $kmOverdue = $item['next_due_mileage'] ? $v['current_mileage'] - $item['next_due_mileage'] : null;
        $daysUntilDue = $item['next_due_date'] ? (strtotime($item['next_due_date']) - time()) / 86400 : null;

        $isOverdue = ($kmOverdue !== null && $kmOverdue > 0) || ($daysUntilDue !== null && $daysUntilDue < 0);
        $isDueSoon = !$isOverdue && (
            ($kmOverdue !== null && $kmOverdue > -$dueSoonKmWindow) ||
            ($daysUntilDue !== null && $daysUntilDue <= $dueSoonDaysWindow)
        );

        if (!$isOverdue && !$isDueSoon) {
            continue;
        }

        $item['status'] = $isOverdue ? 'overdue' : 'due_soon';
        $item['urgency'] = $kmOverdue !== null ? $kmOverdue : ($daysUntilDue !== null ? -$daysUntilDue : 0);
        $due[] = $item;
    }

    usort($due, fn($a, $b) => $b['urgency'] <=> $a['urgency']);

    $vehicleMaintenanceAlert[$v['id']] = [
        'top' => $due[0] ?? null,
        'more' => max(0, count($due) - 1),
    ];
}

// Current insurance policy status per vehicle (covers My Vehicles and the pinned cards)
$insuranceStatusMeta = [
    'expired'  => ['label' => 'Expired', 'class' => 'text-danger', 'badge' => 'bg-danger'],
    'expiring' => ['label' => 'Expiring Soon', 'class' => 'text-warning', 'badge' => 'bg-warning'],
    'ok'       => ['label' => 'Insured', 'class' => 'text-success', 'badge' => 'bg-success'],
    'none'     => ['label' => 'No Policy', 'class' => 'text-600', 'badge' => 'bg-secondary'],
];
$vehicleInsuranceStatus = [];
foreach (InsuranceService::statusForUser($pdo, $userId) as $row) {
    $vehicleInsuranceStatus[$row['vehicle_id']] = $row;
}

// Quick stats for the new dashboard view
$totalVehicleCount = count($vehicles);
$overdueVehicleCount = count(array_filter($vehicles, function ($v) {
    return $v['next_service'] && ($v['next_service'] - $v['current_mileage']) <= 0;
}));
$dueSoonVehicleCount = count($vehiclesNeedingService);
$recentServiceCount = count($recentServices);
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
                        <h4 class="fs-6">Dashboard</h4>
                        <p class="mb-0 fs-10">Welcome back! Here's your vehicle overview.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0 d-flex align-items-center flex-wrap justify-content-end">
                <div class="btn-group btn-group-sm me-2 mb-2 mb-md-0 dashboard-view-toggle" role="group" aria-label="Dashboard view switcher">
                    <button type="button" class="btn btn-outline-secondary" id="dashboardViewOldBtn">
                        <i class="fas fa-th-large"></i> Classic View
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="dashboardViewNewBtn">
                        <i class="fas fa-bolt"></i> New View
                    </button>
                </div>
                <a class="btn btn-outline-success btn-sm me-2 mb-2 mb-md-0" href="add-vehicle" role="button">
                    <i class="fas fa-plus"></i> Add Vehicle</a>
                <a class="btn btn-outline-primary btn-sm mb-2 mb-md-0" href="fuel-log"  role="button">
                    <i class="fas fa-gas-pump"></i> Fuel Log </a>
            </div>
        </div>
    </div>
</div>

<div id="dashboardOldView">

<div class="row g-3 mb-3">
    <?php if (empty($pinnedVehicles)): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-4">
                    <i class="fas fa-thumbtack fs-3 text-300 mb-2"></i>
                    <h6 class="fs-9 mb-1">No pinned vehicle</h6>
                    <p class="fs-10 mb-3 text-600">Pin a vehicle from the Vehicles page to feature it here.</p>
                    <a href="vehicles" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-thumbtack"></i> Go to Vehicles
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($pinnedVehicles as $vehicle): ?>
            <?php $alert = $vehicleMaintenanceAlert[$vehicle['id']] ?? null; ?>
            <div class="col-lg-6">
                <div class="card h-100 overflow-hidden border-warning border-2">
                    <div class="row g-0">
                        <div class="col-sm-5">
                            <?php if ($vehicle['image_path'] && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                                <img class="card-img-top h-100" src="uploads/<?php echo $vehicle['image_path']; ?>" alt="<?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?>" style="min-height: 180px; object-fit: cover;">
                            <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center h-100" style="min-height: 180px;">
                                    <i class="fas fa-car fs-1 text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-7">
                            <div class="card-body">
                                <h5 class="card-title mb-0"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h5>
                                <p class="fs-10 mb-2 text-600">
                                    <?php echo $vehicle['year']; ?>
                                    <?php if ($vehicle['license_plate']): ?>
                                        &bull; <?php echo sanitize($vehicle['license_plate']); ?>
                                    <?php endif; ?>
                                </p>

                                <ul class="list-unstyled mb-0 fs-10">
                                    <li class="d-flex justify-content-between border-bottom py-1">
                                        <span class="text-600">Mileage</span>
                                        <strong><?php echo formatNumber($vehicle['current_mileage']); ?> km</strong>
                                    </li>
                                    <li class="d-flex justify-content-between border-bottom py-1">
                                        <span class="text-600">Last Fuel Fill</span>
                                        <strong>
                                            <?php echo $vehicle['last_fuel_date'] ? date('M d, Y', strtotime($vehicle['last_fuel_date'])) : 'No fuel logs'; ?>
                                        </strong>
                                    </li>
                                    <?php if ($vehicle['next_service']): ?>
                                        <?php $kmRemaining = $vehicle['next_service'] - $vehicle['current_mileage']; ?>
                                        <li class="d-flex justify-content-between border-bottom py-1">
                                            <span class="text-600">Next Service</span>
                                            <strong class="<?php echo $kmRemaining <= 0 ? 'text-danger' : ($kmRemaining <= 1000 ? 'text-warning' : ''); ?>">
                                                <?php echo $kmRemaining <= 0 ? formatNumber(abs($kmRemaining)) . ' km overdue' : formatNumber($kmRemaining) . ' km left'; ?>
                                            </strong>
                                        </li>
                                    <?php endif; ?>
                                    <li class="d-flex justify-content-between border-bottom py-1">
                                        <span class="text-600">Maintenance</span>
                                        <?php if ($alert && $alert['top']): ?>
                                            <strong class="<?php echo $alert['top']['status'] === 'overdue' ? 'text-danger' : 'text-warning'; ?>">
                                                <?php echo sanitize($alert['top']['item_type']); ?>
                                                <?php echo $alert['top']['status'] === 'overdue' ? ' overdue' : ' due soon'; ?>
                                                <?php echo $alert['more'] > 0 ? ' (+' . $alert['more'] . ' more)' : ''; ?>
                                            </strong>
                                        <?php else: ?>
                                            <strong class="text-success">OK</strong>
                                        <?php endif; ?>
                                    </li>
                                    <?php $insurance = $vehicleInsuranceStatus[$vehicle['id']] ?? null;
                                          $insMeta = $insuranceStatusMeta[$insurance['status'] ?? 'none']; ?>
                                    <li class="d-flex justify-content-between py-1">
                                        <span class="text-600">Insurance</span>
                                        <strong class="<?php echo $insMeta['class']; ?>"><?php echo $insMeta['label']; ?></strong>
                                    </li>
                                </ul>

                                <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicle['id']); ?>" class="btn btn-sm btn-outline-primary mt-2 fs-10">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="row g-0">
    <div class="col-lg-6 ps-lg-2 mb-3">

        <div class="card h-100">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-exclamation-circle text-warning"></i> Service Due Soon</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="service-reminders" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($vehiclesNeedingService)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle empty-state-icon text-success"></i>
                        <h6 class="fs-9 mb-1">All caught up!</h6>
                        <p class="fs-10 mb-0">No vehicles need immediate service.</p>
                    </div>
                <?php else: ?>
                    <div>
                        <table class="table table-responsive-sm mb-0 data-table fs-10" data-datatables='{"order": []}'>
                            <thead class="bg-200">
                            <tr>
                                <th class="text-900 sort">Vehicle</th>
                                <th class="text-900 sort">Current</th>
                                <th class="text-900 sort">Next Service</th>
                                <th class="text-900 sort">Remaining</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vehiclesNeedingService as $v): ?>
                                <tr>
                                    <td><strong><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                        <br><small class="text-muted"><?php echo $v['year']; ?></small>
                                    </td>
                                    <td><?php echo formatNumber($v['current_mileage']); ?> km</td>
                                    <td><?php echo formatNumber($v['next_service_mileage']); ?> km</td>
                                    <td><?php
                                        $remaining = $v['km_remaining'];
                                        $badgeClass = $remaining <= 500 ? 'badge-subtle-danger' : ($remaining <= 1000 ? 'badge-subtle-warning' : 'badge-subtle-info');
                                        ?>
                                        <span class="badge rounded-pill ms-2 <?php echo $badgeClass; ?>">
                                            <?php echo formatNumber($remaining); ?> km
                                        </span>
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
    <div class="col-lg-6 ps-lg-2 mb-3">

        <div class="card h-100">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-history"></i> Recent Services</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="service-history" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($recentServices)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-wrench empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No services yet!</h6>
                        <p class="fs-10 mb-0">Record your first service to get started.</p>
                        <a href="add-service" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Service
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($recentServices as $service): ?>
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
                                            <h6 class="text-800 mb-1"><?php echo sanitize($service['make'] . ' ' . $service['model']); ?></h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <?php echo formatNumber($service['mileage']); ?> km
                                                <?php if ($service['service_cost'] > 0): ?>
                                                    &bull; Ksh <?php echo number_format($service['service_cost'], 2); ?>
                                                <?php endif; ?>
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

    </div>
</div>

<div class="row">
    <div class="col">
        <div class="card">
            <div class="card-header d-flex flex-between-center bg-body-tertiary py-2">
                <h6 class="mb-0"><i class="fas fa-car"></i> My Vehicles</h6>
                <a class="btn btn-sm btn-outline-success py-1 fs-10 font-sans-serif" href="add-vehicle">
                    <i class="fas fa-plus"></i> Add Vehicle
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($vehicles)): ?>
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-car empty-state-icon fs-1 text-muted mb-3"></i>
                        <h6 class="fs-9 mb-1">No vehicles added!</h6>
                        <p class="fs-10 mb-3">Add your first vehicle to start tracking services.</p>
                        <a href="add-vehicle" class="btn btn-outline-success">
                            <i class="fas fa-plus"></i> Add Vehicle
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($vehicles as $vehicle): ?>
                            <?php
                            $kmRemaining = $vehicle['next_service'] ? $vehicle['next_service'] - $vehicle['current_mileage'] : null;
                            $isOverdue   = $kmRemaining !== null && $kmRemaining <= 0;

                            if ($isOverdue) {
                                $progressClass = 'bg-danger';
                                $textClass     = 'text-danger';
                                $badgeClass    = 'bg-danger';
                                $statusLabel   = 'Overdue';
                                $statusIcon    = 'fa-exclamation-triangle';
                                $cardBorder    = 'border-danger border-2';
                                $footerClass   = 'border-danger bg-danger bg-opacity-10';
                                $dividerClass  = 'border-danger';
                            } elseif ($kmRemaining !== null && $kmRemaining <= 1000) {
                                $progressClass = 'bg-warning';
                                $textClass     = 'text-warning';
                                $badgeClass    = 'bg-warning';
                                $statusLabel   = 'Urgent';
                                $statusIcon    = 'fa-exclamation-circle';
                                $cardBorder    = 'border-warning border-2';
                                $footerClass   = 'border-warning bg-warning bg-opacity-10';
                                $dividerClass  = 'border-warning';
                            } elseif ($kmRemaining !== null && $kmRemaining <= 2000) {
                                $progressClass = 'bg-primary';
                                $textClass     = 'text-primary';
                                $badgeClass    = 'bg-primary';
                                $statusLabel   = 'Coming Up';
                                $statusIcon    = 'fa-clock';
                                $cardBorder    = '';
                                $footerClass   = 'border-200';
                                $dividerClass  = 'border-200';
                            } else {
                                $progressClass = 'bg-success';
                                $textClass     = 'text-success';
                                $badgeClass    = 'bg-success';
                                $statusLabel   = 'Good Shape';
                                $statusIcon    = 'fa-check-circle';
                                $cardBorder    = '';
                                $footerClass   = 'border-200';
                                $dividerClass  = 'border-200';
                            }
                            ?>
                            <div class="col-lg-6">
                                <div class="card h-100 overflow-hidden <?php echo $cardBorder; ?>">

                                    <?php if ($vehicle['next_service']): ?>
                                        <div class="d-flex align-items-center justify-content-between px-3 py-2 <?php echo $badgeClass; ?> bg-opacity-10">
                                            <small class="fw-semibold <?php echo $textClass; ?>">
                                                <i class="fas <?php echo $statusIcon; ?> me-1"></i><?php echo $statusLabel; ?>
                                            </small>
                                            <span class="badge <?php echo $badgeClass; ?> bg-opacity-75" style="font-size:.65rem;">
                                                <?php if ($isOverdue): ?>
                                                    <?php echo formatNumber(abs($kmRemaining)); ?> km overdue
                                                <?php else: ?>
                                                    <?php echo formatNumber($kmRemaining); ?> km left
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($vehicle['image_path'] && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                                        <img class="card-img-top" src="uploads/<?php echo $vehicle['image_path']; ?>" alt="<?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?>" style="height: 200px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="<?php echo $isOverdue ? 'bg-danger bg-opacity-10' : 'bg-light'; ?> d-flex align-items-center justify-content-center" style="height: 200px;">
                                            <i class="fas fa-car fs-1 <?php echo $isOverdue ? 'text-danger' : 'text-muted'; ?>"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="card-body text-center">
                                        <h5 class="card-title mb-0"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h5>
                                        <p class="fs-10 mb-2 text-600">
                                            <?php echo $vehicle['year']; ?>
                                            <?php if ($vehicle['license_plate']): ?>
                                                &bull; <?php echo sanitize($vehicle['license_plate']); ?>
                                            <?php endif; ?>
                                        </p>

                                        <?php $insurance = $vehicleInsuranceStatus[$vehicle['id']] ?? null;
                                              $insMeta = $insuranceStatusMeta[$insurance['status'] ?? 'none']; ?>
                                        <span class="badge <?php echo $insMeta['badge']; ?> bg-opacity-75 mb-2" style="font-size:.65rem;">
                                            <i class="fas fa-shield-alt me-1"></i><?php echo $insMeta['label']; ?>
                                        </span>

                                        <?php if ($vehicle['next_service']): ?>
                                            <hr class="my-2">
                                            <div class="mb-2">
                                                <h6 class="text-600 mb-1 fs-11">Next Service</h6>
                                                <h6 class="fs-9 mb-0 <?php echo $textClass; ?> fw-bold">
                                                    <?php echo formatNumber($vehicle['next_service']); ?> km
                                                    <i class="fas <?php echo $statusIcon; ?> ms-1"></i>
                                                </h6>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-footer border-top <?php echo $footerClass; ?> p-0">
                                        <div class="row g-0">
                                            <div class="col-6 border-end <?php echo $dividerClass; ?> py-3 text-center">
                                                <h6 class="text-600 mb-1 fs-11">Current Mileage</h6>
                                                <h6 class="fs-9 mb-0"><?php echo formatNumber($vehicle['current_mileage']); ?> km</h6>
                                            </div>
                                            <div class="col-6 py-3 text-center">
                                                <?php if ($vehicle['last_service']): ?>
                                                    <h6 class="text-600 mb-1 fs-11">Last Service</h6>
                                                    <h6 class="fs-9 mb-0"><?php echo date('M d, Y', strtotime($vehicle['last_service'])); ?></h6>
                                                <?php else: ?>
                                                    <h6 class="text-600 mb-1 fs-11">Last Service</h6>
                                                    <h6 class="fs-9 mb-0 text-muted">No service yet</h6>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <a class="stretched-link" href="vehicle-details?id=<?php echo IdCodec::encode($vehicle['id']); ?>"></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div><!-- /#dashboardOldView -->

<div id="dashboardNewView" style="display:none;">

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100 stat-tile stat-tile-primary" data-stat-target="allVehiclesCard">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-tile-icon bg-soft-primary text-primary"><i class="fas fa-car"></i></div>
                    <div class="ms-3">
                        <h4 class="mb-0"><?php echo $totalVehicleCount; ?></h4>
                        <p class="fs-10 text-600 mb-0">Total Vehicles</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 stat-tile stat-tile-danger" data-stat-target="overdueVehiclesCard">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-tile-icon bg-soft-danger text-danger"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="ms-3">
                        <h4 class="mb-0"><?php echo $overdueVehicleCount; ?></h4>
                        <p class="fs-10 text-600 mb-0">Overdue Service</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 stat-tile stat-tile-warning" data-stat-target="dueSoonCard">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-tile-icon bg-soft-warning text-warning"><i class="fas fa-clock"></i></div>
                    <div class="ms-3">
                        <h4 class="mb-0"><?php echo $dueSoonVehicleCount; ?></h4>
                        <p class="fs-10 text-600 mb-0">Due Soon</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100 stat-tile stat-tile-success" data-stat-target="recentServiceCard">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-tile-icon bg-soft-success text-success"><i class="fas fa-wrench"></i></div>
                    <div class="ms-3">
                        <h4 class="mb-0"><?php echo $recentServiceCount; ?></h4>
                        <p class="fs-10 text-600 mb-0">Recent Services</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($pinnedVehicles)): ?>
        <div class="row g-3 mb-3">
            <?php foreach ($pinnedVehicles as $vehicle): ?>
                <?php $alert = $vehicleMaintenanceAlert[$vehicle['id']] ?? null; ?>
                <div class="col-lg-6">
                    <div class="card h-100 overflow-hidden pinned-vehicle-card">
                        <div class="pinned-vehicle-badge"><i class="fas fa-thumbtack"></i> Pinned</div>
                        <div class="row g-0">
                            <div class="col-sm-5">
                                <?php if ($vehicle['image_path'] && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                                    <img class="card-img-top h-100" src="uploads/<?php echo $vehicle['image_path']; ?>" alt="<?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?>" style="min-height: 180px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center h-100" style="min-height: 180px;">
                                        <i class="fas fa-car fs-1 text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-sm-7">
                                <div class="card-body">
                                    <h5 class="card-title mb-0"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h5>
                                    <p class="fs-10 mb-2 text-600">
                                        <?php echo $vehicle['year']; ?>
                                        <?php if ($vehicle['license_plate']): ?>
                                            &bull; <?php echo sanitize($vehicle['license_plate']); ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if ($vehicle['next_service'] && $vehicle['last_service_oil_interval']):
                                        $kmRemaining = $vehicle['next_service'] - $vehicle['current_mileage'];
                                        $kmUsed = $vehicle['current_mileage'] - $vehicle['last_service_mileage'];
                                        $pct = min(100, max(0, ($kmUsed / $vehicle['last_service_oil_interval']) * 100));
                                        $ringClass = $kmRemaining <= 0 ? 'text-danger' : ($kmRemaining <= 1000 ? 'text-warning' : 'text-success');
                                    ?>
                                        <p class="fs-10 mb-1 text-600">Service progress</p>
                                        <div class="progress mb-2" style="height: 6px;">
                                            <div class="progress-bar <?php echo str_replace('text-', 'bg-', $ringClass); ?>" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                        <p class="fs-10 mb-2 <?php echo $ringClass; ?> fw-semibold">
                                            <?php echo $kmRemaining <= 0 ? formatNumber(abs($kmRemaining)) . ' km overdue' : formatNumber($kmRemaining) . ' km left'; ?>
                                        </p>
                                    <?php endif; ?>

                                    <button class="btn btn-sm btn-link p-0 fs-10 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#pinnedDetails<?php echo $vehicle['id']; ?>">
                                        <i class="fas fa-chevron-down me-1"></i>More details
                                    </button>
                                    <div class="collapse mt-2" id="pinnedDetails<?php echo $vehicle['id']; ?>">
                                        <ul class="list-unstyled mb-2 fs-10">
                                            <li class="d-flex justify-content-between border-bottom py-1">
                                                <span class="text-600">Mileage</span>
                                                <strong><?php echo formatNumber($vehicle['current_mileage']); ?> km</strong>
                                            </li>
                                            <li class="d-flex justify-content-between border-bottom py-1">
                                                <span class="text-600">Last Fuel Fill</span>
                                                <strong>
                                                    <?php echo $vehicle['last_fuel_date'] ? date('M d, Y', strtotime($vehicle['last_fuel_date'])) : 'No fuel logs'; ?>
                                                </strong>
                                            </li>
                                            <li class="d-flex justify-content-between border-bottom py-1">
                                                <span class="text-600">Maintenance</span>
                                                <?php if ($alert && $alert['top']): ?>
                                                    <strong class="<?php echo $alert['top']['status'] === 'overdue' ? 'text-danger' : 'text-warning'; ?>">
                                                        <?php echo sanitize($alert['top']['item_type']); ?>
                                                        <?php echo $alert['top']['status'] === 'overdue' ? ' overdue' : ' due soon'; ?>
                                                        <?php echo $alert['more'] > 0 ? ' (+' . $alert['more'] . ' more)' : ''; ?>
                                                    </strong>
                                                <?php else: ?>
                                                    <strong class="text-success">OK</strong>
                                                <?php endif; ?>
                                            </li>
                                            <?php $insurance = $vehicleInsuranceStatus[$vehicle['id']] ?? null;
                                                  $insMeta = $insuranceStatusMeta[$insurance['status'] ?? 'none']; ?>
                                            <li class="d-flex justify-content-between py-1">
                                                <span class="text-600">Insurance</span>
                                                <strong class="<?php echo $insMeta['class']; ?>"><?php echo $insMeta['label']; ?></strong>
                                            </li>
                                        </ul>
                                    </div>

                                    <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicle['id']); ?>" class="btn btn-sm btn-outline-primary mt-1 fs-10">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-0 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex flex-between-center bg-body-tertiary py-2">
                    <ul class="nav nav-tabs card-header-tabs px-3 pt-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-due-soon-btn" data-bs-toggle="tab" data-bs-target="#tab-due-soon" type="button" role="tab">
                                <i class="fas fa-exclamation-circle text-warning"></i> Service Due Soon
                                <span class="badge rounded-pill badge-subtle-warning ms-1"><?php echo $dueSoonVehicleCount; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-recent-btn" data-bs-toggle="tab" data-bs-target="#tab-recent" type="button" role="tab">
                                <i class="fas fa-history"></i> Recent Services
                                <span class="badge rounded-pill badge-subtle-info ms-1"><?php echo $recentServiceCount; ?></span>
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body scrollbar recent-activity-body-height">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab-due-soon" role="tabpanel">
                            <?php if (empty($vehiclesNeedingService)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle empty-state-icon text-success"></i>
                                    <h6 class="fs-9 mb-1">All caught up!</h6>
                                    <p class="fs-10 mb-0">No vehicles need immediate service.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($vehiclesNeedingService as $v):
                                    $remaining = $v['km_remaining'];
                                    $badgeClass = $remaining <= 500 ? 'badge-subtle-danger' : ($remaining <= 1000 ? 'badge-subtle-warning' : 'badge-subtle-info');
                                ?>
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-2 due-soon-row">
                                        <div>
                                            <strong class="fs-10"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                            <p class="fs-11 text-600 mb-0"><?php echo formatNumber($v['current_mileage']); ?> km &rarr; <?php echo formatNumber($v['next_service_mileage']); ?> km</p>
                                        </div>
                                        <span class="badge rounded-pill <?php echo $badgeClass; ?>"><?php echo formatNumber($remaining); ?> km left</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="tab-pane fade" id="tab-recent" role="tabpanel">
                            <?php if (empty($recentServices)): ?>
                                <div class="empty-state text-center py-4">
                                    <i class="fas fa-wrench empty-state-icon fs-3 text-300 mb-3"></i>
                                    <h6 class="fs-9 mb-1">No services yet!</h6>
                                    <p class="fs-10 mb-0">Record your first service to get started.</p>
                                    <a href="add-service" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus"></i> Add Service
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentServices as $service): ?>
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
                                                    <h6 class="text-800 mb-1"><?php echo sanitize($service['make'] . ' ' . $service['model']); ?></h6>
                                                    <p class="fs-10 text-600 mb-0">
                                                        <?php echo formatNumber($service['mileage']); ?> km
                                                        <?php if ($service['service_cost'] > 0): ?>
                                                            &bull; Ksh <?php echo number_format($service['service_cost'], 2); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="col-auto">
                                                    <p class="fs-11 text-500 mb-0"><?php echo formatDate($service['service_date']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <div class="card">
                <div class="card-header d-flex flex-between-center bg-body-tertiary py-2">
                    <h6 class="mb-0"><i class="fas fa-car"></i> My Vehicles</h6>
                    <a class="btn btn-sm btn-outline-success py-1 fs-10 font-sans-serif" href="add-vehicle">
                        <i class="fas fa-plus"></i> Add Vehicle
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($vehicles)): ?>
                        <div class="empty-state text-center py-5">
                            <i class="fas fa-car empty-state-icon fs-1 text-muted mb-3"></i>
                            <h6 class="fs-9 mb-1">No vehicles added!</h6>
                            <p class="fs-10 mb-3">Add your first vehicle to start tracking services.</p>
                            <a href="add-vehicle" class="btn btn-outline-success">
                                <i class="fas fa-plus"></i> Add Vehicle
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($vehicles as $vehicle):
                                $kmRemaining = $vehicle['next_service'] ? $vehicle['next_service'] - $vehicle['current_mileage'] : null;
                                $isOverdue   = $kmRemaining !== null && $kmRemaining <= 0;

                                if ($isOverdue) {
                                    $textClass = 'text-danger'; $badgeClass = 'bg-danger'; $statusLabel = 'Overdue'; $statusIcon = 'fa-exclamation-triangle'; $ringPct = 100;
                                } elseif ($kmRemaining !== null && $kmRemaining <= 1000) {
                                    $textClass = 'text-warning'; $badgeClass = 'bg-warning'; $statusLabel = 'Urgent'; $statusIcon = 'fa-exclamation-circle';
                                } elseif ($kmRemaining !== null && $kmRemaining <= 2000) {
                                    $textClass = 'text-primary'; $badgeClass = 'bg-primary'; $statusLabel = 'Coming Up'; $statusIcon = 'fa-clock';
                                } else {
                                    $textClass = 'text-success'; $badgeClass = 'bg-success'; $statusLabel = $vehicle['next_service'] ? 'Good Shape' : 'No Data'; $statusIcon = 'fa-check-circle';
                                }
                                if (!isset($ringPct)) {
                                    $ringPct = ($kmRemaining !== null && $vehicle['next_service']) ? max(0, min(100, 100 - ($kmRemaining / max($vehicle['next_service'], 1) * 100))) : 0;
                                }
                                $ringDeg = round($ringPct * 3.6);
                            ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="card h-100 flip-card">
                                        <div class="flip-card-inner">
                                            <div class="flip-card-front">
                                                <div class="d-flex align-items-center p-3">
                                                    <div class="service-ring <?php echo $textClass; ?>" style="background: conic-gradient(currentColor <?php echo $ringDeg; ?>deg, var(--falcon-gray-200, #edf2f9) <?php echo $ringDeg; ?>deg);">
                                                        <div class="service-ring-inner">
                                                            <i class="fas <?php echo $statusIcon; ?> <?php echo $textClass; ?>"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ms-3 flex-1 overflow-hidden">
                                                        <h6 class="mb-0 text-truncate"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h6>
                                                        <p class="fs-10 text-600 mb-0"><?php echo $vehicle['year']; ?></p>
                                                        <span class="badge <?php echo $badgeClass; ?> bg-opacity-75 mt-1" style="font-size:.65rem;"><?php echo $statusLabel; ?></span>
                                                    </div>
                                                </div>
                                                <?php if ($vehicle['image_path'] && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                                                    <img class="card-img-top" src="uploads/<?php echo $vehicle['image_path']; ?>" alt="<?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?>" style="height: 140px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center" style="height: 140px;">
                                                        <i class="fas fa-car fs-1 text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="text-center py-2 fs-10 text-600 flip-card-hint">
                                                    <i class="fas fa-sync-alt me-1"></i>Hover for details
                                                </div>
                                            </div>
                                            <div class="flip-card-back bg-body-tertiary">
                                                <div class="p-3 h-100 d-flex flex-column">
                                                    <h6 class="mb-2"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></h6>
                                                    <ul class="list-unstyled mb-2 fs-10 flex-1">
                                                        <li class="d-flex justify-content-between border-bottom py-1">
                                                            <span class="text-600">Mileage</span>
                                                            <strong><?php echo formatNumber($vehicle['current_mileage']); ?> km</strong>
                                                        </li>
                                                        <?php if ($vehicle['license_plate']): ?>
                                                        <li class="d-flex justify-content-between border-bottom py-1">
                                                            <span class="text-600">Plate</span>
                                                            <strong><?php echo sanitize($vehicle['license_plate']); ?></strong>
                                                        </li>
                                                        <?php endif; ?>
                                                        <?php if ($vehicle['next_service']): ?>
                                                        <li class="d-flex justify-content-between border-bottom py-1">
                                                            <span class="text-600">Next Service</span>
                                                            <strong class="<?php echo $textClass; ?>">
                                                                <?php echo $isOverdue ? formatNumber(abs($kmRemaining)) . ' km overdue' : formatNumber($kmRemaining) . ' km left'; ?>
                                                            </strong>
                                                        </li>
                                                        <?php endif; ?>
                                                        <li class="d-flex justify-content-between border-bottom py-1">
                                                            <span class="text-600">Last Service</span>
                                                            <strong><?php echo $vehicle['last_service'] ? date('M d, Y', strtotime($vehicle['last_service'])) : 'No service yet'; ?></strong>
                                                        </li>
                                                        <?php $insurance = $vehicleInsuranceStatus[$vehicle['id']] ?? null;
                                                              $insMeta = $insuranceStatusMeta[$insurance['status'] ?? 'none']; ?>
                                                        <li class="d-flex justify-content-between py-1">
                                                            <span class="text-600">Insurance</span>
                                                            <strong class="<?php echo $insMeta['class']; ?>"><?php echo $insMeta['label']; ?></strong>
                                                        </li>
                                                    </ul>
                                                    <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicle['id']); ?>" class="btn btn-sm btn-primary fs-10">
                                                        <i class="fas fa-eye"></i> View Full Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php unset($ringPct); endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /#dashboardNewView -->

<style>
    .dashboard-view-toggle .btn.active-view {
        background-color: var(--falcon-primary, #2c7be5);
        border-color: var(--falcon-primary, #2c7be5);
        color: #fff;
    }
    .stat-tile { transition: transform .15s ease, box-shadow .15s ease; cursor: default; }
    .stat-tile:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1); }
    .stat-tile-icon {
        width: 44px; height: 44px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .pinned-vehicle-card { position: relative; transition: box-shadow .2s ease, transform .2s ease; border: 1px solid var(--falcon-warning, #f5803e); }
    .pinned-vehicle-card:hover { transform: translateY(-2px); box-shadow: 0 .75rem 1.5rem rgba(0,0,0,.12); }
    .pinned-vehicle-badge {
        position: absolute; top: .5rem; right: .5rem; z-index: 2;
        background: var(--falcon-warning, #f5803e); color: #fff; font-size: .65rem;
        padding: .2rem .5rem; border-radius: 1rem; font-weight: 600;
    }
    .due-soon-row { transition: background-color .15s ease; border-radius: .25rem; }
    .due-soon-row:hover { background-color: var(--falcon-gray-100, #f9fafd); padding-left: .5rem; padding-right: .5rem; }

    .service-ring {
        width: 56px; height: 56px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .service-ring-inner {
        width: 42px; height: 42px; border-radius: 50%; background: var(--falcon-card-bg, #fff);
        display: flex; align-items: center; justify-content: center;
    }

    .flip-card { perspective: 1200px; background: transparent; border: none; }
    .flip-card-inner {
        position: relative; width: 100%; height: 100%; min-height: 280px;
        transition: transform .5s; transform-style: preserve-3d;
        border-radius: var(--falcon-card-border-radius, .5rem);
        box-shadow: var(--falcon-box-shadow, 0 .125rem .25rem rgba(0,0,0,.075));
    }
    .flip-card:hover .flip-card-inner,
    .flip-card.is-flipped .flip-card-inner { transform: rotateY(180deg); }
    .flip-card-front, .flip-card-back {
        position: absolute; width: 100%; height: 100%; backface-visibility: hidden;
        border-radius: var(--falcon-card-border-radius, .5rem); overflow: hidden;
        background: var(--falcon-card-bg, #fff);
    }
    .flip-card-back { transform: rotateY(180deg); }
    .flip-card-hint { border-top: 1px dashed var(--falcon-gray-300, #d8e2ef); }
</style>

<script>
    (function () {
        var STORAGE_KEY = 'asphalt-dashboard-view';
        var oldView = document.getElementById('dashboardOldView');
        var newView = document.getElementById('dashboardNewView');
        var oldBtn = document.getElementById('dashboardViewOldBtn');
        var newBtn = document.getElementById('dashboardViewNewBtn');

        function applyView(view) {
            if (view === 'new') {
                oldView.style.display = 'none';
                newView.style.display = '';
                newBtn.classList.add('active-view');
                oldBtn.classList.remove('active-view');
            } else {
                view = 'old';
                oldView.style.display = '';
                newView.style.display = 'none';
                oldBtn.classList.add('active-view');
                newBtn.classList.remove('active-view');
            }
            try { localStorage.setItem(STORAGE_KEY, view); } catch (e) {}
        }

        var saved = 'new';
        try { saved = localStorage.getItem(STORAGE_KEY) || 'new'; } catch (e) {}
        applyView(saved);

        oldBtn.addEventListener('click', function () { applyView('old'); });
        newBtn.addEventListener('click', function () { applyView('new'); });

        // Touch devices don't have hover — tap the front of a flip-card to flip it.
        document.querySelectorAll('.flip-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (window.matchMedia('(hover: hover)').matches) return;
                if (e.target.closest('a, button')) return;
                card.classList.toggle('is-flipped');
            });
        });
    })();
</script>

<?php require_once 'includes/footer.php'; ?>
