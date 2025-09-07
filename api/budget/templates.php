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
    global $conn, $user_id;
    
    $action = $_GET['action'] ?? '';
    $template_id = $_GET['id'] ?? null;
    
    switch ($action) {
        case 'list':
            getTemplatesList();
            break;
        case 'details':
            getTemplateDetails($template_id);
            break;
        case 'categories':
            getBudgetCategories();
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
            createTemplate($input);
            break;
        case 'use':
            useTemplate($input);
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
    
    $template_id = $input['id'] ?? null;
    
    if (!$template_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Template ID required']);
        return;
    }
    
    updateTemplate($template_id, $input);
}

function handleDeleteRequest() {
    global $conn, $user_id;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $template_id = $input['id'] ?? null;
    
    if (!$template_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Template ID required']);
        return;
    }
    
    deleteTemplate($template_id);
}

function getTemplatesList() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT bt.*, u.username as created_by_name,
                   COUNT(bti.id) as items_count,
                   COALESCE(SUM(bti.budgeted_amount), 0) as total_amount
            FROM budget_templates bt
            LEFT JOIN users u ON bt.created_by = u.id
            LEFT JOIN budget_template_items bti ON bt.id = bti.template_id
            WHERE bt.is_active = 1
            GROUP BY bt.id
            ORDER BY bt.created_at DESC
        ");
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'templates' => $templates
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getTemplateDetails($template_id) {
    global $conn;
    
    if (!$template_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Template ID required']);
        return;
    }
    
    try {
        // Get template info
        $stmt = $conn->prepare("
            SELECT bt.*, u.username as created_by_name
            FROM budget_templates bt
            LEFT JOIN users u ON bt.created_by = u.id
            WHERE bt.id = ? AND bt.is_active = 1
        ");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            return;
        }
        
        // Get template items
        $stmt = $conn->prepare("
            SELECT bti.*, bc.name as category_name
            FROM budget_template_items bti
            LEFT JOIN budget_categories bc ON bti.category_id = bc.id
            WHERE bti.template_id = ?
            ORDER BY bti.budgeted_amount DESC
        ");
        $stmt->execute([$template_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $template['items'] = $items;
        
        echo json_encode([
            'success' => true,
            'template' => $template
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getBudgetCategories() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT * FROM budget_categories 
            WHERE is_active = TRUE 
            ORDER BY name
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

function createTemplate($input) {
    global $conn, $user_id;
    
    $template_name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $budget_type = $input['budget_type'] ?? 'monthly';
    $template_items = $input['items'] ?? [];
    
    if (empty($template_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Template name is required']);
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        // Create budget template
        $stmt = $conn->prepare("
            INSERT INTO budget_templates (name, description, budget_type, created_by, is_active) 
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$template_name, $description, $budget_type, $user_id]);
        
        $template_id = $conn->lastInsertId();
        
        // Create template items
        foreach ($template_items as $item) {
            if (!empty($item['name']) && (!empty($item['amount']) || !empty($item['percentage']))) {
                $stmt = $conn->prepare("
                    INSERT INTO budget_template_items (template_id, category_id, name, description, budgeted_amount, percentage) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $template_id, 
                    $item['category_id'] ?? null, 
                    $item['name'], 
                    $item['description'] ?? '',
                    (float)($item['amount'] ?? 0),
                    (float)($item['percentage'] ?? 0)
                ]);
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Template created successfully',
            'template_id' => $template_id
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function useTemplate($input) {
    global $conn, $user_id;
    
    $template_id = $input['template_id'] ?? null;
    $budget_name = trim($input['budget_name'] ?? '');
    $start_date = $input['start_date'] ?? '';
    $end_date = $input['end_date'] ?? '';
    $total_amount = (float)($input['total_amount'] ?? 0);
    
    if (!$template_id || empty($budget_name) || empty($start_date) || empty($end_date) || $total_amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'All fields are required']);
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        // Get template details
        $stmt = $conn->prepare("
            SELECT * FROM budget_templates WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            return;
        }
        
        // Create budget from template
        $stmt = $conn->prepare("
            INSERT INTO budgets (name, description, budget_type, start_date, end_date, total_budget_amount, created_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$budget_name, $template['description'], $template['budget_type'], $start_date, $end_date, $total_amount, $user_id]);
        
        $budget_id = $conn->lastInsertId();
        
        // Get template items and create budget items
        $stmt = $conn->prepare("
            SELECT * FROM budget_template_items WHERE template_id = ?
        ");
        $stmt->execute([$template_id]);
        $template_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($template_items as $item) {
            $item_amount = $item['budgeted_amount'];
            if ($item['percentage'] > 0) {
                $item_amount = ($total_amount * $item['percentage']) / 100;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO budget_items (budget_id, category_id, name, description, budgeted_amount) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$budget_id, $item['category_id'], $item['name'], $item['description'], $item_amount]);
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Budget created from template successfully',
            'budget_id' => $budget_id
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function updateTemplate($template_id, $input) {
    global $conn, $user_id;
    
    $template_name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $budget_type = $input['budget_type'] ?? 'monthly';
    $template_items = $input['items'] ?? [];
    
    if (empty($template_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Template name is required']);
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        // Check if template exists and user owns it
        $stmt = $conn->prepare("
            SELECT id FROM budget_templates WHERE id = ? AND created_by = ? AND is_active = 1
        ");
        $stmt->execute([$template_id, $user_id]);
        
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found or access denied']);
            return;
        }
        
        // Update template
        $stmt = $conn->prepare("
            UPDATE budget_templates 
            SET name = ?, description = ?, budget_type = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$template_name, $description, $budget_type, $template_id]);
        
        // Delete existing items
        $stmt = $conn->prepare("DELETE FROM budget_template_items WHERE template_id = ?");
        $stmt->execute([$template_id]);
        
        // Add new items
        foreach ($template_items as $item) {
            if (!empty($item['name']) && (!empty($item['amount']) || !empty($item['percentage']))) {
                $stmt = $conn->prepare("
                    INSERT INTO budget_template_items (template_id, category_id, name, description, budgeted_amount, percentage) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $template_id, 
                    $item['category_id'] ?? null, 
                    $item['name'], 
                    $item['description'] ?? '',
                    (float)($item['amount'] ?? 0),
                    (float)($item['percentage'] ?? 0)
                ]);
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Template updated successfully'
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function deleteTemplate($template_id) {
    global $conn, $user_id;
    
    try {
        // Check if template exists and user owns it
        $stmt = $conn->prepare("
            SELECT id FROM budget_templates WHERE id = ? AND created_by = ? AND is_active = 1
        ");
        $stmt->execute([$template_id, $user_id]);
        
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found or access denied']);
            return;
        }
        
        // Soft delete template
        $stmt = $conn->prepare("
            UPDATE budget_templates SET is_active = 0 WHERE id = ?
        ");
        $stmt->execute([$template_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Template deleted successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
