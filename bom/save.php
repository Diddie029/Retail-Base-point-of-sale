<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];

// Check permissions
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

$action = $_POST['action'] ?? '';

// Check permissions based on action
$can_create_boms = hasPermission('create_boms', $permissions);
$can_edit_boms = hasPermission('edit_boms', $permissions);
$can_delete_boms = hasPermission('delete_boms', $permissions);

if ($action === 'create' && !$can_create_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
} elseif ($action === 'update' && !$can_edit_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
} elseif ($action === 'delete' && !$can_delete_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
} elseif (!$can_create_boms && !$can_edit_boms && !$can_delete_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
}

if ($action === 'create') {
    // Create new BOM
    try {
        $conn->beginTransaction();

        // Get settings for BOM number generation
        $settings = getSystemSettings($conn);
        $auto_generate = isset($settings['auto_generate_bom_number']) && $settings['auto_generate_bom_number'] == '1';

        // Handle BOM number
        $bom_number = trim($_POST['bom_number'] ?? '');
        if (empty($bom_number)) {
            if ($auto_generate) {
                $bom_number = generateBOMNumber($conn);
            } else {
                throw new Exception("BOM number is required when auto-generation is disabled");
            }
        }

        // Validate other required fields
        $required_fields = ['product_id', 'name', 'total_quantity'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Required field '$field' is missing");
            }
        }

        // Check if product already has an active BOM
        $stmt = $conn->prepare("SELECT id FROM bom_headers WHERE product_id = :product_id AND status = 'active'");
        $stmt->bindParam(':product_id', $_POST['product_id'], PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetch()) {
            throw new Exception("This product already has an active BOM. Please deactivate the existing BOM first.");
        }

        // Insert BOM header
        $stmt = $conn->prepare("
            INSERT INTO bom_headers (
                bom_number, product_id, name, description, version, status,
                total_cost, labor_cost, overhead_cost, total_quantity, unit_of_measure,
                created_by, notes, created_at, updated_at
            ) VALUES (
                :bom_number, :product_id, :name, :description, :version, :status,
                :total_cost, :labor_cost, :overhead_cost, :total_quantity, :unit_of_measure,
                :created_by, :notes, NOW(), NOW()
            )
        ");

        $labor_cost = floatval($_POST['labor_cost'] ?? 0);
        $overhead_cost = floatval($_POST['overhead_cost'] ?? 0);
        $total_quantity = intval($_POST['total_quantity'] ?? 1);

        $stmt->execute([
            ':bom_number' => $bom_number,
            ':product_id' => $_POST['product_id'],
            ':name' => $_POST['name'],
            ':description' => $_POST['description'] ?? '',
            ':version' => intval($_POST['version'] ?? 1),
            ':status' => $_POST['status'] ?? 'draft',
            ':total_cost' => 0, // Will be calculated after components
            ':labor_cost' => $labor_cost,
            ':overhead_cost' => $overhead_cost,
            ':total_quantity' => $total_quantity,
            ':unit_of_measure' => $_POST['unit_of_measure'] ?? 'each',
            ':created_by' => $user_id,
            ':notes' => $_POST['notes'] ?? ''
        ]);

        $bom_id = $conn->lastInsertId();

        // Insert components
        $total_material_cost = 0;

        if (!empty($_POST['components']) && is_array($_POST['components'])) {
            $component_stmt = $conn->prepare("
                INSERT INTO bom_components (
                    bom_id, component_product_id, quantity_required, unit_of_measure,
                    waste_percentage, unit_cost, supplier_id, notes,
                    quantity_with_waste, total_cost, created_at, updated_at
                ) VALUES (
                    :bom_id, :component_product_id, :quantity_required, :unit_of_measure,
                    :waste_percentage, :unit_cost, :supplier_id, :notes,
                    :quantity_with_waste, :total_cost, NOW(), NOW()
                )
            ");

            foreach ($_POST['components'] as $component) {
                if (empty($component['component_product_id']) || empty($component['quantity_required'])) {
                    continue; // Skip incomplete components
                }

                $quantity_required = floatval($component['quantity_required']);
                $waste_percentage = floatval($component['waste_percentage'] ?? 0);
                $unit_cost = floatval($component['unit_cost'] ?? 0);

                $quantity_with_waste = $quantity_required * (1 + $waste_percentage / 100);
                $component_total_cost = $quantity_with_waste * $unit_cost;
                $total_material_cost += $component_total_cost;

                $component_stmt->execute([
                    ':bom_id' => $bom_id,
                    ':component_product_id' => $component['component_product_id'],
                    ':quantity_required' => $quantity_required,
                    ':unit_of_measure' => $component['unit_of_measure'] ?? 'each',
                    ':waste_percentage' => $waste_percentage,
                    ':unit_cost' => $unit_cost,
                    ':supplier_id' => !empty($component['supplier_id']) ? $component['supplier_id'] : null,
                    ':notes' => $component['notes'] ?? '',
                    ':quantity_with_waste' => $quantity_with_waste,
                    ':total_cost' => $component_total_cost
                ]);
            }
        }

        // Update BOM total cost
        $total_cost = $total_material_cost + $labor_cost + $overhead_cost;
        $stmt = $conn->prepare("UPDATE bom_headers SET total_cost = :total_cost WHERE id = :bom_id");
        $stmt->execute([
            ':total_cost' => $total_cost,
            ':bom_id' => $bom_id
        ]);

        // Update product to mark it as having a BOM
        $stmt = $conn->prepare("UPDATE products SET is_bom = 1, bom_id = :bom_id WHERE id = :product_id");
        $stmt->execute([
            ':bom_id' => $bom_id,
            ':product_id' => $_POST['product_id']
        ]);

        // Log activity
        logActivity($conn, $user_id, 'bom_created', "Created BOM: " . $_POST['bom_number']);

        $conn->commit();

        header("Location: view.php?id=$bom_id&success=bom_created");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("BOM creation failed: " . $e->getMessage());
        header("Location: add.php?error=" . urlencode($e->getMessage()));
        exit();
    }

} elseif ($action === 'update') {
    // Update existing BOM
    try {
        $conn->beginTransaction();

        $bom_id = intval($_POST['bom_id'] ?? 0);
        if (!$bom_id) {
            throw new Exception("BOM ID is required for update");
        }

        // Validate required fields
        $required_fields = ['bom_number', 'product_id', 'name', 'total_quantity'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Required field '$field' is missing");
            }
        }

        // Check if BOM exists and user has permission
        $stmt = $conn->prepare("SELECT * FROM bom_headers WHERE id = :bom_id");
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $existing_bom = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing_bom) {
            throw new Exception("BOM not found");
        }

        // Update BOM header
        $labor_cost = floatval($_POST['labor_cost'] ?? 0);
        $overhead_cost = floatval($_POST['overhead_cost'] ?? 0);
        $total_quantity = intval($_POST['total_quantity'] ?? 1);

        $stmt = $conn->prepare("
            UPDATE bom_headers SET
                name = :name,
                description = :description,
                version = :version,
                status = :status,
                labor_cost = :labor_cost,
                overhead_cost = :overhead_cost,
                total_quantity = :total_quantity,
                unit_of_measure = :unit_of_measure,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :bom_id
        ");

        $stmt->execute([
            ':name' => $_POST['name'],
            ':description' => $_POST['description'] ?? '',
            ':version' => intval($_POST['version'] ?? 1),
            ':status' => $_POST['status'] ?? 'draft',
            ':labor_cost' => $labor_cost,
            ':overhead_cost' => $overhead_cost,
            ':total_quantity' => $total_quantity,
            ':unit_of_measure' => $_POST['unit_of_measure'] ?? 'each',
            ':notes' => $_POST['notes'] ?? '',
            ':bom_id' => $bom_id
        ]);

        // Delete existing components
        $stmt = $conn->prepare("DELETE FROM bom_components WHERE bom_id = :bom_id");
        $stmt->bindParam(':bom_id', $bom_id);
        $stmt->execute();

        // Insert updated components
        $total_material_cost = 0;

        if (!empty($_POST['components']) && is_array($_POST['components'])) {
            $component_stmt = $conn->prepare("
                INSERT INTO bom_components (
                    bom_id, component_product_id, quantity_required, unit_of_measure,
                    waste_percentage, unit_cost, supplier_id, notes,
                    quantity_with_waste, total_cost, created_at, updated_at
                ) VALUES (
                    :bom_id, :component_product_id, :quantity_required, :unit_of_measure,
                    :waste_percentage, :unit_cost, :supplier_id, :notes,
                    :quantity_with_waste, :total_cost, NOW(), NOW()
                )
            ");

            foreach ($_POST['components'] as $component) {
                if (empty($component['component_product_id']) || empty($component['quantity_required'])) {
                    continue; // Skip incomplete components
                }

                $quantity_required = floatval($component['quantity_required']);
                $waste_percentage = floatval($component['waste_percentage'] ?? 0);
                $unit_cost = floatval($component['unit_cost'] ?? 0);

                $quantity_with_waste = $quantity_required * (1 + $waste_percentage / 100);
                $component_total_cost = $quantity_with_waste * $unit_cost;
                $total_material_cost += $component_total_cost;

                $component_stmt->execute([
                    ':bom_id' => $bom_id,
                    ':component_product_id' => $component['component_product_id'],
                    ':quantity_required' => $quantity_required,
                    ':unit_of_measure' => $component['unit_of_measure'] ?? 'each',
                    ':waste_percentage' => $waste_percentage,
                    ':unit_cost' => $unit_cost,
                    ':supplier_id' => !empty($component['supplier_id']) ? $component['supplier_id'] : null,
                    ':notes' => $component['notes'] ?? '',
                    ':quantity_with_waste' => $quantity_with_waste,
                    ':total_cost' => $component_total_cost
                ]);
            }
        }

        // Update BOM total cost
        $total_cost = $total_material_cost + $labor_cost + $overhead_cost;
        $stmt = $conn->prepare("UPDATE bom_headers SET total_cost = :total_cost WHERE id = :bom_id");
        $stmt->execute([
            ':total_cost' => $total_cost,
            ':bom_id' => $bom_id
        ]);

        // Log activity
        logActivity($conn, $user_id, 'bom_updated', "Updated BOM: " . $_POST['bom_number']);

        $conn->commit();

        header("Location: view.php?id=$bom_id&success=bom_updated");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("BOM update failed: " . $e->getMessage());
        header("Location: edit.php?id=" . ($_POST['bom_id'] ?? 0) . "&error=" . urlencode($e->getMessage()));
        exit();
    }

} else {
    header("Location: index.php?error=invalid_action");
    exit();
}
?>
