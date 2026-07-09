<?php
require_once 'includes/bootstrap.php';
\App\Middleware\AuthMiddleware::check();
$pdo = \App\Database\Database::getInstance()->getConnection();
$userId = \App\Middleware\AuthMiddleware::getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['pin', 'unpin'], true) && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

        if ($action === 'pin') {
            $pdo->prepare("UPDATE vehicles SET is_pinned = 1, pinned_at = NOW() WHERE id = ? AND user_id = ?")->execute([$vehicleId, $userId]);
            setFlashMessage('success', 'Vehicle pinned to your dashboard.');
        } else {
            $pdo->prepare("UPDATE vehicles SET is_pinned = 0, pinned_at = NULL WHERE id = ? AND user_id = ?")->execute([$vehicleId, $userId]);
            setFlashMessage('success', 'Vehicle unpinned from your dashboard.');
        }
    }

    redirect('vehicles');
}

$pageTitle = 'My Vehicles';
require_once 'includes/header.php';

// Get all vehicles with stats
$stmt = $pdo->prepare("
    SELECT v.*,
           (SELECT COUNT(*) FROM service_records WHERE vehicle_id = v.id) as service_count,
           (SELECT service_date FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service,
           (SELECT mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service_mileage,
           (SELECT next_service_mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as next_service,
           (SELECT oil_interval FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_oil_interval,
           (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE vehicle_id = v.id) as total_spent
    FROM vehicles v
    WHERE v.user_id = ? AND v.is_active = 1
    ORDER BY v.is_pinned DESC, v.id DESC
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();
?>

<?php
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
                        <div class="calendar me-2">
                            <span class="calendar-month"><?php echo date('M'); ?></span>
                            <span class="calendar-day"><?php echo date('d'); ?></span>
                        </div>
                        <div class="flex-1">
                            <h4 class="fs-6">My Vehicles</h4>
                            <p class="mb-0 fs-10">Manage all your registered vehicles.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <a class="btn btn-outline-success btn-sm me-2" href="add-vehicle" role="button">
                        <i class="fas fa-plus"></i> Add Vehicle
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <div class="card">
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
                                $kmRemaining     = $vehicle['next_service'] ? $vehicle['next_service'] - $vehicle['current_mileage'] : null;
                                $isOverdue       = $kmRemaining !== null && $kmRemaining <= 0;
                                $serviceInterval = $vehicle['last_oil_interval'] ?? $vehicle['oil_interval'] ?? 10000;

                                // Correct progress: real km driven since last service
                                if ($vehicle['last_service_mileage']) {
                                    $kmDriven        = $vehicle['current_mileage'] - $vehicle['last_service_mileage'];
                                    $progressPercent = min(100, max(0, round(($kmDriven / $serviceInterval) * 100, 1)));
                                } else {
                                    $progressPercent = 0;
                                }

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
                                    <div class="card h-100 overflow-hidden <?php echo $cardBorder; ?> <?php echo $vehicle['is_pinned'] ? 'border-warning' : ''; ?>">

                                        <div class="d-flex align-items-center justify-content-between px-2 py-1 position-relative" style="z-index:2;">
                                            <?php if ($vehicle['is_pinned']): ?>
                                                <span class="badge bg-warning bg-opacity-75" style="font-size:.6rem;">
                                                    <i class="fas fa-thumbtack me-1"></i>Pinned
                                                </span>
                                            <?php else: ?>
                                                <span></span>
                                            <?php endif; ?>
                                            <form method="POST" class="mb-0">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="<?php echo $vehicle['is_pinned'] ? 'unpin' : 'pin'; ?>">
                                                <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['id']; ?>">
                                                <button type="submit"
                                                        class="btn btn-sm rounded-circle shadow-sm d-flex align-items-center justify-content-center <?php echo $vehicle['is_pinned'] ? 'btn-warning' : 'btn-light'; ?>"
                                                        style="width:26px;height:26px;padding:0;"
                                                        data-bs-toggle="tooltip"
                                                        title="<?php echo $vehicle['is_pinned'] ? 'Unpin from dashboard' : 'Pin to dashboard'; ?>">
                                                    <i class="fas fa-thumbtack <?php echo $vehicle['is_pinned'] ? 'text-white' : 'text-600'; ?>" style="font-size:.6rem;"></i>
                                                </button>
                                            </form>
                                        </div>

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
                                                <?php if ($vehicle['color']): ?>
                                                    &bull; <?php echo sanitize($vehicle['color']); ?>
                                                <?php endif; ?>
                                            </p>

                                            <?php if ($vehicle['next_service']): ?>
                                                <hr class="my-2">
                                                <div>
                                                    <div class="d-flex flex-between-center mb-2">
                                                        <small class="text-muted">Next service: <?php echo formatNumber($vehicle['next_service']); ?> km</small>
                                                        <small class="<?php echo $textClass; ?> fw-semibold">
                                                            <?php if ($isOverdue): ?>
                                                                <i class="fas fa-exclamation-circle me-1"></i><?php echo formatNumber(abs($kmRemaining)); ?> km overdue
                                                            <?php else: ?>
                                                                <?php echo formatNumber($kmRemaining); ?> km left
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                    <div class="progress" role="progressbar"
                                                         aria-valuenow="<?php echo $progressPercent; ?>"
                                                         aria-valuemin="0"
                                                         aria-valuemax="100"
                                                         style="height: 6px;">
                                                        <div class="progress-bar progress-bar-striped <?php echo $isOverdue ? 'progress-bar-animated' : ''; ?> <?php echo $progressClass; ?>"
                                                             style="width: <?php echo $progressPercent; ?>%"></div>
                                                    </div>
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