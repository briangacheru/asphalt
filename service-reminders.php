<?php
$pageTitle = 'Service Reminders';
require_once 'includes/header.php';

// Get vehicles with upcoming services
$stmt = $pdo->query("
    SELECT v.*, sr.next_service_mileage, sr.service_date as last_service_date,
           (sr.next_service_mileage - v.current_mileage) as km_remaining,
           sr.oil_interval
    FROM vehicles v
    JOIN service_records sr ON v.id = sr.vehicle_id
    WHERE v.is_active = 1 
    AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
    ORDER BY km_remaining ASC
");
$vehicles = $stmt->fetchAll();

// Separate into categories
$overdue = [];
$urgent = [];
$upcoming = [];
$healthy = [];

foreach ($vehicles as $v) {
    if ($v['km_remaining'] <= 0) {
        $overdue[] = $v;
    } elseif ($v['km_remaining'] <= 500) {
        $urgent[] = $v;
    } elseif ($v['km_remaining'] <= 1500) {
        $upcoming[] = $v;
    } else {
        $healthy[] = $v;
    }
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
                        <h4 class="fs-6">Service Reminders</h4>
                        <p class="mb-0 fs-10">Track when your vehicles need servicing.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
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
                <h6>Overdue</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-80" ><?php echo count($overdue); ?></div>
                <a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="vehicles">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-2.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Urgent (&lt; <?php echo formatDistance(500); ?>)</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" ><?php echo count($urgent); ?></div><a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="vehicles">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-3.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Coming Up (&lt; <?php echo formatDistance(1500); ?>)</h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" ><?php echo count($upcoming); ?></div><a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="vehicles">See all</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-6 col-lg-3 col-xxl-3">
        <div class="card overflow-hidden" style="min-width: 12rem">
            <div class="bg-holder bg-card" style="background-image:url(assets/img/icons/spot-illustrations/corner-4.png);">
            </div>
            <!--/.bg-holder-->
            <div class="card-body position-relative">
                <h6>Good Condition
                </h6>
                <div class="display-4 fs-5 mb-2 fw-normal font-sans-serif text-800" ><?php echo count($healthy); ?>
                </div>
                <a class="fw-semi-bold fs-10 text-nowrap stretched-link" href="vehicles">See all</a>
            </div>
        </div>
    </div>
</div>


<?php if (!empty($overdue)): ?>
    <!-- Overdue Services -->
    <div class="card mb-3">
        <div class="card-header bg-danger-subtle">
            <h3 class="card-title">
                <i class="fas fa-exclamation-circle"></i> Overdue Services
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead class="bg-200">
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Mileage</th>
                        <th>Was Due At</th>
                        <th>Overdue By</th>
                        <th>Last Service</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdue as $v): ?>
                        <tr>
                            <td>
                                <strong><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                <br><small class="text-muted"><?php echo $v['year']; ?> • <?php echo $v['color']; ?></small>
                            </td>
                            <td><?php echo formatDistance($v['current_mileage']); ?></td>
                            <td><?php echo formatDistance($v['next_service_mileage']); ?></td>
                            <td>
                            <span class="badge rounded-pill ms-2 badge-subtle-danger">
                                <?php echo formatDistance(abs($v['km_remaining'])); ?> overdue
                            </span>
                            </td>
                            <td><?php echo formatDate($v['last_service_date']); ?></td>
                            <td>
                                <a href="add-service?vehicle_id=<?php echo $v['id']; ?>" class="btn btn-sm btn-danger">
                                    <i class="fas fa-wrench"></i> Service Now
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($urgent)): ?>
    <!-- Urgent Services -->
    <div class="card mb-3">
        <div class="card-header bg-warning-subtle">
            <h3 class="card-title">
                <i class="fas fa-exclamation-triangle"></i> Urgent - Service Very Soon
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead class="bg-200">
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Mileage</th>
                        <th>Service Due At</th>
                        <th>Remaining</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($urgent as $v): ?>
                        <tr>
                            <td>
                                <strong><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                <br><small class="text-muted"><?php echo $v['year']; ?> • <?php echo $v['color']; ?></small>
                            </td>
                            <td><?php echo formatDistance($v['current_mileage']); ?></td>
                            <td><?php echo formatDistance($v['next_service_mileage']); ?></td>
                            <td>
                            <span class="badge rounded-pill ms-2 badge-subtle-warning">
                                <?php echo formatDistance($v['km_remaining']); ?> left
                            </span>
                            </td>
                            <td>
                                <a href="add-service?vehicle_id=<?php echo $v['id']; ?>" class="btn btn-sm btn-warning">
                                    <i class="fas fa-wrench"></i> Schedule
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($upcoming)): ?>
    <!-- Upcoming Services -->
    <div class="card mb-3">
        <div class="card-header bg-info-subtle">
            <h3 class="card-title">
                <i class="fas fa-clock"></i> Coming Up Soon
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead class="bg-200">
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Mileage</th>
                        <th>Service Due At</th>
                        <th>Remaining</th>
                        <th>Progress</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($upcoming as $v): ?>
                        <?php
                        $progress = (($v['oil_interval'] - $v['km_remaining']) / $v['oil_interval']) * 100;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                <br><small class="text-muted"><?php echo $v['year']; ?> • <?php echo $v['color']; ?></small>
                            </td>
                            <td><?php echo formatDistance($v['current_mileage']); ?></td>
                            <td><?php echo formatDistance($v['next_service_mileage']); ?></td>
                            <td>
                            <span class="badge rounded-pill ms-2 badge-subtle-info">
                                <?php echo formatDistance($v['km_remaining']); ?> left
                            </span>
                            </td>
                            <td style="width: 200px;">
                                <div class="progress">
                                    <div class="progress-bar progress-bar-warning" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($healthy)): ?>
    <!-- Healthy Vehicles -->
    <div class="card">
        <div class="card-header bg-success-subtle">
            <h3 class="card-title">
                <i class="fas fa-check-circle"></i> In Good Shape
            </h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead class="bg-200">
                    <tr>
                        <th>Vehicle</th>
                        <th>Current Mileage</th>
                        <th>Service Due At</th>
                        <th>Remaining</th>
                        <th>Progress</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($healthy as $v): ?>
                        <?php
                        $progress = (($v['oil_interval'] - $v['km_remaining']) / $v['oil_interval']) * 100;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></strong>
                                <br><small class="text-muted"><?php echo $v['year']; ?> • <?php echo $v['color']; ?></small>
                            </td>
                            <td><?php echo formatDistance($v['current_mileage']); ?></td>
                            <td><?php echo formatDistance($v['next_service_mileage']); ?></td>
                            <td>
                            <span class="badge rounded-pill ms-2 badge-subtle-info">
                                <?php echo formatDistance($v['km_remaining']); ?> left
                            </span>
                            </td>
                            <td style="width: 200px;">
                                <div class="progress">
                                    <div class="progress-bar progress-bar-warning" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($vehicles)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-clipboard-check empty-state-icon"></i>
                <h6 class="fs-9 mb-1">No Service Records Yet!</h6>
                <p class="fs-10 mb-0">Record a service to start tracking reminders.</p>
                <a href="add-service" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus"></i> Add Service
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
