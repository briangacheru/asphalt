<?php
$pageTitle = 'Compare Vehicles';
require_once 'includes/header.php';

use App\Helpers\IdCodec;

$vehiclesStmt = $pdo->prepare("SELECT id, make, model, year, license_plate, current_mileage, purchase_mileage, image_path FROM vehicles WHERE is_active = 1 AND user_id = ? ORDER BY make, model");
$vehiclesStmt->execute([$userId]);
$allVehicles = $vehiclesStmt->fetchAll();

// Which vehicles to compare — all of them by default, or a chosen subset via ?ids=1,2,3 (encoded ids)
$requestedIds = array_filter(array_map('trim', explode(',', $_GET['ids'] ?? '')));
$selectedIds = [];
if (!empty($requestedIds)) {
    foreach ($requestedIds as $encoded) {
        $decoded = IdCodec::decode($encoded);
        if ($decoded) {
            $selectedIds[] = $decoded;
        }
    }
}
$compareVehicles = empty($selectedIds)
    ? $allVehicles
    : array_values(array_filter($allVehicles, fn($v) => in_array($v['id'], $selectedIds, true)));

// Per-vehicle stats — all-time totals, reusing the same shape reports.php uses per vehicle
$stats = [];
foreach ($compareVehicles as $v) {
    $vid = $v['id'];

    // service_item_id-linked expense rows mirror a service_items row whose cost is
    // already counted in service_cost, so exclude them here to avoid double-counting
    // (same exclusion reports.php uses) — fall back to counting everything on older
    // schemas that predate the expenses.service_item_id column.
    try {
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM service_records WHERE vehicle_id = ?) AS service_count,
                (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE vehicle_id = ?) AS service_cost,
                (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE vehicle_id = ?) AS fuel_cost,
                (SELECT COALESCE(SUM(liters), 0) FROM fuel_log WHERE vehicle_id = ?) AS fuel_liters,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE vehicle_id = ? AND service_item_id IS NULL) AS expense_cost,
                (SELECT service_date FROM service_records WHERE vehicle_id = ? ORDER BY service_date DESC LIMIT 1) AS last_service_date,
                (SELECT MIN(mileage) FROM fuel_log WHERE vehicle_id = ?) AS min_fuel_mileage,
                (SELECT MAX(mileage) FROM fuel_log WHERE vehicle_id = ?) AS max_fuel_mileage
        ");
        $stmt->execute([$vid, $vid, $vid, $vid, $vid, $vid, $vid, $vid]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM service_records WHERE vehicle_id = ?) AS service_count,
                (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE vehicle_id = ?) AS service_cost,
                (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE vehicle_id = ?) AS fuel_cost,
                (SELECT COALESCE(SUM(liters), 0) FROM fuel_log WHERE vehicle_id = ?) AS fuel_liters,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE vehicle_id = ?) AS expense_cost,
                (SELECT service_date FROM service_records WHERE vehicle_id = ? ORDER BY service_date DESC LIMIT 1) AS last_service_date,
                (SELECT MIN(mileage) FROM fuel_log WHERE vehicle_id = ?) AS min_fuel_mileage,
                (SELECT MAX(mileage) FROM fuel_log WHERE vehicle_id = ?) AS max_fuel_mileage
        ");
        $stmt->execute([$vid, $vid, $vid, $vid, $vid, $vid, $vid, $vid]);
        $row = $stmt->fetch();
    }

    $kmDrivenSincePurchase = max(0, (int) $v['current_mileage'] - (int) ($v['purchase_mileage'] ?? 0));
    $kmBetweenFuelLogs = ($row['max_fuel_mileage'] !== null && $row['min_fuel_mileage'] !== null)
        ? max(0, (int) $row['max_fuel_mileage'] - (int) $row['min_fuel_mileage'])
        : 0;

    $totalSpend = (float) $row['service_cost'] + (float) $row['fuel_cost'] + (float) $row['expense_cost'];
    $fuelEconomy = $kmBetweenFuelLogs > 0 && $row['fuel_liters'] > 0 ? $kmBetweenFuelLogs / $row['fuel_liters'] : null;
    $costPerKm = $kmDrivenSincePurchase > 0 ? $totalSpend / $kmDrivenSincePurchase : null;

    $stats[$vid] = [
        'service_count' => (int) $row['service_count'],
        'service_cost' => (float) $row['service_cost'],
        'fuel_cost' => (float) $row['fuel_cost'],
        'expense_cost' => (float) $row['expense_cost'],
        'total_spend' => $totalSpend,
        'last_service_date' => $row['last_service_date'],
        'km_driven' => $kmDrivenSincePurchase,
        'fuel_economy' => $fuelEconomy,
        'cost_per_km' => $costPerKm,
    ];
}

// For each metric, find the "best" vehicle id to highlight (lower is better for
// cost metrics, higher is better for fuel economy).
function bestVehicleFor(array $stats, string $key, string $direction): ?int
{
    $best = null;
    $bestValue = null;
    foreach ($stats as $vid => $s) {
        if ($s[$key] === null) {
            continue;
        }
        if ($bestValue === null || ($direction === 'min' ? $s[$key] < $bestValue : $s[$key] > $bestValue)) {
            $bestValue = $s[$key];
            $best = $vid;
        }
    }
    return $best;
}

$bestCostPerKm = bestVehicleFor($stats, 'cost_per_km', 'min');
$bestFuelEconomy = bestVehicleFor($stats, 'fuel_economy', 'max');
$bestTotalSpend = bestVehicleFor($stats, 'total_spend', 'min');
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
                    <div class="calendar me-2"><span class="calendar-month"><?php echo date('M'); ?></span><span class="calendar-day"><?php echo date('d'); ?> </span></div>
                    <div class="flex-1">
                        <h4 class="fs-6">Compare Vehicles</h4>
                        <p class="mb-0 fs-10">See which vehicle costs less to run, side by side.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-3 mt-md-0">
                <a href="vehicles" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Vehicles</a>
            </div>
        </div>
    </div>
</div>

<?php if (count($allVehicles) < 2): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fas fa-car-side fs-3 text-300 mb-3"></i>
            <h6 class="fs-9 mb-1">Add another vehicle to compare</h6>
            <p class="fs-10 mb-3 text-600">Comparison needs at least two vehicles on your account.</p>
            <a href="add-vehicle" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus"></i> Add Vehicle</a>
        </div>
    </div>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="d-flex flex-wrap gap-3 align-items-center">
                <span class="fs-10 fw-semibold text-muted">Vehicles:</span>
                <?php foreach ($allVehicles as $v): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="vehicle_checkbox[]" value="<?php echo IdCodec::encode($v['id']); ?>"
                               id="veh_<?php echo $v['id']; ?>" <?php echo in_array($v['id'], array_column($compareVehicles, 'id'), true) ? 'checked' : ''; ?>>
                        <label class="form-check-label fs-10" for="veh_<?php echo $v['id']; ?>"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></label>
                    </div>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="applyCompareSelection">Compare Selected</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-borderless mb-0 fs-10">
                    <thead>
                        <tr>
                            <th style="min-width:140px;">Metric</th>
                            <?php foreach ($compareVehicles as $v): ?>
                                <th class="text-center" style="min-width:150px;">
                                    <?php if ($v['image_path'] && file_exists(UPLOAD_DIR . $v['image_path'])): ?>
                                        <img src="uploads/<?php echo $v['image_path']; ?>" class="rounded-circle mb-2" style="width:48px;height:48px;object-fit:cover;" alt="">
                                    <?php else: ?>
                                        <div class="avatar avatar-2xl mb-2 mx-auto"><div class="avatar-name rounded-circle bg-primary-subtle text-primary"><i class="fas fa-car"></i></div></div>
                                    <?php endif; ?>
                                    <div><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></div>
                                    <div class="text-muted fw-normal"><?php echo (int) $v['year']; ?> &bull; <?php echo sanitize($v['license_plate'] ?: '—'); ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-top">
                            <td class="fw-semibold">Current Mileage</td>
                            <?php foreach ($compareVehicles as $v): ?>
                                <td class="text-center"><?php echo formatNumber($v['current_mileage']); ?> km</td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Total Spend (all time)</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center <?php echo $v['id'] === $bestTotalSpend ? 'text-success fw-bold' : ''; ?>">
                                    <?php echo money($s['total_spend'], 0); ?>
                                    <?php if ($v['id'] === $bestTotalSpend): ?><i class="fas fa-trophy text-warning ms-1" title="Lowest total spend"></i><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">— Service Cost</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center text-muted"><?php echo money($s['service_cost'], 0); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">— Fuel Cost</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center text-muted"><?php echo money($s['fuel_cost'], 0); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">— Other Expenses</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center text-muted"><?php echo money($s['expense_cost'], 0); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="border-top">
                            <td class="fw-semibold">Cost per KM</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center <?php echo $v['id'] === $bestCostPerKm ? 'text-success fw-bold' : ''; ?>">
                                    <?php echo $s['cost_per_km'] !== null ? money($s['cost_per_km']) : '—'; ?>
                                    <?php if ($v['id'] === $bestCostPerKm): ?><i class="fas fa-trophy text-warning ms-1" title="Cheapest to run per km"></i><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Fuel Economy</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center <?php echo $v['id'] === $bestFuelEconomy ? 'text-success fw-bold' : ''; ?>">
                                    <?php echo $s['fuel_economy'] !== null ? number_format($s['fuel_economy'], 1) . ' km/L' : '—'; ?>
                                    <?php if ($v['id'] === $bestFuelEconomy): ?><i class="fas fa-trophy text-warning ms-1" title="Best fuel economy"></i><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="border-top">
                            <td class="fw-semibold">Services Logged</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center"><?php echo $s['service_count']; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Last Service</td>
                            <?php foreach ($compareVehicles as $v): $s = $stats[$v['id']]; ?>
                                <td class="text-center"><?php echo $s['last_service_date'] ? date('M d, Y', strtotime($s['last_service_date'])) : '—'; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="fw-semibold"></td>
                            <?php foreach ($compareVehicles as $v): ?>
                                <td class="text-center">
                                    <a href="vehicle-details?id=<?php echo IdCodec::encode($v['id']); ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
    document.getElementById('applyCompareSelection')?.addEventListener('click', function () {
        var checked = Array.prototype.slice.call(document.querySelectorAll('input[name="vehicle_checkbox[]"]:checked'))
            .map(function (el) { return el.value; });
        var url = new URL(window.location.href);
        if (checked.length) {
            url.searchParams.set('ids', checked.join(','));
        } else {
            url.searchParams.delete('ids');
        }
        window.location.href = url.toString();
    });
</script>

<?php require_once 'includes/footer.php'; ?>
