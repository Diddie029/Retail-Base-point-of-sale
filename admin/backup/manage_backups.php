<?php
/**
 * Backup Management Interface
 * List, download, delete, and restore database backups
 */

session_start();
require_once __DIR__ . '/../../include/db.php';
require_once 'security.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Get user permissions
$role_id = $_SESSION['role_id'] ?? 0;
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

// Helper function to check permissions
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
}

// Check if user has permission to manage settings (includes backup)
if (!hasPermission('manage_settings', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Require backup verification for security
requireBackupPermission();
requireBackupVerification('backup_verify.php?action=manage&redirect=' . urlencode($_SERVER['REQUEST_URI']));

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$backup_dir = __DIR__ . '/../../backups/database/';
$log_file = __DIR__ . '/../../backups/logs/backup_log.txt';

/**
 * Format file size
 */
function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Get all backup files
 */
function getBackupFiles() {
    global $backup_dir;
    
    if (!is_dir($backup_dir)) {
        return [];
    }
    
    $files = glob($backup_dir . 'pos_system_backup_*.sql');
    $backups = [];
    
    foreach ($files as $file) {
        $filename = basename($file);
        $backups[] = [
            'filename' => $filename,
            'path' => $file,
            'size' => filesize($file),
            'size_formatted' => formatBytes(filesize($file)),
            'created' => filemtime($file),
            'created_formatted' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
    
    // Sort by creation time (newest first)
    usort($backups, function($a, $b) {
        return $b['created'] - $a['created'];
    });
    
    return $backups;
}

/**
 * Get backup logs
 */
function getBackupLogs($limit = 50) {
    global $log_file;
    
    if (!file_exists($log_file)) {
        return [];
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines); // Show newest first
    
    return array_slice($lines, 0, $limit);
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'delete':
            $filename = $_POST['filename'] ?? '';
            $file_path = $backup_dir . $filename;
            
            if (file_exists($file_path) && preg_match('/^pos_system_backup_.*\.sql$/', $filename)) {
                if (unlink($file_path)) {
                    echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete backup']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Backup file not found']);
            }
            exit();
            
        case 'download':
            $filename = $_POST['filename'] ?? '';
            $file_path = $backup_dir . $filename;
            
            if (file_exists($file_path) && preg_match('/^pos_system_backup_.*\.sql$/', $filename)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit();
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Backup file not found']);
            }
            exit();
    }
}

$backups = getBackupFiles();
$logs = getBackupLogs();
$username = $_SESSION['username'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .backup-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .backup-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .backup-card .card-body {
            padding: 1.5rem;
        }
        
        .backup-card .card-title {
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .backup-card .card-text {
            color: #6b7280;
        }
        
        .backup-card .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .backup-card .btn:hover {
            transform: translateY(-1px);
        }
        
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 8px;
            border-left: 3px solid transparent;
        }
        
        .log-success { 
            background: linear-gradient(135deg, #d1fae5, #a7f3d0); 
            color: #065f46; 
            border-left-color: #10b981;
        }
        .log-error { 
            background: linear-gradient(135deg, #fee2e2, #fecaca); 
            color: #dc2626; 
            border-left-color: #ef4444;
        }
        .log-info { 
            background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
            color: #1e40af; 
            border-left-color: #3b82f6;
        }
        
        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            color: white;
            border: none;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .stats-card .card-body {
            position: relative;
            z-index: 1;
        }
        
        .stats-card .display-4 {
            font-size: 3rem;
            font-weight: 700;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-bottom: 1px solid #e2e8f0;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.25rem 1.5rem;
        }
        
        .card-header h5 {
            color: #1f2937;
            font-weight: 600;
            margin: 0;
        }
        
        .btn {
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
            box-shadow: 0 4px 15px rgba(6, 182, 212, 0.4);
        }
        
        .btn-outline-primary {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-outline-danger {
            border: 2px solid #ef4444;
            color: #ef4444;
            background: transparent;
        }
        
        .btn-outline-danger:hover {
            background: #ef4444;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
            <small>Point of Sale System</small>
        </div>
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="../../dashboard/dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </div>
            
            <?php if (hasPermission('process_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../../pos/index.php" class="nav-link">
                    <i class="bi bi-cart-plus"></i>
                    Point of Sale
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_products', $permissions)): ?>
            <div class="nav-item">
                <a href="../../products/products.php" class="nav-link">
                    <i class="bi bi-box"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="../../categories/categories.php" class="nav-link">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <div class="nav-item">
                <a href="../../inventory/index.php" class="nav-link">
                    <i class="bi bi-boxes"></i>
                    Inventory
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../../sales/index.php" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    Sales History
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../../customers/index.php" class="nav-link">
                    <i class="bi bi-people"></i>
                    Customers
                </a>
            </div>

            <div class="nav-item">
                <a href="../../reports/index.php" class="nav-link">
                    <i class="bi bi-graph-up"></i>
                    Reports
                </a>
            </div>

            <?php if (hasPermission('manage_users', $permissions)): ?>
            <div class="nav-item">
                <a href="../../admin/users/index.php" class="nav-link">
                    <i class="bi bi-person-gear"></i>
                    User Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_settings', $permissions)): ?>
            <div class="nav-item">
                <a href="../../admin/settings/adminsetting.php" class="nav-link">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </div>
            
            <div class="nav-item">
                <a href="manage_backups.php" class="nav-link active">
                    <i class="bi bi-database"></i>
                    Backup Management
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../../auth/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Backup Management</h1>
                    <div class="header-subtitle">Manage database backups and restoration</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <i class="bi bi-database display-4 mb-2"></i>
                            <h3><?php echo count($backups); ?></h3>
                            <p class="mb-0">Total Backups</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-clock display-4 mb-2 text-primary"></i>
                            <h6><?php echo isset($settings['last_backup_time']) ? date('M d, H:i', strtotime($settings['last_backup_time'])) : 'Never'; ?></h6>
                            <p class="mb-0">Last Backup</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-arrow-repeat display-4 mb-2 text-success"></i>
                            <h6><?php echo ucfirst($settings['backup_frequency'] ?? 'Daily'); ?></h6>
                            <p class="mb-0">Frequency</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-hdd display-4 mb-2 text-info"></i>
                            <h6><?php 
                                $total_size = 0;
                                foreach ($backups as $backup) {
                                    $total_size += $backup['size'];
                                }
                                echo formatBytes($total_size);
                            ?></h6>
                            <p class="mb-0">Total Size</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-tools me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex gap-3">
                                <button class="btn btn-primary" onclick="createBackup()">
                                    <i class="bi bi-download me-2"></i>Create New Backup
                                </button>
                                <a href="restore_backup.php" class="btn btn-warning">
                                    <i class="bi bi-upload me-2"></i>Restore Backup
                                </a>
                                <button class="btn btn-info" onclick="refreshPage()">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Backup Files -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-folder me-2"></i>Backup Files (<?php echo count($backups); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($backups)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-database display-1 text-muted"></i>
                                    <h5 class="mt-3">No backups found</h5>
                                    <p class="text-muted">Create your first backup to get started.</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($backups as $backup): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card backup-card">
                                                <div class="card-body">
                                                    <h6 class="card-title">
                                                        <i class="bi bi-file-earmark-zip me-2"></i>
                                                        <?php echo htmlspecialchars($backup['filename']); ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar me-1"></i><?php echo $backup['created_formatted']; ?><br>
                                                            <i class="bi bi-hdd me-1"></i><?php echo $backup['size_formatted']; ?>
                                                        </small>
                                                    </p>
                                                    <div class="d-flex gap-2">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="downloadBackup('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                            <i class="bi bi-download"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="deleteBackup('<?php echo htmlspecialchars($backup['filename']); ?>')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Backup Logs -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-journal-text me-2"></i>Recent Activity</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No activity logs found.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                        $log_class = 'log-info';
                                        if (strpos($log, '[ERROR]') !== false) $log_class = 'log-error';
                                        elseif (strpos($log, '[SUCCESS]') !== false) $log_class = 'log-success';
                                        ?>
                                        <div class="log-entry <?php echo $log_class; ?>">
                                            <?php echo htmlspecialchars($log); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 mb-0">Processing...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));

        function createBackup() {
            loadingModal.show();
            
            fetch('create_backup.php?action=backup', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                loadingModal.hide();
                
                if (data.success) {
                    alert('Backup created successfully!\nFile: ' + data.filename + '\nSize: ' + data.size);
                    refreshPage();
                } else {
                    alert('Backup failed: ' + data.message);
                }
            })
            .catch(error => {
                loadingModal.hide();
                alert('Error: ' + error.message);
            });
        }

        function downloadBackup(filename) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'download';
            
            const filenameInput = document.createElement('input');
            filenameInput.type = 'hidden';
            filenameInput.name = 'filename';
            filenameInput.value = filename;
            
            form.appendChild(actionInput);
            form.appendChild(filenameInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function deleteBackup(filename) {
            if (!confirm('Are you sure you want to delete this backup?\n\nFile: ' + filename + '\n\nThis action cannot be undone.')) {
                return;
            }
            
            loadingModal.show();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete&filename=' + encodeURIComponent(filename)
            })
            .then(response => response.json())
            .then(data => {
                loadingModal.hide();
                
                if (data.success) {
                    alert('Backup deleted successfully!');
                    refreshPage();
                } else {
                    alert('Delete failed: ' + data.message);
                }
            })
            .catch(error => {
                loadingModal.hide();
                alert('Error: ' + error.message);
            });
        }

        function refreshPage() {
            window.location.reload();
        }
    </script>
</body>
</html>
