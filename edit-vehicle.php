<?php
$pageTitle = 'Edit Vehicle';
require_once 'includes/header.php';

use App\Helpers\IdCodec;
use App\Services\VehicleExportService;
use App\Services\EmailService;

// Get vehicle ID
$vehicleId = IdCodec::decode($_GET['id'] ?? null) ?? 0;

// Fetch vehicle
$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$vehicleId, $userId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setFlashMessage('error', 'Vehicle not found!');
    redirect('vehicles');
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $entered_plate = sanitize($_POST['confirm_plate'] ?? '');
        $vehicle_plate = $vehicle['license_plate'] ?? '';

        if ($entered_plate === $vehicle_plate) {
            // Email a full backup export (data + files) before deleting, as a safety net
            $export = (new VehicleExportService($pdo))->buildZip($vehicleId, $userId);
            if ($export && !empty($currentUser['email'])) {
                try {
                    (new EmailService($pdo))->sendVehicleExportEmail($vehicleId, $userId, $export['path'], $export['filename']);
                } catch (\Throwable $e) {
                    error_log('Vehicle export email failed: ' . $e->getMessage());
                }
                @unlink($export['path']);
            }

            // Delete vehicle image if exists
            if (!empty($vehicle['image_path']) && file_exists(UPLOAD_DIR . $vehicle['image_path'])) {
                unlink(UPLOAD_DIR . $vehicle['image_path']);
            }

            // Delete uploaded documents & photos for this vehicle
            $docStmt = $pdo->prepare("SELECT file_path FROM vehicle_documents WHERE vehicle_id = ?");
            $docStmt->execute([$vehicleId]);
            foreach ($docStmt->fetchAll() as $doc) {
                $docFile = UPLOAD_DIR . 'documents/' . $doc['file_path'];
                if (file_exists($docFile)) {
                    unlink($docFile);
                }
            }

            // Delete vehicle
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
            $stmt->execute([$vehicleId, $userId]);

            setFlashMessage('success', 'Vehicle deleted successfully!');
            redirect('vehicles');
        } else {
            $errors[] = 'License plate does not match. Vehicle not deleted.';
        }
    }
}

// Handle update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_vehicle'])) {
    $make = sanitize($_POST['make'] ?? '');
    $model = sanitize($_POST['model'] ?? '');
    $year = (int)($_POST['year'] ?? date('Y'));
    $license_plate = sanitize($_POST['license_plate'] ?? '');
    $vin = sanitize($_POST['vin'] ?? '');
    $color = sanitize($_POST['color'] ?? '');
    $fuel_type = sanitize($_POST['fuel_type'] ?? 'petrol');
    $transmission = sanitize($_POST['transmission'] ?? 'manual');
    $engine_capacity = sanitize($_POST['engine_capacity'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? null;
    $purchase_mileage = (int)($_POST['purchase_mileage'] ?? 0);
    $current_mileage = (int)($_POST['current_mileage'] ?? $purchase_mileage);
    $notes = $_POST['notes'] ?? '';

    // Validate
    $errors = [];
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) $errors[] = 'Invalid security token. Please try again.';
    if (empty($make)) $errors[] = 'Make is required';
    if (empty($model)) $errors[] = 'Model is required';
    if ($year < 1900 || $year > date('Y') + 1) $errors[] = 'Invalid year';

    // Handle image upload
    $image_path = $vehicle['image_path'] ?? null; // Keep existing image by default
    $delete_old_image = false;

    if (isset($_FILES['vehicle_image']) && $_FILES['vehicle_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['vehicle_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($file['type'], $allowed)) {
            $errors[] = 'Invalid image type. Allowed: JPG, PNG, GIF, WebP';
        } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Image too large. Maximum size: 10MB';
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'vehicle_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = UPLOAD_DIR . $filename;

            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (!is_writable(UPLOAD_DIR)) {
                $errors[] = 'Upload directory is not writable. Please check permissions.';
            } else {
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $delete_old_image = true; // Mark old image for deletion
                    $image_path = $filename;
                } else {
                    $errors[] = 'Failed to upload image';
                }
            }
        }
    }

    // Check if user wants to remove image
    if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
        $delete_old_image = true;
        $image_path = null;
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE vehicles 
                SET make = ?, model = ?, year = ?, license_plate = ?, vin = ?, color = ?, 
                    fuel_type = ?, transmission = ?, engine_capacity = ?, purchase_date = ?, 
                    purchase_mileage = ?, current_mileage = ?, image_path = ?, notes = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $make, $model, $year, $license_plate, $vin, $color, $fuel_type,
                $transmission, $engine_capacity, $purchase_date ?: null, $purchase_mileage,
                $current_mileage, $image_path, $notes, $vehicleId, $userId
            ]);

            // Delete old image if new one uploaded or removed
            if ($delete_old_image && !empty($vehicle['image_path']) && file_exists(UPLOAD_DIR . $vehicle['image_path'])) {
                unlink(UPLOAD_DIR . $vehicle['image_path']);
            }

            setFlashMessage('success', 'Vehicle updated successfully!');
            redirect('vehicle-details?id=' . IdCodec::encode($vehicleId));
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
                            <h4 class="fs-6">Edit Vehicle</h4>
                            <p class="mb-0 fs-10"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?> (<?php echo $vehicle['year']; ?>)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-outline-secondary btn-sm me-2"  role="button">
                        <i class="fas fa-arrow-left"></i> Back to Vehicle
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Vehicle Information</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error!</strong>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" data-validate class="row g-3" id="vehicleForm">
                <?php echo csrfField(); ?>
                <!-- Vehicle Image Upload -->
                <div class="col-12">
                    <label class="form-label">Vehicle Image</label>

                    <!-- Current Image Preview -->
                    <?php if (!empty($vehicle['image_path']) && file_exists(UPLOAD_DIR . $vehicle['image_path'])): ?>
                        <div class="mb-3" id="currentImagePreview">
                            <div class="position-relative d-inline-block">
                                <img src="uploads/<?php echo htmlspecialchars($vehicle['image_path']); ?>"
                                     alt="Current vehicle"
                                     class="img-thumbnail"
                                     style="max-width: 300px; max-height: 300px; object-fit: cover;">
                                <button type="button"
                                        class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2"
                                        id="removeCurrentImage"
                                        title="Remove current image">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <p class="text-muted fs-10 mt-2">
                                <i class="fas fa-info-circle"></i> Current image will be replaced if you upload a new one
                            </p>
                            <input type="hidden" name="remove_image" id="removeImageFlag" value="0">
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> No image currently uploaded for this vehicle
                        </div>
                    <?php endif; ?>

                    <!-- Dropzone for new image -->
                    <div id="vehicleImageDropzone" class="dropzone">
                        <div class="dz-message needsclick">
                            <i class="fas fa-cloud-upload-alt fs-3 text-muted"></i>
                            <h6 class="mt-2">Drop new vehicle image here or click to upload</h6>
                            <span class="text-muted fs--1">Maximum file size: 10MB (JPG, PNG, GIF, WebP)</span>
                        </div>
                    </div>
                </div>

                <!-- Basic Info -->
                <div class="col-md-4">
                    <label class="form-label" for="inputMake">Make <span class="text-danger">*</span></label>
                    <input type="text" name="make" id="inputMake" class="form-control" required placeholder="e.g., Toyota, Honda, BMW" value="<?php echo htmlspecialchars($vehicle['make'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputModel">Model <span class="text-danger">*</span></label>
                    <input type="text" name="model" id="inputModel" class="form-control" required placeholder="e.g., Camry, Civic, 3 Series" value="<?php echo htmlspecialchars($vehicle['model'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputYear">Year <span class="text-danger">*</span></label>
                    <input type="number" name="year" id="inputYear" class="form-control" required min="1900" max="<?php echo date('Y') + 1; ?>" value="<?php echo $vehicle['year'] ?? date('Y'); ?>">
                </div>

                <!-- Identification -->
                <div class="col-md-4">
                    <label class="form-label" for="inputLicensePlate">License Plate</label>
                    <input type="text" name="license_plate" id="inputLicensePlate" class="form-control" placeholder="e.g., ABC-1234" value="<?php echo htmlspecialchars($vehicle['license_plate'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputVin">VIN (Vehicle Identification Number)</label>
                    <input type="text" name="vin" id="inputVin" class="form-control" placeholder="17-character VIN" value="<?php echo htmlspecialchars($vehicle['vin'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputColor">Color</label>
                    <input type="text" name="color" id="inputColor" class="form-control" placeholder="e.g., Silver, Black, White" value="<?php echo htmlspecialchars($vehicle['color'] ?? ''); ?>">
                </div>

                <!-- Technical Details -->
                <div class="col-md-4">
                    <label class="form-label" for="inputFuelType">Fuel Type</label>
                    <select name="fuel_type" id="inputFuelType" class="form-control">
                        <option value="petrol" <?php echo ($vehicle['fuel_type'] ?? '') === 'petrol' ? 'selected' : ''; ?>>Petrol</option>
                        <option value="diesel" <?php echo ($vehicle['fuel_type'] ?? '') === 'diesel' ? 'selected' : ''; ?>>Diesel</option>
                        <option value="hybrid" <?php echo ($vehicle['fuel_type'] ?? '') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                        <option value="electric" <?php echo ($vehicle['fuel_type'] ?? '') === 'electric' ? 'selected' : ''; ?>>Electric</option>
                        <option value="other" <?php echo ($vehicle['fuel_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputTransmission">Transmission</label>
                    <select name="transmission" id="inputTransmission" class="form-control">
                        <option value="manual" <?php echo ($vehicle['transmission'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option>
                        <option value="automatic" <?php echo ($vehicle['transmission'] ?? '') === 'automatic' ? 'selected' : ''; ?>>Automatic</option>
                        <option value="cvt" <?php echo ($vehicle['transmission'] ?? '') === 'cvt' ? 'selected' : ''; ?>>CVT</option>
                        <option value="other" <?php echo ($vehicle['transmission'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputEngineCapacity">Engine Capacity</label>
                    <input type="text" name="engine_capacity" id="inputEngineCapacity" class="form-control" placeholder="e.g., 1.8L, 2.0L, 3.5L" value="<?php echo htmlspecialchars($vehicle['engine_capacity'] ?? ''); ?>">
                </div>

                <!-- Purchase Info -->
                <div class="col-md-4">
                    <label class="form-label" for="inputPurchaseDate">Purchase Date</label>
                    <input type="date" name="purchase_date" id="inputPurchaseDate" class="form-control" value="<?php echo $vehicle['purchase_date'] ?? ''; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputPurchaseMileage">Purchase Mileage (km)</label>
                    <input type="number" name="purchase_mileage" id="inputPurchaseMileage" class="form-control" min="0" step="1" placeholder="Mileage when purchased" value="<?php echo $vehicle['purchase_mileage'] ?? '0'; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="inputCurrentMileage">Current Mileage (km)</label>
                    <input type="number" name="current_mileage" id="inputCurrentMileage" class="form-control" min="0" step="1" placeholder="Current odometer reading" value="<?php echo $vehicle['current_mileage'] ?? ''; ?>">
                    <p class="form-text">Current odometer reading</p>
                </div>

                <!-- Notes -->
                <div class="col-12 mb-2">
                    <label class="form-label" for="inputNotes">Notes</label>
                    <textarea class="tinymce" name="notes" id="inputNotes" rows="5" placeholder="Any additional notes about this vehicle..."><?php echo htmlspecialchars($vehicle['notes'] ?? ''); ?></textarea>
                </div>
            </form>
        </div>
    </div>

    <!-- Submit Section -->
    <div class="card mt-3">
        <div class="card-body">
            <div class="row justify-content-between align-items-center">
                <div class="col-md">
                    <h6 class="mb-2 mb-md-0">Update vehicle information</h6>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-danger btn-sm me-2" data-bs-toggle="modal" data-bs-target="#deleteVehicleModal">
                        <i class="fas fa-trash"></i> Delete Vehicle
                    </button>
                    <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-falcon-default btn-sm me-2">Cancel</a>
                    <button class="btn btn-outline-primary btn-sm" type="submit" form="vehicleForm">
                        <i class="fas fa-save"></i> Update Vehicle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteVehicleModal" tabindex="-1" aria-labelledby="deleteVehicleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteVehicleModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> Delete Vehicle
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="deleteVehicleForm">
                    <?php echo csrfField(); ?>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-circle"></i>
                            <strong>Warning:</strong> This action cannot be undone. All service records associated with this vehicle will also be deleted.
                        </div>

                        <p class="mb-3">Are you sure you want to delete this vehicle?</p>

                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="mb-2"><?php echo htmlspecialchars(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')); ?></h6>
                                <p class="mb-0 text-muted fs-10">
                                    Year: <?php echo $vehicle['year'] ?? 'N/A'; ?>
                                    <?php if (!empty($vehicle['license_plate'])): ?>
                                        &bull; Plate: <strong><?php echo htmlspecialchars($vehicle['license_plate']); ?></strong>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <?php if (!empty($vehicle['license_plate'])): ?>
                            <p class="mb-2">To confirm deletion, please enter the license plate number below:</p>

                            <div class="input-group mb-2">
                                <input type="text"
                                       class="form-control"
                                       name="confirm_plate"
                                       id="confirmPlateInput"
                                       placeholder="Enter license plate"
                                       required
                                       autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="copyPlateBtn" title="Copy license plate">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>

                            <input type="hidden" id="vehiclePlate" value="<?php echo htmlspecialchars($vehicle['license_plate'] ?? ''); ?>">

                            <p class="text-muted fs-10 mb-0">
                                <i class="fas fa-info-circle"></i>
                                Expected: <strong id="expectedPlate"><?php echo htmlspecialchars($vehicle['license_plate']); ?></strong>
                            </p>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> This vehicle has no license plate. Click "Delete Vehicle" to confirm deletion.
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="delete_vehicle" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm" id="confirmDeleteBtn">
                            <i class="fas fa-trash"></i> Delete Vehicle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Dropzone
            Dropzone.autoDiscover = false;

            var vehicleDropzone = new Dropzone("#vehicleImageDropzone", {
                url: "#",
                autoProcessQueue: false,
                maxFiles: 1,
                maxFilesize: 10,
                acceptedFiles: "image/jpeg,image/png,image/gif,image/webp",
                addRemoveLinks: true,
                dictRemoveFile: "Delete",
                dictDefaultMessage: "Drop new vehicle image here or click to upload",

                init: function() {
                    var dropzone = this;
                    var form = document.getElementById('vehicleForm');

                    form.addEventListener('submit', function(e) {
                        var files = dropzone.getAcceptedFiles();

                        if (files.length > 0) {
                            var fileInput = document.createElement('input');
                            fileInput.type = 'file';
                            fileInput.name = 'vehicle_image';
                            fileInput.style.display = 'none';

                            var dataTransfer = new DataTransfer();
                            dataTransfer.items.add(files[0]);
                            fileInput.files = dataTransfer.files;

                            form.appendChild(fileInput);
                        }
                    });

                    this.on("addedfile", function(file) {
                        if (this.files.length > 1) {
                            this.removeFile(this.files[0]);
                        }
                        // Dim current image when new one is added
                        var currentPreview = document.getElementById('currentImagePreview');
                        if (currentPreview) {
                            currentPreview.style.opacity = '0.5';
                        }
                    });

                    this.on("removedfile", function(file) {
                        var fileInput = form.querySelector('input[name="vehicle_image"]');
                        if (fileInput) {
                            fileInput.remove();
                        }
                        // Restore current image opacity when new one is removed
                        var currentPreview = document.getElementById('currentImagePreview');
                        if (currentPreview) {
                            currentPreview.style.opacity = '1';
                        }
                    });
                }
            });

            // Remove current image button
            var removeCurrentImageBtn = document.getElementById('removeCurrentImage');
            if (removeCurrentImageBtn) {
                removeCurrentImageBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to remove the current image?')) {
                        document.getElementById('removeImageFlag').value = '1';
                        document.getElementById('currentImagePreview').remove();
                    }
                });
            }

            // Copy license plate functionality
            var copyPlateBtn = document.getElementById('copyPlateBtn');
            if (copyPlateBtn) {
                copyPlateBtn.addEventListener('click', function() {
                    var plate = document.getElementById('vehiclePlate').value;
                    var input = document.getElementById('confirmPlateInput');

                    // Copy to clipboard
                    navigator.clipboard.writeText(plate).then(function() {
                        // Paste into input field
                        input.value = plate;

                        // Visual feedback
                        var icon = copyPlateBtn.querySelector('i');
                        icon.classList.remove('fa-copy');
                        icon.classList.add('fa-check');
                        copyPlateBtn.classList.add('btn-success');
                        copyPlateBtn.classList.remove('btn-outline-secondary');

                        setTimeout(function() {
                            icon.classList.remove('fa-check');
                            icon.classList.add('fa-copy');
                            copyPlateBtn.classList.remove('btn-success');
                            copyPlateBtn.classList.add('btn-outline-secondary');
                        }, 2000);
                    }).catch(function(err) {
                        // Fallback: just paste into input
                        input.value = plate;
                        alert('License plate pasted into field');
                    });
                });
            }

            // Validate license plate before delete
            var deleteForm = document.getElementById('deleteVehicleForm');
            if (deleteForm) {
                deleteForm.addEventListener('submit', function(e) {
                    var confirmPlateInput = document.getElementById('confirmPlateInput');

                    // Only validate if license plate input exists
                    if (confirmPlateInput) {
                        var enteredPlate = confirmPlateInput.value.trim();
                        var actualPlate = document.getElementById('vehiclePlate').value;

                        if (enteredPlate !== actualPlate) {
                            e.preventDefault();
                            alert('License plate does not match. Please enter the correct license plate to delete this vehicle.');
                            confirmPlateInput.focus();
                            return false;
                        }
                    }

                    // Final confirmation
                    if (!confirm('Are you absolutely sure you want to delete this vehicle? This action cannot be undone.')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Initialize TinyMCE
            if (typeof tinymce !== 'undefined') {
                tinymce.init({
                    selector: 'textarea.tinymce',
                    height: 200,
                    menubar: false,
                    plugins: [
                        'advlist', 'autolink', 'lists', 'link', 'charmap', 'preview',
                        'searchreplace', 'visualblocks', 'code', 'fullscreen',
                        'insertdatetime', 'table', 'help', 'wordcount'
                    ],
                    toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | link | code',
                    content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; }'
                });
            }
        });
    </script>

<?php require_once 'includes/footer.php'; ?>