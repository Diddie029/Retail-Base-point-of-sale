<?php
/**
 * Product Family Permissions Migration
 * This script adds permissions for product family management
 */

require_once __DIR__ . '/db.php';

try {
    $conn->beginTransaction();

    echo "Adding Product Family permissions...\n";

    // Define family-related permissions
    $family_permissions = [
        ['name' => 'manage_product_families', 'description' => 'Create, edit, and delete product families'],
        ['name' => 'view_product_families', 'description' => 'View product families and their details']
    ];

    // Insert permissions
    $stmt = $conn->prepare("
        INSERT IGNORE INTO permissions (name, description, created_at)
        VALUES (:name, :description, NOW())
    ");

    foreach ($family_permissions as $permission) {
        $stmt->bindParam(':name', $permission['name']);
        $stmt->bindParam(':description', $permission['description']);
        $stmt->execute();

        $permission_id = $conn->lastInsertId();

        if ($permission_id) {
            echo "Added permission: {$permission['name']}\n";

            // Give admin role these permissions by default
            $admin_role_stmt = $conn->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                SELECT r.id, :permission_id, NOW()
                FROM roles r
                WHERE r.name = 'Admin'
            ");
            $admin_role_stmt->bindParam(':permission_id', $permission_id);
            $admin_role_stmt->execute();

            // Give manager role these permissions by default (if exists)
            $manager_role_stmt = $conn->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                SELECT r.id, :permission_id, NOW()
                FROM roles r
                WHERE r.name = 'Manager'
            ");
            $manager_role_stmt->bindParam(':permission_id', $permission_id);
            $manager_role_stmt->execute();

        } else {
            echo "Permission '{$permission['name']}' already exists\n";
        }
    }

    $conn->commit();
    echo "Product Family permissions migration completed successfully!\n";

} catch (PDOException $e) {
    $conn->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    $conn->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
