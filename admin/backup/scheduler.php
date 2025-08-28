<?php
/**
 * Automatic Backup Scheduler
 * This script should be run by cron job or Windows Task Scheduler
 * to automatically create backups based on the configured frequency
 */

// Set time limit to avoid timeout on large databases
set_time_limit(0);

// Include database connection
require_once __DIR__ . '/../../include/db.php';

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    logSchedulerActivity("Failed to load settings: " . $e->getMessage(), 'ERROR');
    exit(1);
}

$log_file = __DIR__ . '/../../backups/logs/backup_log.txt';

/**
 * Log scheduler activities
 */
function logSchedulerActivity($message, $type = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$type}] [SCHEDULER] {$message}" . PHP_EOL;
    
    // Ensure log directory exists
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Check if backup is needed based on frequency setting
 */
function isBackupNeeded($frequency, $lastBackupTime) {
    if ($frequency === 'never') {
        return false;
    }
    
    if (empty($lastBackupTime)) {
        return true; // No backup has been made yet
    }
    
    $lastBackup = strtotime($lastBackupTime);
    $now = time();
    
    switch ($frequency) {
        case 'daily':
            return ($now - $lastBackup) >= (24 * 60 * 60); // 24 hours
            
        case 'weekly':
            return ($now - $lastBackup) >= (7 * 24 * 60 * 60); // 7 days
            
        case 'monthly':
            return ($now - $lastBackup) >= (30 * 24 * 60 * 60); // 30 days
            
        default:
            return false;
    }
}

/**
 * Create backup using the same logic as create_backup.php
 */
function createScheduledBackup() {
    global $conn, $settings;
    
    $host = 'localhost';
    $dbname = 'pos_system';
    $username = 'root';
    $password = '';
    
    $backup_dir = __DIR__ . '/../../backups/database/';
    $timestamp = date('Y-m-d_H-i-s');
    $backup_filename = "pos_system_scheduled_backup_{$timestamp}.sql";
    $backup_path = $backup_dir . $backup_filename;
    
    // Ensure backup directory exists
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    try {
        logSchedulerActivity("Starting scheduled backup: {$backup_filename}");
        
        // Try mysqldump first
        $result = createMysqlDumpBackup($backup_path, $host, $username, $password, $dbname);
        
        if (!$result['success']) {
            // Fallback to PHP method
            logSchedulerActivity("mysqldump failed, trying PHP method");
            $result = createPHPBackup($backup_path, $conn);
        }
        
        if ($result['success']) {
            // Update last backup time
            updateLastBackupTime();
            
            // Clean old backups
            cleanOldBackups();
            
            logSchedulerActivity("Scheduled backup completed successfully: {$backup_filename}", 'SUCCESS');
            return true;
        } else {
            logSchedulerActivity("Scheduled backup failed: " . $result['message'], 'ERROR');
            return false;
        }
        
    } catch (Exception $e) {
        logSchedulerActivity("Scheduled backup error: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Create backup using mysqldump
 */
function createMysqlDumpBackup($backup_path, $host, $username, $password, $dbname) {
    // Find mysqldump executable
    $mysqldump_path = 'mysqldump';
    
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
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0.21\\bin\\mysqldump.exe'
        ];
        $possible_paths = array_merge($possible_paths, $windows_paths);
        
        // Auto-detect MySQL installation
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
    
    $mysqldump_found = false;
    foreach ($possible_paths as $path) {
        // Cross-platform testing
        if (PHP_OS_FAMILY === 'Windows') {
            $test_command = "\"{$path}\" --version 2>nul";
        } else {
            $test_command = "\"{$path}\" --version 2>/dev/null";
        }
        
        $result = shell_exec($test_command);
        if ($result !== null && strpos($result, 'mysqldump') !== false) {
            $mysqldump_path = $path;
            $mysqldump_found = true;
            logSchedulerActivity("Found working mysqldump at: {$path}");
            break;
        }
    }
    
    if (!$mysqldump_found) {
        return ['success' => false, 'message' => 'mysqldump not found'];
    }
    
    // Build command
    $command = "\"{$mysqldump_path}\" --opt --single-transaction --routines --triggers";
    $command .= " --host=\"{$host}\" --user=\"{$username}\"";
    
    if (!empty($password)) {
        $command .= " --password=\"{$password}\"";
    }
    
    $command .= " \"{$dbname}\" > \"{$backup_path}\"";
    
    // Execute command
    $output = [];
    $return_code = 0;
    exec($command . ' 2>&1', $output, $return_code);
    
    if ($return_code === 0 && file_exists($backup_path) && filesize($backup_path) > 0) {
        return ['success' => true, 'message' => 'Backup created with mysqldump'];
    } else {
        return ['success' => false, 'message' => 'mysqldump failed: ' . implode("\n", $output)];
    }
}

/**
 * Create backup using PHP
 */
function createPHPBackup($backup_path, $conn) {
    try {
        $backup_content = "-- POS System Scheduled Backup\n";
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
        
        if (file_put_contents($backup_path, $backup_content) !== false) {
            return ['success' => true, 'message' => 'Backup created with PHP'];
        } else {
            return ['success' => false, 'message' => 'Failed to write backup file'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
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
 * Clean old backup files
 */
function cleanOldBackups() {
    global $settings;
    
    $backup_dir = __DIR__ . '/../../backups/database/';
    $keep_backups = intval($settings['backup_retention_count'] ?? 10);
    
    // Get all backup files (both manual and scheduled)
    $files = array_merge(
        glob($backup_dir . 'pos_system_backup_*.sql'),
        glob($backup_dir . 'pos_system_scheduled_backup_*.sql')
    );
    
    if (count($files) > $keep_backups) {
        // Sort by modification time (oldest first)
        array_multisort(array_map('filemtime', $files), SORT_ASC, $files);
        
        // Delete oldest files
        $files_to_delete = array_slice($files, 0, count($files) - $keep_backups);
        foreach ($files_to_delete as $file) {
            if (unlink($file)) {
                logSchedulerActivity("Deleted old backup: " . basename($file));
            }
        }
    }
}

// Main execution
try {
    logSchedulerActivity("Scheduler started");
    
    $backup_frequency = $settings['backup_frequency'] ?? 'daily';
    $last_backup_time = $settings['last_backup_time'] ?? '';
    
    logSchedulerActivity("Backup frequency: {$backup_frequency}, Last backup: " . ($last_backup_time ?: 'Never'));
    
    if (isBackupNeeded($backup_frequency, $last_backup_time)) {
        logSchedulerActivity("Backup is needed, starting backup process");
        
        if (createScheduledBackup()) {
            logSchedulerActivity("Scheduled backup completed successfully", 'SUCCESS');
            echo "SUCCESS: Backup completed\n";
            exit(0);
        } else {
            logSchedulerActivity("Scheduled backup failed", 'ERROR');
            echo "ERROR: Backup failed\n";
            exit(1);
        }
    } else {
        logSchedulerActivity("Backup not needed at this time");
        echo "INFO: Backup not needed\n";
        exit(0);
    }
    
} catch (Exception $e) {
    logSchedulerActivity("Scheduler error: " . $e->getMessage(), 'ERROR');
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
