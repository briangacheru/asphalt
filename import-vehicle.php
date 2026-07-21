<?php
$pageTitle = 'Import Vehicle';
require_once 'includes/header.php';

use App\Helpers\IdCodec;
use App\Services\VehicleImportService;

$MAX_IMPORT_SIZE = 50 * 1024 * 1024; // keep in sync with VehicleImportService::MAX_ZIP_SIZE

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid security token. Please try again.');
        redirect('import-vehicle');
    }

    $file = $_FILES['export_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        setFlashMessage('danger', 'Please choose a vehicle export file (.zip) to import.');
        redirect('import-vehicle');
    }

    if ($file['size'] > $MAX_IMPORT_SIZE) {
        setFlashMessage('danger', 'File too large. Maximum size: ' . (int) ($MAX_IMPORT_SIZE / 1024 / 1024) . 'MB.');
        redirect('import-vehicle');
    }

    $detectedMime = mime_content_type($file['tmp_name']);
    if (!in_array($detectedMime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
        setFlashMessage('danger', 'Please upload a valid .zip export file.');
        redirect('import-vehicle');
    }

    $result = (new VehicleImportService($pdo))->importZip($file['tmp_name'], $userId);

    if ($result['success']) {
        setFlashMessage('success', 'Vehicle imported successfully! Review the details below and update anything that needs it.');
        redirect('vehicle-details?id=' . IdCodec::encode($result['vehicleId']));
    }

    setFlashMessage('danger', $result['error'] ?? 'Import failed.');
    redirect('import-vehicle');
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
                                echo $currentMonth; ?>
                        </span><span class="calendar-day"><?php echo $currentDay; ?> </span></div>
                    <div class="flex-1">
                        <h4 class="fs-6"><i class="fas fa-file-import me-1"></i> Import Vehicle</h4>
                        <p class="mb-0 fs-10">Bring in a vehicle exported from this app — useful when buying a car that was previously tracked here.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
                <a href="vehicles" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Vehicles
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$flash = getFlashMessage();
if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
        <span><?php echo $flash['message']; ?></span>
        <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            The vehicle will be added to <strong>your</strong> account as a new vehicle, including its
            full service history, fuel log, expenses, maintenance schedule, and documents/photos.
            The original owner's copy is unaffected.
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <div class="mb-3">
                <label class="form-label">Export File <span class="text-danger">*</span></label>
                <input type="file" name="export_file" class="form-control" accept=".zip,application/zip" required>
                <small class="text-muted">The .zip file you got from someone's "Export" button (max <?php echo (int) ($MAX_IMPORT_SIZE / 1024 / 1024); ?>MB)</small>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-file-import me-1"></i>Import Vehicle
            </button>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
