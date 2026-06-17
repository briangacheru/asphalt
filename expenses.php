<?php
$pageTitle = 'Expenses';
require_once 'includes/header.php';

$vehicles = $pdo->query("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 ORDER BY make, model")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT id, name, icon FROM expense_categories ORDER BY name")->fetchAll();
$vehicleFilter = $_GET['vehicle_id'] ?? '';

// Item types for repair/service categories
$itemTypes = [
    'oil_filter'           => 'Oil Filter',
    'cabin_filter'         => 'Cabin Filter',
    'air_filter'           => 'Air Filter',
    'front_brake_pads'     => 'Front Brake Pads',
    'rear_brake_pads'      => 'Rear Brake Pads',
    'spark_plugs'          => 'Spark Plugs',
    'coolant'              => 'Coolant',
    'transmission_fluid'   => 'Transmission Fluid',
    'brake_fluid'          => 'Brake Fluid',
    'power_steering_fluid' => 'Power Steering Fluid',
    'timing_belt'          => 'Timing Belt',
    'serpentine_belt'      => 'Serpentine Belt',
    'battery'              => 'Battery',
    'tires'                => 'Tires',
    'wipers'               => 'Wipers',
    'other'                => 'Other',
];

// Helper: get category name by id
function getCategoryName($categories, $id) {
    foreach ($categories as $c) {
        if ($c['id'] == $id) return strtolower($c['name']);
    }
    return '';
}

// Handle receipt upload — stores path relative to web root (e.g. uploads/receipts/receipt_xxx.jpg)
function handleReceiptUpload($file) {
    if (empty($file['name']) || empty($file['tmp_name'])) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null; // 5MB max

    // Use server-side MIME detection — never trust the browser-supplied $file['type']
    // Browsers inconsistently report 'image/jpg', 'image/pjpeg', or empty string for JPEGs
    $detectedMime = mime_content_type($file['tmp_name']);

    // Map detected MIME to a canonical safe extension
    $mimeToExt = [
        'image/jpeg'      => 'jpg',
        'image/pjpeg'     => 'jpg',  // older IE JPEG variant
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!array_key_exists($detectedMime, $mimeToExt)) return null;

    $uploadDir = __DIR__ . '/uploads/receipts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // Use the canonical extension from the MIME map, not the user-supplied filename
    $ext      = $mimeToExt[$detectedMime];
    $filename = uniqid('receipt_') . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return 'uploads/receipts/' . $filename;
    }
    return null;
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid security token. Please try again.');
        redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
    }
    
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');

    // Item detail fields (for repair/service)
    $item_type    = sanitize($_POST['item_type'] ?? '');
    $item_name    = sanitize($_POST['item_name'] ?? '');
    $brand        = sanitize($_POST['brand'] ?? '');
    $part_number  = sanitize($_POST['part_number'] ?? '');
    $quantity     = (int)($_POST['quantity'] ?? 0);
    $cost_per_unit = (float)($_POST['cost_per_unit'] ?? 0);
    $item_notes   = sanitize($_POST['item_notes'] ?? '');
    $receipt_path = null;

    // Auto-calculate amount from quantity × cost if item fields are filled
    $catName = getCategoryName($categories, $category_id);
    $isItemCategory = strpos($catName, 'repair') !== false || strpos($catName, 'service') !== false || strpos($catName, 'maintenance') !== false;
    if ($isItemCategory && $quantity > 0 && $cost_per_unit > 0) {
        $amount = $quantity * $cost_per_unit;
    }

    // Handle receipt upload — works for ALL categories
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleReceiptUpload($_FILES['receipt']);
        if ($uploaded) $receipt_path = $uploaded;
    }

    if ($vehicle_id && $category_id && $amount > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO expenses (vehicle_id, category_id, expense_date, amount, description, item_type, item_name, brand, part_number, quantity, cost_per_unit, item_notes, receipt_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicle_id, $category_id, $expense_date, $amount, $description, $item_type ?: null, $item_name ?: null, $brand ?: null, $part_number ?: null, $quantity ?: null, $cost_per_unit ?: null, $item_notes ?: null, $receipt_path]);
            setFlashMessage('success', 'Expense added!');
            redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            // Fallback: try without new columns in case migration hasn't run
            try {
                $stmt = $pdo->prepare("INSERT INTO expenses (vehicle_id, category_id, expense_date, amount, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$vehicle_id, $category_id, $expense_date, $amount, $description]);
                setFlashMessage('success', 'Expense added! (Note: Run DB migration to save item details.)');
                redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
            } catch (PDOException $e2) {
                setFlashMessage('danger', 'Error: ' . $e2->getMessage());
            }
        }
    }
}

// Handle Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid security token. Please try again.');
        redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
    }
    
    $expense_id    = (int)($_POST['expense_id'] ?? 0);
    $vehicle_id    = (int)($_POST['vehicle_id'] ?? 0);
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $expense_date  = $_POST['expense_date'] ?? date('Y-m-d');
    $amount        = (float)($_POST['amount'] ?? 0);
    $description   = sanitize($_POST['description'] ?? '');

    // Item detail fields
    $item_type     = sanitize($_POST['item_type'] ?? '');
    $item_name     = sanitize($_POST['item_name'] ?? '');
    $brand         = sanitize($_POST['brand'] ?? '');
    $part_number   = sanitize($_POST['part_number'] ?? '');
    $quantity      = (int)($_POST['quantity'] ?? 0);
    $cost_per_unit = (float)($_POST['cost_per_unit'] ?? 0);
    $item_notes    = sanitize($_POST['item_notes'] ?? '');

    // Auto-calculate amount from qty × cost if item fields are filled
    $catName = getCategoryName($categories, $category_id);
    $isItemCategory = strpos($catName, 'repair') !== false || strpos($catName, 'service') !== false || strpos($catName, 'maintenance') !== false;
    if ($isItemCategory && $quantity > 0 && $cost_per_unit > 0) {
        $amount = $quantity * $cost_per_unit;
    }

    // Handle receipt — keep existing if no new file uploaded
    $receipt_path = sanitize($_POST['existing_receipt_path'] ?? '');
    if (isset($_FILES['edit_receipt']) && $_FILES['edit_receipt']['error'] === UPLOAD_ERR_OK) {
        $uploaded = handleReceiptUpload($_FILES['edit_receipt']);
        if ($uploaded) $receipt_path = $uploaded;
    }
    // Clear receipt if user checked the remove box
    if (!empty($_POST['remove_receipt'])) $receipt_path = '';

    if ($expense_id && $vehicle_id && $category_id && $amount > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE expenses SET vehicle_id=?, category_id=?, expense_date=?, amount=?, description=?, item_type=?, item_name=?, brand=?, part_number=?, quantity=?, cost_per_unit=?, item_notes=?, receipt_path=? WHERE id=?");
            $stmt->execute([$vehicle_id, $category_id, $expense_date, $amount, $description, $item_type ?: null, $item_name ?: null, $brand ?: null, $part_number ?: null, $quantity ?: null, $cost_per_unit ?: null, $item_notes ?: null, $receipt_path ?: null, $expense_id]);
            setFlashMessage('success', 'Expense updated!');
            redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            // Fallback without new columns
            try {
                $stmt = $pdo->prepare("UPDATE expenses SET vehicle_id=?, category_id=?, expense_date=?, amount=?, description=? WHERE id=?");
                $stmt->execute([$vehicle_id, $category_id, $expense_date, $amount, $description, $expense_id]);
                setFlashMessage('success', 'Expense updated! (Note: Run DB migration to save item details.)');
                redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
            } catch (PDOException $e2) {
                setFlashMessage('danger', 'Error: ' . $e2->getMessage());
            }
        }
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid security token. Please try again.');
        redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
    }
    
    $expense_id = (int)($_POST['expense_id'] ?? 0);

    if ($expense_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->execute([$expense_id]);
            setFlashMessage('success', 'Expense deleted!');
            redirect('expenses' . ($vehicleFilter ? '?vehicle_id=' . $vehicleFilter : ''));
        } catch (PDOException $e) {
            setFlashMessage('danger', 'Error: ' . $e->getMessage());
        }
    }
}

$where = $vehicleFilter ? "WHERE e.vehicle_id = $vehicleFilter" : "";
$expenses = $pdo->query("
    SELECT e.*, v.make, v.model, ec.name as category_name, ec.icon
    FROM expenses e 
    JOIN vehicles v ON e.vehicle_id = v.id 
    JOIN expense_categories ec ON e.category_id = ec.id
    $where 
    ORDER BY e.expense_date DESC
    LIMIT 50
")->fetchAll();

$stats = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) as total,
           COUNT(*) as count,
           COALESCE(SUM(CASE WHEN MONTH(expense_date) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) as this_month
    FROM expenses " . ($vehicleFilter ? "WHERE vehicle_id = $vehicleFilter" : "")
)->fetch();

$byCategory = $pdo->query("
    SELECT ec.id, ec.name, ec.icon, COALESCE(SUM(e.amount), 0) as total
    FROM expense_categories ec
    LEFT JOIN expenses e ON ec.id = e.category_id " . ($vehicleFilter ? "AND e.vehicle_id = $vehicleFilter" : "") . "
    GROUP BY ec.id, ec.name, ec.icon 
    ORDER BY total DESC
")->fetchAll();
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
                            <h4 class="fs-6">Expenses</h4>
                            <p class="mb-0 fs-10">Track all vehicle-related expenses</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#add-expense-modal">
                        <i class="fas fa-plus"></i> Add Expense
                    </button>
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

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <select name="vehicle_id" class="form-control" style="width: auto; min-width: 200px;" onchange="this.form.submit()">
                    <option value="">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?php echo $v['id']; ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($vehicleFilter): ?><a href="expenses" class="btn btn-outline"><i class="fas fa-times"></i></a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-md-6 col-lg-4 col-xxl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">Total Expenses</h6>
                            <i class="fas fa-receipt fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-warning"><?php echo $stats['count']; ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6 col-lg-4 col-xxl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">All Time</h6>
                            <i class="fas fa-money-bill-wave fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-warning">Ksh. <?php echo formatNumber($stats['total']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6 col-lg-4 col-xxl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="row flex-between-center">
                        <div class="col d-md-flex d-lg-block flex-between-center">
                            <h6 class="mb-md-0 mb-lg-2">This Month</h6>
                            <i class="fas fa-calendar fs-4"></i>
                        </div>
                        <div class="col-auto">
                            <h4 class="fs-6 fw-normal text-warning">Ksh. <?php echo formatNumber($stats['this_month']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Full-width Recent Expenses Table ───────────────────────────────── -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Recent Expenses</h3>
                    <span class="badge bg-primary"><?php echo count($expenses); ?> records</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($expenses)): ?>
                        <div class="empty-state text-center py-5">
                            <i class="fas fa-receipt empty-state-icon fs-3 text-300 mb-3"></i>
                            <h6 class="fs-9 mb-1">No Expenses Records!</h6>
                            <p class="fs-10 mb-3">Record your first expense to get started.</p>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#add-expense-modal">
                                <i class="fas fa-plus"></i> Add First Record
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 data-table fs-10" data-datatables="data-datatables">
                                <thead class="bg-200">
                                <tr>
                                    <th class="text-900 sort ps-3">Date</th>
                                    <th class="text-900 sort">Category</th>
                                    <th class="text-900 sort">Vehicle</th>
                                    <th class="text-900 sort">Amount</th>
                                    <th class="text-900 text-end pe-3">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($expenses as $e):
                                    $catLower = strtolower($e['category_name']);
                                    $isItemCat = strpos($catLower,'repair') !== false || strpos($catLower,'service') !== false || strpos($catLower,'maintenance') !== false;
                                    $itemLabel = !empty($e['item_type']) && isset($itemTypes[$e['item_type']]) ? $itemTypes[$e['item_type']] : null;
                                    ?>
                                    <tr class="hover-actions-trigger btn-reveal-trigger hover-bg-100">
                                        <td class="ps-3 align-middle white-space-nowrap">
                                            <span class="fw-semibold"><?php echo formatDate($e['expense_date']); ?></span>
                                        </td>
                                        <td class="align-middle">
                                            <span class="badge rounded-pill" style="background:rgba(var(--falcon-primary-rgb),.1);color:var(--falcon-primary)">
                                                <i class="fas <?php echo htmlspecialchars($e['icon']); ?> me-1"></i><?php echo htmlspecialchars($e['category_name']); ?>
                                            </span>
                                            <p class="mb-0 text-muted mt-1 text-truncate" style="font-size:0.72rem;">
                                                <?php echo sanitize($e['description']) ?: sanitize($e['item_name']); ?>
                                            </p>
                                        </td>
                                        <td class="align-middle">
                                            <i class="fas fa-car me-1 text-muted"></i><?php echo sanitize($e['make'] . ' ' . $e['model']); ?>
                                        </td>
                                        <td class="align-middle">
                                            <strong class="text-success">Ksh <?php echo number_format($e['amount'], 2); ?></strong>
                                            <?php if ($isItemCat && !empty($e['cost_per_unit']) && !empty($e['quantity']) && $e['quantity'] > 1): ?>
                                                <p class="mb-0 text-muted" style="font-size:0.72rem;">@ Ksh <?php echo number_format($e['cost_per_unit'], 2); ?>/unit</p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle white-space-nowrap text-end position-relative">
                                            <div class="hover-actions bg-100">
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" title="View Expense" onclick="viewExpense(<?php echo htmlspecialchars(json_encode($e)); ?>)">
                                                    <span class="fas fa-eye"></span>
                                                </button>
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" title="Edit Expense" onclick="editExpense(<?php echo htmlspecialchars(json_encode($e)); ?>)">
                                                    <span class="fas fa-edit"></span>
                                                </button>
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" title="Delete Expense" onclick="deleteExpense(<?php echo $e['id']; ?>, '<?php echo sanitize($e['category_name']); ?>')">
                                                    <span class="fas fa-trash"></span>
                                                </button>
                                            </div>
                                            <div class="dropdown font-sans-serif btn-reveal-trigger">
                                                <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal-sm transition-none" type="button"  data-boundary="viewport" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs-11"></span></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── By Category + Monthly Breakdown ───────────────────────────────── -->
    <div class="row g-3 mb-3">
        <!-- Left: By Category -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between bg-body-tertiary">
                    <h3 class="card-title mb-0"><i class="fas fa-tags me-2 text-primary"></i>Expenses by Category</h3>
                </div>
                <div class="card-body p-0">
                    <?php
                    $hasExpenses = false;
                    foreach ($byCategory as $cat) { if ($cat['total'] > 0) { $hasExpenses = true; break; } }
                    if (!$hasExpenses): ?>
                        <p class="text-muted text-center py-4 mb-0">No expenses yet.</p>
                    <?php else: ?>
                        <table class="table table-hover mb-0 fs-10">
                            <tbody>
                            <?php
                            $grandTotal = array_sum(array_column(array_filter($byCategory, fn($c) => $c['total'] > 0), 'total'));
                            foreach ($byCategory as $cat): if ($cat['total'] > 0):
                                $pct = $grandTotal > 0 ? round(($cat['total'] / $grandTotal) * 100) : 0;
                                ?>
                                <tr>
                                    <td class="py-2 ps-3" style="width:40%">
                                        <i class="fas <?php echo !empty($cat['icon']) ? htmlspecialchars($cat['icon']) : 'fa-tag'; ?> me-2 text-primary"></i>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </td>
                                    <td class="py-2" style="width:40%">
                                        <div class="progress" style="height:6px;">
                                            <div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?php echo $pct; ?>%</small>
                                    </td>
                                    <td class="py-2 pe-3 text-end fw-semibold">
                                        Ksh <?php echo formatNumber($cat['total']); ?>
                                    </td>
                                </tr>
                            <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Monthly Breakdown (last 6 months) -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between bg-body-tertiary">
                    <h3 class="card-title mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Monthly Breakdown</h3>
                    <small class="text-muted">Last 12 months</small>
                </div>
                <div class="card-body p-0">
                    <?php
                    $monthlyWhere = $vehicleFilter ? "AND vehicle_id = $vehicleFilter" : "";
                    $monthly = $pdo->query("
                        SELECT DATE_FORMAT(expense_date, '%Y-%m') as month_key,
                               DATE_FORMAT(expense_date, '%b %Y')  as month_label,
                               COUNT(*)                             as count,
                               SUM(amount)                         as total
                        FROM expenses
                        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                        $monthlyWhere
                        GROUP BY month_key, month_label
                        ORDER BY month_key DESC
                    ")->fetchAll();

                    $maxMonthly = !empty($monthly) ? max(array_column($monthly, 'total')) : 0;
                    ?>
                    <?php if (empty($monthly)): ?>
                        <p class="text-muted text-center py-4 mb-0">No data for the last 12 months.</p>
                    <?php else: ?>
                        <table class="table table-hover mb-0 fs-10">
                            <tbody>
                            <?php foreach ($monthly as $m):
                                $barPct = $maxMonthly > 0 ? round(($m['total'] / $maxMonthly) * 100) : 0;
                                ?>
                                <tr>
                                    <td class="py-2 ps-3 fw-semibold" style="width:30%"><?php echo htmlspecialchars($m['month_label']); ?></td>
                                    <td class="py-2" style="width:40%">
                                        <div class="progress" style="height:6px;">
                                            <div class="progress-bar bg-warning" style="width:<?php echo $barPct; ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?php echo $m['count']; ?> record<?php echo $m['count'] != 1 ? 's' : ''; ?></small>
                                    </td>
                                    <td class="py-2 pe-3 text-end fw-semibold text-warning">
                                        Ksh <?php echo formatNumber($m['total']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── View Expense Modal ─────────────────────────────────────────────── -->
    <div class="modal fade" id="view-expense-modal" tabindex="-1" aria-labelledby="viewExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewExpenseModalLabel">
                        <i class="fas fa-receipt me-2 text-primary"></i>Expense Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="view_expense_body">
                    <!-- Populated by JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-secondary" id="view_edit_btn" onclick="">
                        <i class="fas fa-edit me-1"></i>Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="add-expense-modal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addExpenseModalLabel">Add Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <?php echo csrfField(); ?>
                    <div class="modal-body">
                        <!-- Basic Info -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select name="vehicle_id" class="form-select" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <select name="category_id" id="add_category_id" class="form-select" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" data-name="<?php echo strtolower(sanitize($c['name'])); ?>"><?php echo $c['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3" id="add_amount_group">
                                <label class="form-label">Amount (Ksh) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="add_amount" step="1" class="form-control" placeholder="0.00">
                                <small class="text-muted d-none" id="add_amount_note">Auto-calculated from Qty × Cost/unit</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>

                        <!-- Item Details Section (shown for Repair / Service / Maintenance) -->
                        <div id="add_item_details" class="d-none">
                            <hr>
                            <h6 class="text-primary mb-3"><i class="fas fa-wrench me-2"></i>Item Details</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Type <span class="text-danger">*</span></label>
                                    <select name="item_type" id="add_item_type" class="form-select">
                                        <option value="">Select type...</option>
                                        <?php foreach ($itemTypes as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Name / Model</label>
                                    <input type="text" name="item_name" class="form-control" placeholder="e.g. Fram PH3593A">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Brand</label>
                                    <input type="text" name="brand" class="form-control" placeholder="e.g. Bosch, NGK, Mobil">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Part Number</label>
                                    <input type="text" name="part_number" class="form-control" placeholder="e.g. 0451103260">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" id="add_quantity" min="1" class="form-control" placeholder="1" value="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Cost per Unit (Ksh)</label>
                                    <input type="number" name="cost_per_unit" id="add_cost_per_unit" step="1" min="0" class="form-control" placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Total (auto)</label>
                                    <input type="text" id="add_item_total" class="form-control bg-light" placeholder="0.00" readonly>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Item Notes</label>
                                <textarea name="item_notes" class="form-control" rows="2" placeholder="Condition, fitment notes, warranty info..."></textarea>
                            </div>
                        </div>

                        <!-- Receipt / Photo Upload — always visible, optional for all categories -->
                        <hr>
                        <h6 class="text-secondary mb-3"><i class="fas fa-receipt me-2"></i>Receipt / Photo <span class="badge bg-light text-muted">Optional</span></h6>
                        <div class="mb-3">
                            <label class="form-label">Upload Receipt or Photo</label>
                            <input type="file" name="receipt" id="add_receipt" class="form-control" accept="image/*,application/pdf">
                            <small class="text-muted">Accepted: JPG, JPEG, PNG, GIF, WEBP, PDF (max 5MB)</small>
                            <div id="add_receipt_preview" class="mt-2 d-none">
                                <img id="add_receipt_img" src="" alt="Preview" class="img-thumbnail" style="max-height:120px;">
                                <span id="add_receipt_filename" class="ms-2 text-muted fs-10"></span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Expense Modal -->
    <div class="modal fade" id="edit-expense-modal" tabindex="-1" aria-labelledby="editExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editExpenseModalLabel"><i class="fas fa-edit me-2 text-primary"></i>Edit Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="expense_id" id="edit_expense_id">
                    <input type="hidden" name="existing_receipt_path" id="edit_existing_receipt_path">
                    <?php echo csrfField(); ?>
                    <div class="modal-body">

                        <!-- Basic Info -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select name="vehicle_id" id="edit_vehicle_id" class="form-select" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($vehicles as $v): ?>
                                        <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['make'] . ' ' . $v['model']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <select name="category_id" id="edit_category_id" class="form-select" required>
                                    <option value="">Select...</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" data-name="<?php echo strtolower(sanitize($c['name'])); ?>"><?php echo $c['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="expense_date" id="edit_expense_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount (Ksh) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="edit_amount" step="1" class="form-control" required>
                                <small class="text-muted d-none" id="edit_amount_note">Auto-calculated from Qty × Cost/unit</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                        </div>

                        <!-- Item Details Section (shown for Repair / Service / Maintenance) -->
                        <div id="edit_item_details" class="d-none">
                            <hr>
                            <h6 class="text-primary mb-3"><i class="fas fa-wrench me-2"></i>Item Details</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Type</label>
                                    <select name="item_type" id="edit_item_type" class="form-select">
                                        <option value="">Select type...</option>
                                        <?php foreach ($itemTypes as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Item Name / Model</label>
                                    <input type="text" name="item_name" id="edit_item_name" class="form-control" placeholder="e.g. Fram PH3593A">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Brand</label>
                                    <input type="text" name="brand" id="edit_brand" class="form-control" placeholder="e.g. Bosch, NGK, Mobil">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Part Number</label>
                                    <input type="text" name="part_number" id="edit_part_number" class="form-control" placeholder="e.g. 0451103260">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" id="edit_quantity" min="1" class="form-control" placeholder="1">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Cost per Unit (Ksh)</label>
                                    <input type="number" name="cost_per_unit" id="edit_cost_per_unit" step="1" min="0" class="form-control" placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Total (auto)</label>
                                    <input type="text" id="edit_item_total" class="form-control bg-light" placeholder="0.00" readonly>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Item Notes</label>
                                <textarea name="item_notes" id="edit_item_notes" class="form-control" rows="2" placeholder="Condition, fitment notes, warranty info..."></textarea>
                            </div>
                        </div>

                        <!-- Receipt / Photo — always visible, optional for all categories -->
                        <hr>
                        <h6 class="text-secondary mb-3"><i class="fas fa-receipt me-2"></i>Receipt / Photo <span class="badge bg-light text-muted">Optional</span></h6>

                        <!-- Existing receipt preview -->
                        <div id="edit_existing_receipt_wrap" class="d-none mb-3">
                            <p class="mb-1 text-muted fs-10 fw-semibold text-uppercase">Current Receipt</p>
                            <div class="d-flex align-items-start gap-3">
                                <div id="edit_existing_receipt_preview">
                                    <!-- populated by JS -->
                                </div>
                                <div>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" name="remove_receipt" id="edit_remove_receipt" value="1">
                                        <label class="form-check-label text-danger fs-10" for="edit_remove_receipt">
                                            <i class="fas fa-trash me-1"></i>Remove current receipt
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">Upload a new file below to replace it</small>
                                </div>
                            </div>
                        </div>

                        <!-- New upload -->
                        <div class="mb-3">
                            <label class="form-label" id="edit_receipt_upload_label">Upload Receipt or Photo</label>
                            <input type="file" name="edit_receipt" id="edit_receipt" class="form-control" accept="image/*,application/pdf">
                            <small class="text-muted">Accepted: JPG, JPEG, PNG, GIF, WEBP, PDF (max 5MB)</small>
                            <div id="edit_receipt_new_preview" class="mt-2 d-none">
                                <img id="edit_receipt_new_img" src="" alt="Preview" class="img-thumbnail" style="max-height:100px;">
                                <span id="edit_receipt_new_filename" class="ms-2 text-muted fs-10"></span>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Expense Modal -->
    <div class="modal fade" id="delete-expense-modal" tabindex="-1" aria-labelledby="deleteExpenseModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteExpenseModalLabel">Delete Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="expense_id" id="delete_expense_id">
                    <?php echo csrfField(); ?>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this expense?</p>
                        <p class="text-muted" id="delete_expense_details"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Category names that trigger item detail fields
        const ITEM_CATEGORIES = ['repair', 'service', 'maintenance'];

        // Item type labels from PHP
        const ITEM_TYPES = <?php echo json_encode($itemTypes); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // ── Add modal: reset on close ──────────────────────────────────────
            const addModal = document.getElementById('add-expense-modal');
            if (addModal) {
                addModal.addEventListener('hidden.bs.modal', function () {
                    this.querySelector('form').reset();
                    toggleItemDetails('add_category_id', 'add_item_details', 'add_amount', 'add_amount_note');
                    document.getElementById('add_receipt_preview').classList.add('d-none');
                    document.getElementById('add_item_total').value = '';
                });
            }

            // ── Add modal: category change ─────────────────────────────────────
            const addCatSelect = document.getElementById('add_category_id');
            if (addCatSelect) {
                addCatSelect.addEventListener('change', function() {
                    toggleItemDetails('add_category_id', 'add_item_details', 'add_amount', 'add_amount_note');
                });
            }

            // ── Add modal: auto-calculate total ────────────────────────────────
            ['add_quantity', 'add_cost_per_unit'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', calcAddTotal);
            });

            // ── Add modal: receipt preview ─────────────────────────────────────
            const addReceiptInput = document.getElementById('add_receipt');
            if (addReceiptInput) {
                addReceiptInput.addEventListener('change', function() {
                    previewReceiptFile(this, 'add_receipt_preview', 'add_receipt_img', 'add_receipt_filename');
                });
            }

            // ── Edit modal: category change ────────────────────────────────────
            const editCatSelect = document.getElementById('edit_category_id');
            if (editCatSelect) {
                editCatSelect.addEventListener('change', toggleEditItemDetails);
            }

            // ── Edit modal: auto-calculate total ───────────────────────────────
            ['edit_quantity', 'edit_cost_per_unit'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', calcEditTotal);
            });

            // ── Edit modal: new receipt file preview ───────────────────────────
            const editReceiptInput = document.getElementById('edit_receipt');
            if (editReceiptInput) {
                editReceiptInput.addEventListener('change', function() {
                    previewReceiptFile(this, 'edit_receipt_new_preview', 'edit_receipt_new_img', 'edit_receipt_new_filename');
                });
            }

            // ── Edit modal: reset new-file preview on close ────────────────────
            const editModal = document.getElementById('edit-expense-modal');
            if (editModal) {
                editModal.addEventListener('hidden.bs.modal', function () {
                    document.getElementById('edit_receipt').value = '';
                    document.getElementById('edit_receipt_new_preview').classList.add('d-none');
                    document.getElementById('edit_remove_receipt').checked = false;
                });
            }
        });

        // Shared receipt preview helper
        function previewReceiptFile(input, wrapId, imgId, nameId) {
            const preview = document.getElementById(wrapId);
            const img     = document.getElementById(imgId);
            const fname   = document.getElementById(nameId);
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (fname) fname.textContent = file.name;
                if (img && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = ev => { img.src = ev.target.result; img.classList.remove('d-none'); };
                    reader.readAsDataURL(file);
                } else if (img) {
                    img.classList.add('d-none');
                }
                preview.classList.remove('d-none');
            } else {
                preview.classList.add('d-none');
            }
        }

        function toggleItemDetails(catSelectId, detailsDivId, amountInputId, amountNoteId) {
            const sel      = document.getElementById(catSelectId);
            const details  = document.getElementById(detailsDivId);
            const amtInput = document.getElementById(amountInputId);
            const amtNote  = document.getElementById(amountNoteId);
            if (!sel || !details) return;

            const selectedOption = sel.options[sel.selectedIndex];
            const catName = (selectedOption ? selectedOption.getAttribute('data-name') : '') || '';
            const isItemCat = ITEM_CATEGORIES.some(c => catName.includes(c));

            if (isItemCat) {
                details.classList.remove('d-none');
                if (amtInput) amtInput.removeAttribute('required');
                if (amtNote) amtNote.classList.remove('d-none');
            } else {
                details.classList.add('d-none');
                if (amtInput) amtInput.setAttribute('required', 'required');
                if (amtNote) amtNote.classList.add('d-none');
            }
        }

        function calcAddTotal() {
            const qty  = parseFloat(document.getElementById('add_quantity')?.value) || 0;
            const cost = parseFloat(document.getElementById('add_cost_per_unit')?.value) || 0;
            const total = qty * cost;
            const totalEl = document.getElementById('add_item_total');
            const amtEl   = document.getElementById('add_amount');
            if (totalEl) totalEl.value = total > 0 ? total.toLocaleString('en-KE', {minimumFractionDigits: 2}) : '';
            if (amtEl && total > 0) amtEl.value = total;
        }

        // ── View Expense Modal ─────────────────────────────────────────────────
        function viewExpense(e) {
            const catLower = (e.category_name || '').toLowerCase();
            const isItemCat = ITEM_CATEGORIES.some(c => catLower.includes(c));
            const itemLabel = e.item_type && ITEM_TYPES[e.item_type] ? ITEM_TYPES[e.item_type] : null;

            let html = `
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <div class="p-3 rounded bg-100">
                        <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Date</p>
                        <p class="mb-0 fw-semibold">${e.expense_date || '—'}</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 rounded bg-100">
                        <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Vehicle</p>
                        <p class="mb-0 fw-semibold"><i class="fas fa-car me-1 text-muted"></i>${escHtml((e.make || '') + ' ' + (e.model || ''))}</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 rounded bg-100">
                        <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Category</p>
                        <p class="mb-0 fw-semibold"><i class="fas ${escHtml(e.icon || 'fa-tag')} me-1 text-primary"></i>${escHtml(e.category_name || '—')}</p>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 rounded bg-success bg-opacity-10">
                        <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Total Amount</p>
                        <p class="mb-0 fw-bold text-success fs-5">Ksh ${parseFloat(e.amount || 0).toLocaleString('en-KE', {minimumFractionDigits: 2})}</p>
                    </div>
                </div>
            </div>`;

            if (e.description) {
                html += `
            <div class="mb-3">
                <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Description</p>
                <p class="mb-0">${escHtml(e.description)}</p>
            </div><hr>`;
            }

            if (isItemCat) {
                html += `
            <h6 class="text-primary mb-3"><i class="fas fa-wrench me-2"></i>Item Details</h6>
            <div class="row g-3 mb-3">`;

                if (itemLabel) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Item Type</p>
                    <p class="mb-0 fw-semibold">${escHtml(itemLabel)}</p>
                </div>`;
                }
                if (e.item_name) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Item Name / Model</p>
                    <p class="mb-0 fw-semibold">${escHtml(e.item_name)}</p>
                </div>`;
                }
                if (e.brand) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Brand</p>
                    <p class="mb-0 fw-semibold">${escHtml(e.brand)}</p>
                </div>`;
                }
                if (e.part_number) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Part Number</p>
                    <p class="mb-0 fw-semibold" style="font-family:monospace">${escHtml(e.part_number)}</p>
                </div>`;
                }
                if (e.quantity) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Quantity</p>
                    <p class="mb-0 fw-semibold">${parseInt(e.quantity)}</p>
                </div>`;
                }
                if (e.cost_per_unit) {
                    html += `
                <div class="col-sm-6 col-md-4">
                    <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Cost per Unit</p>
                    <p class="mb-0 fw-semibold">Ksh ${parseFloat(e.cost_per_unit).toLocaleString('en-KE', {minimumFractionDigits: 2})}</p>
                </div>`;
                }

                html += `</div>`;

                if (e.item_notes) {
                    html += `
            <div class="mb-3">
                <p class="mb-1 text-500 fs-11 text-uppercase fw-semibold">Item Notes</p>
                <p class="mb-0 p-2 bg-100 rounded">${escHtml(e.item_notes)}</p>
            </div>`;
                }
            }

            // Receipt / Photo — two-column: image left, file info right
            if (e.receipt_path) {
                const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(e.receipt_path);
                // Build a web-accessible URL from the stored relative path
                const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
                const imgSrc = /^https?:\/\//i.test(e.receipt_path)
                    ? e.receipt_path
                    : basePath + e.receipt_path.replace(/^\//, '');
                const filename = escHtml(e.receipt_path.split('/').pop());
                const pathDisplay = escHtml(e.receipt_path);

                html += `<hr>
            <div class="row g-3 align-items-start">
                <div class="col-md-5">
                    <h6 class="text-secondary mb-3"><i class="fas fa-receipt me-2"></i>Receipt / Photo</h6>`;

                if (isImage) {
                    html += `<a href="${imgSrc}" target="_blank" title="Click to open full size">
                        <img src="${imgSrc}"
                             class="img-fluid rounded border"
                             style="max-height:260px;width:100%;object-fit:contain;background:#f8f9fa;cursor:zoom-in;"
                             alt="Receipt"
                             onerror="this.parentElement.innerHTML='<div class=&quot;alert alert-warning py-2 fs-10 mb-0&quot;><i class=&quot;fas fa-exclamation-triangle me-1&quot;></i>Image could not load. <a href=&quot;${imgSrc}&quot; target=&quot;_blank&quot;>Open directly</a></div>'">
                    </a>
                    <small class="text-muted d-block mt-1"><i class="fas fa-search-plus me-1"></i>Click to open full size</small>`;
                } else {
                    html += `<a href="${imgSrc}" target="_blank" class="btn btn-outline-danger">
                        <i class="fas fa-file-pdf me-2"></i>View PDF Receipt
                    </a>`;
                }

                html += `</div>
                <div class="col-md-7">
                    <h6 class="text-secondary mb-3"><i class="fas fa-info-circle me-2"></i>File Info</h6>
                    <table class="table table-sm table-borderless fs-10 mb-0">
                        <tr>
                            <td class="text-muted ps-0" style="width:38%">Type</td>
                            <td class="fw-semibold">${isImage ? '<i class="fas fa-image me-1 text-info"></i>Image' : '<i class="fas fa-file-pdf me-1 text-danger"></i>PDF'}</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0">Filename</td>
                            <td class="fw-semibold" style="word-break:break-all;font-size:0.72rem;">${filename}</td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-0">Stored path</td>
                            <td style="word-break:break-all;font-size:0.68rem;color:#aaa;">${pathDisplay}</td>
                        </tr>
                    </table>
                    <a href="${imgSrc}" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="fas fa-external-link-alt me-1"></i>Open in new tab
                    </a>
                </div>
            </div>`;
            }

            document.getElementById('view_expense_body').innerHTML = html;

            // Wire up edit button
            const editBtn = document.getElementById('view_edit_btn');
            editBtn.onclick = function() {
                bootstrap.Modal.getInstance(document.getElementById('view-expense-modal')).hide();
                setTimeout(() => editExpense(e), 300);
            };

            new bootstrap.Modal(document.getElementById('view-expense-modal')).show();
        }

        function escHtml(str) {
            return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Edit Expense Function — populate all fields and trigger show/hide
        function editExpense(expense) {
            const e = expense;

            // Basic fields
            document.getElementById('edit_expense_id').value        = e.id;
            document.getElementById('edit_vehicle_id').value        = e.vehicle_id;
            document.getElementById('edit_expense_date').value      = e.expense_date;
            document.getElementById('edit_amount').value            = e.amount;
            document.getElementById('edit_description').value       = e.description || '';
            document.getElementById('edit_existing_receipt_path').value = e.receipt_path || '';

            // Set category and fire change so item section toggles correctly
            const catSel = document.getElementById('edit_category_id');
            catSel.value = e.category_id;
            toggleEditItemDetails();

            // Item detail fields
            document.getElementById('edit_item_type').value        = e.item_type    || '';
            document.getElementById('edit_item_name').value        = e.item_name    || '';
            document.getElementById('edit_brand').value            = e.brand        || '';
            document.getElementById('edit_part_number').value      = e.part_number  || '';
            document.getElementById('edit_quantity').value         = e.quantity     || '';
            document.getElementById('edit_cost_per_unit').value    = e.cost_per_unit || '';
            document.getElementById('edit_item_notes').value       = e.item_notes   || '';
            calcEditTotal();

            // Existing receipt preview
            const existingWrap    = document.getElementById('edit_existing_receipt_wrap');
            const existingPreview = document.getElementById('edit_existing_receipt_preview');
            const removeCheck     = document.getElementById('edit_remove_receipt');
            const uploadLabel     = document.getElementById('edit_receipt_upload_label');

            removeCheck.checked = false;

            if (e.receipt_path) {
                const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
                const imgSrc   = /^https?:\/\//i.test(e.receipt_path)
                    ? e.receipt_path
                    : basePath + e.receipt_path.replace(/^\//, '');
                const isImage  = /\.(jpg|jpeg|png|gif|webp)$/i.test(e.receipt_path);

                if (isImage) {
                    existingPreview.innerHTML = `<a href="${imgSrc}" target="_blank"><img src="${imgSrc}" class="img-thumbnail" style="max-height:80px;max-width:120px;object-fit:cover;" alt="Current receipt"></a>`;
                } else {
                    existingPreview.innerHTML = `<a href="${imgSrc}" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf me-1"></i>Current PDF</a>`;
                }
                existingWrap.classList.remove('d-none');
                uploadLabel.textContent = 'Replace with New Receipt or Photo';
            } else {
                existingPreview.innerHTML = '';
                existingWrap.classList.add('d-none');
                uploadLabel.textContent = 'Upload Receipt or Photo';
            }

            // Reset new-file preview
            document.getElementById('edit_receipt').value = '';
            document.getElementById('edit_receipt_new_preview').classList.add('d-none');

            new bootstrap.Modal(document.getElementById('edit-expense-modal')).show();
        }

        function toggleEditItemDetails() {
            const sel      = document.getElementById('edit_category_id');
            const details  = document.getElementById('edit_item_details');
            const amtInput = document.getElementById('edit_amount');
            const amtNote  = document.getElementById('edit_amount_note');
            if (!sel || !details) return;

            const opt     = sel.options[sel.selectedIndex];
            const catName = (opt ? opt.getAttribute('data-name') : '') || '';
            const isItemCat = ITEM_CATEGORIES.some(c => catName.includes(c));

            if (isItemCat) {
                details.classList.remove('d-none');
                amtInput.removeAttribute('required');
                amtNote.classList.remove('d-none');
            } else {
                details.classList.add('d-none');
                amtInput.setAttribute('required', 'required');
                amtNote.classList.add('d-none');
            }
        }

        function calcEditTotal() {
            const qty   = parseFloat(document.getElementById('edit_quantity')?.value)       || 0;
            const cost  = parseFloat(document.getElementById('edit_cost_per_unit')?.value)  || 0;
            const total = qty * cost;
            const totalEl = document.getElementById('edit_item_total');
            const amtEl   = document.getElementById('edit_amount');
            if (totalEl) totalEl.value = total > 0 ? total.toLocaleString('en-KE', {minimumFractionDigits: 2}) : '';
            if (amtEl && total > 0) amtEl.value = total;
        }

        // Delete Expense Function
        function deleteExpense(id, categoryName) {
            document.getElementById('delete_expense_id').value = id;
            document.getElementById('delete_expense_details').textContent = 'Category: ' + categoryName;

            const deleteModal = new bootstrap.Modal(document.getElementById('delete-expense-modal'));
            deleteModal.show();
        }
    </script>

<?php require_once 'includes/footer.php'; ?>