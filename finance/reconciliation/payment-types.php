<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
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

// Check if user has permission to manage reconciliation
if (!hasPermission('view_finance', $permissions) && !hasPermission('manage_reconciliation', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $id = (int)$_POST['id'];
        $display_name = $_POST['display_name'];
        $description = $_POST['description'];
        $category = $_POST['category'];
        $icon = $_POST['icon'];
        $color = $_POST['color'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $requires_reconciliation = isset($_POST['requires_reconciliation']) ? 1 : 0;
        $sort_order = (int)$_POST['sort_order'];
        
        try {
            $stmt = $conn->prepare("
                UPDATE payment_types 
                SET display_name = ?, description = ?, category = ?, icon = ?, color = ?, 
                    is_active = ?, requires_reconciliation = ?, sort_order = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $display_name, $description, $category, $icon, $color,
                $is_active, $requires_reconciliation, $sort_order, $id
            ]);
            $success_message = "Payment type updated successfully!";
        } catch (PDOException $e) {
            $error_message = "Error updating payment type: " . $e->getMessage();
        }
    }
}

// Get payment types
$stmt = $conn->query("SELECT * FROM payment_types ORDER BY sort_order, display_name");
$payment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Types - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --sidebar-color: #1e293b;
        }
        
        .payment-type-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .payment-type-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .payment-type-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .category-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
        }
        
        .category-cash { background: #d4edda; color: #155724; }
        .category-digital { background: #cce7ff; color: #004085; }
        .category-card { background: #f8d7da; color: #721c24; }
        .category-bank { background: #e2e3f1; color: #383d41; }
        .category-other { background: #f8f9fa; color: #6c757d; }
        
        .status-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .status-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #28a745;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="page-header">
            <div class="container-fluid">
                <div class="header-content">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../dashboard/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Reconciliation</a></li>
                            <li class="breadcrumb-item active">Payment Types</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-credit-card"></i> Payment Types</h1>
                    <p class="header-subtitle">Manage payment types for reconciliation</p>
                </div>
                <div class="header-actions">
                    <div class="d-flex align-items-center gap-2">
                        <a href="documentation.php" class="btn btn-outline-info btn-sm" title="View Documentation">
                            <i class="bi bi-book"></i> Docs
                        </a>
                        <a href="../reconciliation.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Reconciliation
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Payment Types Overview -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Payment Types Overview</h5>
                            </div>
                            <div class="card-body">
                                <p>Payment types help categorize transactions during reconciliation. Each payment type can be mapped to specific bank accounts and has different reconciliation requirements.</p>
                                
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="payment-type-icon" style="background: #28a745;">
                                                <i class="bi bi-cash"></i>
                                            </div>
                                            <h6 class="mt-2">Cash</h6>
                                            <small class="text-muted">Physical cash payments</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="payment-type-icon" style="background: #007bff;">
                                                <i class="bi bi-phone"></i>
                                            </div>
                                            <h6 class="mt-2">Mobile Money</h6>
                                            <small class="text-muted">M-Pesa, Airtel Money</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="payment-type-icon" style="background: #dc3545;">
                                                <i class="bi bi-credit-card"></i>
                                            </div>
                                            <h6 class="mt-2">Cards</h6>
                                            <small class="text-muted">Credit & Debit cards</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="payment-type-icon" style="background: #6f42c1;">
                                                <i class="bi bi-bank"></i>
                                            </div>
                                            <h6 class="mt-2">Bank Transfer</h6>
                                            <small class="text-muted">Direct transfers</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Types List -->
                <div class="row">
                    <?php foreach ($payment_types as $type): ?>
                    <div class="col-lg-6 col-xl-4 mb-4">
                        <div class="payment-type-card">
                            <form method="POST" class="payment-type-form">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                
                                <div class="d-flex align-items-start mb-3">
                                    <div class="payment-type-icon me-3" style="background: <?php echo $type['color']; ?>;">
                                        <i class="<?php echo $type['icon']; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($type['display_name']); ?></h6>
                                            <span class="category-badge category-<?php echo $type['category']; ?>">
                                                <?php echo ucfirst($type['category']); ?>
                                            </span>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($type['description']); ?></small>
                                    </div>
                                </div>

                                <div class="row g-2">
                                    <div class="col-12">
                                        <label class="form-label small">Display Name</label>
                                        <input type="text" class="form-control form-control-sm" name="display_name" 
                                               value="<?php echo htmlspecialchars($type['display_name']); ?>" required>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label small">Description</label>
                                        <textarea class="form-control form-control-sm" name="description" rows="2"><?php echo htmlspecialchars($type['description']); ?></textarea>
                                    </div>
                                    
                                    <div class="col-6">
                                        <label class="form-label small">Category</label>
                                        <select class="form-select form-select-sm" name="category">
                                            <option value="cash" <?php echo $type['category'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                            <option value="digital" <?php echo $type['category'] === 'digital' ? 'selected' : ''; ?>>Digital</option>
                                            <option value="card" <?php echo $type['category'] === 'card' ? 'selected' : ''; ?>>Card</option>
                                            <option value="bank" <?php echo $type['category'] === 'bank' ? 'selected' : ''; ?>>Bank</option>
                                            <option value="other" <?php echo $type['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-6">
                                        <label class="form-label small">Icon</label>
                                        <input type="text" class="form-control form-control-sm" name="icon" 
                                               value="<?php echo htmlspecialchars($type['icon']); ?>" placeholder="bi-cash">
                                    </div>
                                    
                                    <div class="col-6">
                                        <label class="form-label small">Color</label>
                                        <input type="color" class="form-control form-control-sm" name="color" 
                                               value="<?php echo htmlspecialchars($type['color']); ?>">
                                    </div>
                                    
                                    <div class="col-6">
                                        <label class="form-label small">Sort Order</label>
                                        <input type="number" class="form-control form-control-sm" name="sort_order" 
                                               value="<?php echo $type['sort_order']; ?>" min="0">
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_active" 
                                                   <?php echo $type['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small">Active</label>
                                        </div>
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="requires_reconciliation" 
                                                   <?php echo $type['requires_reconciliation'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small">Requires Reconciliation</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <small class="text-muted">
                                        Created: <?php echo date('M j, Y', strtotime($type['created_at'])); ?>
                                    </small>
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-check"></i> Update
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Help Section -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-question-circle"></i> Payment Types Help</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Categories Explained:</h6>
                                        <ul class="small">
                                            <li><strong>Cash:</strong> Physical cash payments (goes to cash drawer)</li>
                                            <li><strong>Digital:</strong> Mobile money, online payments</li>
                                            <li><strong>Card:</strong> Credit/debit card payments</li>
                                            <li><strong>Bank:</strong> Bank transfers, checks</li>
                                            <li><strong>Other:</strong> Vouchers, store credit, etc.</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Settings Explained:</h6>
                                        <ul class="small">
                                            <li><strong>Active:</strong> Payment type is available for use</li>
                                            <li><strong>Requires Reconciliation:</strong> Must be reconciled with bank statements</li>
                                            <li><strong>Sort Order:</strong> Display order in lists</li>
                                            <li><strong>Color & Icon:</strong> Visual representation</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-save forms on change
        document.querySelectorAll('.payment-type-form').forEach(form => {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Add visual feedback
                    this.style.borderColor = '#28a745';
                    setTimeout(() => {
                        this.style.borderColor = '';
                    }, 1000);
                });
            });
        });
    </script>
</body>
</html>
