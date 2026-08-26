<?php
$pageTitle = 'Driving Licence';
require_once 'includes/header.php';

use App\Services\DrivingLicenseService;

DrivingLicenseService::ensureTable($pdo);

// Server-side MIME detection for the licence scan — never trust the
// browser-supplied $_FILES[...]['type']. Same allow-list as insurance.php.
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
        redirect('driving-license');
    }

    if ($action === 'add') {
        $surname = sanitize($_POST['surname'] ?? '');
        $otherNames = sanitize($_POST['other_names'] ?? '');
        $licenseNumber = sanitize($_POST['license_number'] ?? '');
        $nationalId = sanitize($_POST['national_id'] ?? '') ?: null;
        $dob = $_POST['date_of_birth'] ?? '';
        $sex = sanitize($_POST['sex'] ?? '') ?: null;
        $bloodGroup = sanitize($_POST['blood_group'] ?? '') ?: null;
        $county = sanitize($_POST['county_of_residence'] ?? '') ?: null;
        $serialNumber = sanitize($_POST['serial_number'] ?? '') ?: null;
        $selectedCategories = array_values(array_intersect((array) ($_POST['categories'] ?? []), array_keys(DrivingLicenseService::CATEGORIES)));
        $categories = $selectedCategories ? implode(',', $selectedCategories) : null;
        $issueDate = $_POST['issue_date'] ?? '';
        $expiryDate = $_POST['expiry_date'] ?? '';
        $notes = sanitize($_POST['notes'] ?? '') ?: null;

        $dobValid = $dob === '' || \DateTime::createFromFormat('Y-m-d', $dob) !== false;
        $issueDateValid = $issueDate === '' || \DateTime::createFromFormat('Y-m-d', $issueDate) !== false;
        $expiryDateValid = $expiryDate !== '' && \DateTime::createFromFormat('Y-m-d', $expiryDate) !== false;

        if ($surname === '' || $otherNames === '' || $licenseNumber === '' || !$expiryDateValid || !$dobValid || !$issueDateValid) {
            setFlashMessage('danger', 'Please provide the surname, other names, licence number, and a valid expiry date.');
            redirect('driving-license');
        }

        $scanFileName = null;
        $scanFilePath = null;
        $scanFileType = null;

        $file = $_FILES['scan'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                setFlashMessage('danger', 'Failed to upload the licence scan.');
                redirect('driving-license');
            } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
                setFlashMessage('danger', 'Scan file too large. Maximum size: ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.');
                redirect('driving-license');
            } else {
                $detectedMime = mime_content_type($file['tmp_name']);
                if (!array_key_exists($detectedMime, $mimeToExt)) {
                    setFlashMessage('danger', 'Unsupported scan file type. Allowed: ' . $acceptedFormatsLabel . '.');
                    redirect('driving-license');
                }

                $uploadDir = UPLOAD_DIR . 'driving-license/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = $mimeToExt[$detectedMime];
                $filename = 'dl_' . time() . '_' . uniqid() . '.' . $ext;

                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $scanFileName = $file['name'];
                    $scanFilePath = $filename;
                    $scanFileType = $detectedMime;
                } else {
                    setFlashMessage('danger', 'Failed to save the licence scan.');
                    redirect('driving-license');
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO driving_licenses
                (user_id, surname, other_names, national_id, license_number, date_of_birth, sex, blood_group,
                 county_of_residence, serial_number, categories, issue_date, expiry_date,
                 scan_file_name, scan_file_path, scan_file_type, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, $surname, $otherNames, $nationalId, $licenseNumber,
            $dob ?: null, $sex, $bloodGroup, $county, $serialNumber, $categories,
            $issueDate ?: null, $expiryDate,
            $scanFileName, $scanFilePath, $scanFileType, $notes,
        ]);

        setFlashMessage('success', 'Driving licence saved successfully!');
    } elseif ($action === 'delete') {
        $licenseId = (int) ($_POST['license_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM driving_licenses WHERE id = ? AND user_id = ?");
        $stmt->execute([$licenseId, $userId]);
        $license = $stmt->fetch();

        if (!$license) {
            setFlashMessage('danger', 'Driving licence record not found.');
        } else {
            $expectedName = $license['license_number'] . ' - ' . date('M d, Y', strtotime($license['expiry_date']));
            $enteredName = trim($_POST['confirm_name'] ?? '');

            if ($enteredName !== $expectedName) {
                setFlashMessage('danger', 'Confirmation text did not match. Record not deleted.');
            } else {
                if ($license['scan_file_path']) {
                    $filePath = UPLOAD_DIR . 'driving-license/' . $license['scan_file_path'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
                $pdo->prepare("DELETE FROM driving_licenses WHERE id = ?")->execute([$licenseId]);
                setFlashMessage('success', 'Driving licence record deleted.');
            }
        }
    }

    redirect('driving-license');
}

$license = DrivingLicenseService::statusForUser($pdo, $userId);
$history = DrivingLicenseService::history($pdo, $userId);
$pastLicenses = array_slice($history, 1);

$statusMeta = [
    'expired'  => ['label' => 'Expired', 'color' => 'danger', 'icon' => 'fa-exclamation-triangle'],
    'expiring' => ['label' => 'Expiring Soon', 'color' => 'warning', 'icon' => 'fa-exclamation-circle'],
    'ok'       => ['label' => 'Valid', 'color' => 'success', 'icon' => 'fa-check-circle'],
    'none'     => ['label' => 'Not On File', 'color' => 'secondary', 'icon' => 'fa-id-card'],
];
$meta = $statusMeta[$license['status']];
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
                        <h4 class="fs-6"><i class="fas fa-id-card me-1"></i> Driving Licence</h4>
                        <p class="mb-0 fs-10 text-1000">Keep your driving licence details on file. Alerts start <?php echo DrivingLicenseService::REMINDER_WINDOW_DAYS; ?> days before expiry and continue daily until renewed.</p>
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

<style>
    .dl-face-card { position: relative; background: linear-gradient(155deg, #fbf8f0, #f1ead6); color: #1c1c1e; border-radius: 14px; overflow: hidden; box-shadow: 0 6px 20px rgba(0,0,0,0.18); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    .dl-face-card__stripe { height: 6px; display: flex; }
    .dl-face-card__stripe span { flex: 1; }
    .dl-face-card__stripe span:nth-child(1) { background: #000; }
    .dl-face-card__stripe span:nth-child(2) { background: #fff; flex: 0 0 3px; }
    .dl-face-card__stripe span:nth-child(3) { background: #bb0000; }
    .dl-face-card__stripe span:nth-child(4) { background: #fff; flex: 0 0 3px; }
    .dl-face-card__stripe span:nth-child(5) { background: #006600; }
    .dl-face-card__header { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: 10px 16px 6px; text-align: center; border-bottom: 1px solid rgba(0,0,0,0.08); }
    .dl-face-card__header i { color: #bb0000; font-size: 1.1rem; }
    .dl-face-card__title { font-weight: 800; letter-spacing: .06em; font-size: .95rem; line-height: 1.1; text-transform: uppercase; }
    .dl-face-card__subtitle { font-size: .65rem; color: #5c5c5e; letter-spacing: .04em; text-transform: uppercase; }
    .dl-face-card__body { display: flex; gap: 14px; padding: 14px 16px; }
    .dl-face-card__photo { flex: 0 0 auto; width: 78px; height: 92px; border-radius: 6px; border: 1px solid rgba(0,0,0,0.15); overflow: hidden; background: #e5e0d0; display: flex; align-items: center; justify-content: center; }
    .dl-face-card__photo img { width: 100%; height: 100%; object-fit: cover; }
    .dl-face-card__photo i { font-size: 2rem; color: #a39c86; }
    .dl-face-card__fields { flex: 1; min-width: 0; }
    .dl-field { margin-bottom: 6px; }
    .dl-field__label { display: block; font-size: .58rem; font-weight: 700; letter-spacing: .03em; color: #7a7466; text-transform: uppercase; }
    .dl-field__value { display: block; font-size: .82rem; font-weight: 600; color: #1c1c1e; word-break: break-word; }
    .dl-field-row { display: flex; gap: 14px; flex-wrap: wrap; }
    .dl-field-row .dl-field { flex: 1 1 auto; min-width: 90px; }
    .dl-face-card__footer { padding: 10px 16px 14px; border-top: 1px dashed rgba(0,0,0,0.15); }
    .dl-categories { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .dl-cat-chip { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 22px; padding: 0 6px; border-radius: 4px; background: #1c1c1e; color: #fdfaf3; font-size: .68rem; font-weight: 700; letter-spacing: .02em; }
    .dl-serial { margin-top: 10px; font-family: 'Courier New', monospace; font-size: .75rem; letter-spacing: .12em; color: #3a3a3c; }
    [data-bs-theme="dark"] .dl-face-card { box-shadow: 0 6px 24px rgba(0,0,0,0.45); }
</style>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100 <?php echo in_array($license['status'], ['expired', 'expiring'], true) ? 'border-' . $meta['color'] . ' border-2' : ''; ?>">
            <div class="card-header bg-body-tertiary">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-0"><i class="fas fa-id-card me-1"></i> Current Licence</h6>
                    </div>
                    <div class="col-auto">
                        <span class="badge badge-subtle-<?php echo $meta['color']; ?>">
                            <i class="fas <?php echo $meta['icon']; ?> me-1"></i><?php echo $meta['label']; ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if ($license['status'] === 'none'): ?>
                    <div class="empty-state text-center py-3">
                        <i class="fas fa-id-card empty-state-icon fs-3 text-300 mb-2"></i>
                        <p class="fs-10 mb-3">No driving licence on file yet.</p>
                    </div>
                <?php else:
                    $licenseCategories = !empty($license['categories']) ? explode(',', $license['categories']) : [];
                    $isImageScan = $license['scan_file_path'] && str_starts_with((string) $license['scan_file_type'], 'image/');
                    $scanUrl = $license['scan_file_path'] ? 'uploads/driving-license/' . rawurlencode($license['scan_file_path']) : null;
                ?>
                    <div class="dl-face-card mb-3">
                        <div class="dl-face-card__stripe"><span></span><span></span><span></span><span></span><span></span></div>
                        <div class="dl-face-card__header">
                            <i class="fas fa-id-card"></i>
                            <div>
                                <div class="dl-face-card__title">Driving Licence</div>
                                <div class="dl-face-card__subtitle">Licence Holder Details</div>
                            </div>
                        </div>
                        <div class="dl-face-card__body">
                            <div class="dl-face-card__photo">
                                <?php if ($isImageScan): ?>
                                    <a href="<?php echo $scanUrl; ?>" class="glightbox" data-gallery="license-scans" data-title="Driving licence scan">
                                        <img src="<?php echo $scanUrl; ?>" alt="Licence photo">
                                    </a>
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div class="dl-face-card__fields">
                                <div class="dl-field">
                                    <span class="dl-field__label">1. Surname</span>
                                    <span class="dl-field__value"><?php echo sanitize($license['surname']); ?></span>
                                </div>
                                <div class="dl-field">
                                    <span class="dl-field__label">2. Other Names</span>
                                    <span class="dl-field__value"><?php echo sanitize($license['other_names']); ?></span>
                                </div>
                                <div class="dl-field-row">
                                    <div class="dl-field">
                                        <span class="dl-field__label">National ID No</span>
                                        <span class="dl-field__value"><?php echo sanitize($license['national_id'] ?: '—'); ?></span>
                                    </div>
                                    <div class="dl-field">
                                        <span class="dl-field__label">5. Licence No</span>
                                        <span class="dl-field__value"><?php echo sanitize($license['license_number']); ?></span>
                                    </div>
                                </div>
                                <div class="dl-field-row">
                                    <div class="dl-field">
                                        <span class="dl-field__label">3. Date of Birth</span>
                                        <span class="dl-field__value"><?php echo $license['date_of_birth'] ? date('d.m.Y', strtotime($license['date_of_birth'])) : '—'; ?></span>
                                    </div>
                                    <div class="dl-field">
                                        <span class="dl-field__label">Sex</span>
                                        <span class="dl-field__value"><?php echo sanitize($license['sex'] ?: '—'); ?></span>
                                    </div>
                                    <div class="dl-field">
                                        <span class="dl-field__label">Blood Group</span>
                                        <span class="dl-field__value"><?php echo sanitize($license['blood_group'] ?: '—'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="dl-face-card__footer">
                            <div class="dl-field-row">
                                <div class="dl-field">
                                    <span class="dl-field__label">Date of Issue</span>
                                    <span class="dl-field__value"><?php echo $license['issue_date'] ? date('d.m.Y', strtotime($license['issue_date'])) : '—'; ?></span>
                                </div>
                                <div class="dl-field">
                                    <span class="dl-field__label">Date of Expiry</span>
                                    <span class="dl-field__value" style="color: <?php echo $license['status'] === 'ok' ? '#1c1c1e' : ($license['status'] === 'expiring' ? '#b06a00' : '#bb0000'); ?>;"><?php echo date('d.m.Y', strtotime($license['expiry_date'])); ?></span>
                                </div>
                                <div class="dl-field">
                                    <span class="dl-field__label">County of Residence</span>
                                    <span class="dl-field__value"><?php echo sanitize($license['county_of_residence'] ?: '—'); ?></span>
                                </div>
                            </div>

                            <span class="dl-field__label">Categories Valid For</span>
                            <div class="dl-categories">
                                <?php if (empty($licenseCategories)): ?>
                                    <span class="fs-11 text-600">None recorded</span>
                                <?php else: ?>
                                    <?php foreach ($licenseCategories as $catCode): ?>
                                        <span class="dl-cat-chip" title="<?php echo sanitize(DrivingLicenseService::CATEGORIES[$catCode] ?? $catCode); ?>"><?php echo sanitize($catCode); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($license['serial_number']): ?>
                                <div class="dl-serial"><?php echo sanitize($license['serial_number']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="fs-11 mb-1">
                        <?php if ($license['status'] === 'expired'): ?>
                            <span class="text-danger">Expired <?php echo abs($license['days_remaining']); ?> day(s) ago</span>
                        <?php elseif ($license['status'] === 'expiring'): ?>
                            <span class="text-warning"><?php echo $license['days_remaining']; ?> day(s) left before it expires</span>
                        <?php else: ?>
                            <span class="text-success">Valid — <?php echo $license['days_remaining']; ?> day(s) remaining</span>
                        <?php endif; ?>
                    </p>

                    <?php if (!$isImageScan && $scanUrl): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fs-11 pdf-tile" data-pdf-src="<?php echo $scanUrl; ?>" data-pdf-title="Driving licence scan">
                            <i class="fas fa-file-pdf text-danger me-1"></i> View uploaded scan (PDF)
                        </button>
                    <?php endif; ?>

                    <?php if ($license['notes']): ?>
                        <p class="fs-11 text-600 mt-2 mb-0"><?php echo nl2br(sanitize($license['notes'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($pastLicenses)): ?>
                        <button class="btn btn-link btn-sm px-0 mt-2 fs-11" type="button" data-bs-toggle="collapse" data-bs-target="#license-history">
                            <i class="fas fa-history me-1"></i> View <?php echo count($pastLicenses); ?> past record<?php echo count($pastLicenses) === 1 ? '' : 's'; ?>
                        </button>
                        <div class="collapse mt-2" id="license-history">
                            <div class="table-responsive">
                                <table class="table table-sm fs-11 mb-0">
                                    <thead>
                                        <tr><th>Licence #</th><th>Holder</th><th>Expired</th><th></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pastLicenses as $p): ?>
                                            <tr>
                                                <td><?php echo sanitize($p['license_number']); ?></td>
                                                <td><?php echo sanitize(trim($p['other_names'] . ' ' . $p['surname'])); ?></td>
                                                <td><?php echo formatDate($p['expiry_date']); ?></td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                                            title="Delete"
                                                            onclick='confirmDeleteLicense(<?php echo (int) $p['id']; ?>, <?php echo json_encode($p['license_number'] . " - " . date("M d, Y", strtotime($p["expiry_date"])), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
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
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#add-license-modal">
                    <i class="fas fa-plus"></i> <?php echo $license['status'] === 'none' ? 'Add Licence' : 'Renew Licence'; ?>
                </button>
                <?php if ($license['status'] !== 'none'): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick='confirmDeleteLicense(<?php echo (int) $license['id']; ?>, <?php echo json_encode($license['license_number'] . " - " . date("M d, Y", strtotime($license["expiry_date"])), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>
                        <i class="fas fa-trash"></i> Delete Current
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0"><i class="fas fa-info-circle me-1"></i> How Reminders Work</h6>
            </div>
            <div class="card-body">
                <ul class="fs-10 text-600 mb-0 ps-3">
                    <li class="mb-2">A sticky banner and an email alert start <?php echo DrivingLicenseService::REMINDER_WINDOW_DAYS; ?> days before your licence expires.</li>
                    <li class="mb-2">If the licence has already expired, alerts keep firing every day until it is renewed.</li>
                    <li class="mb-2">Adding a fresh renewal with a later expiry date automatically clears the alert.</li>
                    <li class="mb-0">Email alerts only go out while email notifications are enabled in <a href="settings">Settings</a>.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Add/Renew Driving Licence Modal -->
<div class="modal fade" id="add-license-modal" tabindex="-1" aria-labelledby="addLicenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addLicenseModalLabel"><?php echo $license['status'] === 'none' ? 'Add' : 'Renew'; ?> Driving Licence</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Surname <span class="text-danger">*</span></label>
                            <input type="text" name="surname" class="form-control" value="<?php echo $license['status'] !== 'none' ? sanitize($license['surname']) : ''; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Other Names <span class="text-danger">*</span></label>
                            <input type="text" name="other_names" class="form-control" value="<?php echo $license['status'] !== 'none' ? sanitize($license['other_names']) : ''; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Licence Number <span class="text-danger">*</span></label>
                            <input type="text" name="license_number" class="form-control" placeholder="e.g. DL-1410770" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">National ID No</label>
                            <input type="text" name="national_id" class="form-control" value="<?php echo $license['status'] !== 'none' ? sanitize($license['national_id'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?php echo $license['status'] !== 'none' ? sanitize($license['date_of_birth'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sex</label>
                            <select name="sex" class="form-select">
                                <option value="">—</option>
                                <option value="Male" <?php echo ($license['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($license['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Blood Group</label>
                            <input type="text" name="blood_group" class="form-control" placeholder="e.g. O+" value="<?php echo $license['status'] !== 'none' ? sanitize($license['blood_group'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">County of Residence</label>
                            <input type="text" name="county_of_residence" class="form-control" value="<?php echo $license['status'] !== 'none' ? sanitize($license['county_of_residence'] ?? '') : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" class="form-control" placeholder="Card serial number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Issue</label>
                            <input type="date" name="issue_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Expiry <span class="text-danger">*</span></label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Categories Valid For</label>
                            <?php $currentCategories = ($license['status'] !== 'none' && !empty($license['categories'])) ? explode(',', $license['categories']) : []; ?>
                            <div class="row g-2">
                                <?php foreach (DrivingLicenseService::CATEGORIES as $code => $label): ?>
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo sanitize($code); ?>" id="cat-<?php echo sanitize($code); ?>" <?php echo in_array($code, $currentCategories, true) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fs-11" for="cat-<?php echo sanitize($code); ?>">
                                                <strong><?php echo sanitize($code); ?></strong> — <?php echo sanitize($label); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Licence Scan / Photo</label>
                            <input type="file" name="scan" class="form-control" accept="image/*,.heic,.heif,application/pdf">
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
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Licence</button>
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
                <h5 class="modal-title" id="pdfViewerModalLabel">Driving Licence Scan</h5>
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

<!-- Delete Licence Modal -->
<div class="modal fade" id="delete-license-modal" tabindex="-1" aria-labelledby="deleteLicenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteLicenseModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Delete Driving Licence Record
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="delete-license-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="license_id" id="delete-license-id">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>

                    <p class="mb-2">To confirm deletion, type the licence name below:</p>

                    <div class="card bg-light mb-3">
                        <div class="card-body py-2 d-flex align-items-center justify-content-between">
                            <strong id="delete-license-name-display"></strong>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-license-name-btn" title="Copy">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <input type="text"
                           class="form-control"
                           name="confirm_name"
                           id="delete-license-name-input"
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
                pdfTitle.textContent = this.dataset.pdfTitle || 'Driving Licence Scan';
                pdfDownload.href = src;
                new bootstrap.Modal(pdfModalEl).show();
            });
        });

        pdfModalEl.addEventListener('hidden.bs.modal', function () {
            pdfFrame.src = '';
        });

        var deleteForm = document.getElementById('delete-license-form');
        var nameInput = document.getElementById('delete-license-name-input');
        var copyBtn = document.getElementById('copy-license-name-btn');

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

    function confirmDeleteLicense(licenseId, name) {
        document.getElementById('delete-license-id').value = licenseId;
        document.getElementById('delete-license-name-display').textContent = name;
        document.getElementById('delete-license-name-input').value = '';
        document.getElementById('delete-license-form').dataset.expectedName = name;
        new bootstrap.Modal(document.getElementById('delete-license-modal')).show();
    }
</script>

<?php require_once 'includes/footer.php'; ?>
