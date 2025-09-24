<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Check - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .check-container { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="check-container p-5">
                    <h1 class="text-center mb-4">
                        <i class="bi bi-database-check me-2"></i>
                        Database Check
                    </h1>
                    
                    <?php
                    echo "<div class='alert alert-info'>";
                    echo "<h5><i class='bi bi-info-circle me-2'></i>Database Connection Test</h5>";
                    
                    try {
                        // Test database connection
                        $host = 'localhost';
                        $dbname = 'pos_system';
                        $username = 'root';
                        $password = '';
                        
                        $conn = new PDO("mysql:host=$host", $username, $password);
                        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        echo "<p>✅ MySQL connection successful</p>";
                        
                        // Check if database exists
                        $db_check = $conn->query("SHOW DATABASES LIKE '$dbname'");
                        if ($db_check->rowCount() > 0) {
                            echo "<p>✅ Database '$dbname' exists</p>";
                            
                            $conn->exec("USE `$dbname`");
                            
                            // Check tables
                            $tables = ['users', 'categories', 'products', 'sales', 'sale_items', 'roles', 'permissions', 'settings'];
                            echo "<h6>Table Status:</h6><ul>";
                            
                            foreach ($tables as $table) {
                                $table_check = $conn->query("SHOW TABLES LIKE '$table'");
                                if ($table_check->rowCount() > 0) {
                                    // Count records
                                    $count_check = $conn->query("SELECT COUNT(*) as count FROM `$table`");
                                    $count = $count_check->fetch()['count'];
                                    echo "<li>✅ Table '$table' exists ($count records)</li>";
                                } else {
                                    echo "<li>❌ Table '$table' missing</li>";
                                }
                            }
                            echo "</ul>";
                            
                        } else {
                            echo "<p>❌ Database '$dbname' does not exist</p>";
                        }
                        
                    } catch (PDOException $e) {
                        echo "<p>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                    
                    echo "</div>";
                    ?>
                    
                    <div class="text-center mt-4">
                        <a href="starter.php" class="btn btn-primary btn-lg me-3">
                            <i class="bi bi-arrow-clockwise me-2"></i>Try Installation Again
                        </a>
                        <a href="fresh_install.php" class="btn btn-warning btn-lg">
                            <i class="bi bi-exclamation-triangle me-2"></i>Fresh Install
                        </a>
                    </div>
                    
                    <div class="mt-4">
                        <h5>Common Issues & Solutions:</h5>
                        <div class="accordion" id="troubleshooting">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#issue1">
                                        Database Connection Failed
                                    </button>
                                </h2>
                                <div id="issue1" class="accordion-collapse collapse" data-bs-parent="#troubleshooting">
                                    <div class="accordion-body">
                                        <ul>
                                            <li>Ensure MySQL/MariaDB is running</li>
                                            <li>Check database credentials in include/db.php</li>
                                            <li>Verify the database server is accessible</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#issue2">
                                        Tables Missing or Corrupted
                                    </button>
                                </h2>
                                <div id="issue2" class="accordion-collapse collapse" data-bs-parent="#troubleshooting">
                                    <div class="accordion-body">
                                        <ul>
                                            <li>Use "Fresh Install" to completely recreate all tables</li>
                                            <li>Check MySQL user permissions for CREATE TABLE</li>
                                            <li>Ensure sufficient disk space for database</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>