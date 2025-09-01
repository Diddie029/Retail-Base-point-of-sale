<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check permissions
if (!hasPermission('create_expenses', $permissions)) {
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category_id = $_POST['category_id'];
    $subcategory_id = $_POST['subcategory_id'] ?: null;
    $vendor_id = $_POST['vendor_id'] ?: null;
    $department_id = $_POST['department_id'] ?: null;
    $amount = floatval($_POST['amount']);
    $tax_amount = floatval($_POST['tax_amount']);
    $total_amount = $amount + $tax_amount;
    $payment_method_id = $_POST['payment_method_id'] ?: null;
    $payment_status = $_POST['payment_status'];
    $payment_date = $_POST['payment_date'] ?: null;
    $due_date = $_POST['due_date'] ?: null;
    $expense_date = $_POST['expense_date'];
    $is_tax_deductible = isset($_POST['is_tax_deductible']) ? 1 : 0;
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurring_frequency = $_POST['recurring_frequency'] ?: null;
    $recurring_end_date = $_POST['recurring_end_date'] ?: null;
    $notes = trim($_POST['notes']);

    // Validation
    $errors = [];
    
    if (empty($title)) {
        $errors[] = "Title is required";
    }
    
    if (empty($category_id)) {
        $errors[] = "Category is required";
    }
    
    if (empty($amount) || $amount <= 0) {
        $errors[] = "Amount must be greater than 0";
    }
    
    if (empty($expense_date)) {
        $errors[] = "Expense date is required";
    }
    
    if ($is_recurring && empty($recurring_frequency)) {
        $errors[] = "Recurring frequency is required for recurring expenses";
    }

    if (empty($errors)) {
        try {
            // Generate expense number
            $prefix = getSetting($conn, 'expense_number_prefix', 'EXP');
            $length = intval(getSetting($conn, 'expense_number_length', 6));
            
            $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(expense_number, ?) AS UNSIGNED)) as max_num FROM expenses WHERE expense_number LIKE ?");
            $prefix_length = strlen($prefix);
            $stmt->execute([$prefix_length + 1, $prefix . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $next_number = ($result['max_num'] ?? 0) + 1;
            $expense_number = $prefix . str_pad($next_number, $length, '0', STR_PAD_LEFT);

            // Insert expense
            $stmt = $conn->prepare("
                INSERT INTO expenses (
                    expense_number, title, description, category_id, subcategory_id, 
                    vendor_id, department_id, amount, tax_amount, total_amount,
                    payment_method_id, payment_status, payment_date, due_date, expense_date,
                    is_tax_deductible, is_recurring, recurring_frequency, recurring_end_date,
                    notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $expense_number, $title, $description, $category_id, $subcategory_id,
                $vendor_id, $department_id, $amount, $tax_amount, $total_amount,
                $payment_method_id, $payment_status, $payment_date, $due_date, $expense_date,
                $is_tax_deductible, $is_recurring, $recurring_frequency, $recurring_end_date,
                $notes, $user_id
            ]);
            
            $expense_id = $conn->lastInsertId();

            // Handle file upload
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
                $upload_dir = '../storage/expenses/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
                $file_name = $expense_number . '_' . time() . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
                    $stmt = $conn->prepare("UPDATE expenses SET receipt_file = ? WHERE id = ?");
                    $stmt->execute([$file_name, $expense_id]);
                }
            }

            // Log activity
            logActivity($conn, $user_id, 'expense_created', "Created expense: $expense_number - $title");

            $_SESSION['success_message'] = "Expense created successfully! Expense Number: $expense_number";
            header('Location: index.php');
            exit();
            
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Get form data
$categories = $conn->query("SELECT id, name, color_code FROM expense_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$vendors = $conn->query("SELECT id, name FROM expense_vendors WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $conn->query("SELECT id, name FROM expense_departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$payment_methods = $conn->query("SELECT id, name FROM expense_payment_methods WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Get default values
$default_tax_deductible = getSetting($conn, 'expense_tax_deductible_default', '0');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-plus-circle"></i> Add New Expense</h1>
                    <p class="header-subtitle">Create a new expense entry</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Expense Details</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="expenseForm">
                            <div class="row">
                                <!-- Basic Information -->
                                <div class="col-md-8">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Title <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Expense Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" name="expense_date" value="<?= $_POST['expense_date'] ?? date('Y-m-d') ?>" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Category <span class="text-danger">*</span></label>
                                            <select class="form-select" name="category_id" id="category_id" required>
                                                <option value="">Select Category</option>
                                                <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['id'] ?>" <?= ($_POST['category_id'] ?? '') == $category['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($category['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Subcategory</label>
                                            <select class="form-select" name="subcategory_id" id="subcategory_id">
                                                <option value="">Select Subcategory</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Vendor</label>
                                            <select class="form-select" name="vendor_id">
                                                <option value="">Select Vendor</option>
                                                <?php foreach ($vendors as $vendor): ?>
                                                <option value="<?= $vendor['id'] ?>" <?= ($_POST['vendor_id'] ?? '') == $vendor['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($vendor['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Department</label>
                                            <select class="form-select" name="department_id">
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                <option value="<?= $department['id'] ?>" <?= ($_POST['department_id'] ?? '') == $department['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($department['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Amount Information -->
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">KES</span>
                                                <input type="number" class="form-control" name="amount" id="amount" step="0.01" min="0" value="<?= $_POST['amount'] ?? '' ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Tax Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">KES</span>
                                                <input type="number" class="form-control" name="tax_amount" id="tax_amount" step="0.01" min="0" value="<?= $_POST['tax_amount'] ?? '0' ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Total Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">KES</span>
                                                <input type="text" class="form-control" id="total_amount" readonly value="0.00">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Payment Information -->
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Payment Method</label>
                                            <select class="form-select" name="payment_method_id">
                                                <option value="">Select Payment Method</option>
                                                <?php foreach ($payment_methods as $method): ?>
                                                <option value="<?= $method['id'] ?>" <?= ($_POST['payment_method_id'] ?? '') == $method['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($method['name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Payment Status</label>
                                            <select class="form-select" name="payment_status">
                                                <option value="pending" <?= ($_POST['payment_status'] ?? 'pending') == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="paid" <?= ($_POST['payment_status'] ?? '') == 'paid' ? 'selected' : '' ?>>Paid</option>
                                                <option value="partial" <?= ($_POST['payment_status'] ?? '') == 'partial' ? 'selected' : '' ?>>Partial</option>
                                                <option value="overdue" <?= ($_POST['payment_status'] ?? '') == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Payment Date</label>
                                            <input type="date" class="form-control" name="payment_date" value="<?= $_POST['payment_date'] ?? '' ?>">
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Due Date</label>
                                            <input type="date" class="form-control" name="due_date" value="<?= $_POST['due_date'] ?? '' ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Receipt/Invoice</label>
                                            <input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                            <small class="text-muted">Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max 5MB)</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Options and Notes -->
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6 class="mb-0">Options</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_tax_deductible" id="is_tax_deductible" value="1" <?= ($_POST['is_tax_deductible'] ?? $default_tax_deductible) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_tax_deductible">
                                                        Tax Deductible
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring" value="1" <?= ($_POST['is_recurring'] ?? '') ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="is_recurring">
                                                        Recurring Expense
                                                    </label>
                                                </div>
                                            </div>

                                            <div id="recurring_options" style="display: none;">
                                                <div class="mb-3">
                                                    <label class="form-label">Frequency</label>
                                                    <select class="form-select" name="recurring_frequency">
                                                        <option value="">Select Frequency</option>
                                                        <option value="daily" <?= ($_POST['recurring_frequency'] ?? '') == 'daily' ? 'selected' : '' ?>>Daily</option>
                                                        <option value="weekly" <?= ($_POST['recurring_frequency'] ?? '') == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                                        <option value="monthly" <?= ($_POST['recurring_frequency'] ?? '') == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                                        <option value="quarterly" <?= ($_POST['recurring_frequency'] ?? '') == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                                        <option value="yearly" <?= ($_POST['recurring_frequency'] ?? '') == 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">End Date</label>
                                                    <input type="date" class="form-control" name="recurring_end_date" value="<?= $_POST['recurring_end_date'] ?? '' ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card mt-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">Notes</h6>
                                        </div>
                                        <div class="card-body">
                                            <textarea class="form-control" name="notes" rows="4" placeholder="Additional notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i> Create Expense
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate total amount
        function calculateTotal() {
            const amount = parseFloat(document.getElementById('amount').value) || 0;
            const taxAmount = parseFloat(document.getElementById('tax_amount').value) || 0;
            const total = amount + taxAmount;
            document.getElementById('total_amount').value = total.toFixed(2);
        }

        // Show/hide recurring options
        function toggleRecurringOptions() {
            const isRecurring = document.getElementById('is_recurring').checked;
            const recurringOptions = document.getElementById('recurring_options');
            recurringOptions.style.display = isRecurring ? 'block' : 'none';
        }

        // Load subcategories when category changes
        function loadSubcategories(categoryId) {
            const subcategorySelect = document.getElementById('subcategory_id');
            subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
            
            if (categoryId) {
                fetch(`../api/get_subcategories.php?category_id=${categoryId}`)
                    .then(response => response.json())
                    .then(data => {
                        data.forEach(subcategory => {
                            const option = document.createElement('option');
                            option.value = subcategory.id;
                            option.textContent = subcategory.name;
                            subcategorySelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error loading subcategories:', error));
            }
        }

        // Event listeners
        document.getElementById('amount').addEventListener('input', calculateTotal);
        document.getElementById('tax_amount').addEventListener('input', calculateTotal);
        document.getElementById('is_recurring').addEventListener('change', toggleRecurringOptions);
        document.getElementById('category_id').addEventListener('change', function() {
            loadSubcategories(this.value);
        });

        // Initialize
        calculateTotal();
        toggleRecurringOptions();
    </script>
</body>
</html>
