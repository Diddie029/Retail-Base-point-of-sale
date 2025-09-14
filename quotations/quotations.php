<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters
$filters = [];
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['customer_name']) && !empty($_GET['customer_name'])) {
    $filters['customer_name'] = $_GET['customer_name'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Get quotations
$quotationsResult = getQuotations($conn, $filters, $page, $perPage);
$quotations = $quotationsResult['quotations'];
$total = $quotationsResult['total'];
$pages = $quotationsResult['pages'];

// Get statistics for all quotations (not filtered)
$allQuotationsResult = getQuotations($conn, [], 1, 1000); // Get more for stats
$allQuotations = $allQuotationsResult['quotations'];

// Calculate statistics
$stats = [
    'total' => count($allQuotations),
    'draft' => count(array_filter($allQuotations, fn($q) => $q['quotation_status'] === 'draft')),
    'sent' => count(array_filter($allQuotations, fn($q) => $q['quotation_status'] === 'sent')),
    'approved' => count(array_filter($allQuotations, fn($q) => $q['quotation_status'] === 'approved')),
    'rejected' => count(array_filter($allQuotations, fn($q) => $q['quotation_status'] === 'rejected')),
    'expired' => count(array_filter($allQuotations, fn($q) => $q['quotation_status'] === 'expired'))
];

// Calculate amounts and conversion rates
$totalAmount = array_sum(array_column($allQuotations, 'final_amount'));
$approvedAmount = array_sum(array_map(fn($q) => $q['quotation_status'] === 'approved' ? $q['final_amount'] : 0, $allQuotations));
$conversionRate = $stats['total'] > 0 ? round(($stats['approved'] / $stats['total']) * 100, 1) : 0;

// Recent quotations (last 30 days)
$recentQuotations = array_filter($allQuotations, fn($q) => strtotime($q['created_at']) > strtotime('-30 days'));
$recentCount = count($recentQuotations);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $quotation_id = (int)$_POST['quotation_id'];
    $status = $_POST['status'];

    if (updateQuotationStatus($conn, $quotation_id, $status)) {
        $_SESSION['success_message'] = "Quotation status updated successfully.";
    } else {
        $_SESSION['error_message'] = "Failed to update quotation status.";
    }

    header("Location: quotations.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotations - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --primary-gradient: linear-gradient(135deg, var(--primary-color) 0%, #8b5cf6 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --info-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
        }

        /* Statistics Dashboard */
        .stats-dashboard {
            margin-bottom: 2rem;
        }

        .stats-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }

        .stats-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .stats-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
        }

        .stats-subtitle {
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 25px -8px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card.total::before { background: var(--primary-gradient); }
        .stat-card.draft::before { background: var(--warning-gradient); }
        .stat-card.sent::before { background: var(--info-gradient); }
        .stat-card.approved::before { background: var(--success-gradient); }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
        }

        .stat-card.total .stat-icon {
            background: var(--primary-gradient);
            color: white;
        }

        .stat-card.draft .stat-icon {
            background: var(--warning-gradient);
            color: white;
        }

        .stat-card.sent .stat-icon {
            background: var(--info-gradient);
            color: white;
        }

        .stat-card.approved .stat-icon {
            background: var(--success-gradient);
            color: white;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1;
            position: relative;
            z-index: 2;
        }

        .stat-label {
            font-size: 0.95rem;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
            z-index: 2;
        }

        .stat-change {
            font-size: 0.85rem;
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        .stat-change.positive {
            color: #10b981;
        }

        .stat-change.negative {
            color: #ef4444;
        }

        /* Advanced Stats Cards */
        .advanced-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .advanced-stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }

        .advanced-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -8px rgba(0, 0, 0, 0.15);
        }

        .advanced-stat-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .advanced-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
        }

        .advanced-stat-icon.amount {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .advanced-stat-icon.conversion {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .advanced-stat-icon.recent {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .advanced-stat-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .advanced-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .advanced-stat-subtitle {
            font-size: 0.8rem;
            color: #9ca3af;
        }

        /* Main Content Sections */
        .quotations-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .quotations-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-sent {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-expired {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .btn-outline-secondary {
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
        }

        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }

        .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: 1px solid #e2e8f0;
        }

        .page-item.active .page-link {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Animations */
        .bi-spin {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Toast Styles */
        .toast {
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .stats-overview {
                grid-template-columns: 1fr;
            }

            .advanced-stats {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-value {
                font-size: 2rem;
            }

            .stats-header {
                padding: 1.5rem;
            }

            .stats-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    <div class="main-content" style="padding-bottom: 4rem;">
        <div class="container-fluid" style="margin-bottom: 3rem;">

        <!-- Statistics Dashboard -->
        <div class="stats-dashboard">
            <!-- Statistics Header -->
            <div class="stats-header">
                <div class="stats-title">
                    <i class="bi bi-bar-chart-line me-2"></i>
                    Quotations Analytics
                </div>
                <div class="stats-subtitle">
                    Real-time insights into your quotation performance
                </div>
            </div>

            <!-- Main Statistics Cards -->
            <div class="stats-overview">
                <!-- Total Quotations -->
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Quotations</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> +<?php echo $recentCount; ?> this month
                    </div>
                </div>

                <!-- Draft Quotations -->
                <div class="stat-card draft">
                    <div class="stat-icon">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['draft']); ?></div>
                    <div class="stat-label">Draft</div>
                    <div class="stat-change" style="color: #f59e0b;">
                        <?php echo $stats['total'] > 0 ? round(($stats['draft'] / $stats['total']) * 100, 1) : 0; ?>% of total
                    </div>
                </div>

                <!-- Sent Quotations -->
                <div class="stat-card sent">
                    <div class="stat-icon">
                        <i class="bi bi-send"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['sent']); ?></div>
                    <div class="stat-label">Sent</div>
                    <div class="stat-change" style="color: #3b82f6;">
                        <?php echo $stats['total'] > 0 ? round(($stats['sent'] / $stats['total']) * 100, 1) : 0; ?>% of total
                    </div>
                </div>

                <!-- Approved Quotations -->
                <div class="stat-card approved">
                    <div class="stat-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['approved']); ?></div>
                    <div class="stat-label">Approved</div>
                    <div class="stat-change positive">
                        <i class="bi bi-trophy"></i> <?php echo $conversionRate; ?>% conversion rate
                    </div>
                </div>
            </div>

            <!-- Advanced Statistics -->
            <div class="advanced-stats">
                <!-- Total Amount -->
                <div class="advanced-stat-card">
                    <div class="advanced-stat-header">
                        <div class="advanced-stat-icon amount">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div>
                            <div class="advanced-stat-title">Total Value</div>
                            <div class="advanced-stat-value">KES <?php echo number_format($totalAmount, 2); ?></div>
                            <div class="advanced-stat-subtitle">All quotations combined</div>
                        </div>
                    </div>
                </div>

                <!-- Approved Amount -->
                <div class="advanced-stat-card">
                    <div class="advanced-stat-header">
                        <div class="advanced-stat-icon conversion">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <div class="advanced-stat-title">Approved Value</div>
                            <div class="advanced-stat-value">KES <?php echo number_format($approvedAmount, 2); ?></div>
                            <div class="advanced-stat-subtitle"><?php echo $approvedAmount > 0 ? round(($approvedAmount / $totalAmount) * 100, 1) : 0; ?>% of total value</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="advanced-stat-card">
                    <div class="advanced-stat-header">
                        <div class="advanced-stat-icon recent">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="advanced-stat-title">Recent Activity</div>
                            <div class="advanced-stat-value"><?php echo $recentCount; ?></div>
                            <div class="advanced-stat-subtitle">Quotations in last 30 days</div>
                        </div>
                    </div>
                </div>

                <!-- Conversion Rate -->
                <div class="advanced-stat-card">
                    <div class="advanced-stat-header">
                        <div class="advanced-stat-icon conversion">
                            <i class="bi bi-bullseye"></i>
                        </div>
                        <div>
                            <div class="advanced-stat-title">Conversion Rate</div>
                            <div class="advanced-stat-value"><?php echo $conversionRate; ?>%</div>
                            <div class="advanced-stat-subtitle">Approved vs Total quotations</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Header -->
        <div class="quotations-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-file-earmark-text"></i> Quotations Management</h1>
                    <p class="text-muted mb-0">View, filter, and manage all quotations</p>
                </div>
                <div>
                    <a href="quotation.php?action=create" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Create Quotation
                    </a>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo ($filters['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo ($filters['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="expired" <?php echo ($filters['status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="customer_name" class="form-label">Customer Name</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name"
                           value="<?php echo htmlspecialchars($filters['customer_name'] ?? ''); ?>" placeholder="Search by customer">
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                           value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to"
                           value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="quotations.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Quotations Table -->
        <div class="quotations-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Quotation #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Valid Until</th>
                            <th>Total</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quotations)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-file-earmark-text text-muted" style="font-size: 3rem;"></i>
                                    <div class="mt-3">
                                        <h5>No Quotations Found</h5>
                                        <p class="text-muted">Try adjusting your filters or create a new quotation.</p>
                                        <a href="quotation.php?action=create" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> Create First Quotation
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quotations as $quotation): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo htmlspecialchars($quotation['quotation_number']); ?></strong>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($quotation['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($quotation['customer_name'] ?: 'Walk-in Customer'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $quotation['quotation_status']; ?>">
                                            <?php echo ucfirst($quotation['quotation_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($quotation['valid_until'])); ?></td>
                                    <td>
                                        <strong>KES <?php echo number_format($quotation['final_amount'], 2); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($quotation['created_by']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="quotation.php?quotation_id=<?php echo $quotation['id']; ?>"
                                               class="btn btn-sm btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="quotation.php?quotation_id=<?php echo $quotation['id']; ?>&action=edit"
                                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown" title="More Actions">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="quotation_id" value="<?php echo $quotation['id']; ?>">
                                                            <input type="hidden" name="status" value="sent">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="bi bi-send"></i> Mark as Sent
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="quotation_id" value="<?php echo $quotation['id']; ?>">
                                                            <input type="hidden" name="status" value="approved">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="bi bi-check-circle"></i> Mark as Approved
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="quotation_id" value="<?php echo $quotation['id']; ?>">
                                                            <input type="hidden" name="status" value="rejected">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="bi bi-x-circle"></i> Mark as Rejected
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav aria-label="Quotations pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);

            // Add animation to statistics cards
            animateStatsCards();

            // Add click handlers for advanced stats
            addStatsInteractions();
        });

        function animateStatsCards() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            const advancedCards = document.querySelectorAll('.advanced-stat-card');
            advancedCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, (index * 100) + 800);
            });
        }

        function addStatsInteractions() {
            // Add hover effects and click handlers
            document.querySelectorAll('.advanced-stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    const title = this.querySelector('.advanced-stat-title').textContent;

                    // Show different actions based on the stat type
                    if (title === 'Total Value') {
                        // Could link to detailed financial reports
                        showToast('Financial reports feature coming soon!', 'info');
                    } else if (title === 'Conversion Rate') {
                        // Could show conversion analytics
                        showToast('Advanced analytics available in reports section', 'info');
                    } else if (title === 'Recent Activity') {
                        // Could filter to recent quotations
                        const url = new URL(window.location);
                        const thirtyDaysAgo = new Date();
                        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                        url.searchParams.set('date_from', thirtyDaysAgo.toISOString().split('T')[0]);
                        url.searchParams.set('date_to', new Date().toISOString().split('T')[0]);
                        window.location.href = url.toString();
                    }
                });
            });
        }

        function showToast(message, type = 'info') {
            const toastHtml = `
                <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;

            // Create toast container if it doesn't exist
            let toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                toastContainer.style.zIndex = '9999';
                document.body.appendChild(toastContainer);
            }

            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toast = toastContainer.lastElementChild;
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();

            // Remove toast after it's hidden
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }

        // Add loading animation for filter submissions
        document.addEventListener('submit', function(e) {
            if (e.target.matches('form[method="GET"]')) {
                const submitBtn = e.target.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="bi bi-arrow-clockwise bi-spin"></i> Loading...';
                    submitBtn.disabled = true;
                }
            }
        });
    </script>
</body>
</html>
