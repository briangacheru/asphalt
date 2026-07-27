<?php
$pageTitle = 'Admin Dashboard';
require_once 'includes/header.php';

use App\Middleware\AuthMiddleware;
use App\Services\DatabaseBackupService;
use App\Services\SiteSettingsService;

AuthMiddleware::requireAdmin();

$allowedCategoryColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid request. Please try again.');
        redirect('admin');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_user_active') {
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if ($targetId === (int) $userId) {
            setFlashMessage('danger', 'You cannot deactivate your own account.');
            redirect('admin');
        }

        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            setFlashMessage('danger', 'User not found.');
        } else {
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")
                ->execute([$current ? 0 : 1, $targetId]);
            setFlashMessage('success', 'User status updated.');
        }

        redirect('admin');
    }

    if ($action === 'toggle_user_role') {
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if ($targetId === (int) $userId) {
            setFlashMessage('danger', 'You cannot change your own role.');
            redirect('admin');
        }

        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $currentRole = $stmt->fetchColumn();

        if ($currentRole === false) {
            setFlashMessage('danger', 'User not found.');
            redirect('admin');
        }

        if ($currentRole === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                setFlashMessage('danger', 'Cannot remove the last remaining admin.');
                redirect('admin');
            }
        }

        $newRole = $currentRole === 'admin' ? 'user' : 'admin';
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
        setFlashMessage('success', "User role updated to $newRole.");
        redirect('admin');
    }

    if ($action === 'run_backup_now') {
        try {
            $backupService = new DatabaseBackupService($pdo);
            $details = $backupService->createBackup();
            setFlashMessage('success', 'Backup completed: ' . $details['filename'] . ' (' . number_format($details['size_bytes'] / 1024 / 1024, 2) . ' MB)');
        } catch (\Throwable $e) {
            setFlashMessage('danger', 'Backup failed: ' . $e->getMessage());
        }
        redirect('admin');
    }

    if ($action === 'delete_backup') {
        $filename = basename($_POST['filename'] ?? '');
        if (preg_match('/^db_backup_[A-Za-z0-9_]+\.sql\.gz$/', $filename)) {
            @unlink(UPLOAD_DIR . 'backups/' . $filename);
            setFlashMessage('success', 'Backup deleted.');
        } else {
            setFlashMessage('danger', 'Invalid filename.');
        }
        redirect('admin');
    }

    if ($action === 'add_document_category') {
        $label = sanitize($_POST['label'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-file';
        $color = $_POST['color'] ?? 'dark';
        if (!in_array($color, $allowedCategoryColors, true)) {
            $color = 'dark';
        }
        // Icon must be a bare Font Awesome class name (e.g. "fa-shield-alt"), never raw markup
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-file';

        if ($label === '') {
            setFlashMessage('danger', 'Category name is required.');
        } else {
            $slug = strtolower(trim($label));
            $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
            $slug = trim($slug, '_') ?: 'category';

            $existsStmt = $pdo->prepare("SELECT id FROM vehicle_document_categories WHERE slug = ?");
            $existsStmt->execute([$slug]);
            $suffix = 2;
            $baseSlug = $slug;
            while ($existsStmt->fetch()) {
                $slug = $baseSlug . '_' . $suffix++;
                $existsStmt->execute([$slug]);
            }

            $pdo->prepare("INSERT INTO vehicle_document_categories (slug, label, icon, color) VALUES (?, ?, ?, ?)")
                ->execute([$slug, $label, $icon, $color]);
            setFlashMessage('success', 'Document category added.');
        }
        redirect('admin');
    }

    if ($action === 'edit_document_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $label = sanitize($_POST['label'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-file';
        $color = $_POST['color'] ?? 'dark';
        if (!in_array($color, $allowedCategoryColors, true)) {
            $color = 'dark';
        }
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-file';

        if ($categoryId && $label !== '') {
            $pdo->prepare("UPDATE vehicle_document_categories SET label = ?, icon = ?, color = ? WHERE id = ?")
                ->execute([$label, $icon, $color, $categoryId]);
            setFlashMessage('success', 'Document category updated.');
        } else {
            setFlashMessage('danger', 'Category name is required.');
        }
        redirect('admin');
    }

    if ($action === 'delete_document_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $slugStmt = $pdo->prepare("SELECT slug FROM vehicle_document_categories WHERE id = ?");
        $slugStmt->execute([$categoryId]);
        $slugRow = $slugStmt->fetch();

        if (!$slugRow) {
            setFlashMessage('danger', 'Category not found.');
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_documents WHERE category = ?");
            $countStmt->execute([$slugRow['slug']]);
            $inUseCount = (int) $countStmt->fetchColumn();
            $totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM vehicle_document_categories")->fetchColumn();

            if ($inUseCount > 0) {
                setFlashMessage('danger', "Can't delete: {$inUseCount} document(s) still use this category.");
            } elseif ($totalCategories <= 1) {
                setFlashMessage('danger', "Can't delete the last remaining category.");
            } else {
                $pdo->prepare("DELETE FROM vehicle_document_categories WHERE id = ?")->execute([$categoryId]);
                setFlashMessage('success', 'Document category deleted.');
            }
        }
        redirect('admin');
    }

    if ($action === 'add_expense_category') {
        $name = sanitize($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-receipt';
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-receipt';

        if ($name === '') {
            setFlashMessage('danger', 'Category name is required.');
        } else {
            $pdo->prepare("INSERT INTO expense_categories (name, icon) VALUES (?, ?)")->execute([$name, $icon]);
            setFlashMessage('success', 'Expense category added.');
        }
        redirect('admin');
    }

    if ($action === 'edit_expense_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-receipt';
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-receipt';

        if ($categoryId && $name !== '') {
            $pdo->prepare("UPDATE expense_categories SET name = ?, icon = ? WHERE id = ?")
                ->execute([$name, $icon, $categoryId]);
            setFlashMessage('success', 'Expense category updated.');
        } else {
            setFlashMessage('danger', 'Category name is required.');
        }
        redirect('admin');
    }

    if ($action === 'delete_expense_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $existsStmt = $pdo->prepare("SELECT id FROM expense_categories WHERE id = ?");
        $existsStmt->execute([$categoryId]);

        if (!$existsStmt->fetch()) {
            setFlashMessage('danger', 'Category not found.');
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ?");
            $countStmt->execute([$categoryId]);
            $inUseCount = (int) $countStmt->fetchColumn();
            $totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();

            if ($inUseCount > 0) {
                setFlashMessage('danger', "Can't delete: {$inUseCount} expense(s) still use this category.");
            } elseif ($totalCategories <= 1) {
                setFlashMessage('danger', "Can't delete the last remaining category.");
            } else {
                $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$categoryId]);
                setFlashMessage('success', 'Expense category deleted.');
            }
        }
        redirect('admin');
    }

    if ($action === 'update_reminder_settings') {
        $oilRaw = $_POST['oil_intervals'] ?? '';
        $oilValues = array_filter(array_map('intval', array_map('trim', explode(',', $oilRaw))), fn($v) => $v > 0);
        $oilValues = array_values(array_unique($oilValues));
        sort($oilValues);

        if (empty($oilValues)) {
            setFlashMessage('danger', 'Please provide at least one oil interval.');
            redirect('admin');
        }
        SiteSettingsService::set($pdo, 'oil_intervals_km', json_encode($oilValues), $userId);

        $names = $_POST['preset_name'] ?? [];
        $kms = $_POST['preset_km'] ?? [];
        $months = $_POST['preset_months'] ?? [];
        $presets = [];
        foreach ($names as $i => $name) {
            $name = sanitize(trim($name));
            if ($name === '') {
                continue;
            }
            $km = isset($kms[$i]) && $kms[$i] !== '' ? (int) $kms[$i] : null;
            $mo = isset($months[$i]) && $months[$i] !== '' ? (int) $months[$i] : null;
            $presets[$name] = ['km' => $km, 'months' => $mo];
        }
        if (!empty($presets)) {
            SiteSettingsService::set($pdo, 'maintenance_presets', json_encode($presets), $userId);
        }

        $thresholds = [
            'urgent_km' => max(1, (int) ($_POST['urgent_km'] ?? 500)),
            'upcoming_km' => max(1, (int) ($_POST['upcoming_km'] ?? 1500)),
            'urgent_resend_days' => max(1, (int) ($_POST['urgent_resend_days'] ?? 3)),
            'upcoming_resend_days' => max(1, (int) ($_POST['upcoming_resend_days'] ?? 7)),
            'overdue_resend_days' => max(1, (int) ($_POST['overdue_resend_days'] ?? 1)),
            'maintenance_due_soon_km' => max(0, (int) ($_POST['maintenance_due_soon_km'] ?? 1000)),
            'maintenance_due_soon_days' => max(0, (int) ($_POST['maintenance_due_soon_days'] ?? 30)),
        ];
        SiteSettingsService::set($pdo, 'reminder_thresholds', json_encode($thresholds), $userId);

        setFlashMessage('success', 'Reminder & maintenance settings updated.');
        redirect('admin');
    }

    if ($action === 'update_site_access') {
        $maintenanceMode = isset($_POST['maintenance_mode']) ? '1' : '0';
        $registrationsEnabled = isset($_POST['registrations_enabled']) ? '1' : '0';

        SiteSettingsService::set($pdo, 'maintenance_mode', $maintenanceMode, $userId);
        SiteSettingsService::set($pdo, 'registrations_enabled', $registrationsEnabled, $userId);

        setFlashMessage('success', 'Site access settings updated.');
        redirect('admin');
    }
}

$users = $pdo->query("
    SELECT u.*, COUNT(v.id) AS vehicle_count
    FROM users u
    LEFT JOIN vehicles v ON v.user_id = u.id AND v.is_active = 1
    GROUP BY u.id
    ORDER BY u.created_at ASC
")->fetchAll();

$totalVehicles = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();

$backupDir = UPLOAD_DIR . 'backups/';
$backupFiles = [];
foreach (glob($backupDir . 'db_backup_*.sql.gz') ?: [] as $file) {
    $backupFiles[] = [
        'filename' => basename($file),
        'size' => filesize($file),
        'modified' => filemtime($file),
    ];
}
usort($backupFiles, fn($a, $b) => $b['modified'] <=> $a['modified']);

$cronLogs = [];
try {
    $cronLogs = $pdo->query("SELECT * FROM cron_job_log WHERE emails_failed > 0 ORDER BY run_at DESC LIMIT 20")->fetchAll();
} catch (PDOException $e) {
    // No cron has run yet — table may not exist. Nothing to show.
}

$documentCategories = $pdo->query("SELECT * FROM vehicle_document_categories ORDER BY label")->fetchAll();
$expenseCategories = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();

$iconQuickPicks = [
    'fa-shield-alt', 'fa-ship', 'fa-gas-pump', 'fa-id-card', 'fa-clipboard-check',
    'fa-file', 'fa-file-invoice', 'fa-receipt', 'fa-car', 'fa-wrench',
    'fa-key', 'fa-passport', 'fa-credit-card', 'fa-camera', 'fa-folder', 'fa-file-contract',
];

$defaultOilIntervals = [7000, 7500, 8000, 8500, 9000, 9500, 10000];
$oilIntervalsRaw = SiteSettingsService::get($pdo, 'oil_intervals_km');
$oilIntervals = $oilIntervalsRaw ? (json_decode($oilIntervalsRaw, true) ?: $defaultOilIntervals) : $defaultOilIntervals;

$defaultMaintenancePresets = [
    'Engine Oil & Filter' => ['km' => 10000, 'months' => 12],
    'Air Filter' => ['km' => 20000, 'months' => 24],
    'Cabin Filter' => ['km' => 15000, 'months' => 12],
    'Spark Plugs' => ['km' => 40000, 'months' => 48],
    'Brake Fluid' => ['km' => 40000, 'months' => 24],
    'Coolant' => ['km' => 50000, 'months' => 36],
    'Transmission Fluid' => ['km' => 60000, 'months' => 48],
    'Power Steering Fluid' => ['km' => 50000, 'months' => 36],
    'Timing Belt' => ['km' => 100000, 'months' => 72],
    'Serpentine Belt' => ['km' => 80000, 'months' => 60],
    'Battery' => ['km' => null, 'months' => 48],
    'Front Brake Pads' => ['km' => 50000, 'months' => null],
    'Rear Brake Pads' => ['km' => 60000, 'months' => null],
    'Tires' => ['km' => 50000, 'months' => 48],
    'Wiper Blades' => ['km' => null, 'months' => 12],
];
$maintenancePresetsRaw = SiteSettingsService::get($pdo, 'maintenance_presets');
$maintenancePresets = $maintenancePresetsRaw ? (json_decode($maintenancePresetsRaw, true) ?: $defaultMaintenancePresets) : $defaultMaintenancePresets;

$defaultReminderThresholds = [
    'urgent_km' => 500,
    'upcoming_km' => 1500,
    'urgent_resend_days' => 3,
    'upcoming_resend_days' => 7,
    'overdue_resend_days' => 1,
    'maintenance_due_soon_km' => 1000,
    'maintenance_due_soon_days' => 30,
];
$reminderThresholdsRaw = SiteSettingsService::get($pdo, 'reminder_thresholds');
$reminderThresholds = array_merge(
    $defaultReminderThresholds,
    $reminderThresholdsRaw ? (json_decode($reminderThresholdsRaw, true) ?: []) : []
);

$maintenanceModeOn = SiteSettingsService::get($pdo, 'maintenance_mode') === '1';
$registrationsEnabled = SiteSettingsService::get($pdo, 'registrations_enabled') !== '0';
?>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-1"><span class="fas fa-user-shield me-2 text-warning"></span>Admin Dashboard</h1>
                <p class="text-muted">Site-wide management — users, backups, and scheduled job health</p>
            </div>
        </div>

        <?php
        $flash = getFlashMessage();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $flash['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Site Access -->
        <div class="card mb-4 <?php echo $maintenanceModeOn ? 'border-warning' : ''; ?>">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-power-off me-2"></i>Site Access</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_site_access">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="mb-1 fw-semibold">Maintenance Mode</p>
                            <p class="text-muted small mb-0">When on, only admins can sign in or use the site. Everyone else sees a maintenance notice.</p>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" id="maintenanceModeToggle" <?php echo $maintenanceModeOn ? 'checked' : ''; ?> onchange="this.form.requestSubmit()">
                        </div>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 fw-semibold">Allow New Registrations</p>
                            <p class="text-muted small mb-0">Turn off to close the sign-up page to new accounts. Existing users can still log in.</p>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="registrations_enabled" id="registrationsEnabledToggle" <?php echo $registrationsEnabled ? 'checked' : ''; ?> onchange="this.form.requestSubmit()">
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded p-3 me-3"><i class="fas fa-users fa-2x"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Total Users</h6>
                            <h3 class="mb-0"><?php echo count($users); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded p-3 me-3"><i class="fas fa-car fa-2x"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Active Vehicles</h6>
                            <h3 class="mb-0"><?php echo $totalVehicles; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 text-info rounded p-3 me-3"><i class="fas fa-database fa-2x"></i></div>
                        <div>
                            <h6 class="text-muted mb-1">Backups Stored</h6>
                            <h3 class="mb-0"><?php echo count($backupFiles); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Management -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-users-cog me-2"></i>User Management</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Email</th>
                            <th>Vehicles</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="pe-3 text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="ps-3"><?php echo sanitize($u['first_name'] . ' ' . $u['last_name']); ?></td>
                                <td class="small text-muted"><?php echo sanitize($u['email']); ?></td>
                                <td><?php echo (int) $u['vehicle_count']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $u['role'] === 'admin' ? 'warning' : 'secondary'; ?>">
                                        <?php echo $u['role'] === 'admin' ? 'Admin' : 'User'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $u['is_active'] ? 'Active' : 'Disabled'; ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                <td class="pe-3 text-end text-nowrap">
                                    <?php if ((int) $u['id'] === (int) $userId): ?>
                                        <span class="text-muted small fst-italic">This is you</span>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $u['role'] === 'admin' ? 'Remove admin access from' : 'Grant admin access to'; ?> <?php echo htmlspecialchars(addslashes($u['first_name'])); ?>?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_user_role">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Toggle admin role">
                                                <i class="fas fa-user-shield"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $u['is_active'] ? 'Disable' : 'Re-enable'; ?> this account?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_user_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-<?php echo $u['is_active'] ? 'danger' : 'success'; ?>" title="<?php echo $u['is_active'] ? 'Disable account' : 'Re-enable account'; ?>">
                                                <i class="fas fa-<?php echo $u['is_active'] ? 'ban' : 'check'; ?>"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Database Backups -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="fas fa-database me-2"></i>Database Backups</h5>
                <form method="POST" onsubmit="return confirm('Run a database backup now? This may take a few seconds.');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="run_backup_now">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-play me-1"></i>Run Backup Now
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($backupFiles)): ?>
                    <div class="text-center py-4 text-muted">No backups yet. Run one now, or wait for the scheduled cron job.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th class="ps-3">File</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th class="pe-3 text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($backupFiles as $b): ?>
                                <tr>
                                    <td class="ps-3 small"><?php echo htmlspecialchars($b['filename']); ?></td>
                                    <td class="small"><?php echo number_format($b['size'] / 1024 / 1024, 2); ?> MB</td>
                                    <td class="small text-muted"><?php echo date('M d, Y H:i', $b['modified']); ?></td>
                                    <td class="pe-3 text-end text-nowrap">
                                        <a href="download-backup?file=<?php echo urlencode($b['filename']); ?>" class="btn btn-sm btn-outline-primary" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this backup permanently?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cron Job Log -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-clock me-2"></i>Scheduled Job Runs With Failed Emails</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($cronLogs)): ?>
                    <div class="text-center py-4 text-muted">No failed job runs — everything's healthy.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th class="ps-3">Job</th>
                                <th>Vehicles</th>
                                <th>Emails Sent</th>
                                <th>Emails Failed</th>
                                <th class="pe-3">Ran At</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cronLogs as $log): ?>
                                <tr>
                                    <td class="ps-3 small"><?php echo sanitize(ucwords(str_replace('_', ' ', $log['job_name']))); ?></td>
                                    <td class="small"><?php echo (int) $log['vehicles_processed']; ?></td>
                                    <td class="small text-success"><?php echo (int) $log['emails_sent']; ?></td>
                                    <td class="small text-<?php echo $log['emails_failed'] > 0 ? 'danger' : 'muted'; ?>"><?php echo (int) $log['emails_failed']; ?></td>
                                    <td class="pe-3 small text-muted"><?php echo date('M d, Y H:i', strtotime($log['run_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Document Categories -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-tags me-2"></i>Vehicle Document Categories</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Shared across every user's Documents &amp; Photos gallery.</p>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documentCategories as $cat): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-subtle-<?php echo sanitize($cat['color']); ?>">
                                        <i class="fas <?php echo sanitize($cat['icon']); ?> me-1"></i><?php echo sanitize($cat['label']); ?>
                                    </span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary doc-category-edit-btn"
                                            data-id="<?php echo (int) $cat['id']; ?>"
                                            data-label="<?php echo sanitize($cat['label']); ?>"
                                            data-icon="<?php echo sanitize($cat['icon']); ?>"
                                            data-color="<?php echo sanitize($cat['color']); ?>"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category? Only possible if no documents use it.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_document_category">
                                        <input type="hidden" name="category_id" value="<?php echo (int) $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>

                <h6 class="mb-3" id="doc-category-form-heading"><i class="fas fa-plus-circle me-1"></i>Add New Category</h6>
                <form method="POST" id="doc-category-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_document_category" id="doc-category-form-action">
                    <input type="hidden" name="category_id" id="doc-category-form-id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="doc-category-form-label" class="form-control" placeholder="e.g. Warranty" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Color</label>
                            <select name="color" id="doc-category-form-color" class="form-select">
                                <?php foreach ($allowedCategoryColors as $color): ?>
                                    <option value="<?php echo $color; ?>"><?php echo ucfirst($color); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Icon</label>
                            <div class="input-group">
                                <span class="input-group-text" id="doc-category-icon-preview"><i class="fas fa-file"></i></span>
                                <input type="text" name="icon" id="doc-category-form-icon" class="form-control" value="fa-file" placeholder="e.g. fa-shield-alt">
                            </div>
                            <small class="text-muted">Font Awesome solid icon class — pick one below, or browse more at fontawesome.com/icons</small>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <?php foreach ($iconQuickPicks as $iconOption): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary doc-icon-pick-btn" data-icon="<?php echo $iconOption; ?>" title="<?php echo $iconOption; ?>">
                                        <i class="fas <?php echo $iconOption; ?>"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" id="doc-category-form-submit"><i class="fas fa-plus me-1"></i>Add Category</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="doc-category-form-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expense Categories -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-receipt me-2"></i>Expense Categories</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Shared across every user's Expenses page.</p>
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($expenseCategories as $cat): ?>
                            <tr>
                                <td><i class="fas <?php echo sanitize($cat['icon']); ?> me-1 text-muted"></i><?php echo sanitize($cat['name']); ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary expense-category-edit-btn"
                                            data-id="<?php echo (int) $cat['id']; ?>"
                                            data-name="<?php echo sanitize($cat['name']); ?>"
                                            data-icon="<?php echo sanitize($cat['icon']); ?>"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category? Only possible if no expenses use it.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_expense_category">
                                        <input type="hidden" name="category_id" value="<?php echo (int) $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>

                <h6 class="mb-3" id="expense-category-form-heading"><i class="fas fa-plus-circle me-1"></i>Add New Category</h6>
                <form method="POST" id="expense-category-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_expense_category" id="expense-category-form-action">
                    <input type="hidden" name="category_id" id="expense-category-form-id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="expense-category-form-name" class="form-control" placeholder="e.g. Parking" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Icon</label>
                            <div class="input-group">
                                <span class="input-group-text" id="expense-category-icon-preview"><i class="fas fa-receipt"></i></span>
                                <input type="text" name="icon" id="expense-category-form-icon" class="form-control" value="fa-receipt" placeholder="e.g. fa-gas-pump">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php foreach ($iconQuickPicks as $iconOption): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary expense-icon-pick-btn" data-icon="<?php echo $iconOption; ?>" title="<?php echo $iconOption; ?>">
                                        <i class="fas <?php echo $iconOption; ?>"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" id="expense-category-form-submit"><i class="fas fa-plus me-1"></i>Add Category</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="expense-category-form-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reminder & Maintenance Settings -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-sliders-h me-2"></i>Reminder &amp; Maintenance Settings</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-4">Controls the oil-change interval choices on Add Service, the quick-add presets on Maintenance Schedule, and the thresholds the reminder cron jobs use to decide when and how often to email users.</p>

                <form method="POST" id="reminder-settings-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_reminder_settings">

                    <h6 class="mb-2">Oil Change Interval Presets (km)</h6>
                    <input type="text" name="oil_intervals" class="form-control mb-1" value="<?php echo htmlspecialchars(implode(', ', $oilIntervals)); ?>" placeholder="7000, 7500, 8000...">
                    <div class="form-text mb-4">Comma-separated list of km values shown as quick-select options.</div>

                    <h6 class="mb-2">Service Reminder Thresholds</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small">Urgent at (km remaining)</label>
                            <input type="number" min="1" name="urgent_km" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['urgent_km']; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Upcoming at (km remaining)</label>
                            <input type="number" min="1" name="upcoming_km" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['upcoming_km']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Resend: overdue (days)</label>
                            <input type="number" min="1" name="overdue_resend_days" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['overdue_resend_days']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Resend: urgent (days)</label>
                            <input type="number" min="1" name="urgent_resend_days" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['urgent_resend_days']; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Resend: upcoming (days)</label>
                            <input type="number" min="1" name="upcoming_resend_days" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['upcoming_resend_days']; ?>">
                        </div>
                    </div>

                    <h6 class="mb-2">Maintenance Schedule "Due Soon" Window</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small">Km before due</label>
                            <input type="number" min="0" name="maintenance_due_soon_km" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['maintenance_due_soon_km']; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Days before due</label>
                            <input type="number" min="0" name="maintenance_due_soon_days" class="form-control form-control-sm" value="<?php echo (int) $reminderThresholds['maintenance_due_soon_days']; ?>">
                        </div>
                    </div>

                    <h6 class="mb-2">Default Maintenance Presets</h6>
                    <p class="text-muted small">Shown as quick-add buttons on the Maintenance Schedule page. Leave a field blank for "not tracked by that unit."</p>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm align-middle mb-0" id="maintenance-presets-table">
                            <thead>
                            <tr>
                                <th>Item</th>
                                <th style="width:140px;">Km Interval</th>
                                <th style="width:140px;">Month Interval</th>
                                <th style="width:40px;"></th>
                            </tr>
                            </thead>
                            <tbody id="maintenance-presets-body">
                                <?php foreach ($maintenancePresets as $name => $preset): ?>
                                    <tr>
                                        <td><input type="text" name="preset_name[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($name); ?>"></td>
                                        <td><input type="number" min="0" name="preset_km[]" class="form-control form-control-sm" value="<?php echo $preset['km'] !== null ? (int) $preset['km'] : ''; ?>" placeholder="—"></td>
                                        <td><input type="number" min="0" name="preset_months[]" class="form-control form-control-sm" value="<?php echo $preset['months'] !== null ? (int) $preset['months'] : ''; ?>" placeholder="—"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-preset-row"><i class="fas fa-times"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-4" id="add-preset-row"><i class="fas fa-plus me-1"></i>Add Item</button>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save Reminder &amp; Maintenance Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Generic icon-picker + add/edit form toggler, reused for both category managers
    function setupCategoryManager(prefix, fields) {
        var iconInput = document.getElementById(prefix + '-form-icon');
        var iconPreview = document.getElementById(prefix + '-icon-preview');

        function updateIconPreview() {
            if (!iconInput || !iconPreview) return;
            var iconClass = (iconInput.value || fields.defaultIcon).trim();
            iconPreview.innerHTML = '<i class="fas ' + iconClass.replace(/[^a-z0-9-]/g, '') + '"></i>';
        }
        if (iconInput) {
            iconInput.addEventListener('input', updateIconPreview);
        }

        document.querySelectorAll('.' + fields.pickClass).forEach(function (btn) {
            btn.addEventListener('click', function () {
                iconInput.value = this.dataset.icon;
                updateIconPreview();
            });
        });

        var form = document.getElementById(prefix + '-form');
        var formAction = document.getElementById(prefix + '-form-action');
        var formId = document.getElementById(prefix + '-form-id');
        var formHeading = document.getElementById(prefix + '-form-heading');
        var formSubmit = document.getElementById(prefix + '-form-submit');
        var formCancel = document.getElementById(prefix + '-form-cancel');

        function resetForm() {
            form.reset();
            formAction.value = fields.addAction;
            formId.value = '';
            iconInput.value = fields.defaultIcon;
            updateIconPreview();
            formHeading.innerHTML = '<i class="fas fa-plus-circle me-1"></i>Add New Category';
            formSubmit.innerHTML = '<i class="fas fa-plus me-1"></i>Add Category';
            formCancel.classList.add('d-none');
        }

        document.querySelectorAll('.' + prefix + '-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                formAction.value = fields.editAction;
                formId.value = this.dataset.id;
                fields.setFields(this.dataset);
                iconInput.value = this.dataset.icon;
                updateIconPreview();
                formHeading.innerHTML = '<i class="fas fa-edit me-1"></i>Edit Category';
                formSubmit.innerHTML = '<i class="fas fa-save me-1"></i>Update Category';
                formCancel.classList.remove('d-none');
            });
        });

        if (formCancel) {
            formCancel.addEventListener('click', resetForm);
        }
    }

    setupCategoryManager('doc-category', {
        defaultIcon: 'fa-file',
        addAction: 'add_document_category',
        editAction: 'edit_document_category',
        pickClass: 'doc-icon-pick-btn',
        setFields: function (data) {
            document.getElementById('doc-category-form-label').value = data.label;
            document.getElementById('doc-category-form-color').value = data.color;
        }
    });

    setupCategoryManager('expense-category', {
        defaultIcon: 'fa-receipt',
        addAction: 'add_expense_category',
        editAction: 'edit_expense_category',
        pickClass: 'expense-icon-pick-btn',
        setFields: function (data) {
            document.getElementById('expense-category-form-name').value = data.name;
        }
    });

    // Maintenance presets: dynamic add/remove rows
    var presetsBody = document.getElementById('maintenance-presets-body');
    var addPresetBtn = document.getElementById('add-preset-row');

    function bindRemoveButton(btn) {
        btn.addEventListener('click', function () {
            this.closest('tr').remove();
        });
    }
    presetsBody.querySelectorAll('.remove-preset-row').forEach(bindRemoveButton);

    if (addPresetBtn) {
        addPresetBtn.addEventListener('click', function () {
            var row = document.createElement('tr');
            row.innerHTML =
                '<td><input type="text" name="preset_name[]" class="form-control form-control-sm" placeholder="e.g. Fuel Filter"></td>' +
                '<td><input type="number" min="0" name="preset_km[]" class="form-control form-control-sm" placeholder="—"></td>' +
                '<td><input type="number" min="0" name="preset_months[]" class="form-control form-control-sm" placeholder="—"></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger remove-preset-row"><i class="fas fa-times"></i></button></td>';
            presetsBody.appendChild(row);
            bindRemoveButton(row.querySelector('.remove-preset-row'));
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
