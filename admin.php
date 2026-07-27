<?php
$pageTitle = 'Admin Dashboard';
require_once 'includes/header.php';

use App\Middleware\AuthMiddleware;
use App\Services\DatabaseBackupService;

AuthMiddleware::requireAdmin();

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
    </div>

<?php require_once 'includes/footer.php'; ?>
