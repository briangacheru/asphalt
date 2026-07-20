<?php
$pageTitle = 'Update Mileage';
require_once 'includes/header.php';
use App\Database\Database;
use App\Services\EmailService;
use App\Helpers\IdCodec;

// Get all active vehicles belonging to the current user
$vehiclesStmt = $pdo->prepare("
    SELECT v.*,
           (SELECT mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as last_service_mileage,
           (SELECT next_service_mileage FROM service_records WHERE vehicle_id = v.id ORDER BY service_date DESC LIMIT 1) as next_service
    FROM vehicles v
    WHERE v.is_active = 1 AND v.user_id = ?
    ORDER BY v.make, v.model
");
$vehiclesStmt->execute([$userId]);
$vehicles = $vehiclesStmt->fetchAll();

$selectedVehicleId = IdCodec::decode($_GET['vehicle_id'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $new_mileage = (int)($_POST['mileage'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');

    $errors = [];

    if (!$vehicle_id) {
        $errors[] = 'Please select a vehicle';
    } else {
        $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehicle_id, $userId]);
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            $errors[] = 'Vehicle not found';
        } elseif ($new_mileage < $vehicle['current_mileage']) {
            $errors[] = 'New mileage cannot be less than current mileage (' . formatNumber($vehicle['current_mileage']) . ' km)';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_mileage, $vehicle_id, $userId]);

            $stmt = $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source, notes) VALUES (?, ?, CURDATE(), 'manual', ?)");
            $stmt->execute([$vehicle_id, $new_mileage, $notes]);

            $stmt = $pdo->prepare("SELECT next_service_mileage FROM service_records WHERE vehicle_id = ? ORDER BY service_date DESC LIMIT 1");
            $stmt->execute([$vehicle_id]);
            $lastService = $stmt->fetch();

            if ($lastService) {
                $kmRemaining = $lastService['next_service_mileage'] - $new_mileage;
                if ($kmRemaining <= 1000 && $kmRemaining > 0) {
                    $emailService = new EmailService($pdo);
                    $emailService->sendServiceReminderEmail($vehicle_id, $kmRemaining);
                }
            }

            setFlashMessage('success', 'Mileage updated successfully!');
            redirect('update-mileage');
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
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
                        <h4 class="fs-6">Update Mileage</h4>
                        <p class="mb-0 fs-10">Record your current odometer reading.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">

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

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <div><?php foreach ($errors as $error): ?><p class="mb-0"><?php echo $error; ?></p><?php endforeach; ?></div>
    </div>
<?php endif; ?>

<?php if (empty($vehicles)): ?>
    <div class="card"><div class="card-body"><div class="empty-state">
                <i class="fas fa-car empty-state-icon"></i>
                <h3 class="empty-state-title">No Vehicles Found</h3>
                <a href="add-vehicle" class="btn btn-primary"><i class="fas fa-plus"></i> Add Vehicle</a>
            </div></div></div>
<?php else: ?>
<div class="row g-0">
    <div class="col-lg-6 ps-lg-2 mb-3">
        <div class="card h-100">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-tachometer-alt text-primary"></i>  Update Vehicle Mileage</h6>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" data-validate class="row g-3" >
                    <div>
                        <label class="form-label" for="exampleFormControlInput1">Select Vehicle</label>
                        <select name="vehicle_id" id="vehicle_select" class="form-control" required>
                            <option value="">Choose a vehicle...</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>" data-current="<?php echo $v['current_mileage']; ?>" data-next="<?php echo $v['next_service'] ?? 0; ?>" <?php echo ($selectedVehicleId == $v['id']) ? 'selected' : ''; ?>>
                                    <?php echo sanitize($v['make'] . ' ' . $v['model']); ?> (<?php echo $v['year']; ?>) - <?php echo formatNumber($v['current_mileage']); ?> km
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="exampleFormControlInput1">New Mileage (km) </label>
                        <input type="number" name="mileage" id="mileage" class="form-control" required min="0" step="1" placeholder="Enter current odometer reading" disabled>
                        <p class="form-text" id="mileage-hint"></p>
                    </div>
                    <div id="service-warning" class="alert alert-warning alert-sticky" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="warning-text"></span>
                    </div>
                    <div>
                        <label class="form-label" for="exampleFormControlInput1">Notes (optional)</label>
                        <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="e.g., Monthly check..." disabled></textarea>
                    </div>
                    <button type="submit" class="btn rounded-sm-pill btn-outline-primary btn-sm"><i class="fas fa-save"></i> Update Mileage</button>
                </form>
            </div>
        </div>

    </div>
    <div class="col-lg-6 ps-lg-2 mb-3">
        <div class="card h-100">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-history"></i> Recent Updates</h6>
                    </div>
                </div>
            </div>
            <div class="card-body ps-2"><?php
                // Get mileage updates from both mileage_log and fuel_log
                $logs = $pdo->query("
                SELECT 
                    ml.mileage,
                    ml.log_date as update_date,
                    ml.source,
                    ml.created_at,
                    v.make,
                    v.model
                FROM mileage_log ml
                JOIN vehicles v ON ml.vehicle_id = v.id
                
                UNION ALL
                
                SELECT 
                    fl.mileage,
                    fl.fill_date as update_date,
                    'fuel' as source,
                    fl.created_at,
                    v.make,
                    v.model
                FROM fuel_log fl
                JOIN vehicles v ON fl.vehicle_id = v.id
                
                ORDER BY created_at DESC
                LIMIT 10
            ")->fetchAll();

                if (empty($logs)): ?>
                    <p class="text-muted text-center">No updates yet.</p>
                <?php else: ?>
                    <div>
                        <?php foreach ($logs as $log): ?>
                            <div class="row g-3 timeline timeline-primary timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <div class="icon-item icon-item-sm rounded-circle bg-soft-primary shadow-none">
                                            <i class="fas fa-<?php echo $log['source'] === 'fuel' ? 'gas-pump' : 'wrench'; ?> text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row gx-0 border-bottom pb-x1">
                                        <div class="col">
                                            <h6 class="text-800 mb-1"><?php echo sanitize($log['make'] . ' ' . $log['model']); ?></h6>
                                            <p class="fs-10 text-600 mb-0">
                                                <?php echo formatNumber($log['mileage']); ?> km
                                                <span class="badge rounded-pill ms-2 badge-subtle-<?php
                                                echo $log['source'] === 'service' ? 'success' :
                                                    ($log['source'] === 'fuel' ? 'warning' : 'info');
                                                ?>">
                                                <?php
                                                if ($log['source'] === 'fuel') {
                                                    echo 'Fuel Log';
                                                } else {
                                                    echo ucfirst($log['source']);
                                                }
                                                ?>
                                            </span>
                                            </p>
                                        </div>
                                        <div class="col-auto">
                                            <p class="fs-11 text-500 mb-0"><?php echo formatDate($log['update_date']); ?></p>
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

    <script>
        document.getElementById('vehicle_select').addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const mileageInput = document.getElementById('mileage');
            const notesInput = document.getElementById('notes');
            const hintElement = document.getElementById('mileage-hint');

            if (this.value) {
                const cur = parseInt(opt.dataset.current) || 0;
                const nxt = parseInt(opt.dataset.next) || 0;

                // Enable inputs
                mileageInput.disabled = false;
                notesInput.disabled = false;

                // Set min value
                mileageInput.min = cur;

                // Update hint to include next service if available
                let hintText = 'Must be ≥ ' + cur.toLocaleString() + ' km';
                if (nxt > 0) {
                    hintText += ' | Next service: ' + nxt.toLocaleString() + ' km';
                }
                hintElement.textContent = hintText;

                // Clear the mileage input when switching vehicles
                mileageInput.value = '';

                // Hide warning when switching vehicles
                document.getElementById('service-warning').style.display = 'none';
            } else {
                // Disable inputs
                mileageInput.disabled = true;
                notesInput.disabled = true;
                mileageInput.value = '';
                notesInput.value = '';
                hintElement.textContent = '';
                document.getElementById('service-warning').style.display = 'none';
            }
        });

        document.getElementById('mileage').addEventListener('input', function() {
            const sel = document.getElementById('vehicle_select');
            const opt = sel.options[sel.selectedIndex];
            if (!sel.value) return;
            const nxt = parseInt(opt.dataset.next) || 0;
            const val = parseInt(this.value) || 0;
            const warn = document.getElementById('service-warning');
            const txt = document.getElementById('warning-text');
            if (nxt > 0) {
                const rem = nxt - val;
                if (rem <= 0) { warn.style.display = 'flex'; warn.className = 'alert alert-danger alert-sticky'; txt.textContent = 'OVERDUE by ' + Math.abs(rem).toLocaleString() + ' km!'; }
                else if (rem <= 500) { warn.style.display = 'flex'; warn.className = 'alert alert-danger alert-sticky'; txt.textContent = 'URGENT: ' + rem.toLocaleString() + ' km left!'; }
                else if (rem <= 1000) { warn.style.display = 'flex'; warn.className = 'alert alert-warning alert-sticky'; txt.textContent = 'Service soon: ' + rem.toLocaleString() + ' km left'; }
                else { warn.style.display = 'none'; }
            }
        });
        <?php if ($selectedVehicleId): ?>document.getElementById('vehicle_select').dispatchEvent(new Event('change'));<?php endif; ?>
    </script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
