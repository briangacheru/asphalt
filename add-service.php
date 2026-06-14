<?php
require_once 'includes/config.php';
require_once 'includes/EmailHelper.php';
requireAuth();

$pdo = getDBConnection();
$userId = getCurrentUserId();

// Get all active vehicles
$vehiclesStmt = $pdo->query("SELECT id, make, model, year, license_plate, current_mileage FROM vehicles WHERE is_active = 1 ORDER BY make, model");
$vehicles = $vehiclesStmt->fetchAll();

// Pre-selected vehicle
$selectedVehicleId = $_GET['vehicle_id'] ?? null;
$selectedVehicle = null;
if ($selectedVehicleId) {
    foreach ($vehicles as $v) {
        if ($v['id'] == $selectedVehicleId) {
            $selectedVehicle = $v;
            break;
        }
    }
}

// Oil intervals
$oilIntervals = unserialize(OIL_INTERVALS);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $service_date = $_POST['service_date'] ?? date('Y-m-d');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $mileage_source = sanitize($_POST['mileage_source'] ?? 'manual');
    $oil_interval = (int)($_POST['oil_interval'] ?? 7500);
    $service_cost = (float)($_POST['service_cost'] ?? 0);
    $service_location = sanitize($_POST['service_location'] ?? '');
    $technician_name = sanitize($_POST['technician_name'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    $errors = [];

    if (!$vehicle_id) $errors[] = 'Please select a vehicle';
    if (!$mileage || $mileage < 0) $errors[] = 'Please enter a valid mileage';
    if (!in_array($oil_interval, $oilIntervals)) $errors[] = 'Please select a valid oil interval';

    // Validate mileage against current
    if ($vehicle_id) {
        $stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $vehicleData = $stmt->fetch();
        if ($vehicleData && $mileage < $vehicleData['current_mileage']) {
            $errors[] = 'Mileage cannot be less than current mileage (' . formatNumber($vehicleData['current_mileage']) . ' km)';
        }
    }

    // Handle dashboard image upload
    $dashboard_image = null;
    if (isset($_FILES['dashboard_image']) && $_FILES['dashboard_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['dashboard_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($file['type'], $allowed)) {
            $errors[] = 'Invalid image type for dashboard photo';
        } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Dashboard image too large (max 10MB)';
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'dashboard_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = UPLOAD_DIR . $filename;

            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $dashboard_image = $filename;
            } else {
                $errors[] = 'Failed to upload dashboard image';
            }
        }
    }

    if (empty($errors)) {
        try {
            $next_service_mileage = $mileage + $oil_interval;

            $stmt = $pdo->prepare("
                INSERT INTO service_records (vehicle_id, service_date, mileage, dashboard_image, 
                                            mileage_source, oil_interval, next_service_mileage,
                                            service_cost, service_location, technician_name, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vehicle_id, $service_date, $mileage, $dashboard_image,
                $mileage_source, $oil_interval, $next_service_mileage,
                $service_cost, $service_location, $technician_name, $notes
            ]);

            $serviceRecordId = $pdo->lastInsertId();

            // Update vehicle's current mileage
            $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
            $stmt->execute([$mileage, $vehicle_id]);

            // Log mileage
            $stmt = $pdo->prepare("
                INSERT INTO mileage_log (vehicle_id, mileage, log_date, source)
                VALUES (?, ?, ?, 'service')
            ");
            $stmt->execute([$vehicle_id, $mileage, $service_date]);

            // Send email asking for service details
            $emailHelper = new EmailHelper($pdo);
            $emailHelper->sendServiceDetailsEmail($serviceRecordId);

            setFlashMessage('success', 'Service record added successfully! Check your email for a reminder to add service item details.');
            redirect('service-items.php?service_id=' . $serviceRecordId);

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Add Service Record';
require_once 'includes/header.php';
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
                            <h4 class="fs-6">Add Service</h4>
                            <p class="mb-0 fs-10">Log a service with mileage and oil interval.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <a class="btn btn-outline-secondary btn-sm me-2" href="service-history.php" role="button">
                        <i class="fas fa-history"></i> Service History
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>Please fix the following errors:</strong>
            <ul style="margin: 0.5rem 0 0 1rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?php if (empty($vehicles)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <i class="fas fa-car empty-state-icon text-secondary"></i>
                <h6 class="fs-9 mb-1">No Vehicles Found!</h6>
                <p class="fs-10 mb-0">You need to add a vehicle before recording a service.</p>
                <a href="add-vehicle.php" class="btn btn-outline--primary btn-sm">
                    <i class="fas fa-plus"></i> Add Vehicle First
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="POST" enctype="multipart/form-data" id="serviceForm">

        <!-- Step 1: Select Vehicle -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="badge rounded-pill ms-2 badge-subtle-secondary" style="margin-right: 0.5rem;">1</span>
                    Select Vehicle
                </h3>
            </div>
            <div class="card-body">
                <input type="hidden" name="vehicle_id" id="vehicle_id" value="<?php echo $selectedVehicleId ?? ''; ?>" required>

                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-3">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <div class="col">
                            <div class="vehicle-card card h-100 position-relative <?php echo ($selectedVehicleId == $vehicle['id']) ? 'selected' : ''; ?>"
                                 data-vehicle-id="<?php echo $vehicle['id']; ?>"
                                 role="button"
                                 tabindex="0">
                                <div class="card-body">
                                    <h4 class="card-title h6 mb-2 fw-bold vehicle-name">
                                        <?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?>
                                    </h4>
                                    <p class="card-text mb-2">
                                        <?php echo $vehicle['year']; ?>
                                        <?php if ($vehicle['license_plate']): ?>
                                            &bull; <?php echo sanitize($vehicle['license_plate']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-tachometer-alt me-1"></i>
                                        Current: <strong id="current-mileage-<?php echo $vehicle['id']; ?>"
                                                         data-value="<?php echo $vehicle['current_mileage']; ?>">
                                            <?php echo formatNumber($vehicle['current_mileage']); ?>
                                        </strong> km
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="selected-vehicle-info" class="alert alert-success d-flex align-items-center gap-2 mt-3 <?php echo $selectedVehicle ? '' : 'd-none'; ?>">
                    <i class="fas fa-check-circle"></i>
                    <span>
                        <?php if ($selectedVehicle): ?>
                            Selected: <strong><?php echo sanitize($selectedVehicle['make'] . ' ' . $selectedVehicle['model']); ?></strong>
                            (Current mileage: <?php echo formatNumber($selectedVehicle['current_mileage']); ?> km)
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Step 2: Record Mileage -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="badge rounded-pill ms-2 badge-subtle-secondary" style="margin-right: 0.5rem;">2</span>
                    Record Mileage
                </h3>
            </div>
            <div class="row card-body">
                <div class="col-md-6">
                    <label class="form-label" for="service_date">Service Date</label>
                    <input type="date" name="service_date" id="service_date" class="form-control requires-vehicle" required value="<?php echo $_POST['service_date'] ?? date('Y-m-d'); ?>" <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mileage">Mileage (km)</label>
                    <input type="number" name="mileage" id="mileage" class="form-control requires-vehicle" required min="0" step="1" placeholder="Enter current odometer reading" value="<?php echo $_POST['mileage'] ?? ''; ?>" <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                    <input type="hidden" name="mileage_source" id="mileage_source" value="manual">
                    <p class="form-text" id="current-mileage">
                        <?php if ($selectedVehicle): ?>
                            Current mileage: <strong><?php echo formatNumber($selectedVehicle['current_mileage']); ?></strong> km
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Step 3: Oil Interval -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title h5 mb-1">
                    <span class="badge rounded-pill ms-2 badge-subtle-secondary">3</span>
                    Select Oil Service Interval
                </h3>
                <span class="text-muted small">How many km until next oil change?</span>
            </div>
            <div class="card-body">
                <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 g-3">
                    <?php foreach ($oilIntervals as $interval): ?>
                        <div class="col">
                            <input type="radio"
                                   name="oil_interval"
                                   id="interval_<?php echo $interval; ?>"
                                   value="<?php echo $interval; ?>"
                                   class="btn-check requires-vehicle"
                                   required
                                <?php echo ($interval == ($_POST['oil_interval'] ?? 7500)) ? 'checked' : ''; ?>
                                <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                            <label class="btn btn-outline-success w-100 h-100 d-flex flex-column justify-content-center align-items-center py-3"
                                   for="interval_<?php echo $interval; ?>">
                                <span class="fw-bold fs-5 mb-1"><?php echo number_format($interval); ?></span>
                                <span class="small text-muted">kilometers</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-info d-flex align-items-center gap-2 mt-3 mb-0">
                    <i class="fas fa-calculator"></i>
                    <span>
                Next service will be due at:
                <strong id="next-service-display">-- km</strong>
            </span>
                </div>
            </div>
        </div>

        <!-- Step 4: Additional Details -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="badge rounded-pill ms-2 badge-subtle-secondary" style="margin-right: 0.5rem;">4</span>
                    Additional Details (Optional)
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label" for="service_cost">Total Service Cost</label>
                        <input type="number" name="service_cost" id="service_cost" class="form-control requires-vehicle" step="1" min="0" placeholder="e.g., 1500.00" value="<?php echo $_POST['service_cost'] ?? ''; ?>" <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="service_location">Service Location</label>
                        <input type="text" name="service_location" id="service_location" class="form-control requires-vehicle" placeholder="e.g., ABC Auto Workshop" value="<?php echo $_POST['service_location'] ?? ''; ?>" <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="technician_name">Technician Name</label>
                        <input type="text" name="technician_name" id="technician_name" class="form-control requires-vehicle" placeholder="Mechanic's name" value="<?php echo $_POST['technician_name'] ?? ''; ?>" <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea name="notes" id="notes" class="form-control requires-vehicle" rows="3"
                              placeholder="Any additional notes about this service..."
                          <?php echo !$selectedVehicleId ? 'disabled' : ''; ?>><?php echo $_POST['notes'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="card mt-3" id="submitSection" style="display: none;">
            <div class="card-body">
                <div class="card">
                    <div class="row justify-content-between align-items-center">
                        <div class="col-md">
                            <h6 class="mb-2 mb-md-0">Nice Job! You're almost done</h6>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-falcon-default btn-sm me-2" id="clearFormBtn">Clear</button>
                            <button class="btn btn-outline-primary btn-sm" type="submit" id="submitBtn"><i class="fas fa-save"></i> Save Service Record</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const vehicleCards = document.querySelectorAll('.vehicle-card');
            const selectedVehicleInfo = document.getElementById('selected-vehicle-info');
            const vehicleIdInput = document.getElementById('vehicle_id');
            const submitSection = document.getElementById('submitSection');
            const clearFormBtn = document.getElementById('clearFormBtn');

            // Vehicle selection handler
            vehicleCards.forEach(card => {
                card.addEventListener('click', function() {
                    const vehicleId = this.dataset.vehicleId;

                    // Remove selected class from all cards
                    vehicleCards.forEach(c => c.classList.remove('selected'));

                    // Add selected class to clicked card
                    this.classList.add('selected');

                    // Update hidden input
                    vehicleIdInput.value = vehicleId;

                    // Enable form fields
                    document.querySelectorAll('.requires-vehicle').forEach(field => {
                        field.disabled = false;
                    });

                    // Update current mileage display
                    const mileageEl = document.getElementById('current-mileage-' + vehicleId);
                    const currentMileage = mileageEl ? mileageEl.dataset.value : 0;

                    document.getElementById('current-mileage').innerHTML =
                        'Current mileage: <strong>' + parseInt(currentMileage).toLocaleString() + '</strong> km';

                    // Update mileage input minimum
                    document.getElementById('mileage').min = currentMileage;

                    // Show selected vehicle info
                    selectedVehicleInfo.style.display = 'flex';
                    selectedVehicleInfo.classList.remove('d-none');
                    selectedVehicleInfo.querySelector('span').innerHTML =
                        'Selected: <strong>' + this.querySelector('.vehicle-name').textContent.trim() + '</strong> ' +
                        '(Current mileage: ' + parseInt(currentMileage).toLocaleString() + ' km)';

                    // Update next service calculation
                    updateNextServiceMileage();

                    // Check if form is valid
                    checkFormValidity();
                });
            });

            // Calculate next service mileage
            function updateNextServiceMileage() {
                const mileageInput = document.getElementById('mileage');
                const intervalInput = document.querySelector('input[name="oil_interval"]:checked');
                const display = document.getElementById('next-service-display');

                if (mileageInput && mileageInput.value && intervalInput) {
                    const current = parseInt(mileageInput.value) || 0;
                    const interval = parseInt(intervalInput.value) || 0;
                    display.textContent = (current + interval).toLocaleString() + ' km';
                } else {
                    display.textContent = '-- km';
                }
            }

            // Form validation - show submit button only when required fields are filled
            function checkFormValidity() {
                const vehicleId = document.getElementById('vehicle_id').value;
                const serviceDate = document.getElementById('service_date').value;
                const mileage = document.getElementById('mileage').value;
                const oilInterval = document.querySelector('input[name="oil_interval"]:checked');

                // Check if all required fields have values
                if (vehicleId && serviceDate && mileage && oilInterval) {
                    submitSection.style.display = 'block';
                } else {
                    submitSection.style.display = 'none';
                }
            }

            // Add event listeners to required fields
            document.getElementById('mileage').addEventListener('input', function() {
                updateNextServiceMileage();
                checkFormValidity();
            });

            document.getElementById('service_date').addEventListener('change', checkFormValidity);

            document.querySelectorAll('input[name="oil_interval"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    updateNextServiceMileage();
                    checkFormValidity();
                });
            });

            // Clear form button
            clearFormBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to clear the form? All entered data will be lost.')) {
                    document.getElementById('serviceForm').reset();
                    vehicleCards.forEach(c => c.classList.remove('selected'));
                    selectedVehicleInfo.classList.add('d-none');
                    submitSection.style.display = 'none';
                    document.getElementById('vehicle_id').value = '';
                    document.querySelectorAll('.requires-vehicle').forEach(field => {
                        field.disabled = true;
                    });
                    document.getElementById('next-service-display').textContent = '-- km';
                }
            });

            // Initialize if vehicle pre-selected
            <?php if ($selectedVehicle): ?>
            updateNextServiceMileage();
            checkFormValidity();
            <?php endif; ?>
        });
    </script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>