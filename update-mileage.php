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

// Optional odometer photo column — lazy-added the same defensive way
// DocumentExpiryService adds vehicle_documents.expiry_date, since mileage_log
// predates this app's lazy-table-creation convention.
function ensureOdometerPhotoColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $columnExists = $pdo->query("SHOW COLUMNS FROM mileage_log LIKE 'photo_path'")->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE mileage_log ADD COLUMN photo_path VARCHAR(255) NULL AFTER notes");
        }
        $checked = true;
    } catch (PDOException $e) {
        // mileage_log itself may not exist — nothing to do here.
    }
}
ensureOdometerPhotoColumn($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $new_mileage = (int)($_POST['mileage'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    $photoPath = null;

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

    // Odometer photo — optional proof of reading. Same server-side MIME
    // detection approach as vehicle-documents.php (never trust the browser's
    // declared $_FILES[...]['type']).
    if (empty($errors) && !empty($_FILES['odometer_photo']['name']) && $_FILES['odometer_photo']['error'] === UPLOAD_ERR_OK) {
        $photoFile = $_FILES['odometer_photo'];
        if ($photoFile['size'] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Odometer photo too large. Maximum size: ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.';
        } else {
            $mimeToExt = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];
            $detectedMime = mime_content_type($photoFile['tmp_name']);
            if (!array_key_exists($detectedMime, $mimeToExt)) {
                $errors[] = 'Unsupported photo type. Please use JPG, PNG, WEBP, HEIC or HEIF.';
            } else {
                $uploadDir = UPLOAD_DIR . 'odometer/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'odo_' . time() . '_' . uniqid() . '.' . $mimeToExt[$detectedMime];
                if (move_uploaded_file($photoFile['tmp_name'], $uploadDir . $filename)) {
                    $photoPath = $filename;
                } else {
                    $errors[] = 'Failed to upload odometer photo.';
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_mileage, $vehicle_id, $userId]);

            try {
                $stmt = $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source, notes, photo_path) VALUES (?, ?, CURDATE(), 'manual', ?, ?)");
                $stmt->execute([$vehicle_id, $new_mileage, $notes, $photoPath]);
            } catch (PDOException $e) {
                // photo_path column failed to add (e.g. no ALTER privilege) — save without it.
                $stmt = $pdo->prepare("INSERT INTO mileage_log (vehicle_id, mileage, log_date, source, notes) VALUES (?, ?, CURDATE(), 'manual', ?)");
                $stmt->execute([$vehicle_id, $new_mileage, $notes]);
            }

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
    <div class="card">
        <div class="card-body">
            <div class="empty-state text-center py-5">
                <i class="fas fa-car empty-state-icon fs-1 text-muted mb-3"></i>
                <h6 class="fs-9 mb-1">No vehicles added!</h6>
                <p class="fs-10 mb-3">Add your first vehicle to start tracking services.</p>
                <a href="add-vehicle" class="btn btn-outline-success">
                    <i class="fas fa-plus"></i> Add Vehicle
                </a>
            </div>
        </div>
    </div>
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
                    <div>
                        <label class="form-label">Odometer Photo (optional)</label>
                        <input type="file" name="odometer_photo" id="odometer_photo" class="form-control" accept="image/*">
                        <div class="form-text">Take a photo of the dashboard or choose one from your gallery as proof of this reading.</div>
                        <div id="odometer-photo-preview" class="mt-2 d-none">
                            <img src="" alt="Odometer preview" class="img-thumbnail" style="max-height:140px;">
                        </div>
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
                // Get mileage updates from both mileage_log and fuel_log — scoped to
                // the current user's own vehicles on both branches (previously this
                // query had no such scope and leaked every user's log entries).
                $logsStmt = $pdo->prepare("
                SELECT
                    ml.mileage,
                    ml.log_date as update_date,
                    ml.source,
                    ml.created_at,
                    ml.photo_path,
                    v.make,
                    v.model
                FROM mileage_log ml
                JOIN vehicles v ON ml.vehicle_id = v.id
                WHERE v.user_id = ?

                UNION ALL

                SELECT
                    fl.mileage,
                    fl.fill_date as update_date,
                    'fuel' as source,
                    fl.created_at,
                    NULL as photo_path,
                    v.make,
                    v.model
                FROM fuel_log fl
                JOIN vehicles v ON fl.vehicle_id = v.id
                WHERE v.user_id = ?

                ORDER BY created_at DESC
                LIMIT 10
            ");
                try {
                    $logsStmt->execute([$userId, $userId]);
                    $logs = $logsStmt->fetchAll();
                } catch (PDOException $e) {
                    // mileage_log.photo_path may not exist if the ALTER above couldn't run — retry without it.
                    $logsStmt = $pdo->prepare(str_replace("ml.photo_path,", "NULL as photo_path,", $logsStmt->queryString));
                    $logsStmt->execute([$userId, $userId]);
                    $logs = $logsStmt->fetchAll();
                }

                if (empty($logs)): ?>
                    <p class="text-muted text-center">No updates yet.</p>
                <?php else: ?>
                    <div>
                        <?php foreach ($logs as $log): ?>
                            <div class="row g-3 timeline timeline-primary timeline-current pb-x1">
                                <div class="col-auto ps-4 ms-2">
                                    <div class="ps-2">
                                        <?php if (!empty($log['photo_path'])): ?>
                                            <a href="uploads/odometer/<?php echo rawurlencode($log['photo_path']); ?>" target="_blank" title="View odometer photo">
                                                <img src="uploads/odometer/<?php echo rawurlencode($log['photo_path']); ?>" class="rounded-circle icon-item-sm" style="width:32px;height:32px;object-fit:cover;" alt="Odometer photo">
                                            </a>
                                        <?php else: ?>
                                            <div class="icon-item icon-item-sm rounded-circle bg-soft-primary shadow-none">
                                                <i class="fas fa-<?php echo $log['source'] === 'fuel' ? 'gas-pump' : 'wrench'; ?> text-primary"></i>
                                            </div>
                                        <?php endif; ?>
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

        document.getElementById('odometer_photo').addEventListener('change', function () {
            const preview = document.getElementById('odometer-photo-preview');
            const img = preview.querySelector('img');
            const file = this.files && this.files[0];
            if (!file) {
                preview.classList.add('d-none');
                return;
            }
            const reader = new FileReader();
            reader.onload = function (e) {
                img.src = e.target.result;
                preview.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
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
