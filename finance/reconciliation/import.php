<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

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

// Check if user has permission to import bank statements
if (!hasPermission('view_finance', $permissions) && !hasPermission('import_bank_statements', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get bank accounts
$bank_accounts = [];
$stmt = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY account_name");
$bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['bank_statement'])) {
    $bank_account_id = $_POST['bank_account_id'] ?? '';
    $file = $_FILES['bank_statement'];
    
    if (empty($bank_account_id)) {
        $error = "Please select a bank account";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed: " . $file['error'];
    } else {
        // Check file type
        $allowed_types = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (!in_array($file['type'], $allowed_types)) {
            $error = "Please upload a CSV or Excel file";
        } else {
            // Process the file
            $upload_dir = '../../storage/bank_statements/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filename = uniqid() . '_' . $file['name'];
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // Parse CSV file (simplified implementation)
                $handle = fopen($filepath, 'r');
                $imported_count = 0;
                $errors = [];
                
                // Skip header row
                $header = fgetcsv($handle);
                
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($data) >= 4) { // Minimum required columns
                        $transaction_date = $data[0];
                        $description = $data[1];
                        $amount = floatval($data[2]);
                        $balance_after = isset($data[3]) ? floatval($data[3]) : null;
                        
                        // Determine transaction type
                        $transaction_type = $amount >= 0 ? 'credit' : 'debit';
                        $amount = abs($amount);
                        
                        try {
                            $stmt = $conn->prepare("
                                INSERT INTO bank_transactions (bank_account_id, transaction_date, description, amount, transaction_type, balance_after)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$bank_account_id, $transaction_date, $description, $amount, $transaction_type, $balance_after]);
                            $imported_count++;
                        } catch (Exception $e) {
                            $errors[] = "Error importing transaction: " . $e->getMessage();
                        }
                    }
                }
                
                fclose($handle);
                
                if ($imported_count > 0) {
                    $success = "Successfully imported {$imported_count} transactions";
                } else {
                    $error = "No valid transactions found in the file";
                }
                
                if (!empty($errors)) {
                    $error = "Import completed with errors: " . implode(', ', $errors);
                }
            } else {
                $error = "Failed to save uploaded file";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Bank Statement - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: var(--primary-color);
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        .upload-area.dragover {
            border-color: var(--primary-color);
            background-color: rgba(102, 126, 234, 0.1);
        }
        
        .file-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Reconciliation</a></li>
                            <li class="breadcrumb-item active">Import Bank Statement</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-upload"></i> Import Bank Statement</h1>
                    <p class="header-subtitle">Upload bank statement for reconciliation</p>
                </div>
                <div class="header-actions">
                    <a href="../reconciliation.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reconciliation
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-file-earmark-arrow-up"></i> Upload Bank Statement
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($bank_accounts)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>No Bank Accounts Found</strong><br>
                                    You need to add at least one bank account before importing statements.
                                    <div class="mt-3">
                                        <a href="accounts.php?action=add" class="btn btn-warning">
                                            <i class="bi bi-plus"></i> Add Bank Account
                                        </a>
                                    </div>
                                </div>
                                <?php else: ?>
                                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                    <div class="mb-4">
                                        <label for="bank_account_id" class="form-label">Select Bank Account *</label>
                                        <select class="form-select" id="bank_account_id" name="bank_account_id" required>
                                            <option value="">Choose Bank Account</option>
                                            <?php foreach ($bank_accounts as $account): ?>
                                            <option value="<?php echo $account['id']; ?>">
                                                <?php echo htmlspecialchars($account['account_name']); ?> 
                                                (<?php echo htmlspecialchars($account['bank_name'] ?? 'N/A'); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="upload-area" id="uploadArea">
                                        <i class="bi bi-cloud-upload fs-1 text-muted mb-3"></i>
                                        <h5>Drop your bank statement here</h5>
                                        <p class="text-muted">or click to browse files</p>
                                        <input type="file" class="form-control d-none" id="bank_statement" name="bank_statement" 
                                               accept=".csv,.xlsx,.xls" required>
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('bank_statement').click()">
                                            <i class="bi bi-folder2-open"></i> Choose File
                                        </button>
                                    </div>
                                    
                                    <div id="fileInfo" class="file-info d-none">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-file-earmark-text fs-4 text-primary me-3"></i>
                                            <div>
                                                <div class="fw-semibold" id="fileName"></div>
                                                <small class="text-muted" id="fileSize"></small>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="clearFile()">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <h6>File Format Requirements:</h6>
                                        <ul class="text-muted small">
                                            <li>Supported formats: CSV, Excel (.xlsx, .xls)</li>
                                            <li>Required columns: Date, Description, Amount, Balance (optional)</li>
                                            <li>Date format: YYYY-MM-DD or MM/DD/YYYY</li>
                                            <li>Amount: Positive for credits, negative for debits</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mt-4">
                                        <a href="../reconciliation.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                                            <i class="bi bi-upload"></i> Import Statement
                                        </button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('bank_statement');
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const submitBtn = document.getElementById('submitBtn');

        // Drag and drop functionality
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                handleFileSelect();
            }
        });

        // Click to upload
        uploadArea.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', handleFileSelect);

        function handleFileSelect() {
            const file = fileInput.files[0];
            if (file) {
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                fileInfo.classList.remove('d-none');
                submitBtn.disabled = false;
            }
        }

        function clearFile() {
            fileInput.value = '';
            fileInfo.classList.add('d-none');
            submitBtn.disabled = true;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const bankAccount = document.getElementById('bank_account_id').value;
            const file = document.getElementById('bank_statement').files[0];
            
            if (!bankAccount) {
                e.preventDefault();
                alert('Please select a bank account');
                return;
            }
            
            if (!file) {
                e.preventDefault();
                alert('Please select a file to upload');
                return;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Importing...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>
