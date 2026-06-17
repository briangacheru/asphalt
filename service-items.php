<?php
$pageTitle = 'Service Items';
require_once 'includes/header.php';

$serviceId = (int)($_GET['service_id'] ?? 0);

if (!$serviceId) {
    setFlashMessage('danger', 'Invalid service record.');
    redirect('service-history');
}

// Get service record with vehicle info
$stmt = $pdo->prepare("
    SELECT sr.*, v.make, v.model, v.year, v.license_plate
    FROM service_records sr
    JOIN vehicles v ON sr.vehicle_id = v.id
    WHERE sr.id = ?
");
$stmt->execute([$serviceId]);
$service = $stmt->fetch();

if (!$service) {
    setFlashMessage('danger', 'Service record not found.');
    redirect('service-history');
}

// Get existing service items
$stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_record_id = ? ORDER BY item_type");
$stmt->execute([$serviceId]);
$existingItems = $stmt->fetchAll();

// Item types with labels
$itemTypes = [
    'oil_filter' => ['label' => 'Oil Filter', 'icon' => 'fas fa-oil-can'],
    'cabin_filter' => ['label' => 'Cabin Filter', 'icon' => 'fas fa-fan'],
    'air_filter' => ['label' => 'Air Filter', 'icon' => 'fas fa-wind'],
    'front_brake_pads' => ['label' => 'Front Brake Pads', 'icon' => 'fas fa-compact-disc'],
    'rear_brake_pads' => ['label' => 'Rear Brake Pads', 'icon' => 'fas fa-compact-disc'],
    'spark_plugs' => ['label' => 'Spark Plugs', 'icon' => 'fas fa-bolt'],
    'coolant' => ['label' => 'Coolant', 'icon' => 'fas fa-tint'],
    'transmission_fluid' => ['label' => 'Transmission Fluid', 'icon' => 'fas fa-cog'],
    'brake_fluid' => ['label' => 'Brake Fluid', 'icon' => 'fas fa-tint'],
    'power_steering_fluid' => ['label' => 'Power Steering Fluid', 'icon' => 'fas fa-tint'],
    'timing_belt' => ['label' => 'Timing Belt', 'icon' => 'fas fa-sync'],
    'serpentine_belt' => ['label' => 'Serpentine Belt', 'icon' => 'fas fa-sync'],
    'battery' => ['label' => 'Battery', 'icon' => 'fas fa-car-battery'],
    'tires' => ['label' => 'Tires', 'icon' => 'fas fa-circle'],
    'wipers' => ['label' => 'Wipers', 'icon' => 'fas fa-water'],
    'other' => ['label' => 'Other', 'icon' => 'fas fa-ellipsis-h']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $item_type = sanitize($_POST['item_type'] ?? '');
        $item_name = sanitize($_POST['item_name'] ?? '');
        $brand = sanitize($_POST['brand'] ?? '');
        $part_number = sanitize($_POST['part_number'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 1);
        $cost = (float)($_POST['cost'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');

        if ($item_type && array_key_exists($item_type, $itemTypes)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO service_items (service_record_id, item_type, item_name, brand, part_number, quantity, cost, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$serviceId, $item_type, $item_name, $brand, $part_number, $quantity, $cost, $notes]);

                // Update total service cost
                $stmt = $pdo->prepare("
                    UPDATE service_records 
                    SET service_cost = (SELECT COALESCE(SUM(cost * quantity), 0) FROM service_items WHERE service_record_id = ?)
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId, $serviceId]);

                setFlashMessage('success', 'Item added successfully!');
                redirect('service-items?service_id=' . $serviceId);
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error adding item: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'edit_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $item_type = sanitize($_POST['item_type'] ?? '');
        $item_name = sanitize($_POST['item_name'] ?? '');
        $brand = sanitize($_POST['brand'] ?? '');
        $part_number = sanitize($_POST['part_number'] ?? '');
        $quantity = (int)($_POST['quantity'] ?? 1);
        $cost = (float)($_POST['cost'] ?? 0);
        $notes = sanitize($_POST['notes'] ?? '');

        if ($itemId && $item_type && array_key_exists($item_type, $itemTypes)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE service_items 
                    SET item_type = ?, item_name = ?, brand = ?, part_number = ?, quantity = ?, cost = ?, notes = ?
                    WHERE id = ? AND service_record_id = ?
                ");
                $stmt->execute([$item_type, $item_name, $brand, $part_number, $quantity, $cost, $notes, $itemId, $serviceId]);

                // Update total service cost
                $stmt = $pdo->prepare("
                    UPDATE service_records 
                    SET service_cost = (SELECT COALESCE(SUM(cost * quantity), 0) FROM service_items WHERE service_record_id = ?)
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId, $serviceId]);

                setFlashMessage('success', 'Item updated successfully!');
                redirect('service-items?service_id=' . $serviceId);
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error updating item: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId) {
            try {
                $stmt = $pdo->prepare("DELETE FROM service_items WHERE id = ? AND service_record_id = ?");
                $stmt->execute([$itemId, $serviceId]);

                // Update total service cost
                $stmt = $pdo->prepare("
                    UPDATE service_records 
                    SET service_cost = (SELECT COALESCE(SUM(cost * quantity), 0) FROM service_items WHERE service_record_id = ?)
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId, $serviceId]);

                setFlashMessage('success', 'Item deleted successfully!');
                redirect('service-items?service_id=' . $serviceId);
            } catch (PDOException $e) {
                setFlashMessage('danger', 'Error deleting item.');
            }
        }
    }
}

// Refresh existing items
$stmt = $pdo->prepare("SELECT * FROM service_items WHERE service_record_id = ? ORDER BY item_type");
$stmt->execute([$serviceId]);
$existingItems = $stmt->fetchAll();

// Calculate total
$totalCost = array_reduce($existingItems, function($sum, $item) {
    return $sum + ($item['cost'] * $item['quantity']);
}, 0);
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
                            <h4 class="fs-6">Service Items</h4>
                            <p class="mb-0 fs-10">
                                <?php echo sanitize($service['make'] . ' ' . $service['model']); ?>
                                (<?php echo $service['year']; ?>) &bull;
                                <?php echo formatDate($service['service_date']); ?> &bull;
                                <?php echo formatNumber($service['mileage']); ?> km
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-auto mt-4 mt-md-0">
                    <a class="btn btn-outline-primary btn-sm me-2" href="vehicle-details?id=<?php echo $service['vehicle_id']; ?>" role="button">
                        <i class="fas fa-car"></i> Vehicle Details
                    </a>
                    <a class="btn btn-outline-secondary btn-sm me-2" href="service-history" role="button">
                        <i class="fas fa-arrow-left"></i> Back to History
                    </a>
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

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-box bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="fas fa-calendar fs-4 text-primary"></i>
                        </div>
                    </div>
                    <h6 class="text-muted mb-2 fw-normal">Service Date</h6>
                    <h3 class="mb-0 fw-bold"><?php echo formatDate($service['service_date']); ?></h3>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-box bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="fas fa-tachometer-alt fs-4 text-success"></i>
                        </div>
                    </div>
                    <h6 class="text-muted mb-2 fw-normal">Mileage</h6>
                    <h3 class="mb-0 fw-bold "><?php echo formatNumber($service['mileage']); ?> <small class="fs-6 text-muted">km</small></h3>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-box bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="fas fa-road fs-4 text-info"></i>
                        </div>
                    </div>
                    <h6 class="text-muted mb-2 fw-normal">Next Service</h6>
                    <h3 class="mb-0 fw-bold"><?php echo formatNumber($service['next_service_mileage']); ?> <small class="fs-6 text-muted">km</small></h3>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-0 shadow-sm hover-lift">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-box bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="fas fa-money-bill-wave fs-4 text-warning"></i>
                        </div>
                    </div>
                    <h6 class="text-muted mb-2 fw-normal">Total Cost</h6>
                    <h3 class="mb-0 fw-bold">Ksh. <?php echo number_format($totalCost, 2); ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="row g-0">
        <div class="col-lg-6 ps-lg-2 mb-3">

            <div class="card h-100">
                <div class="card-header bg-body-tertiary">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="mb-0"><i class="fas fa-plus-circle"></i> Add Service Item</h6>
                        </div>
                        <div class="col-auto text-center pe-x1">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" data-validate class="row g-3">
                        <input type="hidden" name="action" value="add_item">
                        <div class="col-md-12">
                            <label class="form-label" for="inputMake">Item Type</label>
                            <select name="item_type" class="form-control" required>
                                <option value="">Select item type...</option>
                                <?php foreach ($itemTypes as $key => $item): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $item['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Item Name / Model</label>
                            <input type="text" name="item_name" class="form-control"
                                   placeholder="e.g., Bosch Premium Oil Filter">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" class="form-control"
                                   placeholder="e.g., Bosch, Jinbo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Part Number</label>
                            <input type="text" name="part_number" class="form-control"
                                   placeholder="e.g., 3323">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control"
                                   min="1" value="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cost (per unit)</label>
                            <input type="number" name="cost" class="form-control"
                                   min="0" step="1" placeholder="0.00">
                        </div>

                        <div class="col">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"
                                      placeholder="Any additional notes..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </form>
                </div>
            </div>

        </div>
        <div class="col-lg-6 ps-lg-2 mb-3">
            <div class="card h-100">
                <div class="card-header d-flex flex-between-center bg-body-tertiary">
                    <h6 class="mb-0"><i class="fas fa-car"></i> Quick Add Common Items</h6>
                </div>
                <div class="card-body">
                    <div class="btn-group" style="flex-wrap: wrap; gap: var(--spacing-sm);">
                        <?php
                        $commonItems = ['oil_filter', 'cabin_filter', 'air_filter', 'front_brake_pads', 'rear_brake_pads', 'spark_plugs', 'coolant', 'battery', 'tires', 'wipers'];
                        foreach ($commonItems as $type):
                            // Check if already added
                            $alreadyAdded = false;
                            foreach ($existingItems as $existing) {
                                if ($existing['item_type'] === $type) {
                                    $alreadyAdded = true;
                                    break;
                                }
                            }
                            ?>
                            <button type="button"
                                    class="btn quick-add-btn <?php echo $alreadyAdded ? 'btn-falcon-primary' : 'btn-outline'; ?>"
                                    data-item-type="<?php echo $type; ?>"
                                    onclick="quickAdd('<?php echo $type; ?>')">
                                <i class="<?php echo $itemTypes[$type]['icon']; ?>"></i>
                                <?php echo $itemTypes[$type]['label']; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <div class="card h-100">
                <div class="card-header bg-body-tertiary">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="mb-0"><i class="fas fa-history"></i> Items Changed</h6>
                        </div>
                        <div class="col-auto text-center pe-x1">
                            <span class="badge rounded-pill ms-2 badge-subtle-primary"><?php echo count($existingItems); ?> items</span>                    </div>
                    </div>
                </div>
                <div class="card-body scrollbar recent-activity-body-height ps-2">
                    <?php if (empty($existingItems)): ?>
                        <div class="empty-state text-center py-4">
                            <i class="fas fa-clipboard-list empty-state-icon fs-3 text-300 mb-3"></i>
                            <h6 class="fs-9 mb-1">No items recorded yet!</h6>
                            <p class="fs-10 mb-0">Add the items that were changed during this service.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-responsive-sm mb-0 data-table fs-10" data-datatables="data-datatables">
                                <thead class="bg-200">
                                <tr>
                                    <th class="text-900 sort">Item Type</th>
                                    <th class="text-900 sort">Item Name</th>
                                    <th class="text-900 sort">Details</th>
                                    <th class="text-900 sort">Quantity</th>
                                    <th class="text-900 sort">Cost</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($existingItems as $item): ?>
                                    <tr class="hover-actions-trigger btn-reveal-trigger hover-bg-100">
                                        <td>
                                            <div class="d-flex align-center gap-1">
                                                <i class="<?php echo $itemTypes[$item['item_type']]['icon'] ?? 'fas fa-cog'; ?>"></i>
                                                <div>
                                                    <strong><?php echo $itemTypes[$item['item_type']]['label'] ?? $item['item_type']; ?></strong>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo sanitize($item['item_name']); ?>
                                        </td>
                                        <td>
                                            <?php if ($item['brand']): ?>
                                                <span class="badge rounded-pill ms-2 badge-subtle-info"><?php echo sanitize($item['brand']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($item['part_number']): ?>
                                                <small class="text-muted">#<?php echo sanitize($item['part_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $item['quantity']; ?>
                                        <td>
                                            <?php if ($item['cost'] > 0): ?>
                                                <strong>Ksh. <?php echo number_format($item['cost'] * $item['quantity'], 0); ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle white-space-nowrap position-relative">
                                            <div class="hover-actions bg-200">
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" type="button" data-bs-toggle="modal" data-bs-target="#viewItemModal<?php echo $item['id']; ?>" title="View Details">
                                                    <span class="fas fa-eye"></span>
                                                </button>
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" type="button" data-bs-toggle="modal" data-bs-target="#editItemModal<?php echo $item['id']; ?>" title="Edit Item">
                                                    <span class="fas fa-edit"></span>
                                                </button>
                                                <button class="btn icon-item rounded-3 me-2 fs-11 icon-item-sm" type="button" data-bs-toggle="modal" data-bs-target="#deleteItemModal<?php echo $item['id']; ?>" title="Delete Item">
                                                    <span class="fas fa-trash"></span>
                                                </button>
                                            </div>
                                            <div class="dropdown font-sans-serif btn-reveal-trigger">
                                                <button class="btn btn-link text-600 btn-sm dropdown-toggle dropdown-caret-none btn-reveal-sm transition-none" type="button" id="crm-recent-leads-0" data-bs-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs-11"></span></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr>
                                    <td colspan="2" class="text-right"><strong>Total:</strong></td>
                                    <td colspan="3" class="text-end"><strong>Ksh. <?php echo number_format($totalCost, 2); ?></strong></td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>

                        <?php
                        // Add modals for each item
                        foreach ($existingItems as $item):
                            ?>
                            <!-- View Item Modal -->
                            <div class="modal fade" id="viewItemModal<?php echo $item['id']; ?>" tabindex="-1" aria-labelledby="viewItemModalLabel<?php echo $item['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="viewItemModalLabel<?php echo $item['id']; ?>">
                                                <i class="<?php echo $itemTypes[$item['item_type']]['icon'] ?? 'fas fa-cog'; ?> text-primary"></i>
                                                Item Details
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row p-3">
                                                <div class="col-12">
                                                    <div class="rounded mb-2">
                                                        <h6 class="text-primary mb-2">
                                                            <i class="<?php echo $itemTypes[$item['item_type']]['icon'] ?? 'fas fa-cog'; ?>"></i>
                                                            <?php echo $itemTypes[$item['item_type']]['label'] ?? $item['item_type']; ?>
                                                        </h6>
                                                        <?php if ($item['item_name']): ?>
                                                            <p class="mb-0 text-muted"><?php echo sanitize($item['item_name']); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if ($item['brand']): ?>
                                                    <div class="col-6">
                                                        <label class="form-label fw-bold text-700 fs-10">Brand</label>
                                                        <p class="mb-0"><?php echo sanitize($item['brand']); ?></p>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($item['part_number']): ?>
                                                    <div class="col-6">
                                                        <label class="form-label fw-bold text-700 fs-10">Part Number</label>
                                                        <p class="mb-0"><?php echo sanitize($item['part_number']); ?></p>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="col-6">
                                                    <label class="form-label fw-bold text-700 fs-10">Quantity</label>
                                                    <p class="mb-0"><?php echo $item['quantity']; ?></p>
                                                </div>

                                                <div class="col-6">
                                                    <label class="form-label fw-bold text-700 fs-10">Unit Cost</label>
                                                    <p class="mb-0">Ksh. <?php echo number_format($item['cost'], 2); ?></p>
                                                </div>

                                                <div class="col-12">
                                                    <div class="bg-success bg-opacity-10 rounded p-2 text-center">
                                                        <label class="form-label fw-bold text-700 fs-10 mb-1">Total Cost</label>
                                                        <h5 class="mb-0 text-success">Ksh. <?php echo number_format($item['cost'] * $item['quantity'], 2); ?></h5>
                                                    </div>
                                                </div>

                                                <?php if ($item['notes']): ?>
                                                    <div class="col-12">
                                                        <label class="form-label fw-bold text-700 fs-10">Notes</label>
                                                        <p class="mb-0 text-muted"><?php echo nl2br(sanitize($item['notes'])); ?></p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editItemModal<?php echo $item['id']; ?>">
                                                <i class="fas fa-edit"></i> Edit Item
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Item Modal -->
                            <div class="modal fade" id="editItemModal<?php echo $item['id']; ?>" tabindex="-1" aria-labelledby="editItemModalLabel<?php echo $item['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header bg-primary">
                                                <h5 class="modal-title" id="editItemModalLabel<?php echo $item['id']; ?>">
                                                    <i class="fas fa-edit"></i> Edit Item
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">

                                                <div class="mb-3">
                                                    <label class="form-label">Item Type <span class="text-danger">*</span></label>
                                                    <select name="item_type" class="form-select" required>
                                                        <option value="">Select type...</option>
                                                        <?php foreach ($itemTypes as $key => $type): ?>
                                                            <option value="<?php echo $key; ?>" <?php echo $item['item_type'] === $key ? 'selected' : ''; ?>>
                                                                <?php echo $type['label']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Item Name</label>
                                                    <input type="text" name="item_name" class="form-control" value="<?php echo sanitize($item['item_name']); ?>" placeholder="e.g., Genuine Oil Filter">
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Brand</label>
                                                        <input type="text" name="brand" class="form-control" value="<?php echo sanitize($item['brand']); ?>" placeholder="e.g., Bosch">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Part Number</label>
                                                        <input type="text" name="part_number" class="form-control" value="<?php echo sanitize($item['part_number']); ?>" placeholder="e.g., 0451103316">
                                                    </div>
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Quantity</label>
                                                        <input type="number" name="quantity" class="form-control" value="<?php echo $item['quantity']; ?>" min="1" required>
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Cost (Ksh)</label>
                                                        <input type="number" name="cost" class="form-control" value="<?php echo $item['cost']; ?>" step="0.01" min="0">
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Notes</label>
                                                    <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes..."><?php echo sanitize($item['notes']); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete Item Modal -->
                            <div class="modal fade" id="deleteItemModal<?php echo $item['id']; ?>" tabindex="-1" aria-labelledby="deleteItemModalLabel<?php echo $item['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title" id="deleteItemModalLabel<?php echo $item['id']; ?>">
                                                    <i class="fas fa-exclamation-triangle"></i> Confirm Deletion
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">

                                                <div class="alert alert-danger" role="alert">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                    <strong>Warning:</strong> This action cannot be undone!
                                                </div>

                                                <p class="mb-3">Are you sure you want to delete this item?</p>

                                                <div class="bg-body-tertiary rounded p-3">
                                                    <h6 class="mb-2">
                                                        <i class="<?php echo $itemTypes[$item['item_type']]['icon'] ?? 'fas fa-cog'; ?> text-danger"></i>
                                                        <?php echo $itemTypes[$item['item_type']]['label'] ?? $item['item_type']; ?>
                                                    </h6>
                                                    <?php if ($item['item_name']): ?>
                                                        <p class="mb-1 text-muted fs-10"><?php echo sanitize($item['item_name']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($item['brand']): ?>
                                                        <p class="mb-1 text-muted fs-10">Brand: <?php echo sanitize($item['brand']); ?></p>
                                                    <?php endif; ?>
                                                    <p class="mb-0 fw-bold text-danger">Cost: Ksh. <?php echo number_format($item['cost'] * $item['quantity'], 2); ?></p>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                                <button type="submit" class="btn btn-danger">
                                                    <i class="fas fa-trash"></i> Yes, Delete Item
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function quickAdd(itemType) {
            const select = document.querySelector('select[name="item_type"]');
            if (select) {
                // Update select value
                select.value = itemType;

                // Reset all buttons to outline
                const buttons = document.querySelectorAll('.quick-add-btn');
                buttons.forEach(btn => {
                    btn.classList.remove('btn-success', 'btn-falcon-primary');
                    btn.classList.add('btn-outline');

                    // Hide checkmark if present
                    const checkIcon = btn.querySelector('.fa-check');
                    if (checkIcon) {
                        checkIcon.style.display = 'none';
                    }
                });

                // Highlight the selected button
                const selectedBtn = document.querySelector(`.quick-add-btn[data-item-type="${itemType}"]`);
                if (selectedBtn) {
                    selectedBtn.classList.remove('btn-outline');
                    selectedBtn.classList.add('btn-falcon-primary');
                }

                select.scrollIntoView({ behavior: 'smooth', block: 'center' });
                select.focus();
            }
        }
    </script>

<?php require_once 'includes/footer.php'; ?>