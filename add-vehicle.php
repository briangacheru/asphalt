<?php
require_once 'includes/bootstrap.php';
\App\Middleware\AuthMiddleware::check();

$pdo = \App\Database\Database::getInstance()->getConnection();
$userId = \App\Middleware\AuthMiddleware::getCurrentUserId();

// Handle form submission BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $notes = sanitize($_POST['notes'] ?? '');

    // Validate
    $errors = [];
    if (empty($make)) $errors[] = 'Make is required';
    if (empty($model)) $errors[] = 'Model is required';
    if ($year < 1900 || $year > date('Y') + 1) $errors[] = 'Invalid year';

    // Handle image upload
    $image_path = null;
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

            // Check if directory exists and is writable
            if (!is_dir(UPLOAD_DIR)) {
                if (!mkdir(UPLOAD_DIR, 0755, true)) {
                    $errors[] = 'Failed to create upload directory';
                }
            }

            if (!is_writable(UPLOAD_DIR)) {
                $errors[] = 'Upload directory is not writable. Please check permissions.';
            } else {
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $image_path = $filename;
                } else {
                    $errors[] = 'Failed to upload image';
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vehicles (user_id, make, model, year, license_plate, vin, color, fuel_type, 
                                     transmission, engine_capacity, purchase_date, purchase_mileage, 
                                     current_mileage, image_path, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $make, $model, $year, $license_plate, $vin, $color, $fuel_type,
                $transmission, $engine_capacity, $purchase_date ?: null, $purchase_mileage,
                $current_mileage, $image_path, $notes
            ]);

            setFlashMessage('success', 'Vehicle added successfully!');
            redirect('vehicles.php');
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// NOW include header - after form processing
$pageTitle = 'Add Vehicle';
require_once 'includes/header.php';
?>

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
                        <h4 class="fs-6">Add New Vehicle</h4>
                        <p class="mb-0 fs-10">Register a new vehicle to track its services.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
                <a class="btn btn-outline-secondary btn-sm me-2" href="vehicles.php" role="button">
                    <i class="fas fa-arrow-left"></i> Back to Vehicles
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
        <form method="POST" enctype="multipart/form-data" data-validate class="row g-3" id="vehicleForm">
            <!-- Vehicle Image Upload -->
            <div class="col-12">
                <label class="form-label">Vehicle Image</label>
                <div id="vehicleImageDropzone" class="dropzone">
                    <div class="dz-message needsclick">
                        <i class="fas fa-cloud-upload-alt fs-3 text-muted"></i>
                        <h6 class="mt-2">Drop vehicle image here or click to upload</h6>
                        <span class="text-muted fs--1">Maximum file size: 10MB (JPG, PNG, GIF, WebP)</span>
                    </div>
                </div>
                <input type="hidden" name="vehicle_image_data" id="vehicleImageData">
            </div>

            <!-- Basic Info -->
            <div class="col-md-4">
                <label class="form-label" for="inputMake">Make</label>
                <input type="text" name="make" class="form-control" required placeholder="e.g., Toyota, Honda, BMW" value="<?php echo $_POST['make'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputModel">Model</label>
                <input type="text" name="model" class="form-control" required placeholder="e.g., Camry, Civic, 3 Series" value="<?php echo $_POST['model'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputYear">Year</label>
                <input type="number" name="year" class="form-control" required min="2000" max="<?php echo date('Y') + 1; ?>" value="<?php echo $_POST['year'] ?? date('Y'); ?>">
            </div>

            <!-- Identification -->
            <div class="col-md-4">
                <label class="form-label" for="inputMake">License Plate</label>
                <input type="text" name="license_plate" class="form-control" placeholder="e.g., ABC-1234" value="<?php echo $_POST['license_plate'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputModel">VIN (Vehicle Identification Number)</label>
                <input type="text" name="vin" class="form-control" placeholder="17-character VIN" value="<?php echo $_POST['vin'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputYear">Color</label>
                <input type="text" name="color" class="form-control" placeholder="e.g., Silver, Black, White" value="<?php echo $_POST['color'] ?? ''; ?>">
            </div>

            <!-- Technical Details -->
            <div class="col-md-4">
                <label class="form-label" for="inputMake">Fuel Type</label>
                <select name="fuel_type" class="form-control">
                    <option value="petrol" <?php echo ($_POST['fuel_type'] ?? '') === 'petrol' ? 'selected' : ''; ?>>Petrol</option>
                    <option value="diesel" <?php echo ($_POST['fuel_type'] ?? '') === 'diesel' ? 'selected' : ''; ?>>Diesel</option>
                    <option value="hybrid" <?php echo ($_POST['fuel_type'] ?? '') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                    <option value="electric" <?php echo ($_POST['fuel_type'] ?? '') === 'electric' ? 'selected' : ''; ?>>Electric</option>
                    <option value="other" <?php echo ($_POST['fuel_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputModel">Transmission</label>
                <select name="transmission" class="form-control">
                    <option value="manual" <?php echo ($_POST['transmission'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option>
                    <option value="automatic" <?php echo ($_POST['transmission'] ?? '') === 'automatic' ? 'selected' : ''; ?>>Automatic</option>
                    <option value="cvt" <?php echo ($_POST['transmission'] ?? '') === 'cvt' ? 'selected' : ''; ?>>CVT</option>
                    <option value="other" <?php echo ($_POST['transmission'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputYear">Engine Capacity</label>
                <input type="text" name="engine_capacity" class="form-control" placeholder="e.g., 1.8L, 2.0L, 3.5L" value="<?php echo $_POST['engine_capacity'] ?? ''; ?>">
            </div>

            <!-- Purchase Info -->
            <div class="col-md-4">
                <label class="form-label" for="inputMake">Purchase Date</label>
                <input type="date" name="purchase_date" class="form-control" value="<?php echo $_POST['purchase_date'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputModel">Purchase Mileage (km)</label>
                <input type="number" name="purchase_mileage" class="form-control" min="0" step="1" placeholder="Mileage when purchased" value="<?php echo $_POST['purchase_mileage'] ?? '0'; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inputYear">Current Mileage (km)</label>
                <input type="number" name="current_mileage" class="form-control" min="0" step="1" placeholder="Current odometer reading" value="<?php echo $_POST['current_mileage'] ?? ''; ?>">
                <p class="form-text">Leave blank to use purchase mileage</p>
            </div>

            <!-- Notes -->
            <div class="col-12 mb-3">
                <label class="form-label">Notes</label>
                <textarea class="tinymce" name="notes" rows="5" placeholder="Any additional notes about this vehicle..."><?php echo $_POST['notes'] ?? ''; ?></textarea>
            </div>

            <!-- Submit -->
            <div class="card mt-3" id="submitSection" style="display: none;">
                <div class="card-body">
                    <div class="row justify-content-between align-items-center">
                        <div class="col-md">
                            <h6 class="mb-2 mb-md-0">Nice Job! You're almost done</h6>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-falcon-default btn-sm me-2" id="clearFormBtn">Clear</button>
                            <button class="btn btn-outline-primary btn-sm" type="submit"><i class="fas fa-save"></i> Save Vehicle </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Dropzone
        Dropzone.autoDiscover = false;

        var vehicleDropzone = new Dropzone("#vehicleImageDropzone", {
            url: "#", // We'll handle upload via the main form
            autoProcessQueue: false,
            maxFiles: 1,
            maxFilesize: 10, // MB
            acceptedFiles: "image/jpeg,image/png,image/gif,image/webp",
            addRemoveLinks: true,
            dictRemoveFile: "Delete",
            dictDefaultMessage: "Drop vehicle image here or click to upload",

            init: function() {
                var dropzone = this;
                var form = document.getElementById('vehicleForm');

                // Handle form submission
                form.addEventListener('submit', function(e) {
                    // Get the file from dropzone
                    var files = dropzone.getAcceptedFiles();

                    if (files.length > 0) {
                        // Create a file input and append the file
                        var fileInput = document.createElement('input');
                        fileInput.type = 'file';
                        fileInput.name = 'vehicle_image';
                        fileInput.style.display = 'none';

                        // Create a DataTransfer object to set the file
                        var dataTransfer = new DataTransfer();
                        dataTransfer.items.add(files[0]);
                        fileInput.files = dataTransfer.files;

                        form.appendChild(fileInput);
                    }
                });

                this.on("addedfile", function(file) {
                    // Preview the image
                    if (this.files.length > 1) {
                        this.removeFile(this.files[0]);
                    }
                });

                this.on("removedfile", function(file) {
                    // Remove the hidden file input if exists
                    var fileInput = form.querySelector('input[name="vehicle_image"]');
                    if (fileInput) {
                        fileInput.remove();
                    }
                });

                this.on("thumbnail", function(file, dataUrl) {
                    // Image preview is automatically handled by Dropzone
                });
            }
        });

        // Check Required Fields and Show/Hide Submit Section
        function checkRequiredFields() {
            var form = document.getElementById('vehicleForm');
            var submitSection = document.getElementById('submitSection');

            // Get all required fields
            var makeInput = form.querySelector('input[name="make"]');
            var modelInput = form.querySelector('input[name="model"]');
            var yearInput = form.querySelector('input[name="year"]');

            // Check if all required fields are filled
            var allFilled = makeInput.value.trim() !== '' &&
                modelInput.value.trim() !== '' &&
                yearInput.value.trim() !== '';

            // Show or hide submit section
            if (allFilled) {
                submitSection.style.display = 'block';
            } else {
                submitSection.style.display = 'none';
            }
        }

        // Add event listeners to required fields
        var form = document.getElementById('vehicleForm');
        var requiredFields = form.querySelectorAll('input[required]');

        requiredFields.forEach(function(field) {
            field.addEventListener('input', checkRequiredFields);
            field.addEventListener('change', checkRequiredFields);
        });

        // Check on page load (in case of form errors with data retained)
        checkRequiredFields();

        // Clear Form Button
        document.getElementById('clearFormBtn').addEventListener('click', function() {
            var form = document.getElementById('vehicleForm');

            // Reset the form
            form.reset();

            // Clear Dropzone
            vehicleDropzone.removeAllFiles();

            // Clear TinyMCE if initialized
            if (typeof tinymce !== 'undefined') {
                tinymce.get('notes')?.setContent('');
            }

            // Remove any file inputs that might have been added
            var fileInput = form.querySelector('input[name="vehicle_image"]');
            if (fileInput) {
                fileInput.remove();
            }

            // Hide submit section after clearing
            checkRequiredFields();
        });

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