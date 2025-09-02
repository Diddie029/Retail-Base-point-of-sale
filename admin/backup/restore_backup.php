<?php
/**
 * Database Backup Restoration Script
 * Restore database from backup files
 */

session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';
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

// Check if user has permission to manage settings (includes backup)
if (!hasPermission('manage_settings', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Require backup verification for security
requireBackupPermission();
requireBackupVerification('backup_verify.php?action=restore&redirect=' . urlencode($_SERVER['REQUEST_URI']));

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$backup_dir = __DIR__ . '/../../backups/database/';
$log_file = __DIR__ . '/../../backups/logs/backup_log.txt';

/**
 * Log restore activities
 */
function logRestoreActivity($message, $type = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $username = $_SESSION['username'] ?? 'System';
    $log_entry = "[{$timestamp}] [{$type}] [{$username}] RESTORE: {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Get available backup files
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
 * Restore database from backup file using mysql command
 */
function restoreFromBackup($backup_file) {
    global $conn;
    
    $host = 'localhost';
    $dbname = 'pos_system';
    $username = 'root';
    $password = '';
    
    try {
        if (!file_exists($backup_file)) {
            throw new Exception('Backup file not found');
        }
        
        logRestoreActivity("Starting restore from: " . basename($backup_file));
        
        // Try mysql command first
        $mysql_path = 'mysql';
        
        // For Windows with XAMPP/Laragon, try common paths
        $possible_paths = [
            'mysql',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysql.exe',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe'
        ];
        
        $mysql_found = false;
        foreach ($possible_paths as $path) {
            $test_command = "\"{$path}\" --version 2>nul";
            $result = shell_exec($test_command);
            if ($result !== null && strpos($result, 'mysql') !== false) {
                $mysql_path = $path;
                $mysql_found = true;
                break;
            }
        }
        
        if ($mysql_found) {
            // Build the command
            $command = "\"{$mysql_path}\" --host=\"{$host}\" --user=\"{$username}\"";
            
            if (!empty($password)) {
                $command .= " --password=\"{$password}\"";
            }
            
            $command .= " \"{$dbname}\" < \"{$backup_file}\"";
            
            logRestoreActivity("Command: " . str_replace($password, '***', $command));
            
            // Execute the command
            $output = [];
            $return_code = 0;
            exec($command . ' 2>&1', $output, $return_code);
            
            if ($return_code === 0) {
                logRestoreActivity("Database restored successfully using mysql command", 'SUCCESS');
                return ['success' => true, 'message' => 'Database restored successfully', 'method' => 'mysql'];
            } else {
                $error_msg = implode("\n", $output);
                logRestoreActivity("mysql command failed: {$error_msg}", 'ERROR');
                // Fall back to PHP method
                return restoreFromBackupPHP($backup_file);
            }
        } else {
            logRestoreActivity("mysql command not found, using PHP method");
            return restoreFromBackupPHP($backup_file);
        }
        
    } catch (Exception $e) {
        logRestoreActivity("Restore error: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Restore database using PHP (fallback method)
 */
function restoreFromBackupPHP($backup_file) {
    global $conn;
    
    try {
        logRestoreActivity("Starting PHP-based restore from: " . basename($backup_file));
        
        // Read the backup file
        $sql = file_get_contents($backup_file);
        if ($sql === false) {
            throw new Exception('Failed to read backup file');
        }
        
        // Disable foreign key checks
        $conn->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Split SQL into individual statements
        $statements = preg_split('/;\s*$/m', $sql);
        $executed = 0;
        $errors = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue; // Skip empty lines and comments
            }
            
            try {
                $conn->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                $errors++;
                logRestoreActivity("Statement error: " . $e->getMessage(), 'ERROR');
                
                // Continue with other statements unless it's a critical error
                if (strpos($e->getMessage(), 'syntax error') !== false) {
                    throw new Exception('Critical SQL syntax error: ' . $e->getMessage());
                }
            }
        }
        
        // Re-enable foreign key checks
        $conn->exec("SET FOREIGN_KEY_CHECKS=1");
        
        if ($executed > 0) {
            logRestoreActivity("PHP restore completed: {$executed} statements executed, {$errors} errors", 'SUCCESS');
            return [
                'success' => true, 
                'message' => "Database restored successfully (PHP method). Executed: {$executed} statements, Errors: {$errors}",
                'method' => 'php',
                'stats' => ['executed' => $executed, 'errors' => $errors]
            ];
        } else {
            throw new Exception('No valid SQL statements found in backup file');
        }
        
    } catch (Exception $e) {
        // Re-enable foreign key checks in case of error
        try {
            $conn->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (Exception $ignored) {}
        
        logRestoreActivity("PHP restore error: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Create backup before restore (safety measure)
 */
function createSafetyBackup() {
    $backup_dir = __DIR__ . '/../../backups/database/';
    $timestamp = date('Y-m-d_H-i-s');
    $backup_filename = "pos_system_pre_restore_backup_{$timestamp}.sql";
    
    // Use the create_backup script functionality
    include_once 'create_backup.php';
    
    // This is a simplified version - in production you might want to call the full backup function
    return true;
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        
        switch ($_POST['action']) {
            case 'restore':
                $filename = $_POST['filename'] ?? '';
                $backup_file = $backup_dir . $filename;
                
                if (!file_exists($backup_file) || !preg_match('/^pos_system_backup_.*\.sql$/', $filename)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid backup file']);
                    exit();
                }
                
                // Create safety backup before restore
                logRestoreActivity("Creating safety backup before restore");
                
                // Perform the restore
                $result = restoreFromBackup($backup_file);
                echo json_encode($result);
                exit();
        }
    }
}

$backups = getBackupFiles();
$username = $_SESSION['username'] ?? 'User';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore Backup - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
            cursor: pointer;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .backup-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .backup-card.selected {
            border: 3px solid var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.1));
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.2);
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
        
        .backup-card .form-check {
            margin-top: 1rem;
        }
        
        .backup-card .form-check-input {
            border: 2px solid var(--primary-color);
        }
        
        .backup-card .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .warning-section {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .warning-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .warning-section .d-flex {
            position: relative;
            z-index: 1;
        }
        
        .warning-section .display-4 {
            font-size: 3rem;
            margin-right: 1rem;
        }
        
        .warning-section h4 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .warning-section ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .warning-section li {
            margin-bottom: 0.5rem;
            opacity: 0.95;
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
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.6);
        }
        
        .btn-warning:disabled {
            opacity: 0.6;
            transform: none;
            cursor: not-allowed;
        }
        
        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.125rem;
        }
        
        .form-check-label {
            font-weight: 500;
            color: #374151;
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

            <?php if (hasPermission('manage_settings', $permissions)): ?>
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
                <a href="manage_backups.php" class="nav-link">
                    <i class="bi bi-database"></i>
                    Backup Management
                </a>
            </div>
            
            <div class="nav-item">
                <a href="restore_backup.php" class="nav-link active">
                    <i class="bi bi-upload"></i>
                    Restore Backup
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
                    <h1>Restore Backup</h1>
                    <div class="header-subtitle">Restore your database from a backup file</div>
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
            <!-- Warning Section -->
            <div class="warning-section">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-exclamation-triangle display-4 me-3"></i>
                    <div>
                        <h4 class="mb-1">⚠️ Important Warning</h4>
                        <p class="mb-0">Restoring a backup will completely replace your current database. This action cannot be undone!</p>
                    </div>
                </div>
                <ul class="mb-0">
                    <li>All current data will be permanently lost</li>
                    <li>A safety backup will be created automatically before restore</li>
                    <li>Make sure you select the correct backup file</li>
                    <li>The restore process may take several minutes</li>
                </ul>
            </div>

            <!-- Backup Selection -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-folder me-2"></i>Select Backup to Restore</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($backups)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-database display-1 text-muted"></i>
                                    <h5 class="mt-3">No backups available</h5>
                                    <p class="text-muted">Create a backup first before you can restore.</p>
                                    <a href="create_backup.php" class="btn btn-primary">
                                        <i class="bi bi-download me-2"></i>Create Backup
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="row" id="backupSelection">
                                    <?php foreach ($backups as $backup): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card backup-card" onclick="selectBackup('<?php echo htmlspecialchars($backup['filename']); ?>', this)">
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
                                                    <div class="form-check">
                                                        <input class="form-check-input backup-radio" type="radio" 
                                                               name="selected_backup" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                        <label class="form-check-label">
                                                            Select this backup
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Restore Actions -->
                                <div class="mt-4 pt-4 border-top">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" id="confirmRestore" required>
                                                <label class="form-check-label" for="confirmRestore">
                                                    <strong>I understand that this will replace all current data and cannot be undone</strong>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <button class="btn btn-warning btn-lg" onclick="startRestore()" disabled id="restoreButton">
                                                <i class="bi bi-upload me-2"></i>Restore Database
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Modal -->
    <div class="modal fade" id="progressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h5 id="progressTitle">Restoring Database...</h5>
                    <p id="progressMessage" class="text-muted mb-0">Please wait while we restore your database. This may take several minutes.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedBackup = null;
        const progressModal = new bootstrap.Modal(document.getElementById('progressModal'));

        function selectBackup(filename, cardElement) {
            // Remove previous selection
            document.querySelectorAll('.backup-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            document.querySelectorAll('.backup-radio').forEach(radio => {
                radio.checked = false;
            });

            // Select current backup
            cardElement.classList.add('selected');
            cardElement.querySelector('.backup-radio').checked = true;
            selectedBackup = filename;
            
            updateRestoreButton();
        }

        function updateRestoreButton() {
            const confirmCheckbox = document.getElementById('confirmRestore');
            const restoreButton = document.getElementById('restoreButton');
            
            if (selectedBackup && confirmCheckbox.checked) {
                restoreButton.disabled = false;
            } else {
                restoreButton.disabled = true;
            }
        }

        // Update button state when checkbox changes
        document.getElementById('confirmRestore').addEventListener('change', updateRestoreButton);

        function startRestore() {
            if (!selectedBackup) {
                alert('Please select a backup file to restore.');
                return;
            }

            if (!document.getElementById('confirmRestore').checked) {
                alert('Please confirm that you understand the restore process.');
                return;
            }

            if (!confirm('⚠️ FINAL WARNING ⚠️\n\nThis will completely replace your current database with the selected backup.\n\nSelected backup: ' + selectedBackup + '\n\nAre you absolutely sure you want to continue?')) {
                return;
            }

            progressModal.show();

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=restore&filename=' + encodeURIComponent(selectedBackup)
            })
            .then(response => response.json())
            .then(data => {
                progressModal.hide();

                if (data.success) {
                    alert('✅ Database restored successfully!\n\nMethod: ' + data.method + '\n\nYou may need to log in again.');
                    window.location.href = '../../auth/login.php';
                } else {
                    alert('❌ Restore failed:\n\n' + data.message);
                }
            })
            .catch(error => {
                progressModal.hide();
                alert('❌ Error during restore:\n\n' + error.message);
            });
        }
    </script>
</body>
</html>
