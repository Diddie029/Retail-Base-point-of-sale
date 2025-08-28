<?php
/**
 * Database Backup Script
 * Creates MySQL database backups using mysqldump
 */

session_start();
require_once __DIR__ . '/../../include/db.php';
require_once 'security.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Require backup verification for security
requireBackupPermission();
requireBackupVerification('backup_verify.php?action=create&redirect=' . urlencode($_SERVER['REQUEST_URI']));

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
if (!in_array('manage_settings', $permissions)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Database configuration
$host = 'localhost';
$dbname = 'pos_system';
$username = 'root';
$password = '';

// Backup configuration
$backup_dir = __DIR__ . '/../../backups/database/';
$log_dir = __DIR__ . '/../../backups/logs/';
$timestamp = date('Y-m-d_H-i-s');
$backup_filename = "pos_system_backup_{$timestamp}.sql";
$backup_path = $backup_dir . $backup_filename;
$log_file = $log_dir . 'backup_log.txt';

// Ensure directories exist
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Log backup activities
 */
function logBackupActivity($message, $type = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $username = $_SESSION['username'] ?? 'System';
    $log_entry = "[{$timestamp}] [{$type}] [{$username}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Load backup configuration
 */
function loadBackupConfig() {
    $config_file = __DIR__ . '/../../config/backup_config.php';
    if (file_exists($config_file)) {
        return include $config_file;
    }
    return [];
}

/**
 * Create database backup using mysqldump
 */
function createDatabaseBackup() {
    global $host, $username, $password, $dbname, $backup_path, $backup_filename;
    
    try {
        // Load configuration
        $config = loadBackupConfig();
        
        // Check if custom path is specified
        if (!empty($config['mysqldump_path']) && file_exists($config['mysqldump_path'])) {
            $mysqldump_path = $config['mysqldump_path'];
            $mysqldump_found = true;
            logBackupActivity("Using custom mysqldump path: {$mysqldump_path}");
        } else {
            // Build mysqldump command
            $mysqldump_path = 'mysqldump'; // Assume mysqldump is in PATH
            
            // Try to find mysqldump in various locations (works locally and online)
        $possible_paths = [
            'mysqldump', // System PATH (most hosting providers)
            '/usr/bin/mysqldump', // Standard Linux path
            '/usr/local/bin/mysqldump', // Alternative Linux path
            '/opt/lampp/bin/mysqldump', // XAMPP on Linux
        ];
        
        // Add Windows paths for local development
        if (PHP_OS_FAMILY === 'Windows') {
            $windows_paths = [
                'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe',
                'C:\\laragon\\bin\\mysql\\mysql-8.0.30\\bin\\mysqldump.exe',
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
                'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
                'C:\\wamp64\\bin\\mysql\\mysql8.0.21\\bin\\mysqldump.exe'
            ];
            $possible_paths = array_merge($possible_paths, $windows_paths);
        }
        
        // Try to auto-detect MySQL installation
        if (PHP_OS_FAMILY === 'Windows') {
            // Check common Windows development environments
            $auto_paths = [];
            
            // Laragon auto-detection
            if (is_dir('C:\\laragon\\bin\\mysql')) {
                $mysql_dirs = glob('C:\\laragon\\bin\\mysql\\mysql-*');
                foreach ($mysql_dirs as $dir) {
                    if (is_dir($dir . '\\bin')) {
                        $auto_paths[] = $dir . '\\bin\\mysqldump.exe';
                    }
                }
            }
            
            // XAMPP auto-detection
            if (is_dir('C:\\xampp\\mysql\\bin')) {
                $auto_paths[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
            }
            
            // WAMP auto-detection
            if (is_dir('C:\\wamp64\\bin\\mysql')) {
                $mysql_dirs = glob('C:\\wamp64\\bin\\mysql\\mysql*');
                foreach ($mysql_dirs as $dir) {
                    if (is_dir($dir . '\\bin')) {
                        $auto_paths[] = $dir . '\\bin\\mysqldump.exe';
                    }
                }
            }
            
            $possible_paths = array_merge($auto_paths, $possible_paths);
        }
        
        // Add custom paths from configuration
        if (!empty($config['custom_paths'])) {
            $possible_paths = array_merge($config['custom_paths'], $possible_paths);
        }
        
        $mysqldump_found = false;
        foreach ($possible_paths as $path) {
            // Cross-platform command testing
            if (PHP_OS_FAMILY === 'Windows') {
                $test_command = "\"{$path}\" --version 2>nul";
            } else {
                $test_command = "\"{$path}\" --version 2>/dev/null";
            }
            
            $result = shell_exec($test_command);
            if ($result !== null && strpos($result, 'mysqldump') !== false) {
                $mysqldump_path = $path;
                $mysqldump_found = true;
                logBackupActivity("Found working mysqldump at: {$path}");
                break;
            }
        }
        
        if (!$mysqldump_found) {
            throw new Exception('mysqldump not found. Please ensure MySQL is installed and mysqldump is accessible.');
        }
        } // Close the else block for custom path check
        
        // Build the command
        $command = "\"{$mysqldump_path}\" --opt --single-transaction --routines --triggers";
        $command .= " --host=\"{$host}\" --user=\"{$username}\"";
        
        if (!empty($password)) {
            $command .= " --password=\"{$password}\"";
        }
        
        $command .= " \"{$dbname}\" > \"{$backup_path}\"";
        
        logBackupActivity("Starting backup: {$backup_filename}");
        logBackupActivity("Command: " . str_replace($password, '***', $command));
        
        // Execute the command (cross-platform)
        $output = [];
        $return_code = 0;
        
        if (PHP_OS_FAMILY === 'Windows') {
            exec($command . ' 2>&1', $output, $return_code);
        } else {
            exec($command . ' 2>&1', $output, $return_code);
        }
        
        if ($return_code === 0 && file_exists($backup_path) && filesize($backup_path) > 0) {
            $file_size = formatBytes(filesize($backup_path));
            logBackupActivity("Backup created successfully: {$backup_filename} ({$file_size})", 'SUCCESS');
            
            // Update last backup time in settings
            updateLastBackupTime();
            
            // Clean old backups if needed
            cleanOldBackups();
            
            return [
                'success' => true,
                'message' => 'Backup created successfully',
                'filename' => $backup_filename,
                'size' => $file_size,
                'path' => $backup_path
            ];
        } else {
            $error_msg = implode("\n", $output);
            logBackupActivity("Backup failed: {$error_msg}", 'ERROR');
            throw new Exception("Backup failed: {$error_msg}");
        }
        
    } catch (Exception $e) {
        logBackupActivity("Backup error: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Create backup using PHP (fallback method)
 */
function createPHPBackup() {
    global $conn, $backup_path, $backup_filename;
    
    try {
        logBackupActivity("Starting PHP-based backup: {$backup_filename}");
        
        $backup_content = "-- POS System Database Backup\n";
        $backup_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "-- Database: pos_system\n\n";
        $backup_content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Get all tables
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        foreach ($tables as $table) {
            // Get table structure
            $backup_content .= "-- Table structure for `{$table}`\n";
            $backup_content .= "DROP TABLE IF EXISTS `{$table}`;\n";
            
            $result = $conn->query("SHOW CREATE TABLE `{$table}`");
            $row = $result->fetch(PDO::FETCH_NUM);
            $backup_content .= $row[1] . ";\n\n";
            
            // Get table data
            $backup_content .= "-- Data for table `{$table}`\n";
            $result = $conn->query("SELECT * FROM `{$table}`");
            
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                foreach ($values as $key => $value) {
                    if ($value === null) {
                        $values[$key] = 'NULL';
                    } else {
                        $values[$key] = "'" . addslashes($value) . "'";
                    }
                }
                
                $backup_content .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $backup_content .= "\n";
        }
        
        $backup_content .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Write to file
        if (file_put_contents($backup_path, $backup_content) !== false) {
            $file_size = formatBytes(filesize($backup_path));
            logBackupActivity("PHP backup created successfully: {$backup_filename} ({$file_size})", 'SUCCESS');
            
            updateLastBackupTime();
            cleanOldBackups();
            
            return [
                'success' => true,
                'message' => 'Backup created successfully (PHP method)',
                'filename' => $backup_filename,
                'size' => $file_size,
                'path' => $backup_path
            ];
        } else {
            throw new Exception('Failed to write backup file');
        }
        
    } catch (Exception $e) {
        logBackupActivity("PHP backup error: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Update last backup time in settings
 */
function updateLastBackupTime() {
    global $conn;
    
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES ('last_backup_time', :time)
        ON DUPLICATE KEY UPDATE setting_value = :time2
    ");
    $stmt->bindParam(':time', $now);
    $stmt->bindParam(':time2', $now);
    $stmt->execute();
}

/**
 * Clean old backup files based on settings
 */
function cleanOldBackups() {
    global $backup_dir, $settings;
    
    $keep_backups = intval($settings['backup_retention_count'] ?? 10);
    
    // Get all backup files
    $files = glob($backup_dir . 'pos_system_backup_*.sql');
    
    if (count($files) > $keep_backups) {
        // Sort by modification time (oldest first)
        array_multisort(array_map('filemtime', $files), SORT_ASC, $files);
        
        // Delete oldest files
        $files_to_delete = array_slice($files, 0, count($files) - $keep_backups);
        foreach ($files_to_delete as $file) {
            if (unlink($file)) {
                logBackupActivity("Deleted old backup: " . basename($file));
            }
        }
    }
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

// Main execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'backup')) {
    header('Content-Type: application/json');
    
    // Try mysqldump first, fallback to PHP method
    $result = createDatabaseBackup();
    
    if (!$result['success'] && strpos($result['message'], 'mysqldump not found') !== false) {
        logBackupActivity("mysqldump not available, trying PHP backup method");
        $result = createPHPBackup();
    }
    
    echo json_encode($result);
} else {
    // Show backup interface
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Create Backup - POS System</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            
            .backup-card {
                background: white;
                border-radius: 20px;
                box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
                border: none;
                overflow: hidden;
            }
            
            .backup-header {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                padding: 2rem;
                text-align: center;
                position: relative;
            }
            
            .backup-header::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
                opacity: 0.3;
            }
            
            .backup-icon {
                font-size: 4rem;
                margin-bottom: 1rem;
                position: relative;
                z-index: 1;
            }
            
            .backup-title {
                font-size: 2rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
                position: relative;
                z-index: 1;
            }
            
            .backup-subtitle {
                font-size: 1.1rem;
                opacity: 0.9;
                position: relative;
                z-index: 1;
            }
            
            .backup-body {
                padding: 3rem;
                text-align: center;
            }
            
            .backup-description {
                color: #6b7280;
                font-size: 1.1rem;
                margin-bottom: 2rem;
                line-height: 1.6;
            }
            
            .btn-create-backup {
                background: linear-gradient(135deg, #667eea, #764ba2);
                border: none;
                border-radius: 15px;
                padding: 1rem 2.5rem;
                font-size: 1.1rem;
                font-weight: 600;
                color: white;
                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
            }
            
            .btn-create-backup::before {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
                transition: left 0.5s;
            }
            
            .btn-create-backup:hover::before {
                left: 100%;
            }
            
            .btn-create-backup:hover {
                transform: translateY(-3px);
                box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
            }
            
            .btn-create-backup:active {
                transform: translateY(-1px);
            }
            
                         .btn-create-backup:disabled {
                 opacity: 0.7;
                 transform: none;
                 cursor: not-allowed;
             }
             
             .btn-back {
                 border: 2px solid #6b7280;
                 color: #6b7280;
                 background: transparent;
                 border-radius: 10px;
                 padding: 0.5rem 1rem;
                 font-weight: 500;
                 transition: all 0.2s ease;
             }
             
             .btn-back:hover {
                 background: #6b7280;
                 color: white;
                 transform: translateY(-1px);
             }
             
             .btn-outline-secondary {
                 border: 2px solid #6b7280;
                 color: #6b7280;
                 background: transparent;
                 border-radius: 10px;
                 padding: 0.75rem 1.5rem;
                 font-weight: 500;
                 transition: all 0.2s ease;
             }
             
             .btn-outline-secondary:hover {
                 background: #6b7280;
                 color: white;
                 transform: translateY(-1px);
             }
             
             .result-container {
                 margin-top: 2rem;
                 border-radius: 15px;
                 overflow: hidden;
             }
            
            .alert {
                border: none;
                border-radius: 15px;
                padding: 1.5rem;
                font-weight: 500;
            }
            
            .alert-success {
                background: linear-gradient(135deg, #d1fae5, #a7f3d0);
                color: #065f46;
                border-left: 4px solid #10b981;
            }
            
            .alert-danger {
                background: linear-gradient(135deg, #fee2e2, #fecaca);
                color: #dc2626;
                border-left: 4px solid #ef4444;
            }
            
            .spinner-border-sm {
                width: 1rem;
                height: 1rem;
            }
            
            .backup-info {
                background: #f8fafc;
                border-radius: 15px;
                padding: 1.5rem;
                margin-top: 2rem;
                border-left: 4px solid #667eea;
            }
            
            .backup-info h6 {
                color: #374151;
                font-weight: 600;
                margin-bottom: 1rem;
            }
            
            .backup-info ul {
                margin-bottom: 0;
                padding-left: 1.5rem;
            }
            
            .backup-info li {
                color: #6b7280;
                margin-bottom: 0.5rem;
            }
            
            @media (max-width: 768px) {
                .backup-body {
                    padding: 2rem 1.5rem;
                }
                
                .backup-title {
                    font-size: 1.5rem;
                }
                
                .btn-create-backup {
                    padding: 0.875rem 2rem;
                    font-size: 1rem;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10">
                    <div class="backup-card">
                        <div class="backup-header">
                            <div class="backup-icon">
                                <i class="bi bi-database"></i>
                            </div>
                            <h1 class="backup-title">Create Database Backup</h1>
                            <p class="backup-subtitle">Secure your POS system data</p>
                        </div>
                        
                                                 <div class="backup-body">
                             <div class="d-flex justify-content-between align-items-center mb-4">
                                 <a href="manage_backups.php" class="btn btn-outline-secondary btn-back">
                                     <i class="bi bi-arrow-left me-2"></i>Back to Backup Management
                                 </a>
                                 <a href="../../dashboard/dashboard.php" class="btn btn-outline-secondary">
                                     <i class="bi bi-house me-2"></i>Dashboard
                                 </a>
                             </div>
                             
                             <p class="backup-description">
                                 Create a complete backup of your POS system database. This backup will include all your products, 
                                 sales records, user accounts, and system settings.
                             </p>
                             
                             <div class="d-flex gap-3 justify-content-center">
                                 <button id="createBackup" class="btn btn-create-backup">
                                     <i class="bi bi-download me-2"></i>Create Backup
                                 </button>
                                 <button type="button" class="btn btn-outline-secondary btn-lg" onclick="goBack()">
                                     <i class="bi bi-x-circle me-2"></i>Cancel
                                 </button>
                             </div>
                            
                            <div id="result" class="result-container"></div>
                            
                            <div class="backup-info">
                                <h6><i class="bi bi-info-circle me-2"></i>What's included in this backup?</h6>
                                <ul>
                                    <li>All database tables and data</li>
                                    <li>User accounts and permissions</li>
                                    <li>Product catalog and inventory</li>
                                    <li>Sales history and transactions</li>
                                    <li>System settings and configurations</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
                 <script>
         function goBack() {
             if (confirm('Are you sure you want to cancel? Any unsaved changes will be lost.')) {
                 window.location.href = 'manage_backups.php';
             }
         }
         
         document.getElementById('createBackup').addEventListener('click', function() {
            const button = this;
            const result = document.getElementById('result');
            
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating backup...';
            result.innerHTML = '';
            
            fetch('?action=backup', {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.innerHTML = `
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            ${data.message}<br>
                            <strong>File:</strong> ${data.filename}<br>
                            <strong>Size:</strong> ${data.size}
                        </div>
                    `;
                } else {
                    result.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle me-2"></i>
                            ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                result.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        Error: ${error.message}
                    </div>
                `;
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-download me-2"></i>Create Backup';
            });
        });
        </script>
    </body>
    </html>
    <?php
}
?>
