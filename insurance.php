<?php
$pageTitle = 'Insurance';
require_once 'includes/header.php';

use App\Helpers\IdCodec;
use App\Services\InsuranceService;

InsuranceService::ensureTable($pdo);

// Server-side MIME detection for the insurance sticker — never trust the
// browser-supplied $_FILES[...]['type']. Same allow-list as vehicle-documents.php.
$mimeToExt = [
    'image/jpeg'      => 'jpg',
    'image/pjpeg'     => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heif',
    'image/bmp'       => 'bmp',
    'image/tiff'      => 'tiff',
    'application/pdf' => 'pdf',
];
$acceptedFormatsLabel = 'JPG, PNG, GIF, WEBP, HEIC, HEIF, BMP, TIFF, PDF';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid request. Please try again.');
        redirect('insurance');
    }

    if ($action === 'add') {
        $vehicleId = IdCodec::decode($_POST['vehicle_id'] ?? null) ?? 0;

        $vehicleStmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $vehicleStmt->execute([$vehicleId, $userId]);

        if (!$vehicleStmt->fetch()) {
            setFlashMessage('danger', 'Vehicle not found.');
            redirect('insurance');
        }

        $provider = sanitize($_POST['provider'] ?? '');
        $policyNumber = sanitize($_POST['policy_number'] ?? '') ?: null;
        $coverageType = array_key_exists($_POST['coverage_type'] ?? '', InsuranceService::COVERAGE_TYPES)
            ? $_POST['coverage_type']
            : 'comprehensive';
        $premiumRaw = trim($_POST['premium_amount'] ?? '');
        $premium = ($premiumRaw !== '' && is_numeric($premiumRaw)) ? (float) $premiumRaw : null;
        $startDate = $_POST['start_date'] ?? '';
        $expiryDate = $_POST['expiry_date'] ?? '';
        $notes = sanitize($_POST['notes'] ?? '') ?: null;

        $startDateValid = $startDate === '' || \DateTime::createFromFormat('Y-m-d', $startDate) !== false;
        $expiryDateValid = $expiryDate !== '' && \DateTime::createFromFormat('Y-m-d', $expiryDate) !== false;

        if ($provider === '' || !$expiryDateValid || !$startDateValid) {
            setFlashMessage('danger', 'Please provide the provider name and a valid expiry date.');
            redirect('insurance');
        }

        $stickerFileName = null;
        $stickerFilePath = null;
        $stickerFileType = null;

        $file = $_FILES['sticker'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                setFlashMessage('danger', 'Failed to upload the insurance sticker.');
                redirect('insurance');
            } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
                setFlashMessage('danger', 'Sticker file too large. Maximum size: ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.');
                redirect('insurance');
            } else {
                $detectedMime = mime_content_type($file['tmp_name']);
                if (!array_key_exists($detectedMime, $mimeToExt)) {
                    setFlashMessage('danger', 'Unsupported sticker file type. Allowed: ' . $acceptedFormatsLabel . '.');
                    redirect('insurance');
                }

                $uploadDir = UPLOAD_DIR . 'insurance/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = $mimeToExt[$detectedMime];
                $filename = 'ins_' . time() . '_' . uniqid() . '.' . $ext;

                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $stickerFileName = $file['name'];
                    $stickerFilePath = $filename;
                    $stickerFileType = $detectedMime;
                } else {
                    setFlashMessage('danger', 'Failed to save the insurance sticker.');
                    redirect('insurance');
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO vehicle_insurance
                (vehicle_id, provider, policy_number, coverage_type, premium_amount, start_date, expiry_date,
                 sticker_file_name, sticker_file_path, sticker_file_type, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $vehicleId, $provider, $policyNumber, $coverageType, $premium,
            $startDate ?: null, $expiryDate,
            $stickerFileName, $stickerFilePath, $stickerFileType, $notes,
        ]);

        setFlashMessage('success', 'Insurance policy saved successfully!');
    } elseif ($action === 'delete') {
        $policyId = (int) ($_POST['policy_id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT vi.* FROM vehicle_insurance vi
            JOIN vehicles v ON v.id = vi.vehicle_id
            WHERE vi.id = ? AND v.user_id = ?
        ");
        $stmt->execute([$policyId, $userId]);
        $policy = $stmt->fetch();

        if (!$policy) {
            setFlashMessage('danger', 'Insurance record not found.');
        } else {
            $expectedName = $policy['provider'] . ' - ' . date('M d, Y', strtotime($policy['expiry_date']));
            $enteredName = trim($_POST['confirm_name'] ?? '');

            if ($enteredName !== $expectedName) {
                setFlashMessage('danger', 'Confirmation text did not match. Record not deleted.');
            } else {
                if ($policy['sticker_file_path']) {
                    $filePath = UPLOAD_DIR . 'insurance/' . $policy['sticker_file_path'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $pdo->prepare("DELETE FROM vehicle_insurance WHERE id = ?")->execute([$policyId]);
                setFlashMessage('success', 'Insurance record deleted.');
            }
        }
    }

    redirect('insurance');
}

$highlightVehicleId = IdCodec::decode($_GET['vehicle_id'] ?? null) ?? 0;

$vehicles = InsuranceService::statusForUser($pdo, $userId);

$historyByVehicle = [];
foreach ($vehicles as $v) {
    $historyByVehicle[$v['vehicle_id']] = InsuranceService::history($pdo, $v['vehicle_id']);
}

$counts = ['expired' => 0, 'expiring' => 0, 'ok' => 0, 'none' => 0];
foreach ($vehicles as $v) {
    $counts[$v['status']]++;
}

$statusMeta = [
    'expired'  => ['label' => 'Expired', 'color' => 'danger', 'icon' => 'fa-exclamation-triangle'],
    'expiring' => ['label' => 'Expiring Soon', 'color' => 'warning', 'icon' => 'fa-exclamation-circle'],
    'ok'       => ['label' => 'Insured', 'color' => 'success', 'icon' => 'fa-check-circle'],
    'none'     => ['label' => 'Not Insured', 'color' => 'secondary', 'icon' => 'fa-shield-alt'],
];
?>

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
                        <h4 class="fs-6"><i class="fas fa-shield-alt me-1"></i> Insurance</h4>
                        <p class="mb-0 fs-10 text-1000">Track insurance cover and stickers for every vehicle. Alerts start <?php echo InsuranceService::REMINDER_WINDOW_DAYS; ?> days before expiry and continue daily until renewed.</p>
                    </div>
                </div>
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

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h3 class="text-danger mb-0"><?php echo $counts['expired']; ?></h3>
                <p class="fs-10 text-600 mb-0">Expired</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h3 class="text-warning mb-0"><?php echo $counts['expiring']; ?></h3>
                <p class="fs-10 text-600 mb-0">Expiring Soon</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h3 class="text-success mb-0"><?php echo $counts['ok']; ?></h3>
                <p class="fs-10 text-600 mb-0">Insured</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <h3 class="text-secondary mb-0"><?php echo $counts['none']; ?></h3>
                <p class="fs-10 text-600 mb-0">Not Insured</p>
            </div>
        </div>
    </div>
</div>

<?php if (empty($vehicles)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state text-center py-5">
                <i class="fas fa-car empty-state-icon fs-1 text-300 mb-3"></i>
                <h6 class="fs-9 mb-1">No vehicles yet!</h6>
                <p class="fs-10 mb-3">Add a vehicle first, then come back to record its insurance.</p>
                <a href="add-vehicle" class="btn btn-outline-success btn-sm"><i class="fas fa-plus"></i> Add Vehicle</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($vehicles as $v):
            $meta = $statusMeta[$v['status']];
            $vehicleIdToken = IdCodec::encode($v['vehicle_id']);
            $vehicleLabel = $v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')';
            $history = $historyByVehicle[$v['vehicle_id']];
            $pastPolicies = array_slice($history, 1);
        ?>
            <div class="col-lg-6" id="vehicle-insurance-<?php echo $v['vehicle_id']; ?>">
                <div class="card h-100 <?php echo in_array($v['status'], ['expired', 'expiring'], true) ? 'border-' . $meta['color'] . ' border-2' : ''; ?> <?php echo $v['vehicle_id'] === $highlightVehicleId ? 'border-primary border-2' : ''; ?>">
                    <div class="card-header bg-body-tertiary">
                        <div class="row align-items-center">
                            <div class="col">
                                <h6 class="mb-0"><i class="fas fa-car-side me-1"></i> <?php echo sanitize($vehicleLabel); ?></h6>
                            </div>
                            <div class="col-auto">
                                <span class="badge badge-subtle-<?php echo $meta['color']; ?>">
                                    <i class="fas <?php echo $meta['icon']; ?> me-1"></i><?php echo $meta['label']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($v['status'] === 'none'): ?>
                            <div class="empty-state text-center py-3">
                                <i class="fas fa-shield-alt empty-state-icon fs-3 text-300 mb-2"></i>
                                <p class="fs-10 mb-3">No insurance policy on file for this vehicle.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <div class="col-auto">
                                    <?php if ($v['sticker_file_path']):
                                        $isImage = str_starts_with((string) $v['sticker_file_type'], 'image/');
                                        $fileUrl = 'uploads/insurance/' . rawurlencode($v['sticker_file_path']);
                                    ?>
                                        <?php if ($isImage): ?>
                                            <a href="<?php echo $fileUrl; ?>" class="glightbox" data-gallery="insurance-stickers" data-title="<?php echo sanitize($v['provider']); ?> sticker">
                                                <img src="<?php echo $fileUrl; ?>" class="rounded-3 border" style="width:64px; height:64px; object-fit:cover;" alt="Insurance sticker">
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn p-0 border-0 pdf-tile" data-pdf-src="<?php echo $fileUrl; ?>" data-pdf-title="<?php echo sanitize($v['provider']); ?> sticker">
                                                <div class="rounded-3 border bg-body-tertiary d-flex align-items-center justify-content-center" style="width:64px; height:64px;">
                                                    <i class="fas fa-file-pdf fs-2 text-danger"></i>
                                                </div>
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="rounded-3 border bg-body-tertiary d-flex align-items-center justify-content-center" style="width:64px; height:64px;">
                                            <i class="fas fa-shield-alt fs-3 text-300"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col">
                                    <p class="fs-10 mb-1"><strong><?php echo sanitize($v['provider']); ?></strong></p>
                                    <p class="fs-11 text-600 mb-1">
                                        <?php echo sanitize(InsuranceService::COVERAGE_TYPES[$v['coverage_type']] ?? $v['coverage_type']); ?>
                                        <?php if ($v['policy_number']): ?> &bull; #<?php echo sanitize($v['policy_number']); ?><?php endif; ?>
                                    </p>
                                    <p class="fs-11 mb-1">
                                        Expires: <strong class="text-<?php echo $meta['color']; ?>"><?php echo formatDate($v['expiry_date']); ?></strong>
                                        <?php if ($v['status'] === 'expired'): ?>
                                            <span class="text-danger">(<?php echo abs($v['days_remaining']); ?> day(s) ago)</span>
                                        <?php elseif ($v['status'] === 'expiring'): ?>
                                            <span class="text-warning">(<?php echo $v['days_remaining']; ?> day(s) left)</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($v['premium_amount']): ?>
                                        <p class="fs-11 text-600 mb-0">Premium: Ksh. <?php echo formatNumber($v['premium_amount']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($pastPolicies)): ?>
                                <button class="btn btn-link btn-sm px-0 mt-2 fs-11" type="button" data-bs-toggle="collapse" data-bs-target="#history-<?php echo $v['vehicle_id']; ?>">
                                    <i class="fas fa-history me-1"></i> View <?php echo count($pastPolicies); ?> past polic<?php echo count($pastPolicies) === 1 ? 'y' : 'ies'; ?>
                                </button>
                                <div class="collapse mt-2" id="history-<?php echo $v['vehicle_id']; ?>">
                                    <div class="table-responsive">
                                        <table class="table table-sm fs-11 mb-0">
                                            <thead>
                                                <tr><th>Provider</th><th>Policy #</th><th>Expired</th><th></th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pastPolicies as $p): ?>
                                                    <tr>
                                                        <td><?php echo sanitize($p['provider']); ?></td>
                                                        <td><?php echo sanitize($p['policy_number'] ?? '—'); ?></td>
                                                        <td><?php echo formatDate($p['expiry_date']); ?></td>
                                                        <td class="text-end">
                                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                                                    title="Delete"
                                                                    onclick='confirmDeleteInsurance(<?php echo (int) $p['id']; ?>, <?php echo json_encode($p['provider'] . " - " . date("M d, Y", strtotime($p["expiry_date"])), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
                                                                <i class="fas fa-times fs-11"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-body-tertiary d-flex justify-content-between">
                        <button type="button" class="btn btn-sm btn-primary" onclick='openAddInsuranceModal(<?php echo json_encode($vehicleIdToken, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>, <?php echo json_encode($vehicleLabel, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
                            <i class="fas fa-plus"></i> <?php echo $v['status'] === 'none' ? 'Add Insurance' : 'Renew Insurance'; ?>
                        </button>
                        <?php if ($v['status'] !== 'none'): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick='confirmDeleteInsurance(<?php echo (int) $v['insurance_id']; ?>, <?php echo json_encode($v['provider'] . " - " . date("M d, Y", strtotime($v["expiry_date"])), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
                                <i class="fas fa-trash"></i> Delete Current
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add/Renew Insurance Modal (shared, target vehicle set via JS) -->
<div class="modal fade" id="add-insurance-modal" tabindex="-1" aria-labelledby="addInsuranceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addInsuranceModalLabel">Add Insurance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="vehicle_id" id="add-insurance-vehicle-id">
                <div class="modal-body">
                    <p class="fs-10 text-600 mb-3">Vehicle: <strong id="add-insurance-vehicle-label"></strong></p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Provider <span class="text-danger">*</span></label>
                            <input type="text" name="provider" class="form-control" placeholder="e.g. Britam" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Policy Number</label>
                            <input type="text" name="policy_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Coverage Type</label>
                            <select name="coverage_type" class="form-select">
                                <?php foreach (InsuranceService::COVERAGE_TYPES as $key => $label): ?>
                                    <option value="<?php echo sanitize($key); ?>"><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Premium (Ksh.)</label>
                            <input type="number" step="0.01" min="0" name="premium_amount" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Insurance Sticker</label>
                            <input type="file" name="sticker" class="form-control" accept="image/*,.heic,.heif,application/pdf">
                            <small class="text-muted">Accepted: <?php echo $acceptedFormatsLabel; ?> (max <?php echo (int)(MAX_UPLOAD_SIZE / 1024 / 1024); ?>MB)</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Policy</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PDF Viewer Modal -->
<div class="modal fade" id="pdf-viewer-modal" tabindex="-1" aria-labelledby="pdfViewerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pdfViewerModalLabel">Insurance Sticker</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdf-viewer-frame" src="" style="width:100%; height:80vh; border:0;" title="PDF document"></iframe>
            </div>
            <div class="modal-footer">
                <a id="pdf-viewer-download" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i>Open in New Tab
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Insurance Modal -->
<div class="modal fade" id="delete-insurance-modal" tabindex="-1" aria-labelledby="deleteInsuranceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteInsuranceModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Delete Insurance Record
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="delete-insurance-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="policy_id" id="delete-insurance-id">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>

                    <p class="mb-2">To confirm deletion, type the policy name below:</p>

                    <div class="card bg-light mb-3">
                        <div class="card-body py-2 d-flex align-items-center justify-content-between">
                            <strong id="delete-insurance-name-display"></strong>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-insurance-name-btn" title="Copy">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <input type="text"
                           class="form-control"
                           name="confirm_name"
                           id="delete-insurance-name-input"
                           placeholder="Enter the text above"
                           required
                           autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($highlightVehicleId): ?>
        var highlightEl = document.getElementById('vehicle-insurance-<?php echo (int) $highlightVehicleId; ?>');
        if (highlightEl) {
            highlightEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        <?php endif; ?>

        if (window.GLightbox) {
            GLightbox({ selector: '.glightbox' });
        }

        var pdfModalEl = document.getElementById('pdf-viewer-modal');
        var pdfFrame = document.getElementById('pdf-viewer-frame');
        var pdfTitle = document.getElementById('pdfViewerModalLabel');
        var pdfDownload = document.getElementById('pdf-viewer-download');

        document.querySelectorAll('.pdf-tile').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var src = this.dataset.pdfSrc;
                pdfFrame.src = src;
                pdfTitle.textContent = this.dataset.pdfTitle || 'Insurance Sticker';
                pdfDownload.href = src;
                new bootstrap.Modal(pdfModalEl).show();
            });
        });

        pdfModalEl.addEventListener('hidden.bs.modal', function () {
            pdfFrame.src = '';
        });

        var deleteForm = document.getElementById('delete-insurance-form');
        var nameInput = document.getElementById('delete-insurance-name-input');
        var copyBtn = document.getElementById('copy-insurance-name-btn');

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var expected = deleteForm.dataset.expectedName || '';
                navigator.clipboard.writeText(expected).then(function () {
                    nameInput.value = expected;
                    var icon = copyBtn.querySelector('i');
                    icon.classList.remove('fa-copy');
                    icon.classList.add('fa-check');
                    copyBtn.classList.add('btn-success');
                    copyBtn.classList.remove('btn-outline-secondary');
                    setTimeout(function () {
                        icon.classList.remove('fa-check');
                        icon.classList.add('fa-copy');
                        copyBtn.classList.remove('btn-success');
                        copyBtn.classList.add('btn-outline-secondary');
                    }, 2000);
                }).catch(function () {
                    nameInput.value = expected;
                });
            });
        }

        if (deleteForm) {
            deleteForm.addEventListener('submit', function (e) {
                var expected = deleteForm.dataset.expectedName || '';
                var entered = nameInput.value.trim();
                if (entered !== expected) {
                    e.preventDefault();
                    alert('Confirmation text does not match. Please enter it exactly as shown.');
                    nameInput.focus();
                    return false;
                }
            });
        }
    });

    function openAddInsuranceModal(vehicleIdToken, vehicleLabel) {
        document.getElementById('add-insurance-vehicle-id').value = vehicleIdToken;
        document.getElementById('add-insurance-vehicle-label').textContent = vehicleLabel;
        new bootstrap.Modal(document.getElementById('add-insurance-modal')).show();
    }

    function confirmDeleteInsurance(policyId, name) {
        document.getElementById('delete-insurance-id').value = policyId;
        document.getElementById('delete-insurance-name-display').textContent = name;
        document.getElementById('delete-insurance-name-input').value = '';
        document.getElementById('delete-insurance-form').dataset.expectedName = name;
        new bootstrap.Modal(document.getElementById('delete-insurance-modal')).show();
    }
</script>

<?php require_once 'includes/footer.php'; ?>
