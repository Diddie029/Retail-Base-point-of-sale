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

// Get duplicate suppliers
function getDuplicateSuppliers($conn) {
    $query = "
        SELECT name, COUNT(*) as count, GROUP_CONCAT(id ORDER BY id) as ids,
               GROUP_CONCAT(created_at ORDER BY id) as created_dates
        FROM suppliers 
        GROUP BY name 
        HAVING COUNT(*) > 1
        ORDER BY name
    ";
    
    $stmt = $conn->query($query);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle cleanup action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_action'])) {
    $action = $_POST['cleanup_action'];
    
    if ($action === 'remove_duplicates') {
        $conn->beginTransaction();
        try {
            // Get all duplicate groups
            $duplicates = getDuplicateSuppliers($conn);
            $removed_count = 0;
            
            foreach ($duplicates as $group) {
                $ids = explode(',', $group['ids']);
                $keep_id = $ids[0]; // Keep the first (oldest) record
                $remove_ids = array_slice($ids, 1); // Remove the rest
                
                if (!empty($remove_ids)) {
                    // Check if any of the duplicate suppliers are referenced in products
                    $placeholders = str_repeat('?,', count($remove_ids) - 1) . '?';
                    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE supplier_id IN ($placeholders)");
                    $check_stmt->execute($remove_ids);
                    $product_references = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($product_references > 0) {
                        // Update products to reference the kept supplier
                        $update_products_stmt = $conn->prepare("UPDATE products SET supplier_id = ? WHERE supplier_id IN ($placeholders)");
                        $update_params = array_merge([$keep_id], $remove_ids);
                        $update_products_stmt->execute($update_params);
                    }
                    
                    // Remove duplicate suppliers
                    $delete_stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($placeholders)");
                    $delete_stmt->execute($remove_ids);
                    
                    $removed_count += count($remove_ids);
                    
                    // Log the cleanup
                    logActivity($conn, $user_id, 'supplier_cleanup', "Removed " . count($remove_ids) . " duplicate suppliers for '{$group['name']}', kept ID: $keep_id");
                }
            }
            
            $conn->commit();
            $_SESSION['success'] = "Successfully removed $removed_count duplicate supplier records.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = 'Error during cleanup: ' . $e->getMessage();
        }
        
        header("Location: cleanup_duplicates.php");
        exit();
    }
}

$duplicates = getDuplicateSuppliers($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Duplicate Suppliers - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .duplicate-group {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .duplicate-header {
            background: #e9ecef;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
        }
        .duplicate-item {
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        .duplicate-item:last-child {
            border-bottom: none;
        }
        .keep-record {
            background: #d1e7dd;
        }
        .remove-record {
            background: #f8d7da;
        }
    </style>
</head>
<body>
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-arrow-repeat me-2"></i>Cleanup Duplicate Suppliers</h1>
                    <div class="header-subtitle">Identify and remove duplicate supplier records</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                </div>
            </div>
        </header>

        <div class="content">
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (empty($duplicates)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Good news!</strong> No duplicate suppliers found in your database.
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Duplicates Found:</strong> <?php echo count($duplicates); ?> supplier name(s) have duplicate records.
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Duplicate Supplier Groups</h5>
                    <small class="text-muted">The system will keep the oldest record (first created) and remove duplicates</small>
                </div>
                <div class="card-body">
                    <?php foreach ($duplicates as $group): ?>
                    <?php 
                    $ids = explode(',', $group['ids']);
                    $dates = explode(',', $group['created_dates']);
                    ?>
                    <div class="duplicate-group">
                        <div class="duplicate-header">
                            <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                            <span class="badge bg-warning ms-2"><?php echo $group['count']; ?> records</span>
                        </div>
                        <?php for ($i = 0; $i < count($ids); $i++): ?>
                        <div class="duplicate-item <?php echo $i === 0 ? 'keep-record' : 'remove-record'; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>ID: <?php echo $ids[$i]; ?></strong>
                                    <span class="text-muted ms-2">Created: <?php echo $dates[$i]; ?></span>
                                </div>
                                <div>
                                    <?php if ($i === 0): ?>
                                    <span class="badge bg-success">KEEP</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">REMOVE</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="alert alert-info mt-4">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>What will happen:</strong>
                        <ul class="mb-0 mt-2">
                            <li>The oldest supplier record (lowest ID) will be kept for each duplicate group</li>
                            <li>All products referencing duplicate suppliers will be updated to reference the kept supplier</li>
                            <li>Duplicate supplier records will be permanently deleted</li>
                            <li>This action cannot be undone</li>
                        </ul>
                    </div>

                    <form method="POST" class="mt-4">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmCleanup" required>
                            <label class="form-check-label" for="confirmCleanup">
                                <strong>I understand that this will permanently remove duplicate records</strong>
                            </label>
                        </div>
                        <button type="submit" name="cleanup_action" value="remove_duplicates" 
                                class="btn btn-danger" id="cleanupBtn" disabled>
                            <i class="bi bi-trash me-2"></i>
                            Remove Duplicate Suppliers
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const confirmCheckbox = document.getElementById('confirmCleanup');
            const cleanupBtn = document.getElementById('cleanupBtn');
            
            if (confirmCheckbox && cleanupBtn) {
                confirmCheckbox.addEventListener('change', function() {
                    cleanupBtn.disabled = !this.checked;
                });
            }
        });
    </script>
</body>
</html>
