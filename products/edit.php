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
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
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

// Check if user has permission to edit products
if (!hasPermission('edit_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get product ID
$product_id = (int)($_GET['id'] ?? 0);
if ($product_id <= 0) {
    $_SESSION['error'] = 'Invalid product ID.';
    header("Location: products.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Set default values from settings
$default_minimum_stock = $settings['default_minimum_stock_level'] ?? '5';
$default_reorder_point = $settings['default_reorder_point'] ?? '10';

// Get existing product data
// Check what columns exist in product_families table
$families_columns = [];
try {
    $result = $conn->query("SHOW COLUMNS FROM product_families");
    $families_columns = $result->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $families_columns = [];
}

$family_select = 'pf.name as family_name';
if (in_array('base_unit', $families_columns)) {
    $family_select .= ', pf.base_unit as family_unit';
} else {
    $family_select .= ', NULL as family_unit';
}

$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name,
           $family_select
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN product_families pf ON p.product_family_id = pf.id
    WHERE p.id = :id
");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = 'Product not found.';
    header("Location: products.php");
    exit();
}

// Ensure tax_category_id column exists in products table
try {
    $stmt = $conn->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('tax_category_id', $columns)) {
        $conn->exec("ALTER TABLE products ADD COLUMN tax_category_id INT DEFAULT NULL AFTER tax_rate");
        $conn->exec("ALTER TABLE products ADD INDEX idx_tax_category_id (tax_category_id)");
        error_log("Added tax_category_id column to products table");
    }

    // Ensure foreign key constraint exists (only if tax_categories table exists)
    $stmt = $conn->query("SHOW TABLES LIKE 'tax_categories'");
    if ($stmt->rowCount() > 0) {
        try {
            $conn->exec("ALTER TABLE products ADD CONSTRAINT fk_products_tax_category_id FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE SET NULL");
            error_log("Added foreign key constraint for tax_category_id");
        } catch (PDOException $e) {
            // Constraint might already exist, ignore error
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                error_log("Error adding foreign key constraint for tax_category_id: " . $e->getMessage());
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error checking/adding tax_category_id column: " . $e->getMessage());
}

// Get categories
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get brands
$brands_stmt = $conn->query("SELECT * FROM brands WHERE is_active = 1 ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tax categories with their rates
$tax_categories_stmt = $conn->prepare("
    SELECT tc.*, tr.name as rate_name, tr.rate_percentage, tr.is_active as rate_active
    FROM tax_categories tc
    LEFT JOIN tax_rates tr ON tc.id = tr.tax_category_id AND tr.is_active = 1
    WHERE tc.is_active = 1
    ORDER BY tc.name, tr.rate_percentage
");
$tax_categories_stmt->execute();
$tax_data = $tax_categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group tax rates by category
$tax_categories = [];
$tax_rates_by_category = [];
foreach ($tax_data as $row) {
    $category_id = $row['id'];
    if (!isset($tax_categories[$category_id])) {
        $tax_categories[$category_id] = $row;
        $tax_categories[$category_id]['rates'] = [];
    }
    if (!empty($row['rate_name'])) {
        $tax_categories[$category_id]['rates'][] = $row;
    }
}

// Get suppliers
$suppliers_stmt = $conn->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product families
// Check if status column exists in product_families table
$has_families_status = false;
try {
    $result = $conn->query("SHOW COLUMNS FROM product_families LIKE 'status'");
    $has_families_status = $result->rowCount() > 0;
} catch (PDOException $e) {
    $has_families_status = false;
}

// Check what columns exist in product_families table for ordering
$families_order_column = 'id'; // Default fallback
try {
    $result = $conn->query("SHOW COLUMNS FROM product_families");
    $columns = $result->fetchAll(PDO::FETCH_COLUMN);

    // Try to find a suitable column for ordering
    if (in_array('name', $columns)) {
        $families_order_column = 'name';
    } elseif (in_array('family_name', $columns)) {
        $families_order_column = 'family_name';
    } elseif (in_array('title', $columns)) {
        $families_order_column = 'title';
    } else {
        $families_order_column = 'id'; // Fallback to ID
    }
} catch (PDOException $e) {
    $families_order_column = 'id'; // Fallback to ID
}

if ($has_families_status) {
    $families_stmt = $conn->query("SELECT * FROM product_families WHERE status = 'active' ORDER BY $families_order_column");
} else {
    $families_stmt = $conn->query("SELECT * FROM product_families ORDER BY $families_order_column");
}
$families = $families_stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic product information
    $name = sanitizeProductInput($_POST['name'] ?? '');
    $description = sanitizeProductInput($_POST['description'] ?? '', 'text');
    $category_id = (int)($_POST['category_id'] ?? 0);

    // SKU and identifiers
    $sku = sanitizeProductInput($_POST['sku'] ?? '');
    $product_number = sanitizeProductInput($_POST['product_number'] ?? '');
    $barcode = sanitizeProductInput($_POST['barcode'] ?? '');

    // Handle clear product number checkbox
    $clear_product_number = isset($_POST['clear_product_number']) ? 1 : 0;

    // Product type and pricing
    $product_type = sanitizeProductInput($_POST['product_type'] ?? 'physical');
    $price = (float)($_POST['price'] ?? 0);
    $cost_price = (float)($_POST['cost_price'] ?? 0);

    // Inventory
    $quantity = (int)($_POST['quantity'] ?? 0);
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    $maximum_stock = !empty($_POST['maximum_stock']) ? (int)$_POST['maximum_stock'] : null;
    $reorder_point = (int)($_POST['reorder_point'] ?? 0);

    // Additional details
    $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $product_family_id = !empty($_POST['product_family_id']) ? (int)$_POST['product_family_id'] : null;
    $status = sanitizeProductInput($_POST['status'] ?? 'active');
    $publication_status = sanitizeProductInput($_POST['publication_status'] ?? 'publish_now');
    $scheduled_date = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
    $tax_rate = !empty($_POST['tax_rate']) ? (float)$_POST['tax_rate'] : null;
    $tax_category_id = !empty($_POST['tax_category_id']) ? (int)$_POST['tax_category_id'] : null;
    $tags = sanitizeProductInput($_POST['tags'] ?? '');
    $warranty_period = sanitizeProductInput($_POST['warranty_period'] ?? '');

    // Sale information
    $sale_price = !empty($_POST['sale_price']) ? (float)$_POST['sale_price'] : null;
    $sale_start_date = !empty($_POST['sale_start_date']) ? $_POST['sale_start_date'] : null;
    $sale_end_date = !empty($_POST['sale_end_date']) ? $_POST['sale_end_date'] : null;

    // Dimensions and weight
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $length = !empty($_POST['length']) ? (float)$_POST['length'] : null;
    $width = !empty($_POST['width']) ? (float)$_POST['width'] : null;
    $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;

    // Settings
    $is_serialized = isset($_POST['is_serialized']) ? 1 : 0;
    $allow_backorders = isset($_POST['allow_backorders']) ? 1 : 0;
    $track_inventory = isset($_POST['track_inventory']) ? 1 : 0;

    // Validation
    if (empty($name)) {
        $errors['name'] = 'Product name is required';
    }

    if ($category_id <= 0) {
        $errors['category_id'] = 'Please select a category';
    }

    // Validate brand_id if provided
    if (!empty($brand_id)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
        $stmt->execute([$brand_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
            $errors['brand_id'] = 'Selected brand does not exist';
        }
    }

    // Validate supplier_id (now required)
    if (empty($supplier_id)) {
        $errors['supplier_id'] = 'Please select a supplier';
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
            $errors['supplier_id'] = 'Selected supplier does not exist';
        }
    }

    if ($price < 0) {
        $errors['price'] = 'Price must be a positive number';
    }

    if ($cost_price < 0) {
        $errors['cost_price'] = 'Cost price must be a positive number';
    }

    if ($quantity < 0) {
        $errors['quantity'] = 'Quantity must be a positive number';
    }

    if ($minimum_stock < 0) {
        $errors['minimum_stock'] = 'Minimum stock must be a positive number';
    }

    if ($maximum_stock !== null && $maximum_stock < 0) {
        $errors['maximum_stock'] = 'Maximum stock must be a positive number';
    }

    if ($reorder_point < 0) {
        $errors['reorder_point'] = 'Reorder point must be a positive number';
    }

    if ($tax_rate !== null && ($tax_rate < 0 || $tax_rate > 100)) {
        $errors['tax_rate'] = 'Tax rate must be between 0 and 100';
    }

    // Validate publication status
    if ($publication_status === 'scheduled') {
        if (empty($scheduled_date)) {
            $errors['scheduled_date'] = 'Scheduled publication date is required';
        } elseif (!strtotime($scheduled_date)) {
            $errors['scheduled_date'] = 'Invalid scheduled publication date format';
        } elseif (strtotime($scheduled_date) <= time()) {
            $errors['scheduled_date'] = 'Scheduled publication date must be in the future';
        }
    }

    // Validate sale information
    if ($sale_price !== null) {
        if ($sale_price < 0) {
            $errors['sale_price'] = 'Sale price must be a positive number';
        } elseif ($sale_price >= $price) {
            $errors['sale_price'] = 'Sale price must be less than regular price';
        }
    }

    if ($sale_start_date && $sale_end_date) {
        $start = strtotime($sale_start_date);
        $end = strtotime($sale_end_date);
        if ($start >= $end) {
            $errors['sale_dates'] = 'Sale end date must be after start date';
        }
    }

    // Check SKU uniqueness if provided (exclude current product)
    if (!empty($sku)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku AND id != :product_id");
        $stmt->bindParam(':sku', $sku);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['sku'] = 'This SKU already exists';
        }
    } else {
        // If SKU is empty, set it to NULL to avoid unique constraint violation
        $sku = null;
    }

    // Handle clear product number checkbox first
    if ($clear_product_number) {
        $product_number = ''; // Force it to be empty so auto-generation can handle it
    }

    // Auto-generate product number if setting is enabled (always generate during updates)
    if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') {
        if (empty($product_number) || $clear_product_number) {
            $product_number = generateProductNumber($conn);
        }
        // If not empty and not clearing, keep the existing value but validate uniqueness
    } else {
        // If auto-generation is not enabled, check for uniqueness only if provided
        if (!empty($product_number)) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_number = :product_number AND id != :product_id");
            $stmt->bindParam(':product_number', $product_number);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                $errors['product_number'] = 'This Product Number already exists';
            }
        } else {
            // If auto-generation is not enabled and empty, set it to NULL to avoid unique constraint violation
            $product_number = null;
        }
    }

    // Final uniqueness check if product_number is not empty
    if (!empty($product_number)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_number = :product_number AND id != :product_id");
        $stmt->bindParam(':product_number', $product_number);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['product_number'] = 'This Product Number already exists';
        }
    }

    // Check barcode uniqueness if provided (exclude current product)
    if (!empty($barcode)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE barcode = :barcode AND id != :product_id");
        $stmt->bindParam(':barcode', $barcode);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['barcode'] = 'This barcode already exists';
        }
    } else {
        // If barcode is empty, set it to NULL to avoid unique constraint violation
        $barcode = null;
    }

    // Validate dimensions
    if ($weight !== null && $weight < 0) {
        $errors['weight'] = 'Weight must be a positive number';
    }
    if ($length !== null && $length < 0) {
        $errors['length'] = 'Length must be a positive number';
    }
    if ($width !== null && $width < 0) {
        $errors['width'] = 'Width must be a positive number';
    }
    if ($height !== null && $height < 0) {
        $errors['height'] = 'Height must be a positive number';
    }

    // If no errors, update the product
    if (empty($errors)) {
        try {
            // Additional validation before database update
            if (empty($name) || empty($category_id) || $price < 0) {
                throw new Exception("Required fields are missing or invalid");
            }

            // Validate that category exists
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                throw new Exception("Selected category does not exist");
            }

            // Validate supplier exists if provided
            if (!empty($supplier_id)) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE id = ?");
                $stmt->execute([$supplier_id]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    throw new Exception("Selected supplier does not exist");
                }
            }

            // Validate brand exists if provided
            if (!empty($brand_id)) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
                $stmt->execute([$brand_id]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    throw new Exception("Selected brand does not exist");
                }
            }

            // Validate product family exists if provided
            if (!empty($product_family_id)) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_families WHERE id = ?");
                $stmt->execute([$product_family_id]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    throw new Exception("Selected product family does not exist");
                }
            }

            // Validate tax category exists if provided
            if (!empty($tax_category_id)) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM tax_categories WHERE id = ?");
                $stmt->execute([$tax_category_id]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    throw new Exception("Selected tax category does not exist");
                }
            }

            // Determine actual status based on publication status
            $actual_status = $status;
            if ($publication_status === 'draft') {
                $actual_status = 'draft';
            } elseif ($publication_status === 'publish_now') {
                $actual_status = 'active';
            } elseif ($publication_status === 'scheduled') {
                $actual_status = 'scheduled';
            }

            $update_stmt = $conn->prepare("
                UPDATE products SET
                    name = :name,
                    description = :description,
                    category_id = :category_id,
                    sku = :sku,
                    product_number = :product_number,
                    product_type = :product_type,
                    price = :price,
                    cost_price = :cost_price,
                    quantity = :quantity,
                    minimum_stock = :minimum_stock,
                    maximum_stock = :maximum_stock,
                    reorder_point = :reorder_point,
                    barcode = :barcode,
                    brand_id = :brand_id,
                    supplier_id = :supplier_id,
                    product_family_id = :product_family_id,
                    weight = :weight,
                    length = :length,
                    width = :width,
                    height = :height,
                    status = :status,
                    tax_rate = :tax_rate,
                    tax_category_id = :tax_category_id,
                    tags = :tags,
                    warranty_period = :warranty_period,
                    is_serialized = :is_serialized,
                    allow_backorders = :allow_backorders,
                    track_inventory = :track_inventory,
                    sale_price = :sale_price,
                    sale_start_date = :sale_start_date,
                    sale_end_date = :sale_end_date,
                    publication_status = :publication_status,
                    scheduled_date = :scheduled_date,
                    updated_at = NOW()
                WHERE id = :product_id
            ");

            $update_stmt->bindParam(':name', $name);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':category_id', $category_id);
            $update_stmt->bindParam(':sku', $sku);
            $update_stmt->bindParam(':product_number', $product_number);
            $update_stmt->bindParam(':product_type', $product_type);
            $update_stmt->bindParam(':price', $price);
            $update_stmt->bindParam(':cost_price', $cost_price);
            $update_stmt->bindParam(':quantity', $quantity);
            $update_stmt->bindParam(':minimum_stock', $minimum_stock);
            $update_stmt->bindParam(':maximum_stock', $maximum_stock);
            $update_stmt->bindParam(':reorder_point', $reorder_point);
            $update_stmt->bindParam(':barcode', $barcode);
            $update_stmt->bindParam(':brand_id', $brand_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':product_family_id', $product_family_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':weight', $weight);
            $update_stmt->bindParam(':length', $length);
            $update_stmt->bindParam(':width', $width);
            $update_stmt->bindParam(':height', $height);
            $update_stmt->bindParam(':status', $actual_status);
            $update_stmt->bindParam(':tax_rate', $tax_rate);
            $update_stmt->bindParam(':tax_category_id', $tax_category_id);
            $update_stmt->bindParam(':tags', $tags);
            $update_stmt->bindParam(':warranty_period', $warranty_period);
            $update_stmt->bindParam(':is_serialized', $is_serialized, PDO::PARAM_INT);
            $update_stmt->bindParam(':allow_backorders', $allow_backorders, PDO::PARAM_INT);
            $update_stmt->bindParam(':track_inventory', $track_inventory, PDO::PARAM_INT);
            $update_stmt->bindParam(':sale_price', $sale_price);
            $update_stmt->bindParam(':sale_start_date', $sale_start_date);
            $update_stmt->bindParam(':sale_end_date', $sale_end_date);
            $update_stmt->bindParam(':publication_status', $publication_status);
            $update_stmt->bindParam(':scheduled_date', $scheduled_date);
            $update_stmt->bindParam(':product_id', $product_id);

            if ($update_stmt->execute()) {
                // Log the activity
                $activity_message = "Updated product: $name (SKU: $sku) - Status: $publication_status";
                if ($publication_status === 'scheduled' && $scheduled_date) {
                    $activity_message .= " - Scheduled for: $scheduled_date";
                }
                logActivity($conn, $user_id, 'product_updated', $activity_message);

                $success_message = "Product '$name' has been updated successfully!";
                $_SESSION['success'] = $success_message;
                header("Location: view.php?id=$product_id");
                exit();
            }
        } catch (Exception $e) {
            // Handle validation errors and other general exceptions
            error_log("Product update validation error: " . $e->getMessage());
            $errors['general'] = $e->getMessage();
        } catch (PDOException $e) {
            // Enhanced error logging and user-friendly error messages
            error_log("Product update error: " . $e->getMessage());
            error_log("SQL Error Code: " . $e->getCode());
            error_log("SQL Error Info: " . print_r($e->errorInfo, true));

            // Provide more specific error messages based on error type
            if ($e->getCode() == 23000) {
                if (strpos($e->getMessage(), 'sku') !== false) {
                    $errors['sku'] = 'This SKU already exists. Please choose a different SKU.';
                } elseif (strpos($e->getMessage(), 'product_number') !== false) {
                    $errors['product_number'] = 'This Product Number already exists. Please choose a different Product Number.';
                } elseif (strpos($e->getMessage(), 'barcode') !== false) {
                    $errors['barcode'] = 'This barcode already exists. Please choose a different barcode.';
                } else {
                    $errors['general'] = 'A product with similar details already exists. Please check your input and try again.';
                }
            } elseif ($e->getCode() == 1452) {
                $errors['general'] = 'Invalid reference data. Please check that the selected category, brand, or supplier exists.';
            } elseif ($e->getCode() == 1054) {
                $errors['general'] = 'Database structure error. Please contact the administrator.';
            } else {
                $errors['general'] = 'An error occurred while updating the product: ' . $e->getMessage();
            }
        }
    }
}

// Handle AJAX requests for SKU generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_sku') {
    $pattern = $_GET['pattern'] ?? '';
    $prefix = $_GET['prefix'] ?? '';

    // Check if auto-generate SKU is enabled in system settings
    $sku_settings = getSKUSettings($conn);
    if ($sku_settings['auto_generate_sku']) {
        $sku = generateSystemSKU($conn, $product_id);
    } else {
        $sku = generateCustomSKU($conn, $pattern, $prefix);
    }

    header('Content-Type: application/json');
    echo json_encode(['sku' => $sku]);
    exit();
}

// Handle AJAX requests for Product Number generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_product_number') {
    $product_number = generateProductNumber($conn);

    header('Content-Type: application/json');
    echo json_encode(['product_number' => $product_number]);
    exit();
}

// Handle AJAX requests for barcode generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_barcode') {
    $timestamp = time();
    $random = rand(100, 999);
    $barcode = $timestamp . $random;
    header('Content-Type: application/json');
    echo json_encode(['barcode' => $barcode]);
    exit();
}

// Custom SKU generation function
function generateCustomSKU($conn, $pattern = '', $prefix = '', $length = 6) {
    if (empty($pattern)) {
        // Default patterns
        $patterns = ['N000000', 'LIZ000000', 'PROD000000', 'ITEM000000'];
        $pattern = $patterns[array_rand($patterns)];
    }

    do {
        $sku = $pattern;
        if (strpos($pattern, '000') !== false) {
            // Replace zeros with random numbers
            $sku = preg_replace_callback('/0+/', function($matches) use ($length) {
                $zeros = strlen($matches[0]);
                return str_pad(rand(0, pow(10, $zeros) - 1), $zeros, '0', STR_PAD_LEFT);
            }, $pattern);
        } else {
            // Append random numbers
            $sku = $pattern . str_pad(rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        }

        if (!empty($prefix)) {
            $sku = $prefix . $sku;
        }

        // Check if SKU already exists
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

    } while ($exists);

    return $sku;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-width: 280px;
        }

        /* Ensure sidebar is properly positioned */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: var(--sidebar-width) !important;
            z-index: 1000 !important;
            overflow-y: auto !important;
            transition: all 0.3s ease !important;
        }

        /* Ensure main content is not hidden by sidebar */
        .main-content {
            margin-left: var(--sidebar-width) !important;
            min-height: 100vh !important;
            position: relative !important;
            z-index: 1 !important;
            transition: all 0.3s ease !important;
        }

        /* Fix navigation overlap issue */
        .main-content {
            margin-left: 280px !important;
            padding-left: 0 !important;
            width: calc(100% - 280px) !important;
        }

        .content {
            padding: 2rem !important;
            max-width: 100% !important;
        }

        .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* Ensure form sections are properly spaced */
        .row {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }

        .col-md-8, .col-md-4 {
            padding-left: 15px !important;
            padding-right: 15px !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }

            .content {
                padding: 1rem !important;
            }
        }

        /* Fix for header and content positioning */
        body {
            overflow-x: hidden !important;
        }

        /* Ensure all sections are visible */
        .product-form, .form-section, .section-title {
            position: relative !important;
            z-index: 2 !important;
        }

        /* Sticky product info adjustments */
        .main-content {
            padding-top: 0 !important;
        }

        /* Ensure sticky element doesn't overlap header */
        .header {
            position: relative;
            z-index: 102;
        }

        /* Add smooth scrolling for better UX */
        html {
            scroll-behavior: smooth;
        }

        /* Product info responsive adjustments */
        @media (max-width: 768px) {
            .product-info {
                position: relative !important;
                top: auto !important;
                margin-bottom: 1rem;
            }

            .product-info .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .product-info .info-label {
                font-size: 0.75rem;
            }

            .product-info .info-value {
                font-size: 0.875rem;
            }
        }

        #publicationSection {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        #publicationSection .section-title {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        #scheduledDateGroup {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        #saveBtn {
            min-width: 150px;
            transition: all 0.2s ease;
        }

        #saveBtn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .product-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
            position: sticky;
            top: 20px;
            z-index: 100;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .product-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.4);
        }

        .product-info .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .product-info .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .product-info .info-label {
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .product-info .info-value {
            color: white;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .product-info h5 {
            color: white;
            margin-bottom: 1.5rem;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .product-info h5 i {
            margin-right: 0.5rem;
            font-size: 1.4rem;
        }

        /* Status badge styling */
        .badge {
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .badge.bg-success {
            background-color: rgba(34, 197, 94, 0.9) !important;
            color: white;
        }

        .badge.bg-secondary {
            background-color: rgba(107, 114, 128, 0.9) !important;
            color: white;
        }

        .badge.bg-danger {
            background-color: rgba(239, 68, 68, 0.9) !important;
            color: white;
        }

        .badge.bg-warning {
            background-color: rgba(245, 158, 11, 0.9) !important;
            color: white;
        }

        .badge.bg-info {
            background-color: rgba(59, 130, 246, 0.9) !important;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'products';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Edit Product</h1>
                    <div class="header-subtitle">Modify product details and settings</div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-eye"></i>
                        View Product
                    </a>
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Products
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($errors['general']); ?>
            </div>
            <?php endif; ?>

            <!-- Product Info Summary -->
            <div class="product-info">
                <h5><i class="bi bi-info-circle"></i>Current Product Information</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-item">
                            <span class="info-label">Product ID:</span>
                            <span class="info-value"><strong>#<?php echo $product_id; ?></strong></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Current Status:</span>
                            <span class="info-value">
                                <?php
                                $status_badge = '';
                                switch($product['status']) {
                                    case 'active':
                                        $status_badge = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>';
                                        break;
                                    case 'inactive':
                                        $status_badge = '<span class="badge bg-secondary"><i class="bi bi-pause-circle me-1"></i>Inactive</span>';
                                        break;
                                    case 'discontinued':
                                        $status_badge = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Discontinued</span>';
                                        break;
                                    case 'draft':
                                        $status_badge = '<span class="badge bg-warning"><i class="bi bi-file-earmark me-1"></i>Draft</span>';
                                        break;
                                    case 'scheduled':
                                        $status_badge = '<span class="badge bg-info"><i class="bi bi-clock me-1"></i>Scheduled</span>';
                                        break;
                                    default:
                                        $status_badge = '<span class="badge bg-secondary"><i class="bi bi-question-circle me-1"></i>' . ucfirst($product['status']) . '</span>';
                                }
                                echo $status_badge;
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created:</span>
                            <span class="info-value"><i class="bi bi-calendar-plus me-1"></i><?php echo date('M d, Y H:i', strtotime($product['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-item">
                            <span class="info-label">SKU:</span>
                            <span class="info-value"><strong><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Product Number:</span>
                            <span class="info-value"><strong><?php echo htmlspecialchars($product['product_number'] ?? 'N/A'); ?></strong></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Last Updated:</span>
                            <span class="info-value"><i class="bi bi-pencil me-1"></i><?php echo date('M d, Y H:i', strtotime($product['updated_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="product-form">
                <form method="POST" id="productForm" enctype="multipart/form-data">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-info-circle me-2"></i>
                            Basic Information
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name" class="form-label">Product Name *</label>
                                <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                       id="name" name="name" value="<?php echo htmlspecialchars($product['name']); ?>"
                                       required placeholder="Enter product name">
                                <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="product_type" class="form-label">Product Type *</label>
                                <select class="form-control" id="product_type" name="product_type">
                                    <option value="physical" <?php echo $product['product_type'] === 'physical' ? 'selected' : ''; ?>>Physical Product</option>
                                    <option value="digital" <?php echo $product['product_type'] === 'digital' ? 'selected' : ''; ?>>Digital Product</option>
                                    <option value="service" <?php echo $product['product_type'] === 'service' ? 'selected' : ''; ?>>Service</option>
                                    <option value="subscription" <?php echo $product['product_type'] === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      placeholder="Detailed product description"><?php echo htmlspecialchars($product['description']); ?></textarea>
                        </div>
                    </div>

                    <!-- Identifiers -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-tag me-2"></i>
                            Product Identifiers
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sku" class="form-label">SKU (Stock Keeping Unit)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['sku']) ? 'is-invalid' : ''; ?>"
                                           id="sku" name="sku" value="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                                           placeholder="Leave empty for auto-generation">
                                    <button type="button" class="btn btn-outline-secondary" id="generateSKU">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['sku'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sku']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Unique identifier for inventory tracking. Leave empty to auto-generate.
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_number" class="form-label">
                                    Product Number
                                    <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                                    <span class="badge bg-success ms-2"><i class="bi bi-gear-fill"></i> Auto-Generated</span>
                                    <?php endif; ?>
                                </label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['product_number']) ? 'is-invalid' : ''; ?>"
                                           id="product_number" name="product_number" value="<?php echo htmlspecialchars($product['product_number'] ?? ''); ?>"
                                           placeholder="<?php echo (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') ? 'Auto-generated during update...' : 'Leave empty for auto-generation'; ?>"
                                           <?php echo (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') ? 'readonly' : ''; ?>>
                                    <button type="button" class="btn btn-outline-secondary" id="generateProductNumber">
                                        <i class="bi bi-magic"></i>
                                        <?php echo (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') ? 'Regenerate' : 'Generate'; ?>
                                    </button>
                                </div>
                                <?php if (isset($errors['product_number'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['product_number']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                                    <span class="text-success"><i class="bi bi-check-circle"></i> Product numbers are automatically generated during updates based on your <a href="../admin/settings/adminsetting.php?tab=inventory" target="_blank">admin settings</a>.</span>
                                    <?php else: ?>
                                    Internal product number for tracking. Leave empty to auto-generate or click "Generate" button.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="barcode" class="form-label">Barcode</label>
                                <div class="input-group">
                                    <input type="text" class="form-control <?php echo isset($errors['barcode']) ? 'is-invalid' : ''; ?>"
                                           id="barcode" name="barcode" value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>"
                                           placeholder="Enter or generate barcode">
                                    <button type="button" class="btn btn-outline-secondary" id="generateBarcode">
                                        <i class="bi bi-magic"></i>
                                        Generate
                                    </button>
                                </div>
                                <?php if (isset($errors['barcode'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['barcode']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Barcode for product scanning (optional but recommended)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-currency-dollar me-2"></i>
                            Pricing & Cost
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="price" class="form-label">Selling Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>) *</label>
                                <input type="number" class="form-control <?php echo isset($errors['price']) ? 'is-invalid' : ''; ?>"
                                       id="price" name="price" value="<?php echo htmlspecialchars($product['price']); ?>"
                                       step="0.01" min="0" required placeholder="0.00">
                                <?php if (isset($errors['price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['price']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="cost_price" class="form-label">Cost Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</label>
                                <input type="number" class="form-control <?php echo isset($errors['cost_price']) ? 'is-invalid' : ''; ?>"
                                       id="cost_price" name="cost_price" value="<?php echo htmlspecialchars($product['cost_price']); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['cost_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['cost_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Your cost to acquire this product (used for profit calculations)
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="tax_category_id" class="form-label">Tax Rate Selection</label>
                                <select class="form-control" id="tax_category_id" name="tax_category_id">
                                    <option value="">Use System Default Tax Rate</option>
                                    <?php foreach ($tax_categories as $category_id => $category): ?>
                                    <optgroup label="<?php echo htmlspecialchars($category['name']); ?>">
                                        <?php if (empty($category['rates'])): ?>
                                    <option value="<?php echo $category['id']; ?>"
                                            <?php echo ($product['tax_category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                            No active rates available
                                        </option>
                                        <?php else: ?>
                                        <?php foreach ($category['rates'] as $rate): ?>
                                        <option value="<?php echo $rate['tax_category_id']; ?>"
                                                data-rate="<?php echo $rate['rate_percentage']; ?>%"
                                                <?php echo ($product['tax_category_id'] == $rate['tax_category_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($rate['rate_name']); ?> (<?php echo number_format($rate['rate_percentage'], 2); ?>%)
                                    </option>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Choose a tax category with specific rates, or use the system default. Product-specific rate below will override this selection.
                                </div>
                            </div>
                        </div>

                        <script>
                        document.getElementById('tax_category_id').addEventListener('change', function() {
                            const selectedOption = this.options[this.selectedIndex];
                            const taxRateField = document.getElementById('tax_rate');
                            const rate = selectedOption.getAttribute('data-rate');

                            if (rate && rate !== '%') {
                                // Extract the numeric value from "16.00%" format
                                const numericRate = rate.replace('%', '');
                                taxRateField.value = numericRate;
                            }
                        });
                        </script>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                <input type="number" class="form-control <?php echo isset($errors['tax_rate']) ? 'is-invalid' : ''; ?>"
                                       id="tax_rate" name="tax_rate" value="<?php echo htmlspecialchars($product['tax_rate'] ?? ''); ?>"
                                       step="0.01" min="0" max="100" placeholder="Leave empty for default">
                                <?php if (isset($errors['tax_rate'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['tax_rate']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Product-specific tax rate (overrides tax category rates if set)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="warranty_period" class="form-label">Warranty Period</label>
                                <input type="text" class="form-control" id="warranty_period" name="warranty_period"
                                       value="<?php echo htmlspecialchars($product['warranty_period']); ?>"
                                       placeholder="e.g., 1 year, 6 months, 30 days">
                                <div class="form-text">
                                    Warranty period for this product
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sale Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-tag me-2"></i>
                            Sale Information
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="sale_price" class="form-label">Sale Price (<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>)</label>
                                <input type="number" class="form-control <?php echo isset($errors['sale_price']) ? 'is-invalid' : ''; ?>"
                                       id="sale_price" name="sale_price" value="<?php echo htmlspecialchars($product['sale_price'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="Leave empty if not on sale">
                                <?php if (isset($errors['sale_price'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_price']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Special sale price (must be less than regular price)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="sale_start_date" class="form-label">Sale Start Date</label>
                                <input type="datetime-local" class="form-control" id="sale_start_date" name="sale_start_date"
                                       value="<?php echo htmlspecialchars(!empty($product['sale_start_date']) ? date('Y-m-d\TH:i', strtotime($product['sale_start_date'])) : ''); ?>">
                                <div class="form-text">
                                    When the sale should start (leave empty for immediate)
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="sale_end_date" class="form-label">Sale End Date</label>
                                <input type="datetime-local" class="form-control <?php echo isset($errors['sale_dates']) ? 'is-invalid' : ''; ?>"
                                       id="sale_end_date" name="sale_end_date" value="<?php echo htmlspecialchars(!empty($product['sale_end_date']) ? date('Y-m-d\TH:i', strtotime($product['sale_end_date'])) : ''); ?>">
                                <?php if (isset($errors['sale_dates'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['sale_dates']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    When the sale should end (leave empty for indefinite)
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="mt-4 pt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="clear_sale" name="clear_sale">
                                        <label class="form-check-label" for="clear_sale">
                                            Clear sale information
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Check to remove sale pricing from this product
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="clear_product_number" name="clear_product_number">
                                    <label class="form-check-label" for="clear_product_number">
                                        <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                                            Clear and auto-generate new product number
                                        <?php else: ?>
                                            Clear product number (will be auto-generated if empty)
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <div class="form-text">
                                    <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                                        Check to clear the current product number and generate a new one automatically during update
                                    <?php else: ?>
                                        Check to clear the current product number (will be auto-generated during update if setting is enabled)
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <!-- Empty for balance -->
                            </div>
                        </div>
                    </div>

                    <!-- Inventory -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-boxes me-2"></i>
                            Inventory Management
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quantity" class="form-label">Current Quantity *</label>
                                <input type="number" class="form-control <?php echo isset($errors['quantity']) ? 'is-invalid' : ''; ?>"
                                       id="quantity" name="quantity" value="<?php echo htmlspecialchars($product['quantity']); ?>"
                                       min="0" required placeholder="0">
                                <?php if (isset($errors['quantity'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['quantity']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="minimum_stock" class="form-label">Minimum Stock Level</label>
                                <input type="number" class="form-control <?php echo isset($errors['minimum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="minimum_stock" name="minimum_stock" value="<?php echo htmlspecialchars($product['minimum_stock']); ?>"
                                       min="0" placeholder="<?php echo $default_minimum_stock; ?>">
                                <?php if (isset($errors['minimum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['minimum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Alert when stock falls below this level. Default: <?php echo $default_minimum_stock; ?> (from system settings)
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="maximum_stock" class="form-label">Maximum Stock Level</label>
                                <input type="number" class="form-control <?php echo isset($errors['maximum_stock']) ? 'is-invalid' : ''; ?>"
                                       id="maximum_stock" name="maximum_stock" value="<?php echo htmlspecialchars($product['maximum_stock'] ?? ''); ?>"
                                       min="0" placeholder="Leave empty for unlimited">
                                <?php if (isset($errors['maximum_stock'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['maximum_stock']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Maximum stock level (optional)
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="reorder_point" class="form-label">Reorder Point</label>
                                <input type="number" class="form-control <?php echo isset($errors['reorder_point']) ? 'is-invalid' : ''; ?>"
                                       id="reorder_point" name="reorder_point" value="<?php echo htmlspecialchars($product['reorder_point']); ?>"
                                       min="0" placeholder="<?php echo $default_reorder_point; ?>">
                                <?php if (isset($errors['reorder_point'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['reorder_point']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Point at which you should reorder this product. Default: <?php echo $default_reorder_point; ?> (from system settings)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Classification -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-folder me-2"></i>
                            Classification & Organization
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="category_id" class="form-label">Category *</label>
                                <select class="form-control <?php echo isset($errors['category_id']) ? 'is-invalid' : ''; ?>"
                                        id="category_id" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"
                                            <?php echo $product['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['category_id'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['category_id']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <a href="../categories/add.php" target="_blank">Add new category</a>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="brand_id" class="form-label">Brand</label>
                                <select class="form-control" id="brand_id" name="brand_id">
                                    <option value="">Select Brand</option>
                                    <?php foreach ($brands as $brand_item): ?>
                                    <option value="<?php echo $brand_item['id']; ?>"
                                            <?php echo $product['brand_id'] == $brand_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brand_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../brands/add.php" target="_blank">Add new brand</a>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="product_family_id" class="form-label">Product Family</label>
                                <select class="form-control" id="product_family_id" name="product_family_id">
                                    <option value="">Select Product Family</option>
                                    <?php foreach ($families as $family): ?>
                                    <option value="<?php echo $family['id']; ?>"
                                            <?php echo $product['product_family_id'] == $family['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($family['name']); ?> (<?php echo htmlspecialchars($family['base_unit']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../product_families/add.php" target="_blank">Add new family</a>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                                <select class="form-control" id="supplier_id" name="supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier_item): ?>
                                    <option value="<?php echo $supplier_item['id']; ?>"
                                            <?php echo $product['supplier_id'] == $supplier_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier_item['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <a href="../suppliers/add.php" target="_blank">Add new supplier</a>
                                </div>
                                <?php if (isset($errors['supplier_id'])): ?>
                                    <div class="text-danger mt-1"><?php echo $errors['supplier_id']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="active" <?php echo $product['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $product['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="discontinued" <?php echo $product['status'] === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <!-- Empty for balance -->
                            </div>
                        </div>
                    </div>

                    <!-- Physical Properties -->
                    <div class="form-section" id="physicalProperties" style="display: none;">
                        <h4 class="section-title">
                            <i class="bi bi-rulers me-2"></i>
                            Physical Properties
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="weight" class="form-label">Weight (kg)</label>
                                <input type="number" class="form-control <?php echo isset($errors['weight']) ? 'is-invalid' : ''; ?>"
                                       id="weight" name="weight" value="<?php echo htmlspecialchars($product['weight'] ?? ''); ?>"
                                       step="0.001" min="0" placeholder="0.000">
                                <?php if (isset($errors['weight'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['weight']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="length" class="form-label">Length (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['length']) ? 'is-invalid' : ''; ?>"
                                       id="length" name="length" value="<?php echo htmlspecialchars($product['length'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['length'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['length']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="width" class="form-label">Width (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['width']) ? 'is-invalid' : ''; ?>"
                                       id="width" name="width" value="<?php echo htmlspecialchars($product['width'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['width'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['width']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="height" class="form-label">Height (cm)</label>
                                <input type="number" class="form-control <?php echo isset($errors['height']) ? 'is-invalid' : ''; ?>"
                                       id="height" name="height" value="<?php echo htmlspecialchars($product['height'] ?? ''); ?>"
                                       step="0.01" min="0" placeholder="0.00">
                                <?php if (isset($errors['height'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['height']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Settings -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-gear me-2"></i>
                            Additional Settings
                        </h4>
                        <div class="form-group">
                            <label for="tags" class="form-label">Tags</label>
                            <input type="text" class="form-control" id="tags" name="tags"
                                   value="<?php echo htmlspecialchars($product['tags'] ?? ''); ?>"
                                   placeholder="Enter tags separated by commas">
                            <div class="form-text">
                                Tags help with product search and organization (e.g., electronics, wireless, portable)
                            </div>
                        </div>

                        <div class="form-check-group">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_serialized" name="is_serialized" value="1"
                                               <?php echo $product['is_serialized'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_serialized">
                                            Serialized Product
                                        </label>
                                        <div class="form-text">
                                            Requires unique serial number tracking
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="allow_backorders" name="allow_backorders" value="1"
                                               <?php echo $product['allow_backorders'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_backorders">
                                            Allow Backorders
                                        </label>
                                        <div class="form-text">
                                            Allow sales when out of stock
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="track_inventory" name="track_inventory" value="1"
                                               <?php echo $product['track_inventory'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="track_inventory">
                                            Track Inventory
                                        </label>
                                        <div class="form-text">
                                            Enable inventory tracking for this product
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Publication Status Section -->
                    <div class="form-section" id="publicationSection">
                        <h4 class="section-title">
                            <i class="bi bi-globe me-2"></i>
                            Publication Settings
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="publication_status" class="form-label">Publication Status</label>
                                <select class="form-control" id="publication_status" name="publication_status" style="width: auto; min-width: 250px;">
                                    <option value="publish_now" <?php echo (($product['publication_status'] ?? 'publish_now') === 'publish_now') ? 'selected' : ''; ?>>Publish Immediately</option>
                                    <option value="draft" <?php echo (($product['publication_status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Save as Draft</option>
                                    <option value="scheduled" <?php echo (($product['publication_status'] ?? '') === 'scheduled') ? 'selected' : ''; ?>>Schedule Publication</option>
                                </select>
                                <div class="form-text">
                                    Choose when this product should be available for sale
                                </div>
                            </div>
                        </div>

                        <!-- Scheduled Date Field -->
                        <div class="form-group" id="scheduledDateGroup" style="display: <?php echo (($product['publication_status'] ?? 'publish_now') === 'scheduled') ? 'block' : 'none'; ?>;">
                            <label for="scheduled_date" class="form-label">Scheduled Publication Date & Time *</label>
                            <input type="datetime-local" class="form-control <?php echo isset($errors['scheduled_date']) ? 'is-invalid' : ''; ?>"
                                   id="scheduled_date" name="scheduled_date"
                                   value="<?php echo htmlspecialchars(!empty($product['scheduled_date']) ? date('Y-m-d\TH:i', strtotime($product['scheduled_date'])) : ''); ?>"
                                   min="<?php echo date('Y-m-d\TH:i'); ?>"
                                   style="width: auto; min-width: 280px;">
                            <?php if (isset($errors['scheduled_date'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['scheduled_date']); ?></div>
                            <?php endif; ?>
                            <div class="form-text">
                                Select when you want this product to be automatically published and become available for sale.
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                <i class="bi bi-check"></i>
                                <span id="saveBtnText">Update Product</span>
                            </button>
                            <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                        <div class="form-text mt-2">
                            <small class="text-muted">
                                Choose your publication option above, then click save. You can always change the publication status later.
                            </small>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Help Section -->
            <div class="data-section mt-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-question-circle me-2"></i>
                        Need Help?
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <h5><i class="bi bi-tag me-2"></i>Product Name</h5>
                        <p class="text-muted">Enter a clear and descriptive name for your product that customers will easily recognize.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-folder me-2"></i>Category</h5>
                        <p class="text-muted">Choose the appropriate category to help organize your products and make them easier to find.</p>
                    </div>
                    <div class="col-md-4">
                        <h5><i class="bi bi-upc-scan me-2"></i>Barcode</h5>
                        <p class="text-muted">Use the existing barcode from your product or generate a new unique one for inventory tracking.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const publicationSelect = document.getElementById('publication_status');
            const scheduledDateGroup = document.getElementById('scheduledDateGroup');
            const saveBtnText = document.getElementById('saveBtnText');

            function updatePublicationUI() {
                const selectedValue = publicationSelect.value;

                // Update button text based on selection
                switch(selectedValue) {
                    case 'draft':
                        saveBtnText.textContent = 'Save as Draft';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'none';
                        break;
                    case 'publish_now':
                        saveBtnText.textContent = 'Update Product';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'none';
                        break;
                    case 'scheduled':
                        saveBtnText.textContent = 'Schedule Product';
                        if (scheduledDateGroup) scheduledDateGroup.style.display = 'block';
                        break;
                }
            }

            // Add event listener to dropdown
            publicationSelect.addEventListener('change', updatePublicationUI);

            // Initialize UI on page load
            updatePublicationUI();

            // Product Number Generation
            const generateProductNumberBtn = document.getElementById('generateProductNumber');
            const productNumberInput = document.getElementById('product_number');

            if (generateProductNumberBtn) {
                generateProductNumberBtn.addEventListener('click', function() {
                    fetch('?action=generate_product_number')
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('product_number').value = data.product_number;
                        })
                        .catch(error => {
                            console.error('Error generating product number:', error);
                        });
                });
            }

            // Form validation for scheduled date
            const scheduledDateInput = document.getElementById('scheduled_date');
            if (scheduledDateInput) {
                scheduledDateInput.addEventListener('change', function() {
                    const selectedDate = new Date(this.value);
                    const now = new Date();

                    if (selectedDate <= now) {
                        this.setCustomValidity('Scheduled date must be in the future');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }

            // Handle clear product number checkbox
            const clearProductNumberCheckbox = document.getElementById('clear_product_number');
            const productNumberInput = document.getElementById('product_number');

            if (clearProductNumberCheckbox && productNumberInput) {
                clearProductNumberCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        // Clear the product number field
                        productNumberInput.value = '';
                        productNumberInput.disabled = true;

                        <?php if (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1'): ?>
                        // If auto-generation is enabled, generate a new product number
                        fetch('?action=generate_product_number')
                            .then(response => response.json())
                            .then(data => {
                                productNumberInput.value = data.product_number;
                            })
                            .catch(error => {
                                console.error('Error generating product number:', error);
                            });
                        <?php endif; ?>
                    } else {
                        // Re-enable the field
                        productNumberInput.disabled = false;
                        productNumberInput.value = '<?php echo htmlspecialchars($product['product_number'] ?? ''); ?>';
                    }
                });
            }

            // Show/hide physical properties based on product type
            const productTypeSelect = document.getElementById('product_type');
            const physicalProperties = document.getElementById('physicalProperties');

            function togglePhysicalProperties() {
                if (productTypeSelect.value === 'physical') {
                    physicalProperties.style.display = 'block';
                } else {
                    physicalProperties.style.display = 'none';
                }
            }

            productTypeSelect.addEventListener('change', togglePhysicalProperties);
            togglePhysicalProperties(); // Initialize on page load
        });
    </script>
</body>
</html>
