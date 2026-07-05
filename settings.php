<?php
$pageTitle = 'Settings';
require_once 'includes/header.php';
use App\Services\EmailService;
use App\Helpers\Preferences;

// Get current user settings
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Ensures the currency_symbol column exists (no migration runner in this app)
// and gives us the resolved preferences for rendering the form below.
$prefs = Preferences::forUser($pdo, (int)$_SESSION['user_id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_test_email') {
        try {
            $emailService = new EmailService($pdo);
            $sent = $emailService->sendTestEmail($user['email'], $user['first_name']);
            
            if ($sent) {
                setFlashMessage('success', 'Test email sent successfully to ' . htmlspecialchars($user['email']) . '!');
            } else {
                setFlashMessage('danger', 'Failed to send test email. Please check your SMTP settings.');
            }
        } catch (Exception $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
        redirect('settings');
    }

    if ($action === 'update_email_settings') {
        $email_notifications_enabled = isset($_POST['email_notifications_enabled']) ? 1 : 0;
        $email_frequency = sanitize($_POST['email_frequency']);
        $email = sanitize($_POST['email']);

        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET email = ?, 
                    email_notifications_enabled = ?, 
                    email_frequency = ? 
                WHERE id = ?
            ");
            $stmt->execute([$email, $email_notifications_enabled, $email_frequency, $_SESSION['user_id']]);
            setFlashMessage('success', 'Email settings updated successfully!');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error updating settings: ' . $e->getMessage());
        }
        redirect('settings');
    }

    if ($action === 'update_profile') {
        $first_name = sanitize($_POST['first_name']);
        $last_name = sanitize($_POST['last_name']);
        $phone = sanitize($_POST['phone']);

        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, 
                    last_name = ?, 
                    phone = ? 
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $phone, $_SESSION['user_id']]);
            setFlashMessage('success', 'Profile updated successfully!');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error updating profile: ' . $e->getMessage());
        }
        redirect('settings');
    }

    if ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Verify current password
        if (!password_verify($current_password, $user['password'])) {
            setFlashMessage('danger', 'Current password is incorrect.');
            redirect('settings');
        }

        // Validate new password
        if ($new_password !== $confirm_password) {
            setFlashMessage('danger', 'New passwords do not match.');
            redirect('settings');
        }

        if (strlen($new_password) < 6) {
            setFlashMessage('danger', 'New password must be at least 6 characters long.');
            redirect('settings');
        }

        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $_SESSION['user_id']]);
            setFlashMessage('success', 'Password changed successfully!');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error changing password: ' . $e->getMessage());
        }
        redirect('settings');
    }

    if ($action === 'update_preferences') {
        $default_currency = sanitize($_POST['default_currency'] ?? 'USD');
        $default_distance_unit = sanitize($_POST['default_distance_unit'] ?? 'km');
        $default_volume_unit = sanitize($_POST['default_volume_unit'] ?? 'L');
        $timezone = sanitize($_POST['timezone'] ?? 'UTC');

        // A custom currency (not in the built-in list) supplies its own code + symbol
        $currency_symbol = null;
        if ($default_currency === '__custom__') {
            $default_currency = substr(strtoupper(sanitize($_POST['custom_currency_code'] ?? '')), 0, 10);
            $currency_symbol = substr(sanitize($_POST['custom_currency_symbol'] ?? ''), 0, 10);

            if ($default_currency === '' || $currency_symbol === '') {
                setFlashMessage('danger', 'Please provide both a currency code and symbol.');
                redirect('settings');
            }
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET default_currency = ?,
                    currency_symbol = ?,
                    default_distance_unit = ?,
                    default_volume_unit = ?,
                    timezone = ?
                WHERE id = ?
            ");
            $stmt->execute([$default_currency, $currency_symbol, $default_distance_unit, $default_volume_unit, $timezone, $_SESSION['user_id']]);
            Preferences::forget((int)$_SESSION['user_id']);
            setFlashMessage('success', 'Preferences updated successfully!');
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error updating preferences: ' . $e->getMessage());
        }
        redirect('settings');
    }

    if ($action === 'update_avatar') {
        $avatar_path = null;

        // Handle custom file upload
        if (isset($_FILES['custom_avatar']) && $_FILES['custom_avatar']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024; // 2MB

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['custom_avatar']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed_types)) {
                setFlashMessage('danger', 'Invalid file type. Please upload a JPEG, PNG, GIF, or WebP image.');
                redirect('settings');
            }

            if ($_FILES['custom_avatar']['size'] > $max_size) {
                setFlashMessage('danger', 'File too large. Maximum size is 2MB.');
                redirect('settings');
            }

            $upload_dir = 'assets/img/avatars/uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $ext = pathinfo($_FILES['custom_avatar']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $dest = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['custom_avatar']['tmp_name'], $dest)) {
                $avatar_path = $dest;
            } else {
                setFlashMessage('danger', 'Failed to upload image. Please try again.');
                redirect('settings');
            }

        } elseif (!empty($_POST['preset_avatar'])) {
            // Validate preset avatar — only allow known safe filenames
            $allowed_presets = [
                'avatar-1.png','avatar-2.png','avatar-3.png','avatar-4.png',
                'avatar-5.png','avatar-6.png','avatar-7.png','avatar-8.png',
                'avatar-9.png','avatar-10.png','avatar-11.png','avatar-12.png',
            ];
            $chosen = basename(sanitize($_POST['preset_avatar']));
            if (in_array($chosen, $allowed_presets)) {
                $avatar_path = 'assets/img/avatars/' . $chosen;
            } else {
                setFlashMessage('danger', 'Invalid avatar selection.');
                redirect('settings');
            }
        }

        if ($avatar_path) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                $stmt->execute([$avatar_path, $_SESSION['user_id']]);
                setFlashMessage('success', 'Avatar updated successfully!');
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error updating avatar: ' . $e->getMessage());
            }
        } else {
            setFlashMessage('danger', 'Please select or upload an avatar.');
        }

        redirect('settings');
    }
}

// Get email statistics
$emailStats = $pdo->prepare("
    SELECT 
        email_type,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        MAX(created_at) as last_sent
    FROM email_log 
    WHERE recipient_email = ?
    GROUP BY email_type
");
$emailStats->execute([$user['email']]);
$emailStatsRaw = $emailStats->fetchAll(PDO::FETCH_ASSOC);

// Organize stats by email type
$emailStatistics = [];
foreach ($emailStatsRaw as $stat) {
    $emailStatistics[$stat['email_type']] = $stat;
}
?>

    <div class="container-fluid py-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-1">Settings</h1>
                <p class="text-muted">Manage your account settings and preferences</p>
            </div>
            <div class="col-auto">
                <a href="email-history" class="btn btn-outline-primary">
                    <i class="fas fa-envelope"></i> Email History
                </a>
            </div>
        </div>

        <?php
        // Display flash messages
        $flash = getFlashMessage();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $flash['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column -->
            <div class="col-lg-8">

                <!-- Avatar Settings -->
                <?php
                $presetAvatars = [
                    'avatar-1.png','avatar-2.png','avatar-3.png','avatar-4.png',
                    'avatar-5.png','avatar-6.png','avatar-7.png','avatar-8.png',
                    'avatar-9.png','avatar-10.png','avatar-11.png','avatar-12.png',
                ];
                $currentAvatar = $user['avatar'] ?? null;
                $avatarDisplay = $currentAvatar ?: 'assets/img/avatars/avatar-1.png';
                ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-id-badge me-2"></i>Profile Avatar
                        </h5>
                    </div>
                    <div class="card-body">

                        <!-- Current avatar preview -->
                        <div class="d-flex align-items-center mb-4 gap-3">
                            <div class="position-relative">
                                <img id="avatarPreview"
                                     src="<?php echo htmlspecialchars($avatarDisplay); ?>"
                                     alt="Current avatar"
                                     class="rounded-circle border border-3 border-primary shadow-sm"
                                     style="width:80px;height:80px;object-fit:cover;">
                                <span class="position-absolute bottom-0 end-0 bg-success border border-2 border-white rounded-circle"
                                      style="width:16px;height:16px;"></span>
                            </div>
                            <div>
                                <p class="mb-1 fw-semibold">Current Avatar</p>
                                <p class="text-muted small mb-0">Choose a preset below or upload your own image</p>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="avatarForm">
                            <input type="hidden" name="action" value="update_avatar">
                            <input type="hidden" name="preset_avatar" id="selectedPreset" value="">

                            <!-- Preset grid -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold mb-3">
                                    <i class="fas fa-th me-1 text-muted"></i>Choose a Preset Avatar
                                </label>
                                <div class="row row-cols-4 row-cols-sm-6 g-2" id="presetGrid">
                                    <?php foreach ($presetAvatars as $preset): ?>
                                        <?php
                                        $presetPath = 'assets/img/avatars/' . $preset;
                                        $isActive   = ($currentAvatar === $presetPath);
                                        ?>
                                        <div class="col">
                                            <div class="avatar-option text-center"
                                                 data-preset="<?php echo htmlspecialchars($preset); ?>"
                                                 data-src="<?php echo htmlspecialchars($presetPath); ?>"
                                                 title="<?php echo htmlspecialchars(ucfirst(str_replace(['-','.png'], [' #',''], $preset))); ?>"
                                                 style="cursor:pointer;">
                                                <img src="<?php echo htmlspecialchars($presetPath); ?>"
                                                     alt="<?php echo htmlspecialchars($preset); ?>"
                                                     class="rounded-circle border border-2 <?php echo $isActive ? 'border-primary avatar-selected' : 'border-transparent'; ?> hover-shadow"
                                                     style="width:52px;height:52px;object-fit:cover;transition:transform .15s,box-shadow .15s;">
                                                <?php if ($isActive): ?>
                                                    <div class="mt-1">
                                                        <span class="badge bg-primary" style="font-size:.65rem;">Active</span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="mt-1" style="height:18px;"></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Divider -->
                            <div class="d-flex align-items-center gap-2 mb-4 text-muted">
                                <hr class="flex-grow-1 my-0">
                                <small class="fw-semibold text-uppercase" style="font-size:.7rem;letter-spacing:.06em;">or upload your own</small>
                                <hr class="flex-grow-1 my-0">
                            </div>

                            <!-- Custom upload -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-upload me-1 text-muted"></i>Upload Custom Image
                                </label>
                                <div class="upload-zone rounded border-2 border-dashed p-3 text-center"
                                     id="uploadZone"
                                     style="border-style:dashed;border-color:#ced4da;cursor:pointer;transition:border-color .2s,background .2s;">
                                    <input type="file"
                                           name="custom_avatar"
                                           id="customAvatarInput"
                                           accept="image/jpeg,image/png,image/gif,image/webp"
                                           class="d-none">
                                    <div id="uploadPrompt">
                                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                        <p class="mb-1 text-muted small">Drag &amp; drop or <span class="text-primary fw-semibold" style="cursor:pointer;" id="browseBtn">browse</span></p>
                                        <p class="text-muted" style="font-size:.75rem;">JPEG, PNG, GIF, WebP · Max 2 MB</p>
                                    </div>
                                    <div id="uploadPreviewWrap" class="d-none">
                                        <img id="uploadPreviewImg" src="" alt="Preview"
                                             class="rounded-circle border border-2 border-success shadow-sm mb-2"
                                             style="width:64px;height:64px;object-fit:cover;">
                                        <p class="mb-0 text-success small fw-semibold" id="uploadFileName"></p>
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 mt-1" id="clearUpload">
                                            <i class="fas fa-times me-1"></i>Remove
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-primary" id="saveAvatarBtn" disabled>
                                    <i class="fas fa-save me-1"></i>Save Avatar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Profile Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user me-2"></i>Profile Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control" value="<?php echo sanitize($user['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" class="form-control" value="<?php echo sanitize($user['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone" class="form-control" value="<?php echo sanitize($user['phone'] ?? ''); ?>" placeholder="+1 (555) 123-4567">
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-save"></i> Save Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Email Notification Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-envelope me-2"></i>Email Notifications
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_email_settings">

                            <div class="mb-4">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" required>
                                <div class="form-text">All notifications will be sent to this email address</div>
                            </div>

                            <div class="mb-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="emailNotifications"
                                           name="email_notifications_enabled"
                                        <?php echo $user['email_notifications_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="emailNotifications">
                                        <strong>Enable Email Notifications</strong>
                                    </label>
                                </div>
                                <div class="form-text ms-5">Turn off to stop receiving all automated emails</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">Email Frequency Preference</label>

                                <div class="form-check mb-3 p-3 border rounded">
                                    <input class="form-check-input" type="radio" name="email_frequency" id="freq_all"
                                           value="all" <?php echo $user['email_frequency'] === 'all' ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="freq_all">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong>All Reminders</strong>
                                            <span class="badge bg-primary">Most Updates</span>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Overdue services (daily if overdue)</div>
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Urgent services - &lt; 500 km (every 3 days)</div>
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Upcoming services - 500-1500 km (weekly)</div>
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Low mileage warnings (monthly)</div>
                                            <div><i class="fas fa-check-circle text-success"></i> Monthly vehicle check-ins</div>
                                        </div>
                                    </label>
                                </div>

                                <div class="form-check mb-3 p-3 border rounded">
                                    <input class="form-check-input" type="radio" name="email_frequency" id="freq_important"
                                           value="important" <?php echo $user['email_frequency'] === 'important' ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="freq_important">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong>Important Only</strong>
                                            <span class="badge bg-warning">Balanced</span>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Overdue services (daily if overdue)</div>
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Urgent services - &lt; 500 km (every 3 days)</div>
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Low mileage warnings (monthly)</div>
                                            <div class="mb-1"><i class="fas fa-times-circle text-danger"></i> Upcoming services - skipped</div>
                                            <div><i class="fas fa-times-circle text-danger"></i> Monthly check-ins - skipped</div>
                                        </div>
                                    </label>
                                </div>

                                <div class="form-check mb-3 p-3 border rounded">
                                    <input class="form-check-input" type="radio" name="email_frequency" id="freq_critical"
                                           value="critical" <?php echo $user['email_frequency'] === 'critical' ? 'checked' : ''; ?>>
                                    <label class="form-check-label w-100" for="freq_critical">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong>Critical Only</strong>
                                            <span class="badge bg-danger">Minimal Emails</span>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <div class="mb-1"><i class="fas fa-check-circle text-success"></i> Overdue services only (daily if overdue)</div>
                                            <div class="mb-1"><i class="fas fa-times-circle text-danger"></i> All other email types disabled</div>
                                            <div class="text-warning mt-2">
                                                <i class="fas fa-exclamation-triangle"></i> You'll only be notified when service is already overdue
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>How it works:</strong> The system checks your vehicles daily and sends reminders based on your preference.
                                You can always view your <a href="email-history" class="alert-link">email history</a>.
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-save"></i> Save Email Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-lock me-2"></i>Change Password
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">

                            <div class="mb-3">
                                <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">New Password <span class="text-danger">*</span></label>
                                <input type="password" name="new_password" class="form-control" required minlength="6">
                                <div class="form-text">Must be at least 8 characters long</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="6">
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preferences -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-cog me-2"></i>Display Preferences
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_preferences">

                            <?php
                            $knownCurrencies = Preferences::knownCurrencies();
                            $isCustomCurrency = !array_key_exists($prefs['currency'], $knownCurrencies);
                            ?>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Currency</label>
                                    <select name="default_currency" id="currencySelect" class="form-select">
                                        <?php foreach ($knownCurrencies as $code => $symbol): ?>
                                            <option value="<?php echo $code; ?>" <?php echo (!$isCustomCurrency && $prefs['currency'] === $code) ? 'selected' : ''; ?>><?php echo $code; ?> (<?php echo $symbol; ?>)</option>
                                        <?php endforeach; ?>
                                        <option value="__custom__" <?php echo $isCustomCurrency ? 'selected' : ''; ?>>Other (specify)...</option>
                                    </select>
                                    <div id="customCurrencyFields" class="row mt-2 <?php echo $isCustomCurrency ? '' : 'd-none'; ?>">
                                        <div class="col-6">
                                            <input type="text" name="custom_currency_code" class="form-control form-control-sm" placeholder="Code, e.g. KES" maxlength="10" value="<?php echo $isCustomCurrency ? sanitize($prefs['currency']) : ''; ?>">
                                        </div>
                                        <div class="col-6">
                                            <input type="text" name="custom_currency_symbol" class="form-control form-control-sm" placeholder="Symbol, e.g. KSh" maxlength="10" value="<?php echo $isCustomCurrency ? sanitize($prefs['currency_symbol']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Distance Unit</label>
                                    <select name="default_distance_unit" class="form-select">
                                        <option value="km" <?php echo $prefs['distance_unit'] === 'km' ? 'selected' : ''; ?>>Kilometers (km)</option>
                                        <option value="mi" <?php echo $prefs['distance_unit'] === 'mi' ? 'selected' : ''; ?>>Miles (mi)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Volume Unit (Fuel)</label>
                                    <select name="default_volume_unit" class="form-select">
                                        <option value="L" <?php echo $prefs['volume_unit'] === 'L' ? 'selected' : ''; ?>>Liters (L)</option>
                                        <option value="gal" <?php echo $prefs['volume_unit'] === 'gal' ? 'selected' : ''; ?>>Gallons (gal)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Timezone</label>
                                    <select name="timezone" class="form-select">
                                        <option value="UTC" <?php echo $prefs['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                        <option value="Africa/Nairobi" <?php echo $prefs['timezone'] === 'Africa/Nairobi' ? 'selected' : ''; ?>>Nairobi</option>
                                        <option value="America/New_York" <?php echo $prefs['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>Eastern Time (US)</option>
                                        <option value="America/Chicago" <?php echo $prefs['timezone'] === 'America/Chicago' ? 'selected' : ''; ?>>Central Time (US)</option>
                                        <option value="America/Denver" <?php echo $prefs['timezone'] === 'America/Denver' ? 'selected' : ''; ?>>Mountain Time (US)</option>
                                        <option value="America/Los_Angeles" <?php echo $prefs['timezone'] === 'America/Los_Angeles' ? 'selected' : ''; ?>>Pacific Time (US)</option>
                                        <option value="Europe/London" <?php echo $prefs['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>London</option>
                                        <option value="Europe/Paris" <?php echo $prefs['timezone'] === 'Europe/Paris' ? 'selected' : ''; ?>>Paris</option>
                                        <option value="Asia/Tokyo" <?php echo $prefs['timezone'] === 'Asia/Tokyo' ? 'selected' : ''; ?>>Tokyo</option>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-save"></i> Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    document.getElementById('currencySelect').addEventListener('change', function () {
                        document.getElementById('customCurrencyFields').classList.toggle('d-none', this.value !== '__custom__');
                    });
                </script>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Email Statistics -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Email Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $emailTypes = [
                            'service_reminder' => ['label' => 'Service Reminders', 'icon' => 'fa-bell', 'color' => 'warning'],
                            'monthly_check' => ['label' => 'Monthly Check-ins', 'icon' => 'fa-calendar', 'color' => 'info'],
                            'service_details' => ['label' => 'Service Details', 'icon' => 'fa-wrench', 'color' => 'success'],
                            'low_mileage_warning' => ['label' => 'Low Mileage Warnings', 'icon' => 'fa-tachometer-alt', 'color' => 'danger']
                        ];

                        $totalEmails = 0;
                        foreach ($emailStatistics as $type => $stats) {
                            $totalEmails += $stats['total'] ?? 0;
                        }
                        ?>

                        <div class="mb-4">
                            <div class="text-center">
                                <h2 class="display-4 mb-0"><?php echo $totalEmails; ?></h2>
                                <p class="text-muted">Total Emails Received</p>
                            </div>
                        </div>

                        <?php foreach ($emailTypes as $type => $info):
                            $stats = $emailStatistics[$type] ?? null;
                            if (!$stats) continue;
                            ?>
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                                <div class="d-flex align-items-center">
                                    <div class="bg-<?php echo $info['color']; ?> bg-opacity-10 text-<?php echo $info['color']; ?> rounded p-2 me-3">
                                        <i class="fas <?php echo $info['icon']; ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo $info['label']; ?></div>
                                        <small class="text-muted">
                                            Last: <?php echo $stats['last_sent'] ? formatDateTimeForUser($stats['last_sent'], $prefs, 'M d, Y') : 'Never'; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold"><?php echo $stats['total']; ?></div>
                                    <small class="text-success"><?php echo $stats['sent']; ?> sent</small>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($totalEmails === 0): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No emails sent yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Account Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle me-2"></i>Account Status
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="text-muted small">Email Notifications</label>
                            <div>
                                <?php if ($user['email_notifications_enabled']): ?>
                                    <span class="badge bg-success"><i class="fas fa-check"></i> Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-times"></i> Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="text-muted small">Email Frequency</label>
                            <div>
                                <?php
                                $frequencyLabels = [
                                    'all' => ['label' => 'All Reminders', 'color' => 'primary'],
                                    'important' => ['label' => 'Important Only', 'color' => 'warning'],
                                    'critical' => ['label' => 'Critical Only', 'color' => 'danger']
                                ];
                                $currentFreq = $frequencyLabels[$user['email_frequency']] ?? $frequencyLabels['all'];
                                ?>
                                <span class="badge bg-<?php echo $currentFreq['color']; ?>">
                                <?php echo $currentFreq['label']; ?>
                            </span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="text-muted small">Member Since</label>
                            <div><?php echo formatDateTimeForUser($user['created_at'], $prefs, 'F d, Y'); ?></div>
                        </div>

                        <div class="mb-0">
                            <label class="text-muted small">Last Updated</label>
                            <div><?php echo formatDateTimeForUser($user['updated_at'] ?? $user['created_at'], $prefs, 'F d, Y'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="email-history" class="btn btn-outline-primary">
                                <i class="fas fa-envelope"></i> View Email History
                            </a>
                            <a href="vehicles" class="btn btn-outline-secondary">
                                <i class="fas fa-car"></i> Manage Vehicles
                            </a>
                            <a href="service-reminders" class="btn btn-outline-warning">
                                <i class="fas fa-bell"></i> Service Reminders
                            </a>
                            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#testEmailModal">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Email Modal -->
    <div class="modal fade" id="testEmailModal" tabindex="-1" aria-labelledby="testEmailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="testEmailModalLabel">
                        <i class="fas fa-paper-plane me-2"></i>Send Test Email
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This will send a test email to <strong><?php echo sanitize($user['email']); ?></strong> to verify your email settings are working correctly.</p>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        The test email will appear in your <a href="email-history" class="alert-link">email history</a>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="" class="d-inline">
                        <input type="hidden" name="action" value="send_test_email">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Test Email
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Avatar picker styles */
        .avatar-option img {
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .avatar-option:hover img {
            transform: scale(1.12);
            box-shadow: 0 0 0 3px rgba(var(--falcon-primary-rgb), .35) !important;
        }
        .avatar-option img.avatar-selected {
            transform: scale(1.08);
            box-shadow: 0 0 0 3px var(--falcon-primary) !important;
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: var(--falcon-primary) !important;
            background: rgba(var(--falcon-primary-rgb), .04);
        }
    </style>

    <script>
        (function () {
            const presetGrid    = document.getElementById('presetGrid');
            const selectedInput = document.getElementById('selectedPreset');
            const avatarPreview = document.getElementById('avatarPreview');
            const saveBtn       = document.getElementById('saveAvatarBtn');
            const fileInput     = document.getElementById('customAvatarInput');
            const uploadZone    = document.getElementById('uploadZone');
            const uploadPrompt  = document.getElementById('uploadPrompt');
            const previewWrap   = document.getElementById('uploadPreviewWrap');
            const previewImg    = document.getElementById('uploadPreviewImg');
            const fileNameEl    = document.getElementById('uploadFileName');
            const clearBtn      = document.getElementById('clearUpload');
            const browseBtn     = document.getElementById('browseBtn');

            // ── Preset selection ──────────────────────────────────────────
            presetGrid.addEventListener('click', function (e) {
                const option = e.target.closest('.avatar-option');
                if (!option) return;

                // Deselect all
                presetGrid.querySelectorAll('.avatar-option img').forEach(img => {
                    img.classList.remove('avatar-selected', 'border-primary');
                    img.classList.add('border-transparent');
                    img.closest('.avatar-option').querySelector('.mt-1').innerHTML =
                        '<div style="height:18px;"></div>';
                });

                // Select clicked
                const img = option.querySelector('img');
                img.classList.add('avatar-selected', 'border-primary');
                img.classList.remove('border-transparent');
                option.querySelector('.mt-1').innerHTML =
                    '<span class="badge bg-primary" style="font-size:.65rem;">Active</span>';

                // Update preview & hidden input
                selectedInput.value = option.dataset.preset;
                avatarPreview.src   = option.dataset.src;

                // Clear any file upload
                resetUpload();

                saveBtn.disabled = false;
            });

            // ── File upload zone ──────────────────────────────────────────
            browseBtn.addEventListener('click', () => fileInput.click());
            uploadZone.addEventListener('click', (e) => {
                if (e.target !== clearBtn && !clearBtn.contains(e.target)) {
                    fileInput.click();
                }
            });

            uploadZone.addEventListener('dragover', e => {
                e.preventDefault();
                uploadZone.classList.add('drag-over');
            });
            uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
            uploadZone.addEventListener('drop', e => {
                e.preventDefault();
                uploadZone.classList.remove('drag-over');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFile(e.dataTransfer.files[0]);
                }
            });

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) handleFile(fileInput.files[0]);
            });

            function handleFile(file) {
                const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                if (!allowed.includes(file.type)) {
                    alert('Please select a JPEG, PNG, GIF, or WebP image.');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    alert('File is too large. Maximum size is 2 MB.');
                    return;
                }

                const reader = new FileReader();
                reader.onload = e => {
                    previewImg.src = e.target.result;
                    avatarPreview.src = e.target.result;
                };
                reader.readAsDataURL(file);

                fileNameEl.textContent = file.name;
                uploadPrompt.classList.add('d-none');
                previewWrap.classList.remove('d-none');

                // Deselect all presets when a custom file is chosen
                presetGrid.querySelectorAll('.avatar-option img').forEach(img => {
                    img.classList.remove('avatar-selected', 'border-primary');
                    img.classList.add('border-transparent');
                });
                selectedInput.value = '';
                saveBtn.disabled = false;
            }

            clearBtn.addEventListener('click', e => {
                e.stopPropagation();
                resetUpload();
                saveBtn.disabled = true;
            });

            function resetUpload() {
                fileInput.value = '';
                previewWrap.classList.add('d-none');
                uploadPrompt.classList.remove('d-none');
                previewImg.src = '';
                fileNameEl.textContent = '';
            }
        })();
    </script>

<?php require_once 'includes/footer.php'; ?>