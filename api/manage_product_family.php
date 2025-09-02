<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required.'
    ]);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    switch ($method) {
        case 'POST':
            handlePostRequest($action, $conn, $user_id);
            break;

        case 'PUT':
            handlePutRequest($action, $conn, $user_id);
            break;

        case 'DELETE':
            handleDeleteRequest($action, $conn, $user_id);
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed.'
            ]);
            break;
    }

} catch (PDOException $e) {
    error_log("API manage_product_family error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred. Please try again.'
    ]);

} catch (Exception $e) {
    error_log("API manage_product_family general error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.'
    ]);
}

function handlePostRequest($action, $conn, $user_id) {
    // Create new family
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data.'
        ]);
        return;
    }

    // Validate required fields
    if (empty($data['name'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Family name is required.'
        ]);
        return;
    }

    if (empty($data['base_unit'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Base unit is required.'
        ]);
        return;
    }

    // Check for duplicate name
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_families WHERE name = :name");
    $stmt->bindParam(':name', $data['name']);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'A family with this name already exists.'
        ]);
        return;
    }

    // Insert new family
    $stmt = $conn->prepare("
        INSERT INTO product_families (
            name, description, base_unit, default_pricing_strategy, status, created_at, updated_at
        ) VALUES (
            :name, :description, :base_unit, :pricing_strategy, :status, NOW(), NOW()
        )
    ");

    $stmt->bindParam(':name', $data['name']);
    $stmt->bindParam(':description', $data['description'] ?? '');
    $stmt->bindParam(':base_unit', $data['base_unit']);
    $stmt->bindParam(':pricing_strategy', $data['pricing_strategy'] ?? 'fixed');
    $stmt->bindParam(':status', $data['status'] ?? 'active');

    if ($stmt->execute()) {
        $family_id = $conn->lastInsertId();

        // Log activity
        logActivity($conn, $user_id, 'create_product_family', "Created product family: {$data['name']} (ID: $family_id)");

        echo json_encode([
            'success' => true,
            'message' => 'Product family created successfully.',
            'family_id' => $family_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create product family.'
        ]);
    }
}

function handlePutRequest($action, $conn, $user_id) {
    // Update existing family
    $family_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$family_id) {
        echo json_encode([
            'success' => false,
            'error' => 'Family ID is required.'
        ]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON data.'
        ]);
        return;
    }

    // Check if family exists
    $stmt = $conn->prepare("SELECT id FROM product_families WHERE id = :id");
    $stmt->bindParam(':id', $family_id);
    $stmt->execute();

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            'success' => false,
            'error' => 'Product family not found.'
        ]);
        return;
    }

    // Validate required fields
    if (empty($data['name'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Family name is required.'
        ]);
        return;
    }

    if (empty($data['base_unit'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Base unit is required.'
        ]);
        return;
    }

    // Check for duplicate name (excluding current family)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_families WHERE name = :name AND id != :id");
    $stmt->bindParam(':name', $data['name']);
    $stmt->bindParam(':id', $family_id);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
        echo json_encode([
            'success' => false,
            'error' => 'A family with this name already exists.'
        ]);
        return;
    }

    // Update family
    $stmt = $conn->prepare("
        UPDATE product_families SET
            name = :name,
            description = :description,
            base_unit = :base_unit,
            default_pricing_strategy = :pricing_strategy,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");

    $stmt->bindParam(':name', $data['name']);
    $stmt->bindParam(':description', $data['description'] ?? '');
    $stmt->bindParam(':base_unit', $data['base_unit']);
    $stmt->bindParam(':pricing_strategy', $data['pricing_strategy'] ?? 'fixed');
    $stmt->bindParam(':status', $data['status'] ?? 'active');
    $stmt->bindParam(':id', $family_id);

    if ($stmt->execute()) {
        // Log activity
        logActivity($conn, $user_id, 'update_product_family', "Updated product family: {$data['name']} (ID: $family_id)");

        echo json_encode([
            'success' => true,
            'message' => 'Product family updated successfully.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update product family.'
        ]);
    }
}

function handleDeleteRequest($action, $conn, $user_id) {
    // Delete family
    $family_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$family_id) {
        echo json_encode([
            'success' => false,
            'error' => 'Family ID is required.'
        ]);
        return;
    }

    // Check if family exists
    $stmt = $conn->prepare("SELECT name FROM product_families WHERE id = :id");
    $stmt->bindParam(':id', $family_id);
    $stmt->execute();
    $family = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$family) {
        echo json_encode([
            'success' => false,
            'error' => 'Product family not found.'
        ]);
        return;
    }

    // Get product count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_family_id = :family_id");
    $stmt->bindParam(':family_id', $family_id);
    $stmt->execute();
    $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $conn->beginTransaction();

    try {
        // Remove family association from products
        $stmt = $conn->prepare("UPDATE products SET product_family_id = NULL WHERE product_family_id = :family_id");
        $stmt->bindParam(':family_id', $family_id);
        $stmt->execute();

        // Delete the family
        $stmt = $conn->prepare("DELETE FROM product_families WHERE id = :id");
        $stmt->bindParam(':id', $family_id);
        $stmt->execute();

        $conn->commit();

        // Log activity
        logActivity($conn, $user_id, 'delete_product_family', "Deleted product family: {$family['name']} (ID: $family_id) with $product_count products");

        echo json_encode([
            'success' => true,
            'message' => 'Product family deleted successfully.',
            'products_affected' => $product_count
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete product family: ' . $e->getMessage()
        ]);
    }
}
?>
