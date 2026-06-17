<?php
$pageTitle = 'Service History';
require_once 'includes/header.php';

$vehicleFilter = $_GET['vehicle_id'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = [];
$params = [];
if ($vehicleFilter) { $where[] = "sr.vehicle_id = ?"; $params[] = $vehicleFilter; }
if ($dateFrom) { $where[] = "sr.service_date >= ?"; $params[] = $dateFrom; }
if ($dateTo) { $where[] = "sr.service_date <= ?"; $params[] = $dateTo; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT sr.*, v.make, v.model, v.year, v.license_plate,
           (SELECT COUNT(*) FROM service_items WHERE service_record_id = sr.id) as item_count
    FROM service_records sr JOIN vehicles v ON sr.vehicle_id = v.id $whereClause
    ORDER BY sr.service_date DESC
");
$stmt->execute($params);
$services = $stmt->fetchAll();

$vehicles = $pdo->query("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 ORDER BY make, model")->fetchAll();
$totalCost = array_sum(array_column($services, 'service_cost'));
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
                        <h4 class="fs-6">Service History</h4>
                        <p class="mb-0 fs-10">View all service records.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
                <a class="btn btn-outline-primary btn-sm" href="add-service"  role="button">
                    <i class="fas fa-plus"></i> Add Service</a>
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

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="d-flex gap-2 align-center" style="flex-wrap: wrap;">
            <select name="vehicle_id" class="form-control" style="width: auto; min-width: 200px;">
                <option value="">All Vehicles</option>
                <?php foreach ($vehicles as $v): ?>
                    <option value="<?php echo $v['id']; ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" style="width: auto;" value="<?php echo $dateFrom; ?>">
            <input type="date" name="date_to" class="form-control" style="width: auto;" value="<?php echo $dateTo; ?>">
            <button type="submit" class="btn btn-sm rounded-sm-pill btn-outline-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($vehicleFilter || $dateFrom || $dateTo): ?>
                <a href="service-history" class="btn btn-sm btn-falcon-default rounded-sm-pill"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4 col-md-4 col-lg-4 col-xxl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="row flex-between-center">
                    <div class="col d-md-flex d-lg-block flex-between-center">
                        <h6 class="mb-md-0 mb-lg-2">Services</h6>
                        <i class="fas fa-wrench fs-4"></i>
                    </div>
                    <div class="col-auto">
                        <h4 class="fs-6 fw-normal text-warning"><?php echo count($services); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 col-md-4 col-lg-4 col-xxl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="row flex-between-center">
                    <div class="col d-md-flex d-lg-block flex-between-center">
                        <h6 class="mb-md-0 mb-lg-2">Total Spent</h6>
                        <i class="fas fa-money-bill-wave fs-4"></i>
                    </div>
                    <div class="col-auto">
                        <h4 class="fs-6 fw-normal text-warning">Ksh. <?php echo formatNumber($totalCost); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4 col-md-4 col-lg-4 col-xxl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="row flex-between-center">
                    <div class="col d-md-flex d-lg-block flex-between-center">
                        <h6 class="mb-md-0 mb-lg-2">Avg Cost</h6>
                        <i class="fas fa-calculator fs-4"></i>
                    </div>
                    <div class="col-auto">
                        <h4 class="fs-6 fw-normal text-warning">Ksh<?php echo count($services) ? formatNumber($totalCost / count($services)) : '0'; ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($services)): ?>
            <div class="empty-state">
                <i class="fas fa-history empty-state-icon"></i>
                <h3 class="empty-state-title">No Records Found</h3>
                <a href="add-service" class="btn btn-primary"><i class="fas fa-plus"></i> Add Service</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0 data-table fs-10" data-datatables="data-datatables">
                    <thead class="bg-200">
                    <tr>
                        <th class="text-900 sort">Date</th>
                        <th class="text-900 sort">Vehicle</th>
                        <th class="text-900 sort">Mileage</th>
                        <th class="text-900 sort">Next Service</th>
                        <th class="text-900 sort">Items</th>
                        <th class="text-900 sort text-start">Cost</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($services as $s): ?>
                        <tr class="hover-actions-trigger btn-reveal-trigger hover-bg-300 cursor-pointer"
                            onclick="window.location='service-items.php?service_id=<?php echo $s['id']; ?>'">
                            <td><strong><?php echo formatDate($s['service_date']); ?></strong></td>
                            <td><?php echo sanitize($s['make'] . ' ' . $s['model']); ?><br><small class="text-muted"><?php echo $s['year']; ?></small></td>
                            <td><strong><?php echo formatNumber($s['mileage']); ?></strong> km</td>
                            <td><?php echo formatNumber($s['next_service_mileage']); ?> km</td>
                            <td><span class="badge rounded-pill ms-2 badge-subtle-<?php echo $s['item_count'] > 0 ? 'success' : 'warning'; ?>"><?php echo $s['item_count']; ?> items</span></td>
                            <td><?php echo $s['service_cost'] > 0 ? 'Ksh. ' . number_format($s['service_cost'], 2) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
