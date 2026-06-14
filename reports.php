<?php
$pageTitle = 'Reports';
require_once 'includes/header.php';

$vehicles = $pdo->query("SELECT id, make, model, year FROM vehicles WHERE is_active = 1 ORDER BY make, model")->fetchAll();
$vehicleFilter = $_GET['vehicle_id'] ?? '';
$yearFilter = $_GET['year'] ?? date('Y');

$years = range(date('Y'), date('Y') - 5);

$whereVehicle = $vehicleFilter ? "AND vehicle_id = $vehicleFilter" : "";

// Overall Statistics
$overallStats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM vehicles WHERE is_active = 1) as total_vehicles,
        (SELECT COUNT(*) FROM service_records) as total_services,
        (SELECT COALESCE(SUM(service_cost), 0) FROM service_records) as total_service_cost,
        (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log) as total_fuel_cost,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses) as total_expenses,
        (SELECT COALESCE(SUM(liters), 0) FROM fuel_log) as total_fuel
")->fetch();

// Year Statistics
$yearStats = $pdo->query("
    SELECT 
        (SELECT COALESCE(SUM(service_cost), 0) FROM service_records WHERE YEAR(service_date) = $yearFilter $whereVehicle) as service_cost,
        (SELECT COUNT(*) FROM service_records WHERE YEAR(service_date) = $yearFilter $whereVehicle) as service_count,
        (SELECT COALESCE(SUM(total_cost), 0) FROM fuel_log WHERE YEAR(fill_date) = $yearFilter $whereVehicle) as fuel_cost,
        (SELECT COALESCE(SUM(liters), 0) FROM fuel_log WHERE YEAR(fill_date) = $yearFilter $whereVehicle) as fuel_liters,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE YEAR(expense_date) = $yearFilter $whereVehicle) as expense_cost
")->fetch();

$yearStats['total'] = $yearStats['service_cost'] + $yearStats['fuel_cost'] + $yearStats['expense_cost'];

// Monthly breakdown for the year
$monthlyData = $pdo->query("
    SELECT 
        months.month,
        COALESCE(services.cost, 0) as service_cost,
        COALESCE(fuel.cost, 0) as fuel_cost,
        COALESCE(expenses.cost, 0) as expense_cost
    FROM (
        SELECT 1 as month UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
    ) months
    LEFT JOIN (
        SELECT MONTH(service_date) as month, SUM(service_cost) as cost 
        FROM service_records WHERE YEAR(service_date) = $yearFilter $whereVehicle GROUP BY MONTH(service_date)
    ) services ON months.month = services.month
    LEFT JOIN (
        SELECT MONTH(fill_date) as month, SUM(total_cost) as cost 
        FROM fuel_log WHERE YEAR(fill_date) = $yearFilter $whereVehicle GROUP BY MONTH(fill_date)
    ) fuel ON months.month = fuel.month
    LEFT JOIN (
        SELECT MONTH(expense_date) as month, SUM(amount) as cost 
        FROM expenses WHERE YEAR(expense_date) = $yearFilter $whereVehicle GROUP BY MONTH(expense_date)
    ) expenses ON months.month = expenses.month
    ORDER BY months.month
")->fetchAll();

// Service items breakdown
$serviceItems = $pdo->query("
    SELECT 
        si.item_type,
        COUNT(*) as count,
        COALESCE(SUM(si.cost * si.quantity), 0) as total_cost
    FROM service_items si
    JOIN service_records sr ON si.service_record_id = sr.id
    WHERE YEAR(sr.service_date) = $yearFilter $whereVehicle
    GROUP BY si.item_type
    ORDER BY count DESC
    LIMIT 10
")->fetchAll();

// Expense categories
$expenseCategories = $pdo->query("
    SELECT 
        ec.name,
        ec.icon,
        COUNT(e.id) as count,
        COALESCE(SUM(e.amount), 0) as total
    FROM expense_categories ec
    LEFT JOIN expenses e ON ec.id = e.category_id AND YEAR(e.expense_date) = $yearFilter " . ($vehicleFilter ? "AND e.vehicle_id = $vehicleFilter" : "") . "
    GROUP BY ec.id
    ORDER BY total DESC
")->fetchAll();

// Per vehicle stats
$vehicleStats = $pdo->query("
    SELECT 
        v.id, v.make, v.model, v.year,
        COALESCE((SELECT SUM(service_cost) FROM service_records WHERE vehicle_id = v.id AND YEAR(service_date) = $yearFilter), 0) as service_cost,
        COALESCE((SELECT SUM(total_cost) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = $yearFilter), 0) as fuel_cost,
        COALESCE((SELECT SUM(amount) FROM expenses WHERE vehicle_id = v.id AND YEAR(expense_date) = $yearFilter), 0) as expense_cost,
        COALESCE((SELECT SUM(liters) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = $yearFilter), 0) as fuel_liters
    FROM vehicles v
    WHERE v.is_active = 1
    ORDER BY (
        COALESCE((SELECT SUM(service_cost) FROM service_records WHERE vehicle_id = v.id AND YEAR(service_date) = $yearFilter), 0) +
        COALESCE((SELECT SUM(total_cost) FROM fuel_log WHERE vehicle_id = v.id AND YEAR(fill_date) = $yearFilter), 0) +
        COALESCE((SELECT SUM(amount) FROM expenses WHERE vehicle_id = v.id AND YEAR(expense_date) = $yearFilter), 0)
    ) DESC
")->fetchAll();

$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>

    <!-- ApexCharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <div class="container-fluid py-4">
        <!-- Page Header -->
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
                                <h4 class="fs-6">Reports & Analytics</h4>
                                <p class="mb-0 fs-10">Analyze your vehicle expenses and maintenance</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-auto mt-4 mt-md-0">

                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Vehicle</label>
                        <select name="vehicle_id" class="form-select">
                            <option value="">All Vehicles</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>" <?php echo $vehicleFilter == $v['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($v['make'] . ' ' . $v['model'] . ' (' . $v['year'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <?php if ($vehicleFilter || $yearFilter != date('Y')): ?>
                            <a href="reports.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Year Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-primary bg-opacity-10 text-primary rounded p-3">
                                    <i class="fas fa-money-bill-wave fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Total (<?php echo $yearFilter; ?>)</h6>
                                <h3 class="mb-0">Ksh<?php echo formatNumber($yearStats['total']); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-success bg-opacity-10 text-success rounded p-3">
                                    <i class="fas fa-wrench fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Services</h6>
                                <h3 class="mb-0">Ksh<?php echo formatNumber($yearStats['service_cost']); ?></h3>
                                <small class="text-muted"><?php echo $yearStats['service_count']; ?> services</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-warning bg-opacity-10 text-warning rounded p-3">
                                    <i class="fas fa-gas-pump fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Fuel</h6>
                                <h3 class="mb-0">Ksh<?php echo formatNumber($yearStats['fuel_cost']); ?></h3>
                                <small class="text-muted"><?php echo number_format($yearStats['fuel_liters'], 0); ?>L consumed</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-info bg-opacity-10 text-info rounded p-3">
                                    <i class="fas fa-receipt fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Other Expenses</h6>
                                <h3 class="mb-0">Ksh<?php echo formatNumber($yearStats['expense_cost']); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <div class="bg-secondary bg-opacity-10 text-secondary rounded p-3">
                                    <i class="fas fa-calculator fa-2x"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-1">Avg per Month</h6>
                                <h3 class="mb-0">Ksh<?php echo formatNumber($yearStats['total'] / 12); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Chart -->
        <div class="card mb-4">
            <div class="card-header bg-body-tertiary">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Monthly Breakdown (<?php echo $yearFilter; ?>)
                </h5>
            </div>
            <div class="card-body">
                <div id="monthlyChart" style="min-height: 350px;"></div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="row g-4 mb-4">
            <!-- Cost per Vehicle -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-body-tertiary">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-car me-2"></i>Cost per Vehicle (<?php echo $yearFilter; ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $hasVehicleData = false;
                        foreach ($vehicleStats as $vs) {
                            if (($vs['service_cost'] + $vs['fuel_cost'] + $vs['expense_cost']) > 0) {
                                $hasVehicleData = true;
                                break;
                            }
                        }
                        ?>

                        <?php if (!$hasVehicleData): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No data for this period.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($vehicleStats as $vs):
                                $vsTotal = $vs['service_cost'] + $vs['fuel_cost'] + $vs['expense_cost'];
                                if ($vsTotal == 0) continue;
                                ?>
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <strong><?php echo sanitize($vs['make'] . ' ' . $vs['model']); ?></strong>
                                        <strong class="text-primary">Ksh<?php echo formatNumber($vsTotal); ?></strong>
                                    </div>

                                    <div class="progress mb-2" style="height: 8px;">
                                        <?php
                                        $total = $yearStats['total'] > 0 ? $yearStats['total'] : 1;
                                        $serviceWidth = ($vs['service_cost'] / $total) * 100;
                                        $fuelWidth = ($vs['fuel_cost'] / $total) * 100;
                                        $expenseWidth = ($vs['expense_cost'] / $total) * 100;
                                        ?>
                                        <?php if ($serviceWidth > 0): ?>
                                            <div class="progress-bar bg-success" role="progressbar"
                                                 style="width: <?php echo $serviceWidth; ?>%;"
                                                 title="Services: Ksh<?php echo formatNumber($vs['service_cost']); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($fuelWidth > 0): ?>
                                            <div class="progress-bar bg-warning" role="progressbar"
                                                 style="width: <?php echo $fuelWidth; ?>%;"
                                                 title="Fuel: Ksh<?php echo formatNumber($vs['fuel_cost']); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($expenseWidth > 0): ?>
                                            <div class="progress-bar bg-info" role="progressbar"
                                                 style="width: <?php echo $expenseWidth; ?>%;"
                                                 title="Other: Ksh<?php echo formatNumber($vs['expense_cost']); ?>">
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex justify-content-between text-muted small">
                                        <span><i class="fas fa-circle text-success" style="font-size: 0.6rem;"></i> Services: Ksh<?php echo formatNumber($vs['service_cost']); ?></span>
                                        <span><i class="fas fa-circle text-warning" style="font-size: 0.6rem;"></i> Fuel: Ksh<?php echo formatNumber($vs['fuel_cost']); ?></span>
                                        <span><i class="fas fa-circle text-info" style="font-size: 0.6rem;"></i> Other: Ksh<?php echo formatNumber($vs['expense_cost']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Service Items -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-body-tertiary">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tools me-2"></i>Top Service Items (<?php echo $yearFilter; ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($serviceItems)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No service items recorded.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Item Type</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-end">Total Cost</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($serviceItems as $si): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-wrench text-muted me-2"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $si['item_type'])); ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?php echo $si['count']; ?></span>
                                            </td>
                                            <td class="text-end">
                                                <strong>Ksh<?php echo number_format($si['total_cost'], 2); ?></strong>
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

        <!-- Expense Categories -->
        <div class="card mb-4">
            <div class="card-header bg-body-tertiary bg">
                <h5 class="card-title mb-0">
                    <i class="fas fa-receipt me-2"></i>Expense Categories (<?php echo $yearFilter; ?>)
                </h5>
            </div>
            <div class="card-body">
                <?php
                $hasExpenseData = false;
                foreach ($expenseCategories as $ec) {
                    if ($ec['total'] > 0) {
                        $hasExpenseData = true;
                        break;
                    }
                }
                ?>

                <?php if (!$hasExpenseData): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No expense data for this period.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($expenseCategories as $ec): if ($ec['total'] > 0): ?>
                            <div class="col-md-6 col-lg-4 col-xl-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="bg-primary bg-opacity-10 text-primary rounded p-2 me-2">
                                                <i class="fas <?php echo $ec['icon']; ?>"></i>
                                            </div>
                                            <h6 class="mb-0"><?php echo $ec['name']; ?></h6>
                                        </div>
                                        <h3 class="mb-1">Ksh<?php echo formatNumber($ec['total']); ?></h3>
                                        <small class="text-muted">
                                            <i class="fas fa-receipt me-1"></i><?php echo $ec['count']; ?> transaction<?php echo $ec['count'] != 1 ? 's' : ''; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ApexCharts JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Breakdown Chart Data
            const monthlyData = <?php echo json_encode($monthlyData); ?>;
            const monthNames = <?php echo json_encode(array_slice($monthNames, 1)); ?>;

            // Prepare data for chart
            const serviceCosts = monthlyData.map(m => parseFloat(m.service_cost));
            const fuelCosts = monthlyData.map(m => parseFloat(m.fuel_cost));
            const expenseCosts = monthlyData.map(m => parseFloat(m.expense_cost));

            // Monthly Stacked Bar Chart
            const monthlyChartOptions = {
                series: [
                    {
                        name: 'Services',
                        data: serviceCosts
                    },
                    {
                        name: 'Fuel',
                        data: fuelCosts
                    },
                    {
                        name: 'Other Expenses',
                        data: expenseCosts
                    }
                ],
                chart: {
                    type: 'bar',
                    height: 350,
                    stacked: true,
                    toolbar: {
                        show: true,
                        tools: {
                            download: true,
                            selection: false,
                            zoom: false,
                            zoomin: false,
                            zoomout: false,
                            pan: false,
                            reset: false
                        }
                    },
                    fontFamily: 'inherit'
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '60%',
                        borderRadius: 4,
                        borderRadiusApplication: 'end'
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['transparent']
                },
                xaxis: {
                    categories: monthNames,
                    labels: {
                        style: {
                            fontSize: '12px'
                        }
                    }
                },
                yaxis: {
                    title: {
                        text: 'Amount (Ksh)',
                        style: {
                            fontSize: '12px'
                        }
                    },
                    labels: {
                        formatter: function (value) {
                            return 'Ksh' + value.toFixed(0);
                        }
                    }
                },
                fill: {
                    opacity: 1
                },
                colors: ['#198754', '#ffc107', '#0dcaf0'],
                legend: {
                    position: 'top',
                    horizontalAlign: 'left',
                    fontSize: '13px',
                    markers: {
                        radius: 2
                    }
                },
                tooltip: {
                    y: {
                        formatter: function (val) {
                            return 'Ksh' + val.toFixed(2);
                        }
                    }
                },
                grid: {
                    borderColor: '#e7e7e7',
                    strokeDashArray: 4
                }
            };

            const monthlyChart = new ApexCharts(document.querySelector("#monthlyChart"), monthlyChartOptions);
            monthlyChart.render();
        });
    </script>

<?php require_once 'includes/footer.php'; ?>