<?php
header('Content-Type: application/json');
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGetRequest();
            break;
        case 'POST':
            handlePostRequest();
            break;
        case 'PUT':
            handlePutRequest();
            break;
        case 'DELETE':
            handleDeleteRequest();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGetRequest() {
    global $conn;
    
    $action = $_GET['action'] ?? '';
    $category_id = $_GET['id'] ?? null;
    
    switch ($action) {
        case 'list':
            getCategoriesList();
            break;
        case 'hierarchy':
            getCategoriesHierarchy();
            break;
        case 'details':
            getCategoryDetails($category_id);
            break;
        case 'stats':
            getCategoryStats();
            break;
        case 'search':
            searchCategories();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePostRequest() {
    global $conn, $user_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            createCategory($input);
            break;
        case 'bulk':
            handleBulkOperation($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePutRequest() {
    global $conn, $user_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $category_id = $input['id'] ?? null;
    
    if (!$category_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Category ID required']);
        return;
    }
    
    updateCategory($category_id, $input);
}

function handleDeleteRequest() {
    global $conn, $user_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $category_id = $input['id'] ?? null;
    
    if (!$category_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Category ID required']);
        return;
    }
    
    deleteCategory($category_id);
}

function getCategoriesList() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT 
                c.*,
                p.name as parent_name,
                (SELECT COUNT(*) FROM budget_categories WHERE parent_id = c.id) as child_count,
                (SELECT COUNT(*) FROM budget_items WHERE category_id = c.id) as usage_count
            FROM budget_categories c
            LEFT JOIN budget_categories p ON c.parent_id = p.id
            ORDER BY c.parent_id IS NULL DESC, c.name
        ");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getCategoriesHierarchy() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT 
                c.*,
                p.name as parent_name,
                (SELECT COUNT(*) FROM budget_categories WHERE parent_id = c.id) as child_count,
                (SELECT COUNT(*) FROM budget_items WHERE category_id = c.id) as usage_count
            FROM budget_categories c
            LEFT JOIN budget_categories p ON c.parent_id = p.id
            ORDER BY c.parent_id IS NULL DESC, c.name
        ");
        $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build hierarchy
        $hierarchy = buildCategoryHierarchy($all_categories);
        
        echo json_encode([
            'success' => true,
            'hierarchy' => $hierarchy
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getCategoryDetails($category_id) {
    global $conn;
    
    if (!$category_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Category ID required']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                c.*,
                p.name as parent_name,
                (SELECT COUNT(*) FROM budget_categories WHERE parent_id = c.id) as child_count,
                (SELECT COUNT(*) FROM budget_items WHERE category_id = c.id) as usage_count
            FROM budget_categories c
            LEFT JOIN budget_categories p ON c.parent_id = p.id
            WHERE c.id = ?
        ");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            http_response_code(404);
            echo json_encode(['error' => 'Category not found']);
            return;
        }
        
        // Get children if any
        $stmt = $conn->prepare("
            SELECT * FROM budget_categories 
            WHERE parent_id = ? 
            ORDER BY name
        ");
        $stmt->execute([$category_id]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $category['children'] = $children;
        
        echo json_encode([
            'success' => true,
            'category' => $category
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getCategoryStats() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT 
                c.id,
                c.name,
                c.color,
                c.icon,
                COUNT(bi.id) as budget_items_count,
                COALESCE(SUM(bi.budgeted_amount), 0) as total_budgeted,
                COALESCE(SUM(bi.actual_amount), 0) as total_spent
            FROM budget_categories c
            LEFT JOIN budget_items bi ON c.id = bi.category_id
            WHERE c.is_active = 1
            GROUP BY c.id, c.name, c.color, c.icon
            ORDER BY total_budgeted DESC
        ");
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function searchCategories() {
    global $conn;
    
    $query = $_GET['q'] ?? '';
    $status = $_GET['status'] ?? 'all';
    
    if (empty($query)) {
        http_response_code(400);
        echo json_encode(['error' => 'Search query required']);
        return;
    }
    
    try {
        $sql = "
            SELECT 
                c.*,
                p.name as parent_name,
                (SELECT COUNT(*) FROM budget_categories WHERE parent_id = c.id) as child_count,
                (SELECT COUNT(*) FROM budget_items WHERE category_id = c.id) as usage_count
            FROM budget_categories c
            LEFT JOIN budget_categories p ON c.parent_id = p.id
            WHERE (c.name LIKE ? OR c.description LIKE ?)
        ";
        
        $params = ["%$query%", "%$query%"];
        
        if ($status !== 'all') {
            $sql .= " AND c.is_active = ?";
            $params[] = $status === 'active' ? 1 : 0;
        }
        
        $sql .= " ORDER BY c.name";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'categories' => $categories,
            'query' => $query
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function createCategory($input) {
    global $conn;
    
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $parent_id = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
    $color = $input['color'] ?? '#6366f1';
    $icon = $input['icon'] ?? 'bi-tag';
    $is_active = $input['is_active'] ?? true;
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Category name is required']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO budget_categories (name, description, parent_id, color, icon, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $description, $parent_id, $color, $icon, $is_active ? 1 : 0]);
        
        $category_id = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Category created successfully',
            'category_id' => $category_id
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function updateCategory($category_id, $input) {
    global $conn;
    
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $parent_id = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
    $color = $input['color'] ?? '#6366f1';
    $icon = $input['icon'] ?? 'bi-tag';
    $is_active = $input['is_active'] ?? true;
    
    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Category name is required']);
        return;
    }
    
    // Check if parent_id is not the same as category_id (prevent self-reference)
    if ($parent_id == $category_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Category cannot be its own parent']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE budget_categories 
            SET name = ?, description = ?, parent_id = ?, color = ?, icon = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $parent_id, $color, $icon, $is_active ? 1 : 0, $category_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Category updated successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function deleteCategory($category_id) {
    global $conn;
    
    try {
        // Check if category has children
        $stmt = $conn->prepare("SELECT COUNT(*) FROM budget_categories WHERE parent_id = ?");
        $stmt->execute([$category_id]);
        $child_count = $stmt->fetchColumn();
        
        if ($child_count > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete category with subcategories. Please delete subcategories first.']);
            return;
        }
        
        // Check if category is used in budget items
        $stmt = $conn->prepare("SELECT COUNT(*) FROM budget_items WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $usage_count = $stmt->fetchColumn();
        
        if ($usage_count > 0) {
            // Soft delete instead
            $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id = ?");
            $stmt->execute([$category_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Category deactivated successfully (in use by budget items)'
            ]);
        } else {
            // Hard delete
            $stmt = $conn->prepare("DELETE FROM budget_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleBulkOperation($input) {
    global $conn;
    
    $action_type = $input['action_type'] ?? '';
    $category_ids = $input['category_ids'] ?? [];
    
    if (empty($category_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'No categories selected']);
        return;
    }
    
    if (empty($action_type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Action type required']);
        return;
    }
    
    try {
        $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
        
        switch ($action_type) {
            case 'activate':
                $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 1 WHERE id IN ($placeholders)");
                $stmt->execute($category_ids);
                break;
            case 'deactivate':
                $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id IN ($placeholders)");
                $stmt->execute($category_ids);
                break;
            case 'delete':
                // Check for dependencies first
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM budget_items bi 
                    WHERE bi.category_id IN ($placeholders)
                ");
                $stmt->execute($category_ids);
                $usage_count = $stmt->fetchColumn();
                
                if ($usage_count > 0) {
                    // Soft delete
                    $stmt = $conn->prepare("UPDATE budget_categories SET is_active = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                } else {
                    // Hard delete
                    $stmt = $conn->prepare("DELETE FROM budget_categories WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                }
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action type']);
                return;
        }
        
        echo json_encode([
            'success' => true,
            'message' => count($category_ids) . " categories " . $action_type . "d successfully"
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function buildCategoryHierarchy($categories, $parent_id = null) {
    $hierarchy = [];
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $category['children'] = buildCategoryHierarchy($categories, $category['id']);
            $hierarchy[] = $category;
        }
    }
    return $hierarchy;
}
?>
