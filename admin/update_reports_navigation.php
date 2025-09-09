<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'] ?? 'User';

// Check if user is admin
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator'
);

if (!$isAdmin) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Check if reports section already exists
$stmt = $conn->prepare("SELECT id FROM menu_sections WHERE section_key = 'reports'");
$stmt->execute();
$reports_section = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reports_section) {
    // Add reports section to menu_sections table
    $stmt = $conn->prepare("
        INSERT INTO menu_sections (section_key, section_name, section_icon, section_description, is_active, sort_order) 
        VALUES ('reports', 'Reports', 'bi-file-earmark-bar-graph', 'Comprehensive business reports and analytics', 1, 8)
    ");
    
    if ($stmt->execute()) {
        $reports_section_id = $conn->lastInsertId();
        echo "✅ Reports section added to menu_sections table with ID: $reports_section_id<br>";
        
        // Get all roles
        $stmt = $conn->prepare("SELECT id FROM roles");
        $stmt->execute();
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add reports access for all roles
        foreach ($roles as $role) {
            $role_id = $role['id'];
            
            // Check if access already exists
            $stmt = $conn->prepare("
                SELECT id FROM role_menu_access 
                WHERE role_id = :role_id AND menu_section_id = :menu_section_id
            ");
            $stmt->bindParam(':role_id', $role_id);
            $stmt->bindParam(':menu_section_id', $reports_section_id);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                // Add access for this role
                $stmt = $conn->prepare("
                    INSERT INTO role_menu_access (role_id, menu_section_id, is_visible, is_priority) 
                    VALUES (:role_id, :menu_section_id, 1, 0)
                ");
                $stmt->bindParam(':role_id', $role_id);
                $stmt->bindParam(':menu_section_id', $reports_section_id);
                $stmt->execute();
                echo "✅ Reports access added for role ID: $role_id<br>";
            }
        }
        
        echo "<br>🎉 Reports navigation setup completed successfully!<br>";
        echo "<a href='../reports/index.php' class='btn btn-primary'>Go to Reports Dashboard</a>";
        
    } else {
        echo "❌ Error adding reports section to database";
    }
} else {
    echo "✅ Reports section already exists in database<br>";
    echo "<a href='../reports/index.php' class='btn btn-primary'>Go to Reports Dashboard</a>";
}

// Check if view_reports permission exists
$stmt = $conn->prepare("SELECT id FROM permissions WHERE name = 'view_reports'");
$stmt->execute();
$view_reports_permission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$view_reports_permission) {
    // Add view_reports permission
    $stmt = $conn->prepare("
        INSERT INTO permissions (name, description, category) 
        VALUES ('view_reports', 'View Reports', 'Reports')
    ");
    
    if ($stmt->execute()) {
        $permission_id = $conn->lastInsertId();
        echo "<br>✅ view_reports permission added with ID: $permission_id<br>";
        
        // Add permission to all roles
        $stmt = $conn->prepare("SELECT id FROM roles");
        $stmt->execute();
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($roles as $role) {
            $role_id = $role['id'];
            
            // Check if permission already exists for this role
            $stmt = $conn->prepare("
                SELECT id FROM role_permissions 
                WHERE role_id = :role_id AND permission_id = :permission_id
            ");
            $stmt->bindParam(':role_id', $role_id);
            $stmt->bindParam(':permission_id', $permission_id);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                // Add permission for this role
                $stmt = $conn->prepare("
                    INSERT INTO role_permissions (role_id, permission_id) 
                    VALUES (:role_id, :permission_id)
                ");
                $stmt->bindParam(':role_id', $role_id);
                $stmt->bindParam(':permission_id', $permission_id);
                $stmt->execute();
                echo "✅ view_reports permission added for role ID: $role_id<br>";
            }
        }
        
        echo "<br>🎉 Reports permissions setup completed successfully!<br>";
    } else {
        echo "❌ Error adding view_reports permission to database";
    }
} else {
    echo "<br>✅ view_reports permission already exists in database<br>";
}

echo "<br><a href='../dashboard/dashboard.php' class='btn btn-secondary'>Back to Dashboard</a>";
?>
