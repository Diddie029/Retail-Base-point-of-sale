<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Get user info
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

// Check if user has permission to view sales or POS management
$hasAccess = false;

// Check if user is admin
if (isAdmin($role_name)) {
    $hasAccess = true;
}

// Check if user has sales permissions
if (!$hasAccess && !empty($permissions)) {
    if (hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions) || hasPermission('view_finance', $permissions)) {
        $hasAccess = true;
    }
}

// Check if user has admin access through permissions
if (!$hasAccess && hasAdminAccess($role_name, $permissions)) {
    $hasAccess = true;
}

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$stmt = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get today's sales summary
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_sales,
        SUM(final_amount) as total_amount,
        AVG(final_amount) as average_sale,
        COUNT(DISTINCT payment_method) as payment_methods_used
    FROM sales 
    WHERE DATE(sale_date) = ?
");
$stmt->execute([$today]);
$today_stats = $stmt->fetch(PDO::FETCH_ASSOC);



// Get payment methods
$stmt = $conn->query("
    SELECT * FROM payment_types 
    WHERE is_active = 1 
    ORDER BY sort_order, display_name
");
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get POS Management statistics
try {
    // Payment method statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_payment_types,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_payment_types
        FROM payment_types
    ");
    $payment_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $payment_stats = ['total_payment_types' => 0, 'active_payment_types' => 0];
}


// Get recent customers (exclude walk-in/default/internal customers)
$recentCustomersSql = "SELECT c.*, COUNT(s.id) as total_purchases, SUM(s.final_amount) as total_spent,"
    . " CONCAT(c.first_name, ' ', c.last_name) as full_name"
    . " FROM customers c"
    . " LEFT JOIN sales s ON c.id = s.customer_id"
    . " WHERE c.membership_status = 'active'"
    . " AND (COALESCE(c.customer_type, '') != 'walk_in')"
    . " AND (COALESCE(c.customer_number, '') NOT LIKE 'WALK-IN%')"
    . " AND (CONCAT(c.first_name, ' ', c.last_name) NOT LIKE '%Walk-in%')"
    . " GROUP BY c.id"
    . " ORDER BY total_spent DESC"
    . " LIMIT 10";

$stmt = $conn->query($recentCustomersSql);
$recent_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .feature-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .alert-item {
            border-left: 4px solid #dc3545;
            background: #f8d7da;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 0 5px 5px 0;
        }
        
        /* Summary Cards Horizontal Layout */
        .summary-cards-container {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 12px;
            margin-bottom: 0;
            padding: 0;
            width: 100%;
            justify-content: space-between;
        }
        
        .summary-card {
            flex: 1;
            min-width: 0;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            margin: 0;
        }
        
        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .summary-card h6 {
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            opacity: 0.9;
        }
        
        .summary-card h4 {
            margin: 0;
            font-size: 2.2rem;
            font-weight: 800;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .summary-card {
                padding: 1.2rem 0.8rem;
            }
            
            .summary-card h4 {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 992px) {
            .summary-card {
                padding: 1rem 0.6rem;
            }
            
            .summary-card h4 {
                font-size: 1.8rem;
            }
            
            .summary-card h6 {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 768px) {
            .summary-cards-container {
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .summary-card {
                flex: 0 0 calc(50% - 4px);
                min-width: calc(50% - 4px);
            }
        }
        
        @media (max-width: 576px) {
            .summary-cards-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .summary-card {
                flex: 1;
                min-width: 100%;
            }
        }

        /* Custom Scrollbar Styling */
        .custom-scrollbar {
            max-height: 500px;
            overflow-y: auto;
        }

        /* Sticky Table Headers */
        .custom-scrollbar table {
            position: relative;
        }

        .custom-scrollbar thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #343a40 !important;
            border-bottom: 2px solid #495057;
        }

        /* Row Numbers Styling */
        .row-number {
            font-weight: 600;
            color: #6c757d;
            text-align: center;
            min-width: 50px;
            background-color: #f8f9fa;
            border-right: 2px solid #dee2e6;
        }

        .table-dark .row-number {
            background-color: #495057;
            color: #adb5bd;
            border-right: 2px solid #6c757d;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border-radius: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
        }

        .custom-scrollbar::-webkit-scrollbar-corner {
            background: #f1f1f1;
        }

        /* Pagination Styling */
        .pagination-info {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .pagination .page-link {
            color: #007bff;
            border: 1px solid #dee2e6;
            padding: 0.375rem 0.75rem;
        }

        .pagination .page-link:hover {
            color: #0056b3;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }

        .pagination .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
            color: white;
        }

        .pagination .page-item.disabled .page-link {
            color: #6c757d;
            background-color: #fff;
            border-color: #dee2e6;
        }

        @media (max-width: 768px) {
            .pagination-info {
                font-size: 0.75rem;
            }

            .pagination .page-link {
                padding: 0.25rem 0.5rem;
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-cash-register"></i> POS Management Dashboard</h2>
                    <p class="text-muted">Comprehensive point of sale management including sales, tills, cash drops, and payment methods</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-primary fs-6"><?php echo date('M d, Y'); ?></span>
                    <a href="../auth/logout.php" class="btn btn-outline-danger" title="Logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Today's Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Today's Sales</h6>
                                <h3 class="mb-0"><?php echo $today_stats['total_sales'] ?? 0; ?></h3>
                            </div>
                            <i class="bi bi-cart-check fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Today's Revenue</h6>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($today_stats['total_amount'] ?? 0, 2); ?></h3>
                            </div>
                            <i class="bi bi-currency-dollar fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Average Sale</h6>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($today_stats['average_sale'] ?? 0, 2); ?></h3>
                            </div>
                            <i class="bi bi-graph-up fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Payment Methods</h6>
                                <h3 class="mb-0"><?php echo $today_stats['payment_methods_used'] ?? 0; ?></h3>
                            </div>
                            <i class="bi bi-credit-card fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- POS Management Statistics -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Payment Types</h6>
                                <h3 class="mb-0"><?php echo $payment_stats['active_payment_types'] ?? 0; ?></h3>
                                <small class="text-white-50"><?php echo $payment_stats['total_payment_types'] ?? 0; ?> Total</small>
                            </div>
                            <i class="bi bi-credit-card fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card" style="background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Payment Methods</h6>
                                <h3 class="mb-0"><?php echo count($payment_methods); ?></h3>
                                <small class="text-white-50">Methods Configured</small>
                            </div>
                            <i class="bi bi-credit-card-2-front fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Till Reconciliation Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator"></i> Till Reconciliation</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Till Closing Reconciliation -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="showTillReconciliation()">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                                                <i class="bi bi-calculator"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Till Closing Reconciliation</h6>
                                                <p class="text-muted mb-0">Review and reconcile till closing records</p>
                                                <small class="text-success">View closing reports</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Till Closing History -->
                                <div class="col-md-4">
                                    <div class="feature-card" onclick="showTillClosingHistory()">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); color: white;">
                                                <i class="bi bi-clock-history"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Till Closing History</h6>
                                                <p class="text-muted mb-0">View historical till closing data</p>
                                                <small class="text-info">Historical reports</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Day Not Closed Report -->
                                <div class="col-md-4">
                                    <div class="feature-card" onclick="showDayNotClosedReport()">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); color: white;">
                                                <i class="bi bi-exclamation-triangle"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Day Not Closed Report</h6>
                                                <p class="text-muted mb-0">View and manage missed day closures</p>
                                                <small class="text-warning">Independent report</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- POS Settings Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear"></i> Point of Sale Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Payment Methods -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='payment-methods.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%); color: white;">
                                                <i class="bi bi-credit-card"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Payment Methods</h6>
                                                <p class="text-muted mb-0">Configure payment types and settings</p>
                                                <small class="text-info"><?php echo count($payment_methods); ?> methods configured</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- POS Configuration -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='pos-config.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #fd7e14 0%, #dc3545 100%); color: white;">
                                                <i class="bi bi-sliders"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">POS Configuration</h6>
                                                <p class="text-muted mb-0">System settings and preferences</p>
                                                <small class="text-warning">Configure settings</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Till Details -->
                                <div class="col-md-6">
                                    <div class="feature-card" onclick="window.location.href='../pos/till_details.php'">
                                        <div class="d-flex align-items-center">
                                            <div class="feature-icon" style="background: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%); color: white;">
                                                <i class="bi bi-eye"></i>
                                            </div>
                                            <div class="ms-3">
                                                <h6 class="mb-1">Till Transaction Details</h6>
                                                <p class="text-muted mb-0">View detailed till transactions and history</p>
                                                <small class="text-info">Read-only view</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


        </div>
    </div>

    <!-- Till Reconciliation Modal -->
    <div class="modal fade" id="tillReconciliationModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calculator"></i> Till Closing Reconciliation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Filter Section -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="reconDateFrom" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="reconDateFrom" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="reconDateTo" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="reconDateTo" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="reconTillFilter" class="form-label">Till</label>
                            <select class="form-select" id="reconTillFilter">
                                <option value="">All Tills</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="reconStatusFilter" class="form-label">Status</label>
                            <select class="form-select" id="reconStatusFilter">
                                <option value="">All</option>
                                <option value="exact">Exact</option>
                                <option value="shortage">Shortage</option>
                                <option value="excess">Excess</option>
                                <option value="missed_day_closed">Missed Day Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12">
                            <button class="btn btn-primary" onclick="loadTillReconciliation(1)">
                                <i class="bi bi-search"></i> Load Reconciliation
                            </button>
                            <button class="btn btn-success" onclick="exportTillReconciliation()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row mb-4" id="reconSummaryCards" style="display: none;">
                        <div class="col-12">
                            <div class="summary-cards-container">
                                <div class="summary-card" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);">
                                    <h6>Total Closings</h6>
                                    <h4 id="totalClosings">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
                                    <h6>Exact Matches</h6>
                                    <h4 id="exactMatches">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                                    <h6>Shortages</h6>
                                    <h4 id="shortages">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                                    <h6>Excess</h6>
                                    <h4 id="excess">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                                    <h6>Days Not Closed</h6>
                                    <h4 id="daysNotClosed">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);">
                                    <h6>Total Days</h6>
                                    <h4 id="totalDays">0</h4>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Reconciliation Table -->
                    <div class="table-responsive custom-scrollbar">
                        <table class="table table-striped table-hover" id="tillReconciliationTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="row-number">#</th>
                                    <th>Date/Time</th>
                                    <th>Till</th>
                                    <th>Cashier</th>
                                    <th>Opening</th>
                                    <th>Sales</th>
                                    <th>Drops</th>
                                    <th>Expected</th>
                                    <th>Actual</th>
                                    <th>Difference</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tillReconciliationTableBody">
                                <tr>
                                    <td colspan="12" class="text-center text-muted">Click "Load Reconciliation" to view data</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination for Till Reconciliation -->
                    <div class="d-flex justify-content-between align-items-center mt-3" id="reconPaginationContainer" style="display: none !important;">
                        <div class="pagination-info">
                            <span id="reconPaginationInfo">Showing 0 to 0 of 0 entries</span>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="reconPagination">
                                <!-- Pagination buttons will be inserted here -->
                            </ul>
                        </nav>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Till Closing History Modal -->
    <div class="modal fade" id="tillClosingHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> Till Closing History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Filter Section -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="historyDateFrom" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="historyDateFrom" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="historyDateTo" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="historyDateTo" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="historyTillFilter" class="form-label">Till</label>
                            <select class="form-select" id="historyTillFilter">
                                <option value="">All Tills</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary" onclick="loadTillClosingHistory(1)">
                                <i class="bi bi-search"></i> Load History
                            </button>
                        </div>
                    </div>

                    <!-- History Table -->
                    <div class="table-responsive custom-scrollbar">
                        <table class="table table-striped table-hover" id="tillClosingHistoryTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="row-number">#</th>
                                    <th>Date/Time</th>
                                    <th>Till</th>
                                    <th>Cashier</th>
                                    <th>Opening</th>
                                    <th>Sales</th>
                                    <th>Drops</th>
                                    <th>Expected</th>
                                    <th>Actual</th>
                                    <th>Difference</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tillClosingHistoryTableBody">
                                <tr>
                                    <td colspan="12" class="text-center text-muted">Click "Load History" to view data</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination for Till Closing History -->
                    <div class="d-flex justify-content-between align-items-center mt-3" id="historyPaginationContainer" style="display: none !important;">
                        <div class="pagination-info">
                            <span id="historyPaginationInfo">Showing 0 to 0 of 0 entries</span>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="historyPagination">
                                <!-- Pagination buttons will be inserted here -->
                            </ul>
                        </nav>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Day Not Closed Report Modal -->
    <div class="modal fade" id="dayNotClosedReportModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Day Not Closed Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Filter Section -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="dayReportDateFrom" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="dayReportDateFrom" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="dayReportDateTo" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="dayReportDateTo" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="dayReportTillFilter" class="form-label">Till</label>
                            <select class="form-select" id="dayReportTillFilter">
                                <option value="">All Tills</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary" onclick="loadDayNotClosedReport(1)">
                                <i class="bi bi-search"></i> Load Report
                            </button>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row mb-4" id="dayReportSummaryCards" style="display: none;">
                        <div class="col-12">
                            <div class="summary-cards-container">
                                <div class="summary-card" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
                                    <h6>Days Not Closed</h6>
                                    <h4 id="dayReportNotClosed">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                                    <h6>Partial Activity</h6>
                                    <h4 id="dayReportPartial">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);">
                                    <h6>No Activity</h6>
                                    <h4 id="dayReportNoActivity">0</h4>
                                </div>
                                <div class="summary-card" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                                    <h6>Total Days</h6>
                                    <h4 id="dayReportTotalDays">0</h4>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Day Not Closed Table -->
                    <div class="table-responsive custom-scrollbar">
                        <table class="table table-striped table-hover" id="dayNotClosedReportTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="row-number">#</th>
                                    <th>Date</th>
                                    <th>Till</th>
                                    <th>Status</th>
                                    <th>Last Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="dayNotClosedReportTableBody">
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Click "Load Report" to view data</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination for Day Not Closed Report -->
                    <div class="d-flex justify-content-between align-items-center mt-3" id="dayReportPaginationContainer" style="display: none !important;">
                        <div class="pagination-info">
                            <span id="dayReportPaginationInfo">Showing 0 to 0 of 0 entries</span>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="dayReportPagination">
                                <!-- Pagination buttons will be inserted here -->
                            </ul>
                        </nav>
                    </div>

                    <!-- Action Buttons -->
                    <div class="mt-3">
                        <button class="btn btn-warning btn-sm" onclick="closeAllNoActivityDaysFromReport()" id="closeAllFromReportBtn" disabled>
                            <i class="bi bi-check-circle"></i> Close All No Activity Days
                        </button>
                            <button class="btn btn-outline-secondary btn-sm ms-2" onclick="loadDayNotClosedReport(1)">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Close Missed Day Modal -->
    <div class="modal fade" id="closeMissedDayModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Close Missed Day</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="closeMissedDayForm">
                        <input type="hidden" id="missedDayDate" name="date">
                        <input type="hidden" id="missedDayTillId" name="till_id">
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> This will create a closing record for the missed day with zero amounts. This action cannot be undone.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="text" class="form-control" id="missedDayDateDisplay" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Till</label>
                            <input type="text" class="form-control" id="missedDayTillDisplay" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="missedDayOpeningAmount" class="form-label">Opening Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                        <input type="number" class="form-control" id="missedDayOpeningAmount" name="opening_amount" step="0.01" min="0" value="0.00">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="missedDaySalesAmount" class="form-label">Sales Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                        <input type="number" class="form-control" id="missedDaySalesAmount" name="sales_amount" step="0.01" min="0" value="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="missedDayDropsAmount" class="form-label">Drops Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                        <input type="number" class="form-control" id="missedDayDropsAmount" name="drops_amount" step="0.01" min="0" value="0.00">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="missedDayActualAmount" class="form-label">Actual Counted Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?></span>
                                        <input type="number" class="form-control" id="missedDayActualAmount" name="actual_amount" step="0.01" min="0" value="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="missedDayNotes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="missedDayNotes" name="notes" rows="3" placeholder="Reason for missed closing..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmMissedDay" required>
                                <label class="form-check-label" for="confirmMissedDay">
                                    I confirm that this day was not properly closed and needs to be marked as closed.
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="submitMissedDayClosing()">
                        <i class="bi bi-check-circle"></i> Close Missed Day
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Till Closing Details Modal -->
    <div class="modal fade" id="tillClosingDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-eye"></i> Till Closing Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="closingDetailsContent">
                        <div class="text-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading details...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printClosingDetails()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Add Points Modal -->
    <div class="modal fade" id="quickAddPointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="loyalty-points.php">
                    <input type="hidden" name="action" value="add_points">
                    <input type="hidden" name="source" value="manual">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Quick Add Points</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Points added from here will require approval before being applied to the customer's account.
                        </div>
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($recent_customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['full_name']); ?> - <?php echo htmlspecialchars($customer['phone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points to Add</label>
                            <input type="number" class="form-control" name="points" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" placeholder="e.g., Manual adjustment, Special reward" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Points (Pending Approval)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Till Reconciliation Functions
        function showTillReconciliation() {
            const modal = new bootstrap.Modal(document.getElementById('tillReconciliationModal'));
            modal.show();
            loadTillList();
            setupReconciliationFilterListeners();
        }

        function showTillClosingHistory() {
            const modal = new bootstrap.Modal(document.getElementById('tillClosingHistoryModal'));
            modal.show();
            loadTillList();
            setupHistoryFilterListeners();
        }

        function showDayNotClosedReport() {
            const modal = new bootstrap.Modal(document.getElementById('dayNotClosedReportModal'));
            modal.show();
            loadTillListForDayReport();
            setupDayReportFilterListeners();
        }

        function loadTillList() {
            // Load till list for both modals
            fetch('../api/get_tills.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const reconSelect = document.getElementById('reconTillFilter');
                        const historySelect = document.getElementById('historyTillFilter');
                        
                        // Clear existing options
                        reconSelect.innerHTML = '<option value="">All Tills</option>';
                        historySelect.innerHTML = '<option value="">All Tills</option>';
                        
                        data.tills.forEach(till => {
                            const option1 = new Option(till.till_name, till.id);
                            const option2 = new Option(till.till_name, till.id);
                            reconSelect.add(option1);
                            historySelect.add(option2);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading till list:', error);
                });
        }

        // Global pagination variables
        let currentReconPage = 1;
        let currentHistoryPage = 1;
        let currentDayReportPage = 1;
        const recordsPerPage = 40;
        
        // Global number formatting function
        function formatCurrency(amount, currencySymbol = 'KES') {
            const num = parseFloat(amount) || 0;
            return currencySymbol + num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function loadTillReconciliation(page = 1) {
            currentReconPage = page;
            const dateFrom = document.getElementById('reconDateFrom').value;
            const dateTo = document.getElementById('reconDateTo').value;
            const tillId = document.getElementById('reconTillFilter').value;
            const status = document.getElementById('reconStatusFilter').value;

            // Show loading state
            const tbody = document.getElementById('tillReconciliationTableBody');
            tbody.innerHTML = '<tr><td colspan="11" class="text-center"><i class="bi bi-hourglass-split"></i> Loading...</td></tr>';

            // Build query parameters
            const params = new URLSearchParams({
                action: 'get_till_reconciliation',
                date_from: dateFrom,
                date_to: dateTo,
                till_id: tillId,
                status: status,
                page: page,
                limit: recordsPerPage
            });

            fetch(`../api/till_reconciliation.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayTillReconciliation(data.reconciliation, data.pagination);
                        updateReconciliationSummary(data.summary);
                    } else {
                        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error: ' + data.message + '</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Error loading reconciliation:', error);
                    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error loading data</td></tr>';
                });
        }

        function displayTillReconciliation(data, pagination = null) {
            const tbody = document.getElementById('tillReconciliationTableBody');
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">No reconciliation data found</td></tr>';
                document.getElementById('reconPaginationContainer').style.display = 'none';
                return;
            }

            tbody.innerHTML = '';
            const startRow = pagination ? ((pagination.current_page - 1) * recordsPerPage) + 1 : 1;
            
            data.forEach((item, index) => {
                const rowNumber = startRow + index;
                const statusClass = item.shortage_type === 'exact' ? 'success' : 
                                  item.shortage_type === 'shortage' ? 'warning' : 'info';
                const statusText = item.shortage_type === 'exact' ? 'Exact' : 
                                 item.shortage_type === 'shortage' ? 'Shortage' : 'Excess';
                
                const row = `
                    <tr>
                        <td class="row-number">${rowNumber}</td>
                        <td>${item.closed_at}</td>
                        <td>${item.till_name}</td>
                        <td>${item.cashier_name}</td>
                        <td>${formatCurrency(item.opening_amount, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.total_sales, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.total_drops, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.expected_balance, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.actual_counted_amount, item.currency_symbol)}</td>
                        <td class="${item.difference < 0 ? 'text-danger' : item.difference > 0 ? 'text-success' : ''}">
                            ${formatCurrency(item.difference, item.currency_symbol)}
                        </td>
                        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="viewTillClosingDetails(${item.id})" title="View Closing Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" title="More Actions">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="../pos/till_details.php?till_id=${item.till_id}&date=${item.closed_at.split(' ')[0]}" target="_blank">
                                        <i class="bi bi-eye"></i> View Transaction Details
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="printClosingDetails(${item.id})">
                                        <i class="bi bi-printer"></i> Print Closing Report
                                    </a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });

            // Update pagination if provided
            if (pagination) {
                updatePagination('recon', pagination, currentReconPage);
            }
        }

        function updateReconciliationSummary(summary) {
            document.getElementById('totalClosings').textContent = summary.total_closings || 0;
            document.getElementById('exactMatches').textContent = summary.exact_matches || 0;
            document.getElementById('shortages').textContent = summary.shortages || 0;
            document.getElementById('excess').textContent = summary.excess || 0;
            document.getElementById('daysNotClosed').textContent = summary.days_not_closed || 0;
            document.getElementById('totalDays').textContent = summary.total_days || 0;
            document.getElementById('reconSummaryCards').style.display = 'block';
        }

        function loadTillClosingHistory(page = 1) {
            currentHistoryPage = page;
            const dateFrom = document.getElementById('historyDateFrom').value;
            const dateTo = document.getElementById('historyDateTo').value;
            const tillId = document.getElementById('historyTillFilter').value;

            // Show loading state
            const tbody = document.getElementById('tillClosingHistoryTableBody');
            tbody.innerHTML = '<tr><td colspan="12" class="text-center"><i class="bi bi-hourglass-split"></i> Loading...</td></tr>';

            // Build query parameters
            const params = new URLSearchParams({
                action: 'get_till_closing_history',
                date_from: dateFrom,
                date_to: dateTo,
                till_id: tillId,
                page: page,
                limit: recordsPerPage
            });

            fetch(`../api/till_reconciliation.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayTillClosingHistory(data.history, data.pagination);
                    } else {
                        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Error: ' + data.message + '</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Error loading data</td></tr>';
                });
        }

        function displayTillClosingHistory(data, pagination = null) {
            const tbody = document.getElementById('tillClosingHistoryTableBody');
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">No closing history found</td></tr>';
                document.getElementById('historyPaginationContainer').style.display = 'none';
                return;
            }

            tbody.innerHTML = '';
            const startRow = pagination ? ((pagination.current_page - 1) * recordsPerPage) + 1 : 1;
            
            data.forEach((item, index) => {
                const rowNumber = startRow + index;
                const statusClass = item.shortage_type === 'exact' ? 'success' : 
                                  item.shortage_type === 'shortage' ? 'warning' : 'info';
                const statusText = item.shortage_type === 'exact' ? 'Exact' : 
                                 item.shortage_type === 'shortage' ? 'Shortage' : 'Excess';
                
                const row = `
                    <tr>
                        <td class="row-number">${rowNumber}</td>
                        <td>${item.closed_at}</td>
                        <td>${item.till_name}</td>
                        <td>${item.cashier_name}</td>
                        <td>${formatCurrency(item.opening_amount, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.total_sales, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.total_drops, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.expected_balance, item.currency_symbol)}</td>
                        <td>${formatCurrency(item.actual_counted_amount, item.currency_symbol)}</td>
                        <td class="${item.difference < 0 ? 'text-danger' : item.difference > 0 ? 'text-success' : ''}">
                            ${formatCurrency(item.difference, item.currency_symbol)}
                        </td>
                        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                        <td>${item.closing_notes || ''}</td>
                        <td>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="viewTillClosingDetails(${item.id})" title="View Closing Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" title="More Actions">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="../pos/till_details.php?till_id=${item.till_id}&date=${item.closed_at.split(' ')[0]}" target="_blank">
                                        <i class="bi bi-eye"></i> View Transaction Details
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" onclick="printClosingDetails(${item.id})">
                                        <i class="bi bi-printer"></i> Print Closing Report
                                    </a></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });

            // Update pagination if provided
            if (pagination) {
                updatePagination('history', pagination, currentHistoryPage);
            }
        }

        function viewTillClosingDetails(closingId) {
            const modal = new bootstrap.Modal(document.getElementById('tillClosingDetailsModal'));
            modal.show();
            
            // Show loading state
            document.getElementById('closingDetailsContent').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
            `;
            
            // Fetch closing details
            fetch(`../api/till_reconciliation.php?action=get_till_closing_details&closing_id=${closingId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayClosingDetails(data);
                    } else {
                        document.getElementById('closingDetailsContent').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> Error: ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading closing details:', error);
                    document.getElementById('closingDetailsContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> Error loading details. Please try again.
                        </div>
                    `;
                });
        }

        function displayClosingDetails(data) {
            const closing = data.closing;
            const cashDrops = data.cash_drops;
            const salesSummary = data.sales_summary;
            
            const statusClass = closing.shortage_type === 'exact' ? 'success' : 
                              closing.shortage_type === 'shortage' ? 'warning' : 'info';
            const statusText = closing.shortage_type === 'exact' ? 'Exact Match' : 
                             closing.shortage_type === 'shortage' ? 'Shortage' : 'Excess';
            
            // Number formatting function
            function formatCurrency(amount) {
                const num = parseFloat(amount) || 0;
                return closing.currency_symbol + num.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
            
            const content = `
                <div class="row">
                    <!-- Basic Information -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Basic Information</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Closing ID:</strong></td>
                                        <td>#${closing.id}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Date/Time:</strong></td>
                                        <td>${closing.closed_at}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Till:</strong></td>
                                        <td>${closing.till_name}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Location:</strong></td>
                                        <td>${closing.till_location || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Cashier:</strong></td>
                                        <td>${closing.cashier_name}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Closed By:</strong></td>
                                        <td>${closing.cashier_name} (${closing.first_name} ${closing.last_name})</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Status:</strong></td>
                                        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Financial Summary -->
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-cash-stack"></i> Financial Summary</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Opening Amount:</strong></td>
                                        <td>${formatCurrency(closing.opening_amount)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Sales:</strong></td>
                                        <td>${formatCurrency(closing.total_sales)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Drops:</strong></td>
                                        <td>${formatCurrency(closing.total_drops)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Expected Balance:</strong></td>
                                        <td>${formatCurrency(closing.expected_balance)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Actual Counted:</strong></td>
                                        <td>${formatCurrency(closing.actual_counted_amount)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Closing Amount:</strong></td>
                                        <td><strong class="text-primary">${formatCurrency(closing.total_amount)}</strong></td>
                                    </tr>
                                    <tr class="table-${closing.difference < 0 ? 'danger' : closing.difference > 0 ? 'success' : ''}">
                                        <td><strong>Difference:</strong></td>
                                        <td><strong>${formatCurrency(closing.difference)}</strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Opening and Closing Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card mb-3">
                            <div class="card-header bg-dark text-white">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> Opening & Closing Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">Opening Amount</h6>
                                            <h3 class="text-primary">${formatCurrency(closing.opening_amount)}</h3>
                                            <small class="text-muted">Start of day cash</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">Closing Amount</h6>
                                            <h3 class="text-success">${formatCurrency(closing.total_amount)}</h3>
                                            <small class="text-muted">End of day cash</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">Net Change</h6>
                                            <h3 class="text-${closing.difference >= 0 ? 'success' : 'danger'}">${formatCurrency(closing.difference)}</h3>
                                            <small class="text-muted">${closing.difference >= 0 ? 'Gain' : 'Loss'}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Breakdown -->
                <div class="row">
                    <div class="col-12">
                        <div class="card mb-3">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="bi bi-credit-card"></i> Payment Breakdown</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6>Cash</h6>
                                            <h5 class="text-success">${formatCurrency(closing.cash_amount)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6>Voucher</h6>
                                            <h5 class="text-info">${formatCurrency(closing.voucher_amount)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6>Loyalty Points</h6>
                                            <h5 class="text-warning">${formatCurrency(closing.loyalty_points)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h6>Other</h6>
                                            <h5 class="text-secondary">${formatCurrency(closing.other_amount)}</h5>
                                        </div>
                                    </div>
                                </div>
                                ${closing.other_description ? `<p class="mt-2"><small><strong>Other Description:</strong> ${closing.other_description}</small></p>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card mb-3">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="bi bi-graph-up"></i> Sales Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Total Transactions</h6>
                                            <h5>${salesSummary.total_transactions || 0}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Cash Sales</h6>
                                            <h5 class="text-success">${formatCurrency(salesSummary.cash_sales || 0)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Card Sales</h6>
                                            <h5 class="text-primary">${formatCurrency(salesSummary.card_sales || 0)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Mobile Money</h6>
                                            <h5 class="text-info">${formatCurrency(salesSummary.mobile_sales || 0)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Voucher Sales</h6>
                                            <h5 class="text-warning">${formatCurrency(salesSummary.voucher_sales || 0)}</h5>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-center">
                                            <h6>Loyalty Sales</h6>
                                            <h5 class="text-secondary">${formatCurrency(salesSummary.loyalty_sales || 0)}</h5>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Cash Drops -->
                <div class="row">
                    <div class="col-12">
                        <div class="card mb-3">
                            <div class="card-header bg-danger text-white">
                                <h6 class="mb-0"><i class="bi bi-arrow-down-circle"></i> Cash Drops (${cashDrops.length})</h6>
                            </div>
                            <div class="card-body">
                                ${cashDrops.length > 0 ? `
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Amount</th>
                                                    <th>Reason</th>
                                                    <th>Dropped By</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${cashDrops.map(drop => `
                                                    <tr>
                                                        <td>${drop.created_at}</td>
                                                        <td>${formatCurrency(drop.amount)}</td>
                                                        <td>${drop.reason || 'N/A'}</td>
                                                        <td>${drop.dropped_by || 'N/A'}</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                    </div>
                                ` : '<p class="text-muted text-center">No cash drops recorded for this day.</p>'}
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                ${closing.closing_notes ? `
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-secondary text-white">
                                    <h6 class="mb-0"><i class="bi bi-sticky"></i> Notes</h6>
                                </div>
                                <div class="card-body">
                                    <p>${closing.closing_notes}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <!-- Full Amount Summary -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> Complete Financial Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Opening & Closing</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Opening Amount:</strong></td>
                                                <td class="text-end">${formatCurrency(closing.opening_amount)}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Closing Amount:</strong></td>
                                                <td class="text-end"><strong class="text-primary">${formatCurrency(closing.total_amount)}</strong></td>
                                            </tr>
                                            <tr class="table-light">
                                                <td><strong>Net Change:</strong></td>
                                                <td class="text-end"><strong class="text-${closing.difference >= 0 ? 'success' : 'danger'}">${formatCurrency(closing.difference)}</strong></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted mb-3">Activity Summary</h6>
                                        <table class="table table-sm">
                                            <tr>
                                                <td><strong>Total Sales:</strong></td>
                                                <td class="text-end">${formatCurrency(closing.total_sales)}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Drops:</strong></td>
                                                <td class="text-end">${formatCurrency(closing.total_drops)}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Expected Balance:</strong></td>
                                                <td class="text-end">${formatCurrency(closing.expected_balance)}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Actual Counted:</strong></td>
                                                <td class="text-end">${formatCurrency(closing.actual_counted_amount)}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row">
                                    <div class="col-12">
                                        <h6 class="text-muted mb-3">Payment Method Breakdown</h6>
                                        <div class="row">
                                            <div class="col-md-2">
                                                <div class="text-center">
                                                    <small class="text-muted">Cash</small>
                                                    <div class="fw-bold">${formatCurrency(closing.cash_amount)}</div>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="text-center">
                                                    <small class="text-muted">Voucher</small>
                                                    <div class="fw-bold">${formatCurrency(closing.voucher_amount)}</div>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="text-center">
                                                    <small class="text-muted">Loyalty Points</small>
                                                    <div class="fw-bold">${formatCurrency(closing.loyalty_points)}</div>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="text-center">
                                                    <small class="text-muted">Other</small>
                                                    <div class="fw-bold">${formatCurrency(closing.other_amount)}</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="text-center">
                                                    <small class="text-muted">Total Closing Amount</small>
                                                    <div class="fw-bold text-primary fs-5">${formatCurrency(closing.total_amount)}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row">
                                    <div class="col-12">
                                        <div class="alert alert-${closing.difference === 0 ? 'success' : closing.difference > 0 ? 'info' : 'warning'} mb-0">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h6 class="mb-1">
                                                        <i class="bi bi-${closing.difference === 0 ? 'check-circle' : closing.difference > 0 ? 'arrow-up-circle' : 'exclamation-triangle'}"></i>
                                                        Reconciliation ${closing.difference === 0 ? 'Perfect Match' : closing.difference > 0 ? 'Excess Found' : 'Shortage Detected'}
                                                    </h6>
                                                    <small>
                                                        ${closing.difference === 0 ? 'The till balance matches exactly with expected amount.' : 
                                                          closing.difference > 0 ? `There is an excess of ${formatCurrency(closing.difference)} in the till.` : 
                                                          `There is a shortage of ${formatCurrency(Math.abs(closing.difference))} in the till.`}
                                                    </small>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <h5 class="mb-0 text-${closing.difference >= 0 ? 'success' : 'danger'}">
                                                        ${formatCurrency(closing.difference)}
                                                    </h5>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('closingDetailsContent').innerHTML = content;
        }

        function printClosingDetails() {
            const content = document.getElementById('closingDetailsContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Till Closing Details</title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                        <style>
                            @media print {
                                .card { border: 1px solid #000 !important; }
                                .card-header { background-color: #f8f9fa !important; color: #000 !important; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="container mt-3">
                            <h2 class="text-center mb-4">Till Closing Details</h2>
                            ${content}
                        </div>
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        function exportTillReconciliation() {
            const dateFrom = document.getElementById('reconDateFrom').value;
            const dateTo = document.getElementById('reconDateTo').value;
            const tillId = document.getElementById('reconTillFilter').value;
            const status = document.getElementById('reconStatusFilter').value;

            const params = new URLSearchParams({
                action: 'export_till_reconciliation',
                date_from: dateFrom,
                date_to: dateTo,
                till_id: tillId,
                status: status
            });

            window.open(`../api/till_reconciliation.php?${params}`, '_blank');
        }

        // Day Not Closed Functions
        function loadDayNotClosedReport(dateFrom, dateTo, tillId) {
            const params = new URLSearchParams({
                action: 'get_days_not_closed',
                date_from: dateFrom,
                date_to: dateTo,
                till_id: tillId
            });

            fetch(`../api/till_reconciliation.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayDayNotClosedReport(data.days_not_closed);
                        document.getElementById('dayNotClosedSection').style.display = 'block';
                    } else {
                        console.error('Error loading days not closed:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading days not closed:', error);
                });
        }

        function displayDayNotClosedReport(data) {
            const tbody = document.getElementById('dayNotClosedTableBody');
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-success"><i class="bi bi-check-circle"></i> All days are properly closed</td></tr>';
                document.getElementById('closeAllBtn').disabled = true;
                return;
            }

            tbody.innerHTML = '';
            data.forEach(day => {
                const statusClass = day.status === 'no_activity' ? 'danger' : 
                                  day.status === 'partial' ? 'warning' : 'secondary';
                const statusText = day.status === 'no_activity' ? 'No Activity' : 
                                 day.status === 'partial' ? 'Partial' : 'Unknown';
                
                const row = `
                    <tr>
                        <td>${day.date}</td>
                        <td>${day.till_name}</td>
                        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                        <td>${day.last_activity || 'None'}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="showCloseMissedDayModal('${day.date}', ${day.till_id}, '${day.till_name}')" title="Close This Day">
                                <i class="bi bi-check-circle"></i> Close
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
            
            document.getElementById('closeAllBtn').disabled = false;
        }

        function showCloseMissedDayModal(date, tillId, tillName) {
            document.getElementById('missedDayDate').value = date;
            document.getElementById('missedDayTillId').value = tillId;
            document.getElementById('missedDayDateDisplay').value = date;
            document.getElementById('missedDayTillDisplay').value = tillName;
            document.getElementById('missedDayNotes').value = '';
            document.getElementById('confirmMissedDay').checked = false;
            
            // Reset amount fields
            document.getElementById('missedDayOpeningAmount').value = '0.00';
            document.getElementById('missedDaySalesAmount').value = '0.00';
            document.getElementById('missedDayDropsAmount').value = '0.00';
            document.getElementById('missedDayActualAmount').value = '0.00';
            
            const modal = new bootstrap.Modal(document.getElementById('closeMissedDayModal'));
            modal.show();
        }

        function submitMissedDayClosing() {
            if (!document.getElementById('confirmMissedDay').checked) {
                alert('Please confirm that this day was not properly closed.');
                return;
            }

            // Get amount values
            const openingAmount = parseFloat(document.getElementById('missedDayOpeningAmount').value) || 0;
            const salesAmount = parseFloat(document.getElementById('missedDaySalesAmount').value) || 0;
            const dropsAmount = parseFloat(document.getElementById('missedDayDropsAmount').value) || 0;
            const actualAmount = parseFloat(document.getElementById('missedDayActualAmount').value) || 0;
            
            // Calculate expected balance and difference
            const expectedBalance = openingAmount + salesAmount - dropsAmount;
            const difference = actualAmount - expectedBalance;
            const shortageType = difference === 0 ? 'exact' : (difference < 0 ? 'shortage' : 'excess');

            const formData = new FormData();
            formData.append('action', 'close_missed_day');
            formData.append('date', document.getElementById('missedDayDate').value);
            formData.append('till_id', document.getElementById('missedDayTillId').value);
            formData.append('notes', document.getElementById('missedDayNotes').value);
            formData.append('opening_amount', openingAmount);
            formData.append('sales_amount', salesAmount);
            formData.append('drops_amount', dropsAmount);
            formData.append('actual_amount', actualAmount);
            formData.append('expected_balance', expectedBalance);
            formData.append('difference', difference);
            formData.append('shortage_type', shortageType);

            fetch('../api/till_reconciliation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Missed day closed successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('closeMissedDayModal')).hide();
                    refreshDayNotClosed();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error closing missed day:', error);
                alert('Error closing missed day. Please try again.');
            });
        }

        function closeAllMissedDays() {
            if (!confirm('Are you sure you want to close all missed days? This action cannot be undone.')) {
                return;
            }

            const dateFrom = document.getElementById('reconDateFrom').value;
            const dateTo = document.getElementById('reconDateTo').value;
            const tillId = document.getElementById('reconTillFilter').value;

            const formData = new FormData();
            formData.append('action', 'close_all_missed_days');
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('till_id', tillId);

            fetch('../api/till_reconciliation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully closed ${data.closed_count} missed days!`);
                    refreshDayNotClosed();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error closing all missed days:', error);
                alert('Error closing missed days. Please try again.');
            });
        }

        function refreshDayNotClosed() {
            const dateFrom = document.getElementById('reconDateFrom').value;
            const dateTo = document.getElementById('reconDateTo').value;
            const tillId = document.getElementById('reconTillFilter').value;
            loadDayNotClosedReport(dateFrom, dateTo, tillId);
        }

        // Filter Event Listeners
        function setupReconciliationFilterListeners() {
            document.getElementById('reconDateFrom').addEventListener('change', () => loadTillReconciliation(1));
            document.getElementById('reconDateTo').addEventListener('change', () => loadTillReconciliation(1));
            document.getElementById('reconTillFilter').addEventListener('change', () => loadTillReconciliation(1));
            document.getElementById('reconStatusFilter').addEventListener('change', () => loadTillReconciliation(1));
        }

        function setupHistoryFilterListeners() {
            document.getElementById('historyDateFrom').addEventListener('change', () => loadTillClosingHistory(1));
            document.getElementById('historyDateTo').addEventListener('change', () => loadTillClosingHistory(1));
            document.getElementById('historyTillFilter').addEventListener('change', () => loadTillClosingHistory(1));
        }

        function setupDayReportFilterListeners() {
            document.getElementById('dayReportDateFrom').addEventListener('change', () => loadDayNotClosedReport(1));
            document.getElementById('dayReportDateTo').addEventListener('change', () => loadDayNotClosedReport(1));
            document.getElementById('dayReportTillFilter').addEventListener('change', () => loadDayNotClosedReport(1));
        }

        // Independent Day Not Closed Report Functions
        function loadTillListForDayReport() {
            fetch('../api/get_tills.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const tillSelect = document.getElementById('dayReportTillFilter');
                        tillSelect.innerHTML = '<option value="">All Tills</option>';
                        
                        data.tills.forEach(till => {
                            const option = new Option(till.till_name, till.id);
                            tillSelect.add(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading till list:', error);
                });
        }

        function loadDayNotClosedReport(page = 1) {
            currentDayReportPage = page;
            const dateFrom = document.getElementById('dayReportDateFrom').value;
            const dateTo = document.getElementById('dayReportDateTo').value;
            const tillId = document.getElementById('dayReportTillFilter').value;

            // Show loading state
            const tbody = document.getElementById('dayNotClosedReportTableBody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="bi bi-hourglass-split"></i> Loading...</td></tr>';

            const params = new URLSearchParams({
                action: 'get_days_not_closed',
                date_from: dateFrom,
                date_to: dateTo,
                till_id: tillId,
                page: page,
                limit: recordsPerPage
            });

            fetch(`../api/till_reconciliation.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayDayNotClosedReport(data.days_not_closed, data.pagination);
                        updateDayReportSummary(data.days_not_closed);
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error: ' + data.message + '</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Error loading day not closed report:', error);
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data</td></tr>';
                });
        }

        function displayDayNotClosedReport(data, pagination = null) {
            const tbody = document.getElementById('dayNotClosedReportTableBody');
            
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-success"><i class="bi bi-check-circle"></i> All days are properly closed</td></tr>';
                document.getElementById('closeAllFromReportBtn').disabled = true;
                document.getElementById('dayReportPaginationContainer').style.display = 'none';
                return;
            }

            tbody.innerHTML = '';
            let hasNoActivityDays = false;
            const startRow = pagination ? ((pagination.current_page - 1) * recordsPerPage) + 1 : 1;
            
            data.forEach((day, index) => {
                const rowNumber = startRow + index;
                const statusClass = day.status === 'no_activity' ? 'danger' : 
                                  day.status === 'partial' ? 'warning' : 'secondary';
                const statusText = day.status === 'no_activity' ? 'No Activity' : 
                                 day.status === 'partial' ? 'Partial' : 'Unknown';
                
                if (day.status === 'no_activity') {
                    hasNoActivityDays = true;
                }
                
                const row = `
                    <tr>
                        <td class="row-number">${rowNumber}</td>
                        <td>${day.date}</td>
                        <td>${day.till_name}</td>
                        <td><span class="badge bg-${statusClass}">${statusText}</span></td>
                        <td>${day.last_activity || 'None'}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="showCloseMissedDayModal('${day.date}', ${day.till_id}, '${day.till_name}')" title="Close This Day">
                                <i class="bi bi-check-circle"></i> Close
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
            
            // Only enable the button if there are "No Activity" days
            document.getElementById('closeAllFromReportBtn').disabled = !hasNoActivityDays;

            // Update pagination if provided
            if (pagination) {
                updatePagination('dayReport', pagination, currentDayReportPage);
            }
        }

        function updateDayReportSummary(data) {
            const totalDays = data.length;
            const partialActivity = data.filter(day => day.status === 'partial').length;
            const noActivity = data.filter(day => day.status === 'no_activity').length;
            
            document.getElementById('dayReportNotClosed').textContent = totalDays;
            document.getElementById('dayReportPartial').textContent = partialActivity;
            document.getElementById('dayReportNoActivity').textContent = noActivity;
            document.getElementById('dayReportTotalDays').textContent = totalDays;
            document.getElementById('dayReportSummaryCards').style.display = 'block';
        }

        function closeAllNoActivityDaysFromReport() {
            if (!confirm('Are you sure you want to close all "No Activity" days? This will close only days with no sales or cash drops. This action cannot be undone.')) {
                return;
            }

            const dateFrom = document.getElementById('dayReportDateFrom').value;
            const dateTo = document.getElementById('dayReportDateTo').value;
            const tillId = document.getElementById('dayReportTillFilter').value;

            const formData = new FormData();
            formData.append('action', 'close_all_no_activity_days');
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('till_id', tillId);

            fetch('../api/till_reconciliation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Successfully closed ${data.closed_count} "No Activity" days!`);
                    loadDayNotClosedReport();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error closing no activity days:', error);
                alert('Error closing no activity days. Please try again.');
            });
        }

        // Pagination utility functions
        function updatePagination(type, pagination, currentPage) {
            const container = document.getElementById(`${type}PaginationContainer`);
            const info = document.getElementById(`${type}PaginationInfo`);
            const paginationUl = document.getElementById(`${type}Pagination`);
            
            if (!pagination || pagination.total_pages <= 1) {
                container.style.display = 'none';
                return;
            }
            
            container.style.display = 'flex';
            
            // Update pagination info
            const start = ((currentPage - 1) * recordsPerPage) + 1;
            const end = Math.min(currentPage * recordsPerPage, pagination.total_records);
            info.textContent = `Showing ${start} to ${end} of ${pagination.total_records} entries`;
            
            // Get the correct function name based on type
            let functionName;
            switch(type) {
                case 'recon':
                    functionName = 'loadRecon';
                    break;
                case 'history':
                    functionName = 'loadHistory';
                    break;
                case 'dayReport':
                    functionName = 'loadDayReport';
                    break;
                default:
                    functionName = 'loadRecon';
            }
            
            // Generate pagination buttons
            let paginationHTML = '';
            
            // Previous button
            const prevPage = Math.max(1, currentPage - 1);
            paginationHTML += `
                <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="${functionName}(${prevPage}); return false;">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            `;
            
            // Page numbers
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(pagination.total_pages, currentPage + 2);
            
            if (startPage > 1) {
                paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="${functionName}(1); return false;">1</a></li>`;
                if (startPage > 2) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                paginationHTML += `
                    <li class="page-item ${i === currentPage ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="${functionName}(${i}); return false;">${i}</a>
                    </li>
                `;
            }
            
            if (endPage < pagination.total_pages) {
                if (endPage < pagination.total_pages - 1) {
                    paginationHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
                paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="${functionName}(${pagination.total_pages}); return false;">${pagination.total_pages}</a></li>`;
            }
            
            // Next button
            const nextPage = Math.min(pagination.total_pages, currentPage + 1);
            paginationHTML += `
                <li class="page-item ${currentPage === pagination.total_pages ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="${functionName}(${nextPage}); return false;">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            `;
            
            paginationUl.innerHTML = paginationHTML;
        }

        // Wrapper functions for pagination
        function loadRecon(page = 1) {
            loadTillReconciliation(page);
        }

        function loadHistory(page = 1) {
            loadTillClosingHistory(page);
        }

        function loadDayReport(page = 1) {
            loadDayNotClosedReport(page);
        }
    </script>
    
    <style>
        /* Extra space at page bottom to prevent content/footer overlap */
        .page-bottom-space {
            height: 80px;
            width: 100%;
            display: block;
        }
    </style>
    <!-- bottom spacing so content doesn't sit flush to the viewport bottom -->
    <div class="page-bottom-space" aria-hidden="true"></div>
</body>
</html>
