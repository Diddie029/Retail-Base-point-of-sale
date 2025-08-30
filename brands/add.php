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

// Check if user has permission to manage products (includes brands)
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$errors = [];
$success = '';

// Input sanitization function for brand data
function sanitizeBrandInput($input, $type = 'string') {
    $input = trim($input);

    switch ($type) {
        case 'text':
            $input = filter_var($input, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
            break;
        case 'email':
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            break;
        case 'url':
            $input = filter_var($input, FILTER_SANITIZE_URL);
            break;
        default:
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    return $input;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic brand information
    $name = sanitizeBrandInput($_POST['name'] ?? '');
    $description = sanitizeBrandInput($_POST['description'] ?? '', 'text');
    $website = sanitizeBrandInput($_POST['website'] ?? '', 'url');
    $logo_url = sanitizeBrandInput($_POST['logo_url'] ?? '', 'url');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($name)) {
        $errors['name'] = 'Brand name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Brand name must be at least 2 characters long';
    } elseif (strlen($name) > 100) {
        $errors['name'] = 'Brand name cannot exceed 100 characters';
    }

    // Check if brand name already exists
    if (!empty($name)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM brands WHERE name = :name");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['name'] = 'This brand name already exists';
        }
    }

    // Validate website URL if provided
    if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
        $errors['website'] = 'Please enter a valid website URL';
    }

    // Validate logo URL if provided
    if (!empty($logo_url) && !filter_var($logo_url, FILTER_VALIDATE_URL)) {
        $errors['logo_url'] = 'Please enter a valid logo URL';
    }

    // If no errors, save the brand
    if (empty($errors)) {
        try {
            $insert_stmt = $conn->prepare("
                INSERT INTO brands (name, description, logo_url, website, is_active, created_at, updated_at)
                VALUES (:name, :description, :logo_url, :website, :is_active, NOW(), NOW())
            ");

            $insert_stmt->bindParam(':name', $name);
            $insert_stmt->bindParam(':description', $description);
            $insert_stmt->bindParam(':logo_url', $logo_url);
            $insert_stmt->bindParam(':website', $website);
            $insert_stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);

            if ($insert_stmt->execute()) {
                $brand_id = $conn->lastInsertId();

                // Log the activity
                logActivity($conn, $user_id, 'brand_created', "Created brand: $name");

                $_SESSION['success'] = "Brand '$name' has been added successfully!";
                header("Location: view.php?id=$brand_id");
                exit();
            }
        } catch (PDOException $e) {
            $errors['general'] = 'An error occurred while saving the brand. Please try again.';
            error_log("Brand creation error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Brand - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
    </style>
</head>
<body>
        <!-- Sidebar -->
    <?php
    $current_page = 'brands';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Add Brand</h1>
                    <div class="header-subtitle">Create a new brand for your products</div>
                </div>
                <div class="header-actions">
                    <a href="brands.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Brands
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

            <div class="product-form">
                <form method="POST" id="brandForm">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-info-circle me-2"></i>
                            Basic Information
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name" class="form-label">Brand Name *</label>
                                <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                       id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                       required placeholder="Enter brand name" maxlength="100">
                                <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    The name of the brand or manufacturer
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="is_active" class="form-label">Status</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                           value="1" <?php echo (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Active Brand
                                    </label>
                                </div>
                                <div class="form-text">
                                    Only active brands can be assigned to products
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      placeholder="Describe the brand, its history, or special features"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            <div class="form-text">
                                Optional description of the brand
                            </div>
                        </div>
                    </div>

                    <!-- Online Presence -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-globe me-2"></i>
                            Online Presence
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control <?php echo isset($errors['website']) ? 'is-invalid' : ''; ?>"
                                       id="website" name="website" value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>"
                                       placeholder="https://www.example.com">
                                <?php if (isset($errors['website'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['website']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Official website of the brand
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="logo_url" class="form-label">Logo URL</label>
                                <input type="url" class="form-control <?php echo isset($errors['logo_url']) ? 'is-invalid' : ''; ?>"
                                       id="logo_url" name="logo_url" value="<?php echo htmlspecialchars($_POST['logo_url'] ?? ''); ?>"
                                       placeholder="https://www.example.com/logo.png">
                                <?php if (isset($errors['logo_url'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['logo_url']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    URL of the brand's logo image
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Add Brand
                            </button>
                            <a href="brands.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
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
                    <div class="col-md-6">
                        <h5><i class="bi bi-star me-2"></i>Brand Management</h5>
                        <p class="text-muted">Brands help you organize and categorize your products by manufacturer or brand name. This makes it easier for customers to find products from their preferred brands.</p>
                    </div>
                    <div class="col-md-6">
                        <h5><i class="bi bi-link-45deg me-2"></i>Online Presence</h5>
                        <p class="text-muted">Adding website and logo URLs helps build credibility and allows customers to learn more about the brand directly from your system.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const brandForm = document.getElementById('brandForm');
            if (brandForm) {
                brandForm.addEventListener('submit', function(e) {
                    let isValid = true;
                    const requiredFields = brandForm.querySelectorAll('[required]');

                    requiredFields.forEach(field => {
                        const feedback = field.parentNode.querySelector('.invalid-feedback');
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            if (feedback) {
                                feedback.textContent = 'This field is required';
                            }
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                            if (feedback) {
                                feedback.textContent = '';
                            }
                        }
                    });

                    // Validate name length
                    const nameField = document.getElementById('name');
                    if (nameField && nameField.value) {
                        if (nameField.value.length < 2) {
                            nameField.classList.add('is-invalid');
                            const feedback = nameField.parentNode.querySelector('.invalid-feedback');
                            if (feedback) {
                                feedback.textContent = 'Brand name must be at least 2 characters';
                            }
                            isValid = false;
                        } else if (nameField.value.length > 100) {
                            nameField.classList.add('is-invalid');
                            const feedback = nameField.parentNode.querySelector('.invalid-feedback');
                            if (feedback) {
                                feedback.textContent = 'Brand name cannot exceed 100 characters';
                            }
                            isValid = false;
                        }
                    }

                    if (!isValid) {
                        e.preventDefault();
                        showAlert('Please fix the errors below', 'danger');
                    }
                });

                // Real-time validation
                const inputs = brandForm.querySelectorAll('input, textarea');
                inputs.forEach(input => {
                    input.addEventListener('blur', function() {
                        validateField(this);
                    });

                    input.addEventListener('input', function() {
                        if (this.classList.contains('is-invalid')) {
                            validateField(this);
                        }
                    });
                });
            }

            function validateField(field) {
                const feedback = field.parentNode.querySelector('.invalid-feedback');

                if (field.hasAttribute('required') && !field.value.trim()) {
                    field.classList.add('is-invalid');
                    if (feedback) {
                        feedback.textContent = 'This field is required';
                    }
                    return false;
                }

                if (field.type === 'url' && field.value && !field.value.match(/^https?:\/\/.+/)) {
                    field.classList.add('is-invalid');
                    if (feedback) {
                        feedback.textContent = 'Please enter a valid URL starting with http:// or https://';
                    }
                    return false;
                }

                if (field.id === 'name' && field.value) {
                    if (field.value.length < 2) {
                        field.classList.add('is-invalid');
                        if (feedback) {
                            feedback.textContent = 'Brand name must be at least 2 characters';
                        }
                        return false;
                    } else if (field.value.length > 100) {
                        field.classList.add('is-invalid');
                        if (feedback) {
                            feedback.textContent = 'Brand name cannot exceed 100 characters';
                        }
                        return false;
                    }
                }

                field.classList.remove('is-invalid');
                if (feedback) {
                    feedback.textContent = '';
                }
                return true;
            }
        });
    </script>
</body>
</html>
