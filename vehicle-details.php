<?php
$pageTitle = 'Vehicle Details';
require_once 'includes/header.php';

$vehicleId = (int)($_GET['id'] ?? 0);

if (!$vehicleId) {
    setFlashMessage('danger', 'Invalid vehicle.');
    redirect('vehicles');
}

// Get vehicle
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
$stmt->execute([$vehicleId]);
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
    'total_spent' => array_sum(array_column($services, 'service_cost')),
    'total_km_driven' => $vehicle['current_mileage'] - $vehicle['purchase_mileage'],
];

// Last service info
$lastService = $services[0] ?? null;
$pageTitle = $vehicle['make'] . ' ' . $vehicle['model'];
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
                <a href="edit-vehicle?id=<?php echo $vehicleId; ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="add-service?vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-sm  btn-outline-success">
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
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="row flex-between-center">
                            <div class="col d-md-flex d-lg-block flex-between-center">
                                <h6 class="mb-md-0 mb-lg-2">Current Mileage (km)</h6>
                                    <i class="fas fa-tachometer-alt fs-4"></i>
                            </div>
                            <div class="col-auto">
                                <h4 class="fs-6 fw-normal text-primary"><?php echo formatNumber($vehicle['current_mileage']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="row flex-between-center">
                            <div class="col d-md-flex d-lg-block flex-between-center">
                                <h6 class="mb-md-0 mb-lg-2">Total KM Driven</h6>
                                <i class="fas fa-road fs-4"></i>
                            </div>
                            <div class="col-auto">
                                <h4 class="fs-6 fw-normal text-primary"><?php echo formatNumber($stats['total_km_driven']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="row flex-between-center">
                            <div class="col d-md-flex d-lg-block flex-between-center">
                                <h6 class="mb-md-0 mb-lg-2">Total Services</h6>
                                <i class="fas fa-wrench fs-4"></i>
                            </div>
                            <div class="col-auto">
                                <h4 class="fs-6 fw-normal text-primary"><?php echo $stats['total_services']; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="row flex-between-center">
                            <div class="col d-md-flex d-lg-block flex-between-center">
                                <h6 class="mb-md-0 mb-lg-2">Total Spent</h6>
                                <i class="fas fa-money-bill-wave fs-4"></i>
                            </div>
                            <div class="col-auto">
                                <h4 class="fs-6 fw-normal text-primary">Ksh. <?php echo formatNumber($stats['total_spent']); ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Status -->
        <?php if ($lastService): ?>
            <?php
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
            ?>
            <div class="card mt-3">
                <div class="card-header bg-body-tertiary d-flex flex-between-center py-2">
                    <h5 class="mb-0">
                        <i class="fas fa-oil-can me-2"></i>Service Status
                    </h5>
                    <span class="badge rounded-pill <?php echo $badgeClass; ?>">
                <i class="fas <?php echo $statusIcon; ?> me-1"></i><?php echo $badgeLabel; ?>
            </span>
                </div>
                <div class="card-body">
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
                </div>
                <div class="card-footer">
                    <a href="add-service?vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-sm btn-outline-primary w-100">
                        <i class="fas fa-wrench me-1"></i>Record New Service
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <!-- Recent Services -->
        <div class="card mt-3">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-history"></i> Recent Services</h6>
                    </div>
                    <div class="col-auto text-center pe-x1">
                        <a href="service-history?vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>
            <div class="card-body scrollbar recent-activity-body-height ps-2">
                <?php if (empty($services)): ?>
                    <div class="empty-state text-center py-4">
                        <i class="fas fa-wrench empty-state-icon fs-3 text-300 mb-3"></i>
                        <h6 class="fs-9 mb-1">No services yet!</h6>
                        <p class="fs-10 mb-0">Record your first service to get started.</p>
                        <a href="add-service?vehicle_id=<?php echo $vehicleId; ?>" class="btn btn-primary btn-sm">
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
                                                <a href="service-items?service_id=<?php echo $service['id']; ?>" class="btn rounded-sm-pill btn-sm btn-outline-secondary mt-1">
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

    </div>
</div>

<?php require_once 'includes/footer.php'; ?>