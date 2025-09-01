<?php
session_start();

// Initialize variables
$db_connected = false;
$db_error = '';
$current_count = 'N/A';
$duplicate_groups = 0;
$extra_records = 0;

try {
    require_once __DIR__ . '/../include/db.php';
    $db_connected = true;
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$step = $_GET['step'] ?? 'start';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Deduplication Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .step-card {
            border: 1px solid #dee2e6;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .step-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .step-header {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            padding: 1rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .step-number {
            background: rgba(255, 255, 255, 0.2);
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .step-content {
            padding: 1.5rem;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .success-box {
            background: #d1e7dd;
            border: 1px solid #badbcc;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .danger-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .btn-lg-custom {
            padding: 0.75rem 2rem;
            font-size: 1.1rem;
            border-radius: 8px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="bi bi-tools text-primary me-2"></i>
                            Supplier Deduplication Manager
                        </h1>
                        <p class="text-muted mt-1">Safely remove duplicate supplier entries</p>
                    </div>
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                </div>

                <?php if ($step === 'start'): ?>
                <!-- Step 1: Backup -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-number">1</div>
                        <div>
                            <h4 class="mb-0">Create Backup</h4>
                            <small>Protect your data before making changes</small>
                        </div>
                    </div>
                    <div class="step-content">
                        <div class="warning-box">
                            <i class="bi bi-exclamation-triangle text-warning me-2"></i>
                            <strong>Important:</strong> Always create a backup before removing duplicates. This allows you to restore your data if something goes wrong.
                        </div>
                        <p>This will create a timestamped backup table of all your suppliers.</p>
                        <a href="backup_suppliers.php" target="_blank" class="btn btn-warning btn-lg-custom">
                            <i class="bi bi-shield-check me-2"></i>
                            Create Backup
                        </a>
                    </div>
                </div>

                <!-- Step 2: Analyze -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-number">2</div>
                        <div>
                            <h4 class="mb-0">Analyze Duplicates</h4>
                            <small>Identify duplicate suppliers in your database</small>
                        </div>
                    </div>
                    <div class="step-content">
                        <p>Review which suppliers are duplicated and understand what will be changed.</p>
                        <a href="analyze_duplicates.php" target="_blank" class="btn btn-info btn-lg-custom">
                            <i class="bi bi-search me-2"></i>
                            Analyze Duplicates
                        </a>
                    </div>
                </div>

                <!-- Step 3: Remove Duplicates -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-number">3</div>
                        <div>
                            <h4 class="mb-0">Remove Duplicates</h4>
                            <small>Safely eliminate duplicate entries</small>
                        </div>
                    </div>
                    <div class="step-content">
                        <div class="danger-box">
                            <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                            <strong>Warning:</strong> This will permanently remove duplicate suppliers. Make sure you have a backup!
                        </div>
                        <p>This will:</p>
                        <ul>
                            <li>Keep the oldest record for each duplicate set</li>
                            <li>Update all products to reference the kept supplier</li>
                            <li>Remove the duplicate supplier records</li>
                            <li>Maintain all data relationships</li>
                        </ul>
                        <a href="remove_duplicates.php" target="_blank" class="btn btn-danger btn-lg-custom">
                            <i class="bi bi-trash me-2"></i>
                            Remove Duplicates
                        </a>
                    </div>
                </div>

                <!-- Step 4: Verify -->
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-number">4</div>
                        <div>
                            <h4 class="mb-0">Verify Results</h4>
                            <small>Check that everything worked correctly</small>
                        </div>
                    </div>
                    <div class="step-content">
                        <p>After deduplication, verify that:</p>
                        <ul>
                            <li>No duplicate suppliers remain</li>
                            <li>All products still have valid supplier references</li>
                            <li>The supplier count matches your expectations</li>
                        </ul>
                        <a href="suppliers.php" class="btn btn-success btn-lg-custom">
                            <i class="bi bi-check-circle me-2"></i>
                            View Suppliers Page
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$db_connected): ?>
                <!-- Database Error Alert -->
                <div class="alert alert-danger" role="alert">
                    <h4 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Database Connection Error</h4>
                    <p>Unable to connect to the database. The deduplication tools require a working database connection.</p>
                    <hr>
                    <p class="mb-0"><strong>Error:</strong> <?php echo htmlspecialchars($db_error); ?></p>
                    <p class="mb-0 mt-2"><strong>Solution:</strong> Please check your database configuration in <code>include/db.php</code> and ensure the database server is running.</p>
                </div>
                <?php else: ?>
                
                <!-- Quick Stats -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="card-title">Current Suppliers</h5>
                                <?php
                                if ($db_connected) {
                                    try {
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM suppliers");
                                        $current_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                        echo "<h2 class='text-primary'>$current_count</h2>";
                                    } catch (Exception $e) {
                                        echo "<h2 class='text-muted'>N/A</h2>";
                                        echo "<small class='text-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</small>";
                                    }
                                } else {
                                    echo "<h2 class='text-muted'>N/A</h2>";
                                }
                                ?>
                                <p class="card-text text-muted">Total suppliers in database</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body text-center">
                                <h5 class="card-title">Potential Duplicates</h5>
                                <?php
                                if ($db_connected) {
                                    try {
                                        $stmt = $conn->query("
                                            SELECT COUNT(*) as duplicate_groups
                                            FROM (
                                                SELECT name, COUNT(*) as count
                                                FROM suppliers 
                                                GROUP BY name 
                                                HAVING count > 1
                                            ) as duplicates
                                        ");
                                        $duplicate_groups = $stmt->fetch(PDO::FETCH_ASSOC)['duplicate_groups'];
                                        
                                        $stmt = $conn->query("
                                            SELECT SUM(count - 1) as extra_records
                                            FROM (
                                                SELECT name, COUNT(*) as count
                                                FROM suppliers 
                                                GROUP BY name 
                                                HAVING count > 1
                                            ) as duplicates
                                        ");
                                        $extra_records = $stmt->fetch(PDO::FETCH_ASSOC)['extra_records'] ?? 0;
                                        
                                        echo "<h2 class='text-warning'>$extra_records</h2>";
                                        echo "<p class='card-text text-muted'>Duplicate records to remove</p>";
                                        if ($duplicate_groups > 0) {
                                            echo "<small class='text-muted'>($duplicate_groups duplicate groups)</small>";
                                        }
                                    } catch (Exception $e) {
                                        echo "<h2 class='text-muted'>N/A</h2>";
                                        echo "<small class='text-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</small>";
                                    }
                                } else {
                                    echo "<h2 class='text-muted'>N/A</h2>";
                                    echo "<p class='card-text text-muted'>Database not connected</p>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Help Section -->
                <div class="mt-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-question-circle me-2"></i>
                                How This Works
                            </h5>
                        </div>
                        <div class="card-body">
                            <h6>Duplicate Detection Logic:</h6>
                            <ul>
                                <li><strong>Name duplicates:</strong> Suppliers with exactly the same name</li>
                                <li><strong>Email duplicates:</strong> Suppliers with the same email address (but different names are kept)</li>
                                <li><strong>Phone duplicates:</strong> Suppliers with the same phone number</li>
                            </ul>
                            
                            <h6>Safe Removal Process:</h6>
                            <ul>
                                <li>The <strong>oldest</strong> supplier record is kept (based on created_at date)</li>
                                <li>All products linked to duplicate suppliers are reassigned to the kept supplier</li>
                                <li>Duplicate supplier records are then safely deleted</li>
                                <li>All operations are wrapped in a database transaction for safety</li>
                            </ul>
                            
                            <h6>Recovery:</h6>
                            <p>If you need to restore your data, contact your database administrator with the backup table name provided after step 1.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
