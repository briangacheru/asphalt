<?php
$pageTitle = 'Dashboard';
require_once 'includes/header.php';

// Get statistics for current user
$stats = [];

// Total vehicles
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM vehicles WHERE user_id = ? AND is_active = 1");
$stmt->execute([$userId]);
$stats['total_vehicles'] = $stmt->fetch()['count'];

// Total services
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM service_records sr JOIN vehicles v ON sr.vehicle_id = v.id WHERE v.user_id = ?");
$stmt->execute([$userId]);
$stats['total_services'] = $stmt->fetch()['count'];

// Total spent this year
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(sr.service_cost), 0) as total 
    FROM service_records sr
    JOIN vehicles v ON sr.vehicle_id = v.id
    WHERE v.user_id = ? AND YEAR(sr.service_date) = YEAR(CURDATE())
");
$stmt->execute([$userId]);
$stats['spent_this_year'] = $stmt->fetch()['total'];

// Upcoming services (within 1000km)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT v.id) as count
    FROM vehicles v
    JOIN service_records sr ON v.id = sr.vehicle_id
    WHERE v.user_id = ? AND v.is_active = 1 
    AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
    AND (sr.next_service_mileage - v.current_mileage) <= 1000
");
$stmt->execute([$userId]);
$stats['upcoming_services'] = $stmt->fetch()['count'];

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
           (SELECT next_service_mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as next_service
    FROM vehicles v 
    WHERE v.user_id = ? AND v.is_active = 1 
    ORDER BY v.make, v.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();
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
            <div class="col-md-auto mt-4 mt-md-0">
                <a class="btn btn-outline-success btn-sm me-2" href="add-vehicle" role="button">
                    <i class="fas fa-plus"></i> Add Vehicle</a>
                <a class="btn btn-outline-primary btn-sm" href="add-service"  role="button">
                    <i class="fas fa-wrench"></i> Record Service </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-1.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Total Vehicles</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-80" ><?php echo $stats['total_vehicles']; ?></div><a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="vehicles">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-2.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Total Services</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" ><?php echo $stats['total_services']; ?></div><a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="service-history">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-3.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Spent This Year</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" >Ksh. <?php echo formatNumber($stats['spent_this_year']); ?></div><a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="service-history">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-4.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Services Due Soon
                <?php if ($stats['upcoming_services'] > 0): ?>
                    <span class="badge badge-subtle-danger rounded-pill ms-2" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Needs attention" data-bs-original-title="Needs attention"><span class="fas fa-bell" data-fa-transform="shrink-1"></span></span>
                <?php endif; ?>
                </h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" ><?php echo $stats['upcoming_services']; ?>
                </div>
                <a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="service-reminders">See all</a>
            </div>
        </div>
    </div>
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
                        <table class="table table-responsive-sm mb-0 data-table fs-10" data-datatables="data-datatables">
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

                                    <a class="stretched-link" href="vehicle-details?id=<?php echo $vehicle['id']; ?>"></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
