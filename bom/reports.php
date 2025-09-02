<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check BOM reports permissions - use granular permissions
$can_view_boms = hasPermission('view_boms', $permissions);
$can_view_production_reports = hasPermission('view_production_reports', $permissions);
$can_view_bom_reports = hasPermission('view_bom_reports', $permissions);
$can_analyze_performance = hasPermission('analyze_bom_performance', $permissions);

if (!$can_view_boms && !$can_view_production_reports && !$can_view_bom_reports && !$can_analyze_performance) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get report type and parameters
$report_type = $_GET['type'] ?? 'cost_analysis';
$bom_id = intval($_GET['bom_id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get BOM list for dropdown
$stmt = $conn->prepare("
    SELECT bh.id, bh.bom_number, bh.name, p.name as product_name
    FROM bom_headers bh
    INNER JOIN products p ON bh.product_id = p.id
    WHERE bh.status = 'active'
    ORDER BY bh.bom_number ASC
");
$stmt->execute();
$available_boms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$report_data = [];
$report_title = '';

if ($report_type === 'cost_analysis' && $bom_id) {
    // Cost Analysis Report
    $report_title = 'BOM Cost Analysis';
    $cost_data = calculateBOMCost($conn, $bom_id);

    if (!empty($cost_data['components'])) {
        $report_data = [
            'bom_details' => [
                'bom_number' => $cost_data['bom_id'],
                'total_cost' => $cost_data['total_cost'],
                'cost_per_unit' => $cost_data['cost_per_unit'],
                'material_cost' => $cost_data['material_cost'],
                'labor_cost' => $cost_data['labor_cost'],
                'overhead_cost' => $cost_data['overhead_cost']
            ],
            'components' => $cost_data['components'],
            'cost_breakdown' => [
                'material_percentage' => $cost_data['total_cost'] > 0 ? ($cost_data['material_cost'] / $cost_data['total_cost']) * 100 : 0,
                'labor_percentage' => $cost_data['total_cost'] > 0 ? ($cost_data['labor_cost'] / $cost_data['total_cost']) * 100 : 0,
                'overhead_percentage' => $cost_data['total_cost'] > 0 ? ($cost_data['overhead_cost'] / $cost_data['total_cost']) * 100 : 0
            ]
        ];
    }
} elseif ($report_type === 'material_requirements' && $bom_id) {
    // Material Requirements Report
    $report_title = 'Material Requirements Planning';
    $quantity = intval($_GET['quantity'] ?? 1);
    $explosion = getBOMExplosion($conn, $bom_id, $quantity);

    if (!empty($explosion)) {
        $report_data = [
            'quantity_to_produce' => $quantity,
            'total_items' => count($explosion),
            'materials' => $explosion,
            'total_material_cost' => array_sum(array_column($explosion, 'total_cost')),
            'shortages' => array_filter($explosion, function($item) {
                return $item['is_available'] === false;
            })
        ];
    }
} elseif ($report_type === 'where_used') {
    // Where Used Report
    $report_title = 'Where Used Analysis';
    $component_id = intval($_GET['component_id'] ?? 0);

    if ($component_id) {
        $report_data = getWhereUsedReport($conn, $component_id);
    } else {
        // Get all components with their usage
        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.name,
                p.sku,
                COUNT(bc.id) as usage_count,
                SUM(bc.quantity_required) as total_quantity_used
            FROM products p
            LEFT JOIN bom_components bc ON p.id = bc.component_product_id
            LEFT JOIN bom_headers bh ON bc.bom_id = bh.id AND bh.status = 'active'
            WHERE p.status = 'active'
            GROUP BY p.id, p.name, p.sku
            HAVING usage_count > 0
            ORDER BY usage_count DESC
            LIMIT 50
        ");
        $stmt->execute();
        $report_data = [
            'components' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
} elseif ($report_type === 'production_summary') {
    // Production Summary Report
    $report_title = 'Production Summary';
    $stmt = $conn->prepare("
        SELECT
            po.*,
            bh.bom_number,
            bh.name as bom_name,
            p.name as product_name,
            u.username as created_by_name
        FROM bom_production_orders po
        INNER JOIN bom_headers bh ON po.bom_id = bh.id
        INNER JOIN products p ON bh.product_id = p.id
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.created_at BETWEEN :start_date AND :end_date
        ORDER BY po.created_at DESC
    ");
    $stmt->bindParam(':start_date', $start_date . ' 00:00:00');
    $stmt->bindParam(':end_date', $end_date . ' 23:59:59');
    $stmt->execute();
    $production_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report_data = [
        'period' => ['start' => $start_date, 'end' => $end_date],
        'total_orders' => count($production_orders),
        'completed_orders' => count(array_filter($production_orders, function($order) {
            return $order['status'] === 'completed';
        })),
        'total_value' => array_sum(array_column($production_orders, 'total_production_cost')),
        'orders' => $production_orders
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BOM Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .report-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .report-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .report-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }

        .filters-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .cost-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .cost-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .cost-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            display: block;
        }

        .cost-label {
            color: #64748b;
            font-size: 0.875rem;
            text-transform: uppercase;
        }

        .materials-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            font-weight: 600;
        }

        .material-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            align-items: center;
        }

        .material-row:last-child {
            border-bottom: none;
        }

        .material-name {
            font-weight: 500;
        }

        .material-sku {
            color: #64748b;
            font-size: 0.875rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-sufficient { background: #d1fae5; color: #059669; }
        .status-insufficient { background: #fee2e2; color: #dc2626; }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            display: block;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            text-transform: uppercase;
        }

        .chart-placeholder {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .report-section {
                padding: 1rem;
            }

            .material-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .cost-breakdown {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'bom';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>BOM Reports</h1>
                    <p class="header-subtitle">Cost analysis and material requirements planning</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Report Filters -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="type" class="form-label">Report Type</label>
                        <select class="form-select" id="type" name="type" onchange="updateFilters()">
                            <option value="cost_analysis" <?php echo $report_type === 'cost_analysis' ? 'selected' : ''; ?>>Cost Analysis</option>
                            <option value="material_requirements" <?php echo $report_type === 'material_requirements' ? 'selected' : ''; ?>>Material Requirements</option>
                            <option value="where_used" <?php echo $report_type === 'where_used' ? 'selected' : ''; ?>>Where Used</option>
                            <option value="production_summary" <?php echo $report_type === 'production_summary' ? 'selected' : ''; ?>>Production Summary</option>
                        </select>
                    </div>

                    <div class="col-md-3" id="bom-select" style="<?php echo in_array($report_type, ['cost_analysis', 'material_requirements']) ? '' : 'display: none;'; ?>">
                        <label for="bom_id" class="form-label">Select BOM</label>
                        <select class="form-select" id="bom_id" name="bom_id">
                            <option value="">Choose a BOM...</option>
                            <?php foreach ($available_boms as $bom): ?>
                            <option value="<?php echo $bom['id']; ?>" <?php echo $bom_id == $bom['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($bom['bom_number'] . ' - ' . $bom['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2" id="quantity-input" style="<?php echo $report_type === 'material_requirements' ? '' : 'display: none;'; ?>">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" value="<?php echo intval($_GET['quantity'] ?? 1); ?>" min="1">
                    </div>

                    <div class="col-md-2" id="start-date" style="<?php echo $report_type === 'production_summary' ? '' : 'display: none;'; ?>">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>

                    <div class="col-md-2" id="end-date" style="<?php echo $report_type === 'production_summary' ? '' : 'display: none;'; ?>">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Generate Report
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Content -->
            <?php if (!empty($report_data)): ?>
            <div class="report-section">
                <div class="report-header">
                    <h2 class="report-title"><?php echo htmlspecialchars($report_title); ?></h2>
                    <div class="text-muted">
                        Generated on <?php echo date('M j, Y H:i'); ?>
                    </div>
                </div>

                <?php if ($report_type === 'cost_analysis' && isset($report_data['bom_details'])): ?>
                <!-- Cost Analysis Report -->
                <div class="summary-stats">
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['bom_details']['total_cost'], $settings); ?></span>
                        <span class="stat-label">Total Cost</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['bom_details']['cost_per_unit'], $settings); ?></span>
                        <span class="stat-label">Cost per Unit</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['bom_details']['material_cost'], $settings); ?></span>
                        <span class="stat-label">Material Cost</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['bom_details']['labor_cost'], $settings); ?></span>
                        <span class="stat-label">Labor Cost</span>
                    </div>
                </div>

                <div class="cost-breakdown">
                    <div class="cost-card">
                        <span class="cost-value"><?php echo number_format($report_data['cost_breakdown']['material_percentage'], 1); ?>%</span>
                        <span class="cost-label">Material Cost</span>
                    </div>
                    <div class="cost-card">
                        <span class="cost-value"><?php echo number_format($report_data['cost_breakdown']['labor_percentage'], 1); ?>%</span>
                        <span class="cost-label">Labor Cost</span>
                    </div>
                    <div class="cost-card">
                        <span class="cost-value"><?php echo number_format($report_data['cost_breakdown']['overhead_percentage'], 1); ?>%</span>
                        <span class="cost-label">Overhead Cost</span>
                    </div>
                </div>

                <h4>Component Breakdown</h4>
                <div class="materials-table">
                    <div class="table-header material-row">
                        <div>Component</div>
                        <div>Quantity</div>
                        <div>Unit Cost</div>
                        <div>Total Cost</div>
                        <div>Stock Status</div>
                        <div>% of Total</div>
                    </div>
                    <?php foreach ($report_data['components'] as $component): ?>
                    <div class="material-row">
                        <div>
                            <div class="material-name"><?php echo htmlspecialchars($component['component_name']); ?></div>
                            <div class="material-sku"><?php echo htmlspecialchars($component['component_sku']); ?></div>
                        </div>
                        <div><?php echo number_format($component['quantity_required'], 3); ?> <?php echo htmlspecialchars($component['unit_of_measure']); ?></div>
                        <div><?php echo formatCurrency($component['unit_cost'], $settings); ?></div>
                        <div><?php echo formatCurrency($component['total_cost'], $settings); ?></div>
                        <div>
                            <span class="status-badge status-<?php echo $component['stock_status']; ?>">
                                <?php echo ucfirst($component['stock_status']); ?>
                            </span>
                        </div>
                        <div><?php echo number_format(($component['total_cost'] / $report_data['bom_details']['total_cost']) * 100, 1); ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php elseif ($report_type === 'material_requirements' && isset($report_data['materials'])): ?>
                <!-- Material Requirements Report -->
                <div class="summary-stats">
                    <div class="stat-box">
                        <span class="stat-number"><?php echo $report_data['quantity_to_produce']; ?></span>
                        <span class="stat-label">Quantity to Produce</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo $report_data['total_items']; ?></span>
                        <span class="stat-label">Total Items Required</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo count($report_data['shortages']); ?></span>
                        <span class="stat-label">Items with Shortages</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['total_material_cost'], $settings); ?></span>
                        <span class="stat-label">Total Material Cost</span>
                    </div>
                </div>

                <h4>Material Requirements</h4>
                <div class="materials-table">
                    <div class="table-header material-row">
                        <div>Component</div>
                        <div>Required Qty</div>
                        <div>Qty with Waste</div>
                        <div>Available Stock</div>
                        <div>Status</div>
                        <div>Unit Cost</div>
                    </div>
                    <?php foreach ($report_data['materials'] as $material): ?>
                    <div class="material-row">
                        <div>
                            <div class="material-name"><?php echo htmlspecialchars($material['component_name']); ?></div>
                            <div class="material-sku"><?php echo htmlspecialchars($material['component_sku']); ?></div>
                        </div>
                        <div><?php echo number_format($material['quantity_required'], 3); ?> <?php echo htmlspecialchars($material['unit_of_measure']); ?></div>
                        <div><?php echo number_format($material['quantity_with_waste'], 3); ?> <?php echo htmlspecialchars($material['unit_of_measure']); ?></div>
                        <div><?php echo number_format($material['available_stock'], 3); ?></div>
                        <div>
                            <span class="status-badge status-<?php echo $material['is_available'] ? 'sufficient' : 'insufficient'; ?>">
                                <?php echo $material['is_available'] ? 'Available' : 'Shortage'; ?>
                            </span>
                        </div>
                        <div><?php echo formatCurrency($material['unit_cost'], $settings); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php elseif ($report_type === 'production_summary' && isset($report_data['orders'])): ?>
                <!-- Production Summary Report -->
                <div class="summary-stats">
                    <div class="stat-box">
                        <span class="stat-number"><?php echo $report_data['total_orders']; ?></span>
                        <span class="stat-label">Total Orders</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo $report_data['completed_orders']; ?></span>
                        <span class="stat-label">Completed Orders</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo formatCurrency($report_data['total_value'], $settings); ?></span>
                        <span class="stat-label">Total Value</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-number"><?php echo number_format(($report_data['total_orders'] > 0 ? ($report_data['completed_orders'] / $report_data['total_orders']) * 100 : 0), 1); ?>%</span>
                        <span class="stat-label">Completion Rate</span>
                    </div>
                </div>

                <h4>Production Orders (<?php echo $report_data['period']['start']; ?> to <?php echo $report_data['period']['end']; ?>)</h4>
                <div class="materials-table">
                    <div class="table-header material-row">
                        <div>Order #</div>
                        <div>BOM</div>
                        <div>Quantity</div>
                        <div>Status</div>
                        <div>Total Cost</div>
                        <div>Created</div>
                    </div>
                    <?php foreach ($report_data['orders'] as $order): ?>
                    <div class="material-row">
                        <div><?php echo htmlspecialchars($order['production_order_number']); ?></div>
                        <div><?php echo htmlspecialchars($order['bom_number'] . ' - ' . $order['bom_name']); ?></div>
                        <div><?php echo number_format($order['quantity_to_produce']); ?></div>
                        <div>
                            <span class="status-badge status-<?php echo $order['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                            </span>
                        </div>
                        <div><?php echo formatCurrency($order['total_production_cost'], $settings); ?></div>
                        <div><?php echo date('M j, Y', strtotime($order['created_at'])); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php else: ?>
                <!-- No Data Message -->
                <div class="text-center py-5">
                    <i class="bi bi-graph-up display-1 text-muted mb-3"></i>
                    <h4 class="text-muted">No Data Available</h4>
                    <p class="text-muted">Please select appropriate filters to generate the report.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Initial State -->
            <div class="report-section">
                <div class="text-center py-5">
                    <i class="bi bi-graph-up display-1 text-muted mb-3"></i>
                    <h4 class="text-muted">BOM Reports</h4>
                    <p class="text-muted">Select a report type and parameters to generate insights</p>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateFilters() {
            const reportType = document.getElementById('type').value;
            const bomSelect = document.getElementById('bom-select');
            const quantityInput = document.getElementById('quantity-input');
            const startDate = document.getElementById('start-date');
            const endDate = document.getElementById('end-date');

            // Hide all filters first
            bomSelect.style.display = 'none';
            quantityInput.style.display = 'none';
            startDate.style.display = 'none';
            endDate.style.display = 'none';

            // Show relevant filters based on report type
            if (reportType === 'cost_analysis' || reportType === 'material_requirements') {
                bomSelect.style.display = 'block';
            }

            if (reportType === 'material_requirements') {
                quantityInput.style.display = 'block';
            }

            if (reportType === 'production_summary') {
                startDate.style.display = 'block';
                endDate.style.display = 'block';
            }
        }

        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', updateFilters);
    </script>
</body>
</html>
