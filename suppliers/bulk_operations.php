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
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    try {
        $stmt = $conn->prepare("
            SELECT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
        ");
        $stmt->bindParam(':role_id', $role_id);
        $stmt->execute();
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $permissions = ['manage_products', 'process_sales', 'manage_sales'];
    }
}

// Check if user has permission to manage product suppliers
if (!hasPermission('manage_product_suppliers', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    $settings = [
        'company_name' => 'POS System',
        'theme_color' => '#6366f1',
        'sidebar_color' => '#1e293b'
    ];
}

// Handle bulk export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_suppliers'])) {
    $export_format = $_POST['export_format'] ?? 'csv';
    $export_fields = $_POST['export_fields'] ?? [];
    $supplier_ids = $_POST['supplier_ids'] ?? [];
    
    // Build query for export
    $query = "
        SELECT s.*,
               COUNT(p.id) as product_count,
               COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_product_count
        FROM suppliers s
        LEFT JOIN products p ON s.id = p.supplier_id
    ";
    
    if (!empty($supplier_ids)) {
        $placeholders = str_repeat('?,', count($supplier_ids) - 1) . '?';
        $query .= " WHERE s.id IN ($placeholders)";
    }
    
    $query .= " GROUP BY s.id";
    
    $stmt = $conn->prepare($query);
    if (!empty($supplier_ids)) {
        $stmt->execute($supplier_ids);
    } else {
        $stmt->execute();
    }
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Export as CSV
    if ($export_format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="suppliers_export_' . date('Y-m-d_H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Define available fields
        $all_fields = [
            'id' => 'ID',
            'name' => 'Name',
            'contact_person' => 'Contact Person',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Country',
            'payment_terms' => 'Payment Terms',
            'credit_limit' => 'Credit Limit',
            'tax_number' => 'Tax Number',
            'notes' => 'Notes',
            'is_active' => 'Status',
            'created_at' => 'Created At',
            'product_count' => 'Total Products',
            'active_product_count' => 'Active Products'
        ];
        
        // Use selected fields or all fields
        $fields_to_export = empty($export_fields) ? array_keys($all_fields) : $export_fields;
        
        // Write header
        $header = [];
        foreach ($fields_to_export as $field) {
            $header[] = $all_fields[$field] ?? $field;
        }
        fputcsv($output, $header);
        
        // Write data
        foreach ($suppliers as $supplier) {
            $row = [];
            foreach ($fields_to_export as $field) {
                if ($field === 'is_active') {
                    $row[] = $supplier[$field] ? 'Active' : 'Inactive';
                } elseif ($field === 'created_at') {
                    $row[] = date('Y-m-d H:i:s', strtotime($supplier[$field]));
                } else {
                    $row[] = $supplier[$field] ?? '';
                }
            }
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    }
}

// Handle bulk import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_suppliers'])) {
    $import_mode = $_POST['import_mode'] ?? 'create_only';
    
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $file_path = $_FILES['import_file']['tmp_name'];
        $file_extension = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        
        if ($file_extension === 'csv') {
            $errors = [];
            $success_count = 0;
            $update_count = 0;
            $skip_count = 0;
            
            if (($handle = fopen($file_path, 'r')) !== FALSE) {
                // Get header row
                $header = fgetcsv($handle);
                $line_number = 1;
                
                // Define field mapping
                $field_mapping = [
                    'name' => ['name', 'supplier name', 'supplier_name'],
                    'contact_person' => ['contact person', 'contact_person', 'contact'],
                    'email' => ['email', 'email address', 'email_address'],
                    'phone' => ['phone', 'telephone', 'phone_number'],
                    'address' => ['address', 'street address'],
                    'city' => ['city', 'location'],
                    'country' => ['country'],
                    'payment_terms' => ['payment terms', 'payment_terms', 'terms'],
                    'credit_limit' => ['credit limit', 'credit_limit', 'limit'],
                    'tax_number' => ['tax number', 'tax_number', 'tax_id', 'vat_number'],
                    'notes' => ['notes', 'description', 'comments'],
                    'is_active' => ['status', 'is_active', 'active']
                ];
                
                // Map header to database fields
                $column_mapping = [];
                foreach ($header as $index => $column_name) {
                    $column_name = strtolower(trim($column_name));
                    foreach ($field_mapping as $db_field => $aliases) {
                        if (in_array($column_name, $aliases)) {
                            $column_mapping[$index] = $db_field;
                            break;
                        }
                    }
                }
                
                // Process each row
                while (($row = fgetcsv($handle)) !== FALSE) {
                    $line_number++;
                    
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        $skip_count++;
                        continue;
                    }
                    
                    $supplier_data = [];
                    $validation_errors = [];
                    
                    // Map row data to database fields
                    foreach ($row as $index => $value) {
                        if (isset($column_mapping[$index])) {
                            $field = $column_mapping[$index];
                            $value = trim($value);
                            
                            // Data validation and conversion
                            switch ($field) {
                                case 'name':
                                    if (empty($value)) {
                                        $validation_errors[] = "Name is required";
                                    } else {
                                        $supplier_data[$field] = $value;
                                    }
                                    break;
                                case 'email':
                                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                        $validation_errors[] = "Invalid email format";
                                    } else {
                                        $supplier_data[$field] = $value;
                                    }
                                    break;
                                case 'credit_limit':
                                    $supplier_data[$field] = is_numeric($value) ? floatval($value) : 0;
                                    break;
                                case 'is_active':
                                    $value = strtolower($value);
                                    $supplier_data[$field] = in_array($value, ['1', 'true', 'yes', 'active']) ? 1 : 0;
                                    break;
                                default:
                                    $supplier_data[$field] = $value;
                            }
                        }
                    }
                    
                    // Check if required fields are present
                    if (empty($supplier_data['name'])) {
                        $validation_errors[] = "Name is required";
                    }
                    
                    if (!empty($validation_errors)) {
                        $errors[] = "Line {$line_number}: " . implode(', ', $validation_errors);
                        continue;
                    }
                    
                    try {
                        // Check if supplier exists (by name or email)
                        $existing_supplier = null;
                        if (!empty($supplier_data['email'])) {
                            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE email = :email OR name = :name");
                            $stmt->bindParam(':email', $supplier_data['email']);
                            $stmt->bindParam(':name', $supplier_data['name']);
                        } else {
                            $stmt = $conn->prepare("SELECT id FROM suppliers WHERE name = :name");
                            $stmt->bindParam(':name', $supplier_data['name']);
                        }
                        $stmt->execute();
                        $existing_supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing_supplier) {
                            if ($import_mode === 'update_existing' || $import_mode === 'create_and_update') {
                                // Update existing supplier
                                $update_fields = [];
                                $update_params = [];
                                
                                foreach ($supplier_data as $field => $value) {
                                    if ($field !== 'name') { // Don't update name to avoid conflicts
                                        $update_fields[] = "{$field} = :{$field}";
                                        $update_params[$field] = $value;
                                    }
                                }
                                
                                if (!empty($update_fields)) {
                                    $update_query = "UPDATE suppliers SET " . implode(', ', $update_fields) . " WHERE id = :id";
                                    $update_params['id'] = $existing_supplier['id'];
                                    
                                    $stmt = $conn->prepare($update_query);
                                    $stmt->execute($update_params);
                                    $update_count++;
                                }
                            } else {
                                $skip_count++;
                            }
                        } else {
                            if ($import_mode === 'create_only' || $import_mode === 'create_and_update') {
                                // Create new supplier
                                $supplier_data['created_at'] = date('Y-m-d H:i:s');
                                $supplier_data['updated_at'] = date('Y-m-d H:i:s');
                                
                                $fields = implode(', ', array_keys($supplier_data));
                                $placeholders = ':' . implode(', :', array_keys($supplier_data));
                                
                                $insert_query = "INSERT INTO suppliers ({$fields}) VALUES ({$placeholders})";
                                $stmt = $conn->prepare($insert_query);
                                $stmt->execute($supplier_data);
                                $success_count++;
                            } else {
                                $skip_count++;
                            }
                        }
                    } catch (Exception $e) {
                        $errors[] = "Line {$line_number}: Database error - " . $e->getMessage();
                    }
                }
                
                fclose($handle);
                
                // Set success/error messages
                $messages = [];
                if ($success_count > 0) {
                    $messages[] = "Successfully imported {$success_count} supplier(s)";
                }
                if ($update_count > 0) {
                    $messages[] = "Updated {$update_count} existing supplier(s)";
                }
                if ($skip_count > 0) {
                    $messages[] = "Skipped {$skip_count} row(s)";
                }
                
                if (!empty($messages)) {
                    $_SESSION['success'] = implode('. ', $messages);
                }
                
                if (!empty($errors)) {
                    $_SESSION['error'] = "Import completed with errors: " . implode('; ', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $_SESSION['error'] .= " and " . (count($errors) - 5) . " more errors.";
                    }
                }
            } else {
                $_SESSION['error'] = 'Could not read the uploaded file.';
            }
        } else {
            $_SESSION['error'] = 'Please upload a CSV file.';
        }
    } else {
        $_SESSION['error'] = 'Please select a file to upload.';
    }
    
    header("Location: bulk_operations.php");
    exit();
}

// Handle template download
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="supplier_import_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Template header
    $header = [
        'Name',
        'Contact Person',
        'Email',
        'Phone',
        'Address',
        'City',
        'Country',
        'Payment Terms',
        'Credit Limit',
        'Tax Number',
        'Notes',
        'Status'
    ];
    
    fputcsv($output, $header);
    
    // Sample data
    $sample_rows = [
        [
            'ABC Suppliers Ltd',
            'John Smith',
            'john@abcsuppliers.com',
            '+1234567890',
            '123 Business Street',
            'Business City',
            'USA',
            'Net 30',
            '10000',
            'TAX123456',
            'Reliable supplier for electronics',
            'Active'
        ],
        [
            'XYZ Trading Co',
            'Jane Doe',
            'jane@xyztrading.com',
            '+0987654321',
            '456 Commerce Ave',
            'Trade City',
            'Canada',
            'Net 15',
            '5000',
            'TAX789012',
            'Good quality office supplies',
            'Active'
        ]
    ];
    
    foreach ($sample_rows as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Operations - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Bulk Operations</h1>
                    <div class="header-subtitle">Import and export supplier data</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <!-- Import Section -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-upload me-2"></i>Import Suppliers</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Upload a CSV file to import multiple suppliers at once.</p>
                            
                            <form method="POST" enctype="multipart/form-data" id="importForm">
                                <div class="mb-3">
                                    <label for="import_file" class="form-label">Select CSV File</label>
                                    <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                                    <div class="form-text">
                                        <a href="?download_template=1" class="text-decoration-none">
                                            <i class="bi bi-download me-1"></i>Download Template
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="import_mode" class="form-label">Import Mode</label>
                                    <select class="form-control" id="import_mode" name="import_mode" required>
                                        <option value="create_only">Create new suppliers only (skip existing)</option>
                                        <option value="update_existing">Update existing suppliers only</option>
                                        <option value="create_and_update">Create new and update existing</option>
                                    </select>
                                </div>
                                
                                <div class="alert alert-info">
                                    <h6>Import Guidelines:</h6>
                                    <ul class="mb-0 small">
                                        <li>Name field is required for all suppliers</li>
                                        <li>Email addresses must be valid format</li>
                                        <li>Status can be: Active, Inactive, 1, 0, Yes, No, True, False</li>
                                        <li>Credit limit should be numeric values only</li>
                                        <li>Existing suppliers are matched by name or email</li>
                                    </ul>
                                </div>
                                
                                <button type="submit" name="import_suppliers" class="btn btn-primary w-100">
                                    <i class="bi bi-upload me-2"></i>Import Suppliers
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Export Section -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-download me-2"></i>Export Suppliers</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export your supplier data to CSV format for backup or analysis.</p>
                            
                            <form method="POST" id="exportForm">
                                <div class="mb-3">
                                    <label class="form-label">Export Format</label>
                                    <select class="form-control" name="export_format">
                                        <option value="csv">CSV (.csv)</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Fields to Export</label>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="name" id="field_name" checked>
                                                <label class="form-check-label" for="field_name">Name</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="contact_person" id="field_contact" checked>
                                                <label class="form-check-label" for="field_contact">Contact Person</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="email" id="field_email" checked>
                                                <label class="form-check-label" for="field_email">Email</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="phone" id="field_phone" checked>
                                                <label class="form-check-label" for="field_phone">Phone</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="address" id="field_address">
                                                <label class="form-check-label" for="field_address">Address</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="city" id="field_city">
                                                <label class="form-check-label" for="field_city">City</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="country" id="field_country">
                                                <label class="form-check-label" for="field_country">Country</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="payment_terms" id="field_payment">
                                                <label class="form-check-label" for="field_payment">Payment Terms</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="credit_limit" id="field_credit">
                                                <label class="form-check-label" for="field_credit">Credit Limit</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="product_count" id="field_products">
                                                <label class="form-check-label" for="field_products">Product Count</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="is_active" id="field_status" checked>
                                                <label class="form-check-label" for="field_status">Status</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="export_fields[]" value="created_at" id="field_created">
                                                <label class="form-check-label" for="field_created">Created Date</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllFields(true)">Select All</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllFields(false)">Clear All</button>
                                    </div>
                                </div>
                                
                                <button type="submit" name="export_suppliers" class="btn btn-success w-100">
                                    <i class="bi bi-download me-2"></i>Export All Suppliers
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Usage Instructions -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Usage Instructions</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Import Instructions:</h6>
                                    <ol>
                                        <li>Download the template CSV file to see the required format</li>
                                        <li>Fill in your supplier data following the template structure</li>
                                        <li>Save your file as CSV format</li>
                                        <li>Choose the appropriate import mode:
                                            <ul>
                                                <li><strong>Create only:</strong> Add new suppliers, skip existing ones</li>
                                                <li><strong>Update only:</strong> Update existing suppliers, ignore new ones</li>
                                                <li><strong>Create and update:</strong> Add new and update existing suppliers</li>
                                            </ul>
                                        </li>
                                        <li>Upload and process your file</li>
                                    </ol>
                                </div>
                                <div class="col-md-6">
                                    <h6>Export Instructions:</h6>
                                    <ol>
                                        <li>Select the fields you want to include in the export</li>
                                        <li>Choose your preferred format (CSV recommended)</li>
                                        <li>Click "Export All Suppliers" to download all supplier data</li>
                                        <li>You can also export selected suppliers from the main suppliers page</li>
                                    </ol>
                                    
                                    <h6 class="mt-3">Tips:</h6>
                                    <ul>
                                        <li>Regular exports can serve as data backups</li>
                                        <li>Use exports to analyze supplier performance data</li>
                                        <li>Share supplier lists with other team members</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAllFields(select) {
            const checkboxes = document.querySelectorAll('input[name="export_fields[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = select;
            });
        }
        
        // Form validation
        document.getElementById('importForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('import_file');
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please select a file to upload.');
                return;
            }
            
            const file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.csv')) {
                e.preventDefault();
                alert('Please select a CSV file.');
                return;
            }
        });
        
        document.getElementById('exportForm').addEventListener('submit', function(e) {
            const checkedFields = document.querySelectorAll('input[name="export_fields[]"]:checked');
            if (checkedFields.length === 0) {
                e.preventDefault();
                alert('Please select at least one field to export.');
                return;
            }
        });
    </script>
</body>
</html>
