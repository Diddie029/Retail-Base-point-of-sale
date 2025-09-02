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

// Check if user has permission to delete customers
if (!hasPermission('delete_customers', $permissions)) {
    header("Location: index.php");
    exit();
}

// Get customer ID from URL
$customer_id = intval($_GET['id'] ?? 0);
if (!$customer_id) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get customer details
$stmt = $conn->prepare("
    SELECT c.*,
           CONCAT(c.first_name, ' ', c.last_name) as full_name
    FROM customers c
    WHERE c.id = :customer_id
");
$stmt->bindParam(':customer_id', $customer_id);
$stmt->execute();
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: index.php");
    exit();
}

// Prevent deletion of walk-in customer
if ($customer['customer_type'] === 'walk_in') {
    header("Location: view.php?id=$customer_id");
    exit();
}

// Check if customer has any sales/transactions
$sales_count = 0;
$sales_stmt = $conn->prepare("SELECT COUNT(*) as count FROM sales WHERE customer_name = :customer_name");
$sales_stmt->bindParam(':customer_name', $customer['full_name']);
$sales_stmt->execute();
$sales_count = $sales_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Double-check permissions
        if (!hasPermission('delete_customers', $permissions)) {
            throw new Exception('You do not have permission to delete customers');
        }

        // Check if customer has sales (to prevent accidental deletion)
        if ($sales_count > 0) {
            $confirm_deletion = $_POST['confirm_deletion'] ?? '';
            if ($confirm_deletion !== 'yes') {
                throw new Exception('You must confirm deletion of customer with transaction history');
            }
        }

        // Start transaction
        $conn->beginTransaction();

        // Log the deletion before actually deleting
        $log_stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, details, created_at)
            VALUES (:user_id, :action, :details, NOW())
        ");
        $log_stmt->execute([
            ':user_id' => $user_id,
            ':action' => "Deleted customer: {$customer['full_name']} ({$customer['customer_number']})",
            ':details' => json_encode([
                'customer_id' => $customer_id,
                'customer_number' => $customer['customer_number'],
                'customer_name' => $customer['full_name'],
                'customer_type' => $customer['customer_type'],
                'sales_count' => $sales_count
            ])
        ]);

        // Delete the customer
        $delete_stmt = $conn->prepare("DELETE FROM customers WHERE id = :customer_id");
        $delete_stmt->bindParam(':customer_id', $customer_id);
        $delete_stmt->execute();

        // Commit transaction
        $conn->commit();

        $success = "Customer '{$customer['full_name']}' has been successfully deleted.";

        // Redirect after successful deletion
        header("Location: index.php?deleted=1&message=" . urlencode($success));
        exit();

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Customer - <?php echo htmlspecialchars($customer['full_name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .delete-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 2px solid #fee2e2;
        }

        .delete-header {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .customer-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
            color: white;
            margin-bottom: 1rem;
        }

        .warning-section {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .warning-section.danger {
            background: #fee2e2;
            border-color: #dc2626;
        }

        .checkbox-container {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            transition: all 0.3s ease;
        }

        .checkbox-container.has-sales {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .form-check-input:checked ~ .checkbox-container {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 500;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c, #991b1b);
            transform: translateY(-1px);
        }

        .btn-outline-secondary:hover {
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 8px;
            padding: 1rem 1.5rem;
        }

        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .stats-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .stats-label {
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'customers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb" class="mb-2">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Customers</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['full_name']); ?></a></li>
                            <li class="breadcrumb-item active">Delete</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-trash me-2"></i>Delete Customer</h1>
                    <p class="header-subtitle">Remove customer from the system</p>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-eye me-1"></i>View Customer
                    </a>
                    <a href="edit.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-pencil me-1"></i>Edit Customer
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Customers
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Success/Error Messages -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="delete-card">
                        <div class="delete-header">
                            <i class="bi bi-exclamation-triangle display-4 mb-3"></i>
                            <h3>Confirm Customer Deletion</h3>
                            <p class="mb-0">This action cannot be undone</p>
                        </div>

                        <div class="p-4">
                            <!-- Customer Information -->
                            <div class="customer-info">
                                <div class="text-center mb-3">
                                    <div class="customer-avatar">
                                        <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <h5><?php echo htmlspecialchars($customer['full_name']); ?></h5>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($customer['customer_number']); ?></p>
                                    <div class="d-flex justify-content-center gap-2">
                                        <span class="badge bg-primary"><?php echo ucfirst($customer['customer_type']); ?> Customer</span>
                                        <span class="badge bg-<?php echo $customer['membership_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($customer['membership_status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer Statistics -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-3">
                                    <div class="stats-card">
                                        <div class="stats-number">$<?php echo number_format($customer['current_balance'], 2); ?></div>
                                        <div class="stats-label">Current Balance</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-card">
                                        <div class="stats-number">$<?php echo number_format($customer['credit_limit'], 2); ?></div>
                                        <div class="stats-label">Credit Limit</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-card">
                                        <div class="stats-number"><?php echo number_format($customer['loyalty_points']); ?></div>
                                        <div class="stats-label">Loyalty Points</div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stats-card">
                                        <div class="stats-number"><?php echo $sales_count; ?></div>
                                        <div class="stats-label">Total Sales</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Warnings -->
                            <?php if ($sales_count > 0): ?>
                            <div class="warning-section danger">
                                <h6 class="text-danger mb-2">
                                    <i class="bi bi-exclamation-triangle me-2"></i>Important Warning
                                </h6>
                                <p class="mb-2">
                                    This customer has <strong><?php echo $sales_count; ?> sales transaction(s)</strong> in the system.
                                    Deleting this customer will not remove the transaction history, but the customer information
                                    will be permanently lost.
                                </p>
                                <p class="mb-0 text-danger">
                                    <strong>Consider:</strong> Instead of deleting, you may want to mark the customer as inactive
                                    by changing their membership status.
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="warning-section">
                                <h6 class="text-warning mb-2">
                                    <i class="bi bi-info-circle me-2"></i>Information
                                </h6>
                                <p class="mb-0">
                                    This customer has no sales transactions in the system and can be safely deleted.
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Deletion Form -->
                            <form method="POST" id="deleteForm">
                                <?php if ($sales_count > 0): ?>
                                <div class="checkbox-container has-sales">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="confirm_deletion" name="confirm_deletion" value="yes">
                                        <label class="form-check-label" for="confirm_deletion">
                                            <strong>I understand that this customer has transaction history and confirm deletion</strong>
                                        </label>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-center gap-3 mt-4">
                                    <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-1"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-danger" id="deleteBtn"
                                            <?php echo ($sales_count > 0) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-trash me-1"></i>Delete Customer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($sales_count > 0): ?>
        // Enable/disable delete button based on checkbox
        document.getElementById('confirm_deletion').addEventListener('change', function() {
            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = !this.checked;
        });
        <?php endif; ?>

        // Form submission confirmation
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const confirmed = confirm('Are you absolutely sure you want to delete this customer? This action cannot be undone.');
            if (!confirmed) {
                e.preventDefault();
                return false;
            }

            // Disable the button to prevent double submission
            const deleteBtn = document.getElementById('deleteBtn');
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Deleting...';
        });
    </script>
</body>
</html>
